<?php
declare(strict_types=1);

function page_header(string $title, string $active = ''): void
{
    $u   = current_user();
    $nav = [
        'index'    => ['index.php', 'Ringkasan'],
        'products' => ['products.php', 'Barang'],
        'stock'    => ['stock.php', 'Stok masuk dan keluar'],
    ];

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - Kasirku</title>';
    echo '<link rel="stylesheet" href="assets/style.css"></head>';
    echo '<body class="' . ($u ? 'app' : 'auth') . '">';

    if ($u) {
        echo '<aside class="side"><div class="brand">Kasirku</div><nav aria-label="Menu utama">';
        foreach ($nav as $key => [$href, $label]) {
            $cur = $key === $active ? ' aria-current="page"' : '';
            echo '<a href="' . e($href) . '"' . $cur . '>' . e($label) . '</a>';
        }
        echo '</nav><div class="who"><span>' . e($u['full_name']) . '</span>';
        echo '<small>' . ($u['role'] === 'admin' ? 'Admin' : 'Staf') . '</small>';
        echo '<form method="post" action="logout.php">' . csrf_field()
           . '<button class="btn ghost" type="submit">Keluar</button></form></div></aside>';
    }

    echo '<main class="main" id="main">';
    foreach (flashes() as $f) {
        echo '<div class="flash ' . e($f['t']) . '" role="status">' . e($f['m']) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main></body></html>';
}

function forbidden(): never
{
    http_response_code(403);
    page_header('Akses ditolak');
    echo '<h1>Akses ditolak</h1><p>Akun Anda tidak punya izin untuk membuka halaman ini.</p>';
    page_footer();
    exit;
}
