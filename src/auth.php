<?php
declare(strict_types=1);

function current_user(): ?array
{
  return $_SESSION['user'] ?? null;
}

function password_hash_secure(string $password): string
{
  // Argon2id jika tersedia, jika tidak bcrypt. Salt dibuat otomatis oleh PHP.
  return password_hash(
    $password,
    defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
  );
}

/** Kebijakan password untuk akun baru. Mengembalikan pesan error atau null jika lolos. */
function password_policy_error(string $pw): ?string
{
  if (mb_strlen($pw) < 8) {
    return 'Password minimal 8 karakter.';
  }
  if (mb_strlen($pw) > 128) {
    return 'Password maksimal 128 karakter.';
  }
  if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
    return 'Password harus mengandung huruf dan angka.';
  }
  return null;
}

/** Pembatasan percobaan login (anti brute-force & credential stuffing). */
function login_locked(string $username): bool
{
  $c = cfg()['login'];
  $ip = client_ip();
  $st = db()->prepare(
    'SELECT SUM(username = ? AND ip = ?) AS by_pair,
                SUM(ip = ?)                  AS by_ip,
                SUM(username = ?)            AS by_user
           FROM login_attempts
          WHERE success = 0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)',
  );
  $st->execute([$username, $ip, $ip, $username, $c['window_minutes']]);
  $r = $st->fetch() ?: [];

  return (int) ($r['by_pair'] ?? 0) >= $c['max_attempts'] ||
    (int) ($r['by_ip'] ?? 0) >= $c['max_attempts'] * 4 ||
    (int) ($r['by_user'] ?? 0) >= $c['max_attempts'] * 6;
}

function attempt_login(string $username, string $password): bool
{
  $pdo = db();
  $st = $pdo->prepare(
    'SELECT id, username, full_name, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1',
  );
  $st->execute([$username]);
  $u = $st->fetch();

  // password_verify SELALU dijalankan (hash palsu bila user tidak ada) agar waktu respons
  // tidak membocorkan apakah username terdaftar (user enumeration via timing).
  static $dummy = null;
  $dummy ??= password_hash('dummy-password-timing', PASSWORD_DEFAULT);
  $hash = $u ? $u['password_hash'] : $dummy;
  $ok = password_verify($password, $hash) && $u && (int) $u['is_active'] === 1;

  $log = $pdo->prepare(
    'INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)',
  );
  $log->execute([$username, client_ip(), $ok ? 1 : 0]);

  if (!$ok) {
    return false;
  }

  if (
    password_needs_rehash(
      $u['password_hash'],
      defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
    )
  ) {
    $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $up->execute([password_hash_secure($password), $u['id']]);
  }

  // Anti session fixation: ganti session ID & buang data sesi lama (termasuk token CSRF lama).
  session_regenerate_id(true);
  $_SESSION = [
    'user' => [
      'id' => (int) $u['id'],
      'username' => $u['username'],
      'full_name' => $u['full_name'],
      'role' => $u['role'],
    ],
    'login_at' => time(),
    'last_seen' => time(),
  ];
  try {
    $pdo->exec(
      'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)',
    );
  } catch (PDOException $e) {
    error_log('[POS] housekeeping login_attempts gagal: ' . $e->getMessage());
  }
  audit((int) $u['id'], 'login', '');
  return true;
}

function destroy_session(): void
{
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
      'expires' => time() - 42000,
      'path' => $p['path'],
      'domain' => $p['domain'],
      'secure' => $p['secure'],
      'httponly' => $p['httponly'],
      'samesite' => $p['samesite'] ?? 'Strict',
    ]);
  }
  session_destroy();
}

/** Wajib login. Memeriksa timeout dan memvalidasi ulang user ke database di setiap request. */
function require_login(): array
{
  $s = $_SESSION['user'] ?? null;
  if (!$s) {
    redirect('login.php');
  }

  $cfg = cfg()['session'];
  $now = time();
  if (
    $now - (int) ($_SESSION['last_seen'] ?? 0) > $cfg['idle_timeout'] ||
    $now - (int) ($_SESSION['login_at'] ?? 0) > $cfg['absolute_timeout']
  ) {
    destroy_session();
    redirect('login.php?expired=1');
  }

  // Akun yang dinonaktifkan / diubah role-nya langsung berlaku tanpa menunggu sesi habis.
  $st = db()->prepare(
    'SELECT id, username, full_name, role FROM users WHERE id = ? AND is_active = 1',
  );
  $st->execute([$s['id']]);
  $fresh = $st->fetch();
  if (!$fresh) {
    destroy_session();
    redirect('login.php');
  }

  $_SESSION['user'] = ['id' => (int) $fresh['id']] + $fresh;
  $_SESSION['last_seen'] = $now;
  return $_SESSION['user'];
}

/** Kontrol akses berbasis peran (RBAC), dicek di SERVER pada setiap halaman/aksi sensitif. */
function require_role(string ...$roles): void
{
  $u = require_login();
  if (!in_array($u['role'], $roles, true)) {
    audit((int) $u['id'], 'forbidden', $_SERVER['SCRIPT_NAME'] ?? '');
    forbidden();
  }
}
