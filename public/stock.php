<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

$user    = require_login();
$isAdmin = $user['role'] === 'admin';
$pdo     = db();

$typeLabel = ['in' => 'Masuk', 'out' => 'Keluar', 'adjust' => 'Koreksi'];
$allowed   = $isAdmin ? ['in', 'out', 'adjust'] : ['in', 'out'];   // koreksi hanya admin
$errors    = [];
$selected  = to_int($_GET['product_id'] ?? null, 1, 2147483647);
$formType  = 'in';
$formQty   = '';
$formNote  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $selected = to_int($_POST['product_id'] ?? null, 1, 2147483647);
    $formType = clean_str($_POST['type'] ?? '');
    $formQty  = clean_str($_POST['quantity'] ?? '');
    $formNote = clean_str($_POST['note'] ?? '');

    if ($selected === null) {
        $errors[] = 'Pilih barang terlebih dahulu.';
    }
    if (!in_array($formType, $allowed, true)) {            // whitelist jenis + cek hak akses
        $errors[] = 'Jenis pencatatan tidak valid atau tidak diizinkan untuk akun Anda.';
    }
    $qty = to_int($formQty, $formType === 'adjust' ? 0 : 1, 1000000);
    if ($qty === null) {
        $errors[] = $formType === 'adjust'
            ? 'Hasil hitung fisik harus bilangan bulat 0 sampai 1.000.000.'
            : 'Jumlah harus bilangan bulat 1 sampai 1.000.000.';
    }
    if (mb_strlen($formNote) > 255) {
        $errors[] = 'Catatan maksimal 255 karakter.';
    }
    if ($formType === 'adjust' && mb_strlen($formNote) < 3) {
        $errors[] = 'Koreksi stok wajib disertai alasan (minimal 3 karakter).';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Kunci baris: dua kasir yang mengubah barang yang sama tidak bisa saling menimpa (race condition).
            $st = $pdo->prepare('SELECT stock FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
            $st->execute([$selected]);
            $row = $st->fetch();

            if (!$row) {
                $pdo->rollBack();
                $errors[] = 'Barang tidak ditemukan atau sudah dinonaktifkan.';
            } else {
                $old = (int)$row['stock'];
                $new = match ($formType) {
                    'in'     => $old + $qty,
                    'out'    => $old - $qty,
                    'adjust' => $qty,
                };

                if ($new < 0) {
                    $pdo->rollBack();
                    $errors[] = "Stok tidak cukup. Stok saat ini {$old}.";
                } elseif ($new > 2000000000) {
                    $pdo->rollBack();
                    $errors[] = 'Jumlah stok melebihi batas yang didukung.';
                } else {
                    $up = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
                    $up->execute([$new, $selected]);

                    $mv = $pdo->prepare(
                        'INSERT INTO stock_movements (product_id, user_id, type, quantity, stock_after, note)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $mv->execute([$selected, $user['id'], $formType, $new - $old, $new, $formNote]);

                    audit((int)$user['id'], 'stock.' . $formType, "product={$selected} {$old}->{$new}");
                    $pdo->commit();
                    flash('ok', "Tercatat. Stok sekarang {$new}.");
                    redirect('stock.php?product_id=' . $selected);
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }
}

$products = $pdo->query('SELECT id, sku, name, stock FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($selected !== null) {
    $st = $pdo->prepare(
        'SELECT m.type, m.quantity, m.stock_after, m.note, m.created_at, p.name AS product, u.full_name AS who
           FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN users u ON u.id = m.user_id
          WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 50'
    );
    $st->execute([$selected]);
} else {
    $st = $pdo->prepare(
        'SELECT m.type, m.quantity, m.stock_after, m.note, m.created_at, p.name AS product, u.full_name AS who
           FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN users u ON u.id = m.user_id
          ORDER BY m.id DESC LIMIT 50'
    );
    $st->execute();
}
$history = $st->fetchAll();

page_header('Stok', 'stock');
?>
<h1>Stok masuk dan keluar</h1>

<?php if ($errors): ?>
  <div class="flash err" role="alert">
    <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form class="card form" method="post" novalidate>
  <?= csrf_field() ?>
  <label>Barang
    <select name="product_id" required>
      <option value="">Pilih barang</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= e($p['id']) ?>"<?= (int)$p['id'] === $selected ? ' selected' : '' ?>>
          <?= e($p['sku'] . ' - ' . $p['name'] . ' (stok ' . $p['stock'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="row">
    <label>Jenis
      <select name="type">
        <?php foreach ($allowed as $t): ?>
          <option value="<?= e($t) ?>"<?= $t === $formType ? ' selected' : '' ?>><?= e($typeLabel[$t]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jumlah
      <input name="quantity" value="<?= e($formQty) ?>" inputmode="numeric" required>
      <?php if ($isAdmin): ?><small>Untuk koreksi, isi hasil hitung fisik.</small><?php endif; ?>
    </label>
  </div>
  <label>Catatan
    <input name="note" value="<?= e($formNote) ?>" maxlength="255">
  </label>
  <div class="row buttons"><button class="btn" type="submit">Catat stok</button></div>
</form>

<h2>Riwayat <?= $selected !== null ? 'barang ini' : 'terbaru' ?></h2>
<?php if (!$history): ?>
  <p class="empty">Belum ada riwayat stok.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Waktu</th><th>Barang</th><th>Jenis</th><th class="num">Jumlah</th><th class="num">Stok akhir</th><th>Catatan</th><th>Oleh</th></tr></thead>
    <tbody>
    <?php foreach ($history as $r): ?>
      <tr>
        <td><?= e($r['created_at']) ?></td>
        <td><?= e($r['product']) ?></td>
        <td><?= e($typeLabel[$r['type']] ?? $r['type']) ?></td>
        <td class="num"><?= $r['quantity'] > 0 ? '+' : '' ?><?= e($r['quantity']) ?></td>
        <td class="num"><?= e($r['stock_after']) ?></td>
        <td><?= e($r['note']) ?></td>
        <td><?= e($r['who']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif;
page_footer();
