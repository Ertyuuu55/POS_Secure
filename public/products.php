<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

$user    = require_login();
$isAdmin = $user['role'] === 'admin';
$pdo     = db();

/* ---- Aksi nonaktifkan / aktifkan (admin, POST + CSRF). Data tidak dihapus agar riwayat stok utuh. ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    csrf_check();
    $id     = to_int($_POST['id'] ?? null, 1, 2147483647);
    $action = $_POST['action'] ?? '';
    if ($id !== null && in_array($action, ['activate', 'deactivate'], true)) {
        $st = $pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?');
        $st->execute([$action === 'activate' ? 1 : 0, $id]);
        audit((int)$user['id'], 'product.' . $action, 'id=' . $id);
        flash('ok', $action === 'activate' ? 'Barang diaktifkan kembali.' : 'Barang dinonaktifkan.');
    } else {
        flash('err', 'Permintaan tidak valid.');
    }
    redirect('products.php');
}

/* ---- Filter & pencarian (semua lewat prepared statement) ---- */
$q      = mb_substr(clean_str($_GET['q'] ?? ''), 0, 60);
$status = (($_GET['status'] ?? '') === 'inactive') ? 'inactive' : 'active';
$page   = to_int($_GET['page'] ?? 1, 1, 100000) ?? 1;
$per    = 15;

$where  = ['p.is_active = ?'];
$params = [$status === 'active' ? 1 : 0];
if ($q !== '') {
    $like     = '%' . addcslashes($q, '\\%_') . '%';   // wildcard dari user di-escape
    $where[]  = '(p.sku LIKE ? OR p.name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

function bind_all(PDOStatement $st, array $params): void
{
    foreach (array_values($params) as $i => $v) {
        $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

$st = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
bind_all($st, $params);
$st->execute();
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = $pdo->prepare(
    "SELECT p.id, p.sku, p.name, p.price, p.cost, p.stock, p.min_stock, c.name AS category
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE $whereSql ORDER BY p.name ASC LIMIT ? OFFSET ?"
);
bind_all($st, array_merge($params, [$per, ($page - 1) * $per]));
$st->execute();
$rows = $st->fetchAll();

page_header('Barang', 'products');
?>
<div class="head">
  <h1>Daftar barang</h1>
  <?php if ($isAdmin): ?><a class="btn" href="product_form.php">Tambah barang</a><?php endif; ?>
</div>

<form class="filters" method="get">
  <label>Cari SKU atau nama
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60">
  </label>
  <label>Tampilkan
    <select name="status">
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Barang aktif</option>
      <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Barang nonaktif</option>
    </select>
  </label>
  <button class="btn ghost" type="submit">Terapkan</button>
</form>

<?php if (!$rows): ?>
  <p class="empty">Tidak ada barang yang cocok.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr>
      <th>SKU</th><th>Nama</th><th>Kategori</th><th class="num">Harga jual</th>
      <?php if ($isAdmin): ?><th class="num">Harga modal</th><?php endif; ?>
      <th class="num">Stok</th><th>Status</th><th>Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['sku']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['category'] ?? '-') ?></td>
        <td class="num"><?= e(rupiah($r['price'])) ?></td>
        <?php if ($isAdmin): ?><td class="num"><?= e(rupiah($r['cost'])) ?></td><?php endif; ?>
        <td class="num"><?= e($r['stock']) ?></td>
        <td><?= stock_badge((int)$r['stock'], (int)$r['min_stock']) ?></td>
        <td class="actions">
          <a href="stock.php?product_id=<?= e($r['id']) ?>">Stok</a>
          <?php if ($isAdmin): ?>
            <a href="product_form.php?id=<?= e($r['id']) ?>">Ubah</a>
            <details class="confirm">
              <summary><?= $status === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></summary>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <input type="hidden" name="action" value="<?= $status === 'active' ? 'deactivate' : 'activate' ?>">
                <button class="btn danger" type="submit">Ya, <?= $status === 'active' ? 'nonaktifkan' : 'aktifkan' ?></button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($page, $pages, ['q' => $q, 'status' => $status]) ?>
<?php endif;
page_footer();
