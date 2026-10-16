<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_login();

// Hanya pembeli yang dapat membuat pesanan.
if (($_SESSION['peran'] ?? 'pencari') !== 'pencari') {
    header('Location: pilih_peran.php');
    exit;
}
$menuId = (int)($_GET['menu_id'] ?? $_POST['menu_id'] ?? 0);
$stmt = $pdo->prepare('SELECT m.*, r.id AS toko_id, r.nama AS nama_toko, r.alamat AS alamat_toko FROM restaurant_menus m JOIN restaurants r ON r.id=m.restaurant_id WHERE m.id=? AND m.tersedia=1');
$stmt->execute([$menuId]);
$menu = $stmt->fetch();
if (!$menu) { http_response_code(404); exit('Menu tidak ditemukan atau sedang tidak tersedia.'); }
$errors = [];
$nama = trim($_POST['nama_penerima'] ?? ($_SESSION['nama'] ?? ''));
$telepon = trim($_POST['telepon'] ?? '');
$alamat = trim($_POST['alamat'] ?? '');
$catatan = trim($_POST['catatan'] ?? '');
$jumlah = (int)($_POST['jumlah'] ?? 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($nama === '') $errors[] = 'Nama penerima wajib diisi.';
    if ($telepon === '') $errors[] = 'Nomor telepon wajib diisi.';
    if ($alamat === '') $errors[] = 'Alamat pengantaran wajib diisi.';
    if ($jumlah < 1 || $jumlah > 99) $errors[] = 'Jumlah makanan harus antara 1 sampai 99.';
    if (!$errors) {
        $harga = (float)$menu['harga'];
        $total = $harga * $jumlah;
        $insert = $pdo->prepare('INSERT INTO orders (buyer_id, restaurant_id, menu_id, nama_penerima, telepon, alamat, catatan, jumlah, harga_satuan, total_harga) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([(int)$_SESSION['user_id'], (int)$menu['toko_id'], $menuId, $nama, $telepon, $alamat, $catatan, $jumlah, $harga, $total]);
        header('Location: pesanan_saya.php?berhasil=1');
        exit;
    }
}
function rupiah($value) { return 'Rp' . number_format((float)$value, 0, ',', '.'); }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beli Makanan - Mencari Makan</title><link rel="stylesheet" href="style.css"><style>.order-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.8fr);gap:20px}.order-photo{width:100%;max-width:220px;height:150px;object-fit:cover;border-radius:12px}.field-error{background:#fff0f0;color:#a51d1d;padding:10px;border-radius:8px;margin-bottom:12px}@media(max-width:700px){.order-layout{grid-template-columns:1fr}}</style></head><body>
<header><h1>🍽️ Mencari Makan</h1><p>Lengkapi alamat sebelum membuat pesanan</p><nav><a href="detail_toko.php?id=<?= (int)$menu['toko_id'] ?>">Kembali ke toko</a><a href="index.php">Daftar toko</a></nav></header>
<main><h2>Konfirmasi pembelian</h2><div class="order-layout"><section class="panel"><h3>Alamat pengantaran</h3><?php foreach($errors as $error): ?><div class="field-error"><?= e($error) ?></div><?php endforeach; ?><form method="post" action="beli.php"><input type="hidden" name="menu_id" value="<?= (int)$menuId ?>"><label>Nama penerima *</label><input name="nama_penerima" required maxlength="120" value="<?= e($nama) ?>"><label>Nomor telepon *</label><input type="tel" name="telepon" required maxlength="30" value="<?= e($telepon) ?>" placeholder="Contoh: 08xxxxxxxxxx"><label>Alamat lengkap pengantaran *</label><textarea name="alamat" required placeholder="Nama jalan, nomor rumah, kelurahan, kecamatan, patokan..."><?= e($alamat) ?></textarea><label>Catatan untuk penjual (opsional)</label><textarea name="catatan" placeholder="Contoh: tidak pedas, pagar warna hitam..."><?= e($catatan) ?></textarea><label>Jumlah *</label><input type="number" name="jumlah" min="1" max="99" required value="<?= (int)$jumlah ?>"><button type="submit">Buat Pesanan</button></form></section><section class="panel"><h3>Ringkasan pesanan</h3><?php if(!empty($menu['foto'])): ?><img class="order-photo" src="<?= e($menu['foto']) ?>" alt="<?= e($menu['nama_menu']) ?>"><?php endif; ?><h3><?= e($menu['nama_menu']) ?></h3><p class="muted">Toko: <?= e($menu['nama_toko']) ?></p><p>Harga satuan: <strong><?= rupiah($menu['harga']) ?></strong></p><p>Jumlah: <strong><?= (int)$jumlah ?></strong></p><hr><p>Total makanan: <strong><?= rupiah((float)$menu['harga'] * max(1, (int)$jumlah)) ?></strong></p><p class="muted">Pastikan alamat dan nomor telepon benar sebelum membuat pesanan.</p></section></div></main><footer>© 2026 Mencari Makan</footer></body></html>
