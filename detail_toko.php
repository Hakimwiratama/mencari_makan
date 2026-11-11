<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('ID toko tidak valid.');
}

$stmt = $pdo->prepare(
    'SELECT r.*, u.nama AS nama_pemilik
     FROM restaurants r
     JOIN users u ON u.id = r.owner_id
     WHERE r.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    http_response_code(404);
    exit('Toko tidak ditemukan.');
}

$stmt = $pdo->prepare(
    'SELECT *
     FROM restaurant_menus
     WHERE restaurant_id = ? AND tersedia = 1
     ORDER BY nama_menu'
);
$stmt->execute([$id]);
$menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT AVG(rating) AS rating_rata_rata, COUNT(*) AS jumlah_rating
     FROM restaurant_reviews
     WHERE restaurant_id = ?'
);
$stmt->execute([$id]);
$ratingSummary = $stmt->fetch(PDO::FETCH_ASSOC);
$averageRating = (float) ($ratingSummary['rating_rata_rata'] ?? 0);
$ratingCount = (int) ($ratingSummary['jumlah_rating'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT rr.rating, rr.ulasan, rr.created_at, u.nama AS nama_pembeli,
            m.nama_menu
     FROM restaurant_reviews rr
     JOIN users u ON u.id = rr.buyer_id
     JOIN orders o ON o.id = rr.order_id
     LEFT JOIN restaurant_menus m ON m.id = o.menu_id
     WHERE rr.restaurant_id = ?
     ORDER BY rr.created_at DESC
     LIMIT 20'
);
$stmt->execute([$id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isOwner = (int) ($_SESSION['user_id'] ?? 0) === (int) $shop['owner_id'];
$canBuy = ($_SESSION['peran'] ?? '') === 'pencari';
$menuMessage = $isOwner ? ($_GET['menu'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($shop['nama']) ?> - Mencari Makan</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .rating-summary {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 12px 0;
        }

        .rating-stars {
            color: #d97706;
            letter-spacing: 2px;
            white-space: nowrap;
        }

        .review-list {
            display: grid;
            gap: 12px;
        }

        .review-card {
            background: #fff;
            border: 1px solid #e3e8e4;
            border-radius: 12px;
            padding: 16px;
        }

        .review-card h3,
        .review-card p {
            margin: 6px 0;
        }

        .success {
            background: #e8f5eb;
            color: #166534;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>
<header class="<?= $isOwner ? 'seller-dashboard-header' : 'buyer-dashboard-header' ?>">
    <h1>🍽️ <?= e($shop['nama']) ?></h1>
    <p><?= e($shop['alamat']) ?></p>
    <?php if ($isOwner): ?>
        <?php require __DIR__ . '/seller_navigation.php'; ?>
    <?php else: ?>
        <?php $buyerChatRestaurantId = (int) $shop['id']; ?>
        <?php require __DIR__ . '/buyer_navigation.php'; ?>
    <?php endif; ?>
</header>

<main>
    <?php if ($menuMessage === 'ditambahkan'): ?>
        <section class="success" role="status">
            <strong>✅ Menu berhasil ditambahkan!</strong>
            Menu sekarang tampil di halaman toko ini.
        </section>
    <?php elseif ($menuMessage === 'diubah'): ?>
        <section class="success" role="status">
            <strong>✅ Menu berhasil diperbarui.</strong>
            Perubahan sudah terlihat di halaman toko ini.
        </section>
    <?php endif; ?>

    <section class="profile-cover">
        <?php if (!empty($shop['foto_profil'])): ?>
            <img class="avatar" src="<?= e($shop['foto_profil']) ?>" alt="Foto toko">
        <?php else: ?>
            <div class="avatar" style="display:grid;place-items:center;font-size:44px">🏪</div>
        <?php endif; ?>
        <div>
            <h2><?= e($shop['nama']) ?></h2>
            <p>👤 <?= e($shop['nama_pemilik']) ?></p>
            <p>📍 <?= e($shop['alamat']) ?></p>
            <?php if (trim((string) $shop['deskripsi']) !== ''): ?>
                <p><?= nl2br(e($shop['deskripsi'])) ?></p>
            <?php else: ?>
                <p>Belum ada deskripsi toko.</p>
            <?php endif; ?>
            <div class="rating-summary" aria-label="Rating toko">
                <strong class="rating-stars">
                    <?= $ratingCount > 0
                        ? str_repeat('★', (int) round($averageRating)) .
                            str_repeat('☆', 5 - (int) round($averageRating))
                        : '☆☆☆☆☆' ?>
                </strong>
                <?php if ($ratingCount > 0): ?>
                    <span>
                        <?= number_format($averageRating, 1, ',', '.') ?>/5
                        dari <?= $ratingCount ?> rating
                    </span>
                <?php else: ?>
                    <span>Belum ada rating</span>
                <?php endif; ?>
            </div>
            <a class="btn" href="chat.php?restaurant_id=<?= (int) $shop['id'] ?>">
                Tanya menu melalui chat
            </a>
        </div>
    </section>

    <h2>Menu tersedia</h2>
    <?php if (!$menus): ?>
        <section class="panel">Belum ada menu yang tersedia.</section>
    <?php else: ?>
        <div class="menu-catalog">
            <?php foreach ($menus as $menu): ?>
                <article class="menu-product">
                    <div class="menu-product-image">
                        <?php if (!empty($menu['foto'])): ?>
                            <img src="<?= e($menu['foto']) ?>"
                                 alt="<?= e($menu['nama_menu']) ?>">
                        <?php else: ?>
                            <span aria-hidden="true">🍽️</span>
                        <?php endif; ?>
                    </div>
                    <div class="menu-product-body">
                        <h3><?= e($menu['nama_menu']) ?></h3>
                        <p class="menu-product-description">
                            <?= trim((string) $menu['deskripsi']) !== ''
                                ? e($menu['deskripsi'])
                                : 'Menu lezat dari ' . e($shop['nama']) . '.' ?>
                        </p>
                        <p class="menu-product-price">
                            Rp<?= number_format((float) $menu['harga'], 0, ',', '.') ?>
                        </p>
                        <div class="menu-product-actions">
                        <?php if ($canBuy): ?>
                            <a class="btn" href="beli.php?menu_id=<?= (int) $menu['id'] ?>">
                                Lihat dan pesan
                            </a>
                        <?php else: ?>
                            <a class="btn" href="pilih_peran.php">
                                Pilih peran pembeli untuk membeli
                            </a>
                        <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Rating dan ulasan pembeli</h2>
        <?php if (!$reviews): ?>
            <p>Belum ada pembeli yang memberi rating untuk toko ini.</p>
        <?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <h3><?= e($review['nama_pembeli']) ?></h3>
                        <div class="rating-stars" aria-label="<?= (int) $review['rating'] ?> dari 5 bintang">
                            <?= str_repeat('★', (int) $review['rating']) .
                                str_repeat('☆', 5 - (int) $review['rating']) ?>
                            (<?= (int) $review['rating'] ?>/5)
                        </div>
                        <?php if (!empty($review['nama_menu'])): ?>
                            <p class="muted">Pesanan: <?= e($review['nama_menu']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($review['ulasan'])): ?>
                            <p><?= nl2br(e($review['ulasan'])) ?></p>
                        <?php endif; ?>
                        <p class="muted"><?= e($review['created_at']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<footer>© 2026 Mencari Makan</footer>
</body>
</html>
