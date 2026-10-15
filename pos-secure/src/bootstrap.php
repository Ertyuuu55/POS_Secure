<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function cfg(): array
{
    static $c = null;
    return $c ??= require APP_ROOT . '/config/config.php';
}

/* ---------- Penanganan error: log di server, pesan generik ke pengguna ---------- */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[POS] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Terjadi kesalahan pada server. Silakan coba lagi nanti.';
    exit;
});

/* ---------- Header keamanan & sesi (hanya untuk request web) ---------- */
if (PHP_SAPI !== 'cli') {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; "
         . "img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header('Cache-Control: no-store');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    ini_set('session.use_strict_mode', '1');   // tolak session ID yang tidak dibuat server
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name(cfg()['session']['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,        // tidak bisa dibaca JavaScript
        'samesite' => 'Strict',    // lapisan tambahan anti-CSRF
    ]);
    session_start();
}

/* ---------- Database (PDO + prepared statement asli) ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = cfg()['db'];
    $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // query & data dikirim terpisah -> kebal SQL injection
    ]);
    return $pdo;
}

/* ---------- Helper umum ---------- */

/** Output encoding (anti-XSS). Pakai untuk SEMUA data yang dicetak ke HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect hanya ke path internal yang ditulis di kode (bukan input user) -> tidak ada open redirect. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function client_ip(): string
{
    // Sengaja TIDAK memakai X-Forwarded-For karena bisa dipalsukan klien.
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Token keamanan tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

/* Flash message */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* Sanitasi & validasi input (selalu di sisi server) */

/** Ambil string bersih: hanya string (bukan array), UTF-8 valid, tanpa karakter kontrol, di-trim. */
function clean_str(mixed $v): string
{
    if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
        return '';
    }
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
}

function to_int(mixed $v, int $min, int $max): ?int
{
    if (is_int($v)) {
        $v = (string)$v;
    }
    if (!is_string($v)) {
        return null;
    }
    $r = filter_var(trim($v), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    return $r === false ? null : $r;
}

/** Uang: angka tanpa pemisah ribuan, maksimal 2 desimal. Contoh: 15000 atau 15000.50 */
function valid_money(string $s): bool
{
    return (bool)preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s);
}

function rupiah(mixed $v): string
{
    return 'Rp ' . number_format((float)$v, 0, ',', '.');
}

function audit(?int $userId, string $action, string $detail = ''): void
{
    $st = db()->prepare('INSERT INTO audit_logs (user_id, action, detail, ip) VALUES (?, ?, ?, ?)');
    $st->execute([$userId, $action, mb_substr($detail, 0, 500), client_ip()]);
}

function stock_badge(int $stock, int $min): string
{
    if ($stock === 0) {
        return '<span class="tag out">Habis</span>';
    }
    if ($stock <= $min) {
        return '<span class="tag low">Menipis</span>';
    }
    return '<span class="tag ok">Aman</span>';
}

function pager(int $page, int $pages, array $query): string
{
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pager" aria-label="Halaman">';
    for ($i = 1; $i <= $pages; $i++) {
        $href = '?' . http_build_query(array_merge($query, ['page' => $i]));
        $cur  = $i === $page ? ' aria-current="page"' : '';
        $out .= '<a href="' . e($href) . '"' . $cur . '>' . $i . '</a>';
    }
    return $out . '</nav>';
}
