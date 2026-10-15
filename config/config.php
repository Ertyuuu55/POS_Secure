<?php
declare(strict_types=1);

$config = [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'name'    => getenv('DB_NAME') ?: 'pos_database',
        'user'    => getenv('DB_USER') ?: 'pos_app',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name'             => 'POSSESSID',
        'idle_timeout'     => 900,
        'absolute_timeout' => 28800,
    ],
    'login' => [
        'max_attempts'   => 5,
        'window_minutes' => 15,
    ],
    'register' => [
        'enabled'     => true,
        'invite_code' => getenv('REGISTER_CODE') ?: '',
        'max_per_ip'  => 10,
    ],
];

// Pengaturan rahasia per mesin (password DB, kode undangan), file lengkap ada di .gitignore.
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;