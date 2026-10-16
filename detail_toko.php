<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$st=$pdo->prepare('SELECT r.*,u.nama AS nama_pemilik FROM restaurants r JOIN users u ON u.id=r.owner_id WHERE r.id=?');
$st->execute([$id]); $shop=$st->fetch();
if (!$shop) { http_response_code(404); exit('Toko tidak ditemukan.'); }
$st=$pdo->prepare('SELECT * FROM restaurant_menus WHERE restaurant_id=? AND tersedia=1 ORDER BY nama_menu');
$st->execute([$id]); $menus=$st->fetchAll();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($shop['nama']) ?> - Mencari Makan</title><link rel="stylesheet" href="style.css"></head><body><header><h1>🍽️ <?= e($shop['nama']) ?></h1><nav><a href="index.php">Kembali ke daftar toko</a><a href="chat.php?restaurant_id=<?= (int)$shop['id'] ?>">Chat penjual</a><a href="logout.php">Keluar</a></nav></header><main>
<section class="profile-cover"><?php if($shop['foto_profil']): ?><img class="avatar" src="<?= e($shop['foto_profil']) ?>" alt="Foto toko"><?php else: ?><div class="avatar" style="display:grid;place-items:center;font-size:44px">🏪</div><?php endif; ?><div><h2><?= e($shop['nama']) ?></h2><p>👤 <?= e($shop['nama_pemilik']) ?></p><p>📍 <?= e($shop['alamat']) ?></p><p><?= e($shop['deskripsi']) ?></p><a class="btn" href="chat.php?restaurant_id=<?= (int)$shop['id'] ?>">Tanya menu melalui chat</a></div></section>
<h2>Menu tersedia</h2><?php if(!$menus): ?><section class="panel">Belum ada menu yang tersedia.</section><?php else: ?><div class="grid"><?php foreach($menus as $m): ?><article class="card"><?php if($m['foto']): ?><img src="<?= e($m['foto']) ?>" alt="<?= e($m['nama_menu']) ?>"><?php else: ?><div style="height:150px;display:grid;place-items:center;background:#eee;font-size:42px">🍽️</div><?php endif; ?><div class="card-body"><h3><?= e($m['nama_menu']) ?></h3><p><?= e($m['deskripsi']) ?></p><p class="price">Rp<?= number_format((float)$m['harga'],0,',','.') ?></p><a class="btn" href="beli.php?menu_id=<?= (int)$m['id'] ?>">Beli sekarang</a></div></article><?php endforeach; ?></div><?php endif; ?></main><footer>© 2026 Mencari Makan</footer></body></html>
