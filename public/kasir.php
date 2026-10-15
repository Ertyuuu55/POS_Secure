<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/layout.php';

$user = require_login();
$pdo  = db();

/* ============================================================
 * Keranjang disimpan di SESI (server-side).
 * Harga dan stok TIDAK dipakai dari data yang dikirim browser:
 * setiap baris dibaca ulang dari database saat ditampilkan.
 * ========================================================== */

/** Isi keranjang saat ini: [product_id => jumlah]. */
function cart(): array
{
    $c = $_SESSION['cart'] ?? [];
    return is_array($c) ? $c : [];
}

function cart_put(array $c): void
{
    $_SESSION['cart'] = $c;
}

/** Ambil satu barang untuk keperluan validasi. */
function find_product(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, sku, name, price, stock, is_active FROM products WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Tambah satu barang ke keranjang. Menolak barang nonaktif / stok kosong dengan
 * pesan yang menyebut alasannya, dan tidak pernah melebihi stok tersedia.
 */
function cart_add(PDO $pdo, int $id): void
{
    $p = find_product($pdo, $id);

    if (!$p) {
        flash('err', 'Barang tidak ditemukan.');
        return;
    }

    $label = $p['sku'] . ' - ' . $p['name'];

    if ((int)$p['is_active'] !== 1) {
        flash('err', "{$label} sudah dinonaktifkan, jadi tidak bisa dijual.");
        return;
    }

    $stock = (int)$p['stock'];
    if ($stock < 1) {
        flash('err', "{$label} stoknya kosong, jadi tidak bisa ditambahkan.");
        return;
    }

    $c     = cart();
    $now   = (int)($c[$id] ?? 0) + 1;
    $batas = false;

    if ($now > $stock) {
        $now   = $stock;
        $batas = true;
    }

    $c[$id] = $now;
    cart_put($c);

    if ($batas) {
        flash('err', "{$label} dibatasi {$stock}, itu stok yang tersedia.");
    } else {
        flash('ok', "{$label} ditambahkan. Jumlah sekarang {$now}.");
    }
}

/** Jumlah item, unit, dan uang di keranjang — selalu dihitung dari data terbaru. */
function cart_detail(PDO $pdo): array
{
    $c = cart();
    if (!$c) {
        return ['lines' => [], 'total' => 0.0, 'units' => 0, 'warn' => false];
    }

    $ids = array_map('intval', array_keys($c));
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare("SELECT id, sku, name, price, stock, is_active FROM products WHERE id IN ($in)");
    $st->execute($ids);

    $lines = [];
    $total = 0.0;
    $units = 0;

    foreach ($st->fetchAll() as $r) {
        $id      = (int)$r['id'];
        $jumlah  = (int)($c[$id] ?? 0);
        $subtot  = (float)$r['price'] * $jumlah;
        $problem = '';

        if ((int)$r['is_active'] !== 1) {
            $problem = 'barang dinonaktifkan';
        } elseif ((int)$r['stock'] < $jumlah) {
            $problem = 'stok tersisa ' . (int)$r['stock'];
        }

        $total += $subtot;
        $units += $jumlah;

        $lines[] = [
            'id'       => $id,
            'sku'      => $r['sku'],
            'name'     => $r['name'],
            'price'    => (float)$r['price'],
            'stock'    => (int)$r['stock'],
            'qty'      => $jumlah,
            'subtotal' => $subtot,
            'problem'  => $problem,
        ];
    }

    // id di sesi yang tidak lagi ada di tabel products (mis. data dihapus manual)
    $warn = count($lines) !== count($ids);
    foreach ($lines as $l) {
        if ($l['problem'] !== '') {
            $warn = true;
        }
    }

    return ['lines' => $lines, 'total' => $total, 'units' => $units, 'warn' => $warn];
}

/* ============================================================
 * Aksi — hanya lewat POST + token CSRF.
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = clean_str($_POST['action'] ?? '');

    switch ($action) {
        /* Kasir mengetik SKU/nama atau alat scan barcode mengirim kode + Enter. */
        case 'add':
            $q = mb_substr(clean_str($_POST['q'] ?? ''), 0, 60);

            if ($q === '') {
                flash('err', 'Ketik SKU atau nama barang dulu, atau scan barcode-nya.');
                redirect('kasir.php');
            }

            // 1) Cocok PERSIS dengan SKU -> ini jalur alat scan barcode.
            $st = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
            $st->execute([$q]);
            $id = $st->fetchColumn();

            // 2) Kalau bukan SKU persis, cari sebagian SKU atau nama.
            if ($id === false) {
                $like = '%' . addcslashes($q, '\\%_') . '%';   // wildcard dari user di-escape
                $st   = $pdo->prepare('SELECT id FROM products WHERE sku LIKE ? OR name LIKE ? ORDER BY name LIMIT 2');
                $st->execute([$like, $like]);
                $rows = $st->fetchAll();

                if (count($rows) === 1) {
                    $id = $rows[0]['id'];                       // satu-satunya kandidat: langsung masuk keranjang
                } elseif (count($rows) > 1) {
                    redirect('kasir.php?q=' . rawurlencode($q)); // banyak hasil: kasir yang memilih
                }
            }

            if ($id === false) {
                flash('err', 'Barang "' . $q . '" tidak ditemukan.');
                redirect('kasir.php');
            }

            cart_add($pdo, (int)$id);
            redirect('kasir.php');

        /* Ubah jumlah satu baris. */
        case 'set':
            $id    = to_int($_POST['product_id'] ?? null, 1, 2147483647);
            $input = clean_str($_POST['quantity'] ?? '');
            $qty   = to_int($input, 1, 1000000);

            if ($id === null) {
                flash('err', 'Baris keranjang tidak valid.');
                redirect('kasir.php');
            }
            if ($qty === null) {
                flash('err', 'Untuk membuang barang dari keranjang, pakai tombol Hapus.');
                redirect('kasir.php');
            }

            $c = cart();
            if (!isset($c[$id])) {
                flash('err', 'Barang itu tidak ada di keranjang.');
                redirect('kasir.php');
            }

            $p = find_product($pdo, $id);
            if (!$p) {
                unset($c[$id]);
                cart_put($c);
                flash('err', 'Barang itu sudah tidak ada di daftar barang, barisnya dibuang dari keranjang.');
                redirect('kasir.php');
            }

            $stock = (int)$p['stock'];
            if ((int)$p['is_active'] !== 1) {
                flash('err', $p['sku'] . ' - ' . $p['name'] . ' sudah dinonaktifkan, barisnya dibuang dari keranjang.');
                unset($c[$id]);
                cart_put($c);
                redirect('kasir.php');
            }
            if ($qty > $stock) {
                flash('err', 'Stok ' . $p['sku'] . ' - ' . $p['name'] . " hanya {$stock}.");
                redirect('kasir.php');
            }

            $c[$id] = $qty;
            cart_put($c);
            flash('ok', 'Jumlah ' . $p['sku'] . ' diubah menjadi ' . $qty . '.');
            redirect('kasir.php');

        /* Buang satu baris. */
        case 'remove':
            $id = to_int($_POST['product_id'] ?? null, 1, 2147483647);
            $c  = cart();
            if ($id !== null && isset($c[$id])) {
                unset($c[$id]);
                cart_put($c);
                flash('ok', 'Barang dibuang dari keranjang.');
            } else {
                flash('err', 'Barang itu tidak ada di keranjang.');
            }
            redirect('kasir.php');

        /* Kosongkan seluruh keranjang. */
        case 'clear':
            cart_put([]);
            flash('ok', 'Keranjang dikosongkan.');
            redirect('kasir.php');

        default:
            flash('err', 'Permintaan tidak valid.');
            redirect('kasir.php');
    }
}

