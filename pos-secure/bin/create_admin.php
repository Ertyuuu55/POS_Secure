<?php
declare(strict_types=1);

// Hanya bisa dijalankan dari command line -> tidak ada halaman "register" yang bisa disalahgunakan.
// Pemakaian: php bin/create_admin.php <username> "<Nama Lengkap>" [admin|staff]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

$username = $argv[1] ?? '';
$fullName = $argv[2] ?? '';
$role     = $argv[3] ?? 'admin';

if (!preg_match('/^[a-z0-9_.]{3,50}$/', $username) || $fullName === '' || mb_strlen($fullName) > 100
    || !in_array($role, ['admin', 'staff'], true)) {
    fwrite(STDERR, "Pemakaian: php bin/create_admin.php <username a-z0-9_.> \"<Nama Lengkap>\" [admin|staff]\n");
    exit(1);
}

function read_password(string $prompt): string
{
    echo $prompt;

    if (PHP_OS_FAMILY === 'Windows') {
        // Windows tidak punya stty: pakai PowerShell supaya ketikan tersembunyi.
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
             . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
             . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $pw = shell_exec($cmd);
        if (is_string($pw) && trim($pw) !== '') {
            return rtrim($pw, "\r\n");
        }
        echo "(PowerShell gagal dipakai, ketikan akan terlihat) ";
        return rtrim((string)fgets(STDIN), "\r\n");
    }

    @system('stty -echo');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    @system('stty echo');
    echo "\n";
    return $pw;
}

$pw = read_password('Password (minimal 8 karakter): ');

if ($err = password_policy_error($pw)) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}

try {
    $st = db()->prepare('INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)');
    $st->execute([$username, $fullName, password_hash_secure($pw), $role]);
    echo "Akun '{$username}' ({$role}) berhasil dibuat.\n";
} catch (PDOException $e) {
    fwrite(STDERR, ($e->errorInfo[1] ?? 0) === 1062 ? "Username sudah ada.\n" : "Gagal membuat akun.\n");
    exit(1);
}
