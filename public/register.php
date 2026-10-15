<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

if (current_user()) {
    redirect('index.php');
}

$reg     = cfg()['register'];
$enabled = $reg['enabled'] && $reg['invite_code'] !== '';

if (!$enabled) {
    http_response_code(403);
    page_header('Pendaftaran ditutup');
    echo '<section class="login"><h1>Pendaftaran ditutup</h1>'
       . '<p>Hubungi admin untuk dibuatkan akun.</p>'
       . '<p class="alt"><a href="login.php">Kembali ke halaman masuk</a></p></section>';
    page_footer();
    exit;
}

/** Batasi percobaan pendaftaran per IP (anti spam akun dan tebak kode undangan). */
function register_rate_limited(int $max): bool
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM audit_logs
          WHERE action = 'register_attempt' AND ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= $max;
}

$errors   = [];
$username = '';
$fullName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = mb_strtolower(clean_str($_POST['username'] ?? ''));
    $fullName = clean_str($_POST['full_name'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
    $code     = clean_str($_POST['invite_code'] ?? '');

    if (register_rate_limited((int)$reg['max_per_ip'])) {
        $errors[] = 'Terlalu banyak percobaan dari alamat ini. Coba lagi dalam 1 jam.';
    } else {
        // Setiap percobaan dicatat SEBELUM validasi, termasuk yang gagal.
        audit(null, 'register_attempt', '');

        if (!hash_equals($reg['invite_code'], $code)) {
            $errors[] = 'Kode undangan salah.';
        }
        if (!preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
            $errors[] = 'Username 3-50 karakter: huruf kecil, angka, titik, atau garis bawah.';
        }
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            $errors[] = 'Nama lengkap wajib diisi (maksimal 100 karakter).';
        }
        if ($msg = password_policy_error($password)) {
            $errors[] = $msg;
        } elseif ($username !== '' && stripos($password, $username) !== false) {
            $errors[] = 'Password tidak boleh mengandung username.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'Konfirmasi password tidak sama.';
        }

        if (!$errors) {
            try {
                // Role SELALU 'staff', ditulis di query, bukan dari input pengguna.
                $st = db()->prepare(
                    "INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'staff')"
                );
                $st->execute([$username, $fullName, password_hash_secure($password)]);
                audit((int)db()->lastInsertId(), 'register', 'username=' . $username);

                flash('ok', 'Akun berhasil dibuat. Silakan masuk.');
                redirect('login.php');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'Username sudah dipakai.';
                } else {
                    throw $ex;   // dicatat di log server, pengguna lihat pesan generik
                }
            }
        }
    }
}

page_header('Daftar');
?>
<section class="login">
  <h1>Buat akun staf</h1>

  <?php if ($errors): ?>
    <div class="flash err" role="alert">
      <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <label>Kode undangan
      <input type="password" name="invite_code" maxlength="100" required autocomplete="off">
      <small>Minta kode ini ke admin toko.</small>
    </label>
    <label>Nama lengkap
      <input type="text" name="full_name" value="<?= e($fullName) ?>" maxlength="100" required autocomplete="name">
    </label>
    <label>Username
      <input type="text" name="username" value="<?= e($username) ?>" maxlength="50" required autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" maxlength="128" required autocomplete="new-password">
      <small>Minimal 8 karakter, mengandung huruf dan angka.</small>
    </label>
    <label>Ulangi password
      <input type="password" name="password_confirm" maxlength="128" required autocomplete="new-password">
    </label>
    <button class="btn" type="submit">Daftar</button>
  </form>
  <p class="alt">Sudah punya akun? <a href="login.php">Masuk</a></p>
</section>
<?php page_footer();