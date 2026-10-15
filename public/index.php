<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

$user    = require_login();
$isAdmin = $user['role'] === 'admin';
$pdo     = db();

$sum = $pdo->query(
    'SELECT COUNT(*) AS items, COALESCE(SUM(stock),0) AS units, COALESCE(SUM(stock*cost),0) AS value
       FROM products WHERE is_active = 1'
)->fetch();

$low = $pdo->query(
    'SELECT id, sku, name, stock, min_stock FROM products
      WHERE is_active = 1 AND stock <= min_stock ORDER BY stock ASC, name ASC LIMIT 10'
)->fetchAll();

$recent = $pdo->query(
    'SELECT m.type, m.quantity, m.stock_after, m.created_at, p.name AS product, u.full_name AS who
       FROM stock_movements m
       JOIN products p ON p.id = m.product_id
       JOIN users u ON u.id = m.user_id
      ORDER BY m.id DESC LIMIT 8'
)->fetchAll();

$typeLabel = ['in' => 'Masuk', 'out' => 'Keluar', 'adjust' => 'Koreksi'];

page_header('Ringkasan', 'index');
?>
<h1>Ringkasan stok</h1>
<div class="stats">
  <div class="stat"><span class="n"><?= e(number_format((int)$sum['items'], 0, ',', '.')) ?></span><span>jenis barang aktif</span></div>
  <div class="stat"><span class="n"><?= e(number_format((int)$sum['units'], 0, ',', '.')) ?></span><span>total unit di rak dan gudang</span></div>
  <?php if ($isAdmin): ?>
    <div class="stat"><span class="n"><?= e(rupiah($sum['value'])) ?></span><span>nilai persediaan (harga modal)</span></div>
  <?php endif; ?>
</div>

<h2>Perlu diisi ulang</h2>
<?php if (!$low): ?>
  <p class="empty">Semua barang masih di atas batas minimum.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>SKU</th><th>Barang</th><th class="num">Stok</th><th class="num">Minimum</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($low as $r): ?>
      <tr>
        <td><?= e($r['sku']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td class="num"><?= e($r['stock']) ?></td>
        <td class="num"><?= e($r['min_stock']) ?></td>
        <td><?= stock_badge((int)$r['stock'], (int)$r['min_stock']) ?></td>
        <td><a href="stock.php?product_id=<?= e($r['id']) ?>">Catat stok masuk</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

<h2>Pergerakan stok terakhir</h2>
<?php if (!$recent): ?>
  <p class="empty">Belum ada pergerakan stok.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Waktu</th><th>Barang</th><th>Jenis</th><th class="num">Jumlah</th><th class="num">Stok akhir</th><th>Oleh</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e($r['created_at']) ?></td>
        <td><?= e($r['product']) ?></td>
        <td><?= e($typeLabel[$r['type']] ?? $r['type']) ?></td>
        <td class="num"><?= $r['quantity'] > 0 ? '+' : '' ?><?= e($r['quantity']) ?></td>
        <td class="num"><?= e($r['stock_after']) ?></td>
        <td><?= e($r['who']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif;
page_footer();
