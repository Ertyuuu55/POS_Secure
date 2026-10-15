<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = clean_str($_POST['username'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $window   = cfg()['login']['window_minutes'];

    if ($username === '' || $password === '' || mb_strlen($username) > 50 || strlen($password) > 128) {
        $error = 'Username atau password salah.';
    } elseif (login_locked($username)) {
        $error = "Terlalu banyak percobaan gagal. Coba lagi dalam {$window} menit.";
    } elseif (attempt_login($username, $password)) {
        redirect('index.php');
    } else {
        $error = 'Username atau password salah.';   // pesan sama untuk user tidak ada / password salah
    }
}

page_header('Masuk');
?>
<section class="login">
  <h1>Masuk ke Kasirku</h1>
  <?php if (($_GET['expired'] ?? '') === '1' && !$error): ?>
    <div class="flash err" role="alert">Sesi berakhir. Silakan masuk lagi.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>Username
      <input type="text" name="username" maxlength="50" required autofocus autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" maxlength="128" required autocomplete="current-password">
    </label>
    <button class="btn" type="submit">Masuk</button>
  </form>
  <?php if (cfg()['register']['enabled'] && cfg()['register']['invite_code'] !== ''): ?>
    <p class="alt">Belum punya akun? <a href="register.php">Daftar</a></p>
  <?php endif; ?>
</section>
<?php page_footer();