/* ============================================================
 * Tampilan
 * ========================================================== */
$q = mb_substr(clean_str($_GET['q'] ?? ''), 0, 60);

/* Daftar pilihan saat pencarian menghasilkan lebih dari satu barang. */
$pick = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $st   = $pdo->prepare(
        'SELECT id, sku, name, price, stock, is_active FROM products
          WHERE sku LIKE ? OR name LIKE ?
          ORDER BY is_active DESC, stock DESC, name ASC LIMIT 20'
    );
    $st->execute([$like, $like]);
    $pick = $st->fetchAll();
}

$cart = cart_detail($pdo);

page_header('Kasir', 'kasir');
?>
<h1>Kasir</h1>

<div class="kasir">
  <section class="scan card">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label>Scan barcode atau ketik SKU / nama barang
        <input type="search" name="q" class="scan-input" maxlength="60" required autofocus
               autocomplete="off" placeholder="Contoh: KOPI-001">
      </label>
      <small>Alat scan barcode bekerja seperti keyboard: pastikan kursor ada di kolom ini, lalu scan.
        Tekan Enter bila mengetik manual.</small>
      <div class="row buttons"><button class="btn" type="submit">Tambahkan</button></div>
    </form>

    <?php if ($q !== ''): ?>
      <h2>Hasil pencarian &ldquo;<?= e($q) ?>&rdquo;</h2>
      <?php if (!$pick): ?>
        <p class="empty">Tidak ada barang yang cocok.</p>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>SKU</th><th>Barang</th><th class="num">Harga</th><th class="num">Stok</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($pick as $p): ?>
            <?php
              $aktif  = (int)$p['is_active'] === 1;
              $kosong = (int)$p['stock'] < 1;
            ?>
            <tr>
              <td><?= e($p['sku']) ?></td>
              <td><?= e($p['name']) ?></td>
              <td class="num"><?= e(rupiah($p['price'])) ?></td>
              <td class="num"><?= e($p['stock']) ?></td>
              <td>
                <?php if (!$aktif): ?>
                  <span class="tag out">Nonaktif</span>
                <?php elseif ($kosong): ?>
                  <span class="tag out">Stok kosong</span>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="q" value="<?= e($p['sku']) ?>">
                    <button class="btn ghost" type="submit">Tambahkan</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="cart">
    <h2>Keranjang</h2>

    <?php if ($cart['warn']): ?>
      <div class="flash err" role="alert">Ada baris di keranjang yang stoknya sudah tidak cukup atau barangnya
        dinonaktifkan. Periksa kolom Keterangan, lalu ubah jumlahnya atau hapus.</div>
    <?php endif; ?>

    <?php if (!$cart['lines']): ?>
      <p class="empty">Keranjang masih kosong. Scan barcode atau cari barang di sebelah kiri.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr>
          <th>SKU</th><th>Barang</th><th class="num">Harga</th><th class="num">Jumlah</th>
          <th class="num">Subtotal</th><th>Keterangan</th><th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cart['lines'] as $l): ?>
          <tr>
            <td><?= e($l['sku']) ?></td>
            <td><?= e($l['name']) ?></td>
            <td class="num"><?= e(rupiah($l['price'])) ?></td>
            <td class="num">
              <form method="post" class="qty-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set">
                <input type="hidden" name="product_id" value="<?= e($l['id']) ?>">
                <input class="qty" type="number" name="quantity" value="<?= e($l['qty']) ?>"
                       min="1" max="<?= e(max(1, $l['stock'])) ?>" step="1" inputmode="numeric"
                       aria-label="Jumlah <?= e($l['name']) ?>">
                <button class="btn ghost" type="submit">Ubah</button>
              </form>
            </td>
            <td class="num"><?= e(rupiah($l['subtotal'])) ?></td>
            <td>
              <?php if ($l['problem'] !== ''): ?>
                <span class="tag low"><?= e($l['problem']) ?></span>
              <?php else: ?>
                <span class="tag ok">Stok cukup</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="product_id" value="<?= e($l['id']) ?>">
                <button class="btn danger" type="submit">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <div class="cart-total">
        <span>
          <?= e(count($cart['lines'])) ?> jenis barang &middot; <?= e($cart['units']) ?> unit
        </span>
        <span class="n" aria-live="polite"><?= e(rupiah($cart['total'])) ?></span>
      </div>

      <form method="post" class="cart-clear">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear">
        <button class="btn ghost" type="submit">Kosongkan keranjang</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php page_footer();
