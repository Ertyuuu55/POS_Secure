<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

require_role('admin');
$user = current_user();
$pdo  = db();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$id     = to_int($isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null), 1, 2147483647);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$catIds     = array_map('intval', array_column($categories, 'id'));

$form = ['sku' => '', 'name' => '', 'category_id' => '', 'price' => '', 'cost' => '0', 'stock' => '0', 'min_stock' => '5'];

if ($id !== null) {
    $st = $pdo->prepare('SELECT sku, name, category_id, price, cost, stock, min_stock FROM products WHERE id = ?');
    $st->execute([$id]);
    $existing = $st->fetch();
    if (!$existing) {
        http_response_code(404);
        page_header('Tidak ditemukan');
        echo '<h1>Barang tidak ditemukan</h1><p><a href="products.php">Kembali ke daftar barang</a></p>';
        page_footer();
        exit;
    }
    $form = array_map('strval', $existing);
    $form['price'] = (string)(int)$existing['price'] === $existing['price'] ? (string)(int)$existing['price'] : $existing['price'];
    $form['cost']  = (string)(int)$existing['cost']  === $existing['cost']  ? (string)(int)$existing['cost']  : $existing['cost'];
}

$errors = [];
if ($isPost) {
    csrf_check();

    $form['sku']       = clean_str($_POST['sku'] ?? '');
    $form['name']      = clean_str($_POST['name'] ?? '');
    $form['price']     = clean_str($_POST['price'] ?? '');
    $form['cost']      = clean_str($_POST['cost'] ?? '');
    $form['min_stock'] = clean_str($_POST['min_stock'] ?? '');
    $catRaw            = clean_str($_POST['category_id'] ?? '');
    $form['category_id'] = $catRaw;
    if ($id === null) {
        $form['stock'] = clean_str($_POST['stock'] ?? '');
    }

    if (!preg_match('/^[A-Za-z0-9._-]{2,40}$/', $form['sku'])) {
        $errors[] = 'SKU 2-40 karakter: huruf, angka, titik, garis bawah, atau strip.';
    }
    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors[] = 'Nama barang wajib diisi (maksimal 150 karakter).';
    }
    if (!valid_money($form['price'])) {
        $errors[] = 'Harga jual harus berupa angka tanpa titik ribuan, contoh 15000 atau 15000.50.';
    }
    if (!valid_money($form['cost'])) {
        $errors[] = 'Harga modal harus berupa angka tanpa titik ribuan.';
    }
    $minStock = to_int($form['min_stock'], 0, 1000000);
    if ($minStock === null) {
        $errors[] = 'Stok minimum harus bilangan bulat 0 sampai 1.000.000.';
    }
    $initStock = 0;
    if ($id === null) {
        $initStock = to_int($form['stock'], 0, 1000000);
        if ($initStock === null) {
            $errors[] = 'Stok awal harus bilangan bulat 0 sampai 1.000.000.';
            $initStock = 0;
        }
    }
    $categoryId = null;
    if ($catRaw !== '') {
        $categoryId = to_int($catRaw, 1, 2147483647);
        if ($categoryId === null || !in_array($categoryId, $catIds, true)) {   // whitelist dari database
            $errors[] = 'Kategori tidak valid.';
            $categoryId = null;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($id === null) {
                $st = $pdo->prepare(
                    'INSERT INTO products (sku, name, category_id, price, cost, stock, min_stock) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$form['sku'], $form['name'], $categoryId, $form['price'], $form['cost'], $initStock, $minStock]);
                $newId = (int)$pdo->lastInsertId();
                if ($initStock > 0) {
                    $mv = $pdo->prepare(
                        "INSERT INTO stock_movements (product_id, user_id, type, quantity, stock_after, note)
                         VALUES (?, ?, 'in', ?, ?, 'Stok awal')"
                    );
                    $mv->execute([$newId, $user['id'], $initStock, $initStock]);
                }
                audit((int)$user['id'], 'product.create', 'id=' . $newId . ' sku=' . $form['sku']);
                flash('ok', 'Barang ditambahkan.');
            } else {
                // Stok TIDAK diubah di sini; perubahan stok hanya lewat halaman Stok agar tercatat.
                $st = $pdo->prepare(
                    'UPDATE products SET sku = ?, name = ?, category_id = ?, price = ?, cost = ?, min_stock = ? WHERE id = ?'
                );
                $st->execute([$form['sku'], $form['name'], $categoryId, $form['price'], $form['cost'], $minStock, $id]);
                audit((int)$user['id'], 'product.update', 'id=' . $id);
                flash('ok', 'Perubahan disimpan.');
            }
            $pdo->commit();
            redirect('products.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($ex->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'SKU sudah dipakai barang lain.';
            } else {
                throw $ex;   // ditangani handler global: dicatat di log, pengguna lihat pesan generik
            }
        }
    }
}

page_header($id === null ? 'Tambah barang' : 'Ubah barang', 'products');
?>
<h1><?= $id === null ? 'Tambah barang' : 'Ubah barang' ?></h1>

<?php if ($errors): ?>
  <div class="flash err" role="alert">
    <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form class="card form" method="post" novalidate>
  <?= csrf_field() ?>
  <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>

  <label>SKU
    <input name="sku" value="<?= e($form['sku']) ?>" maxlength="40" required>
  </label>
  <label>Nama barang
    <input name="name" value="<?= e($form['name']) ?>" maxlength="150" required>
  </label>
  <label>Kategori
    <select name="category_id">
      <option value="">Tanpa kategori</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e($c['id']) ?>"<?= (string)$c['id'] === (string)$form['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="row">
    <label>Harga jual (Rp)
      <input name="price" value="<?= e($form['price']) ?>" inputmode="decimal" required>
    </label>
    <label>Harga modal (Rp)
      <input name="cost" value="<?= e($form['cost']) ?>" inputmode="decimal" required>
    </label>
  </div>
  <div class="row">
    <?php if ($id === null): ?>
      <label>Stok awal
        <input name="stock" value="<?= e($form['stock']) ?>" inputmode="numeric" required>
      </label>
    <?php else: ?>
      <label>Stok saat ini
        <input value="<?= e($form['stock']) ?>" disabled>
        <small>Ubah lewat halaman stok agar tercatat di riwayat.</small>
      </label>
    <?php endif; ?>
    <label>Stok minimum
      <input name="min_stock" value="<?= e($form['min_stock']) ?>" inputmode="numeric" required>
    </label>
  </div>
  <div class="row buttons">
    <button class="btn" type="submit">Simpan barang</button>
    <a class="btn ghost" href="products.php">Batal</a>
  </div>
</form>
<?php page_footer();
