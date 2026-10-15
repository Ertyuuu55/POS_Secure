<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

// Hanya POST + token CSRF: link <img src="logout.php"> dari situs lain tidak bisa mengeluarkan user.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
csrf_check();
if ($u = current_user()) {
    audit((int)$u['id'], 'logout', '');
}
destroy_session();
redirect('login.php');
