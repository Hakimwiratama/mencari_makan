
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rating') {
    if (!in_array($_SESSION['peran'] ?? '', ['pencari', 'pembeli'], true)) {
        http_response_code(403);
        exit('Hanya pembeli yang dapat memberi rating.');
    }

    verify_csrf();

    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $review = trim($_POST['ulasan'] ?? '');

    if (!$orderId || !$rating || $rating < 1 || $rating > 5) {
        header('Location: pesanan_saya.php?rating=error&pesan=' .
            rawurlencode('Pilih nilai rating dari 1 sampai 5.'));
        exit;
    }

    if (mb_strlen($review) > 2000) {
        header('Location: pesanan_saya.php?rating=error&pesan=' .
            rawurlencode('Ulasan maksimal 2.000 karakter.'));
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT restaurant_id
            FROM orders
            WHERE id = ?
              AND buyer_id = ?
              AND status = 'selesai'
            LIMIT 1
        ");
        $stmt->execute([$orderId, $userId]);
        $completedOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$completedOrder) {
            header('Location: pesanan_saya.php?rating=error&pesan=' .
                rawurlencode('Rating hanya dapat diberikan untuk pesanan selesai milikmu.'));
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO restaurant_reviews
                (order_id, restaurant_id, buyer_id, rating, ulasan)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                ulasan = VALUES(ulasan),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $orderId,
            (int) $completedOrder['restaurant_id'],
            $userId,
            $rating,
            $review !== '' ? $review : null
        ]);

        header('Location: pesanan_saya.php?rating=berhasil');
        exit;
    } catch (Throwable $e) {
        error_log('Menyimpan rating toko: ' . $e->getMessage());
        http_response_code(500);
        exit('Rating gagal disimpan. Periksa log PHP.');
    }
}

function rupiahPembeli($angka): string
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

function waktuArsipPembeli(array $o): ?string
{
    if ($o['status'] === 'ditolak') {
        return $o['ditolak_at'] ?? $o['created_at'] ?? null;
    }

    if ($o['status'] === 'selesai') {
        return $o['selesai_at'] ?? $o['created_at'] ?? null;
    }

    return null;
}

function hitungSisaArsipPembeli(?string $waktu): ?int
{
    if (!$waktu) {
        return null;
    }

    $mulai = strtotime($waktu);

    if ($mulai === false) {
        return null;
    }

    return ($mulai + (3 * 86400)) - time();
}

function formatSisaArsipPembeli(?int $sisa): string
{
    if ($sisa === null) {
        return 'Waktu mulai arsip belum tercatat.';
    }

    if ($sisa <= 0) {
        return 'Masa arsip 3 hari telah lewat.';
    }

    $hari = (int) floor($sisa / 86400);
    $jam = (int) floor(($sisa % 86400) / 3600);
    $menit = (int) floor(($sisa % 3600) / 60);

    if ($hari > 0) {
        return "Pesanan tersimpan selama masa arsip. Sisa waktu: {$hari} hari {$jam} jam.";
    }

    return "Pesanan tersimpan selama masa arsip. Sisa waktu: {$jam} jam {$menit} menit.";
}

try {
    $stmt = $pdo->prepare("
        SELECT o.*,
               m.nama_menu,
               r.nama AS nama_toko,
               rr.id AS review_id,
               rr.rating AS review_rating,
               rr.ulasan AS review_ulasan
        FROM orders o
        LEFT JOIN restaurant_menus m ON m.id = o.menu_id
        LEFT JOIN restaurants r ON r.id = o.restaurant_id
        LEFT JOIN restaurant_reviews rr ON rr.order_id = o.id
        WHERE o.buyer_id = ?
        ORDER BY o.created_at DESC
    ");

    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Daftar pesanan pembeli: ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal memuat pesanan. Periksa struktur database dan log PHP.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pesanan Saya - Mencari Makan</title>
<link rel="stylesheet" href="dashboard.css">

<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f6f9;color:#202733}
header{background:#0f766e;color:white}
header h1{font-size:22px;margin:0}
main{max-width:1000px;margin:24px auto;padding:0 16px}
.order{background:white;padding:18px;border-radius:14px;margin-bottom:16px;border:1px solid #e6eaf0;box-shadow:0 4px 14px #00000008}
.head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;border-bottom:1px solid #edf0f4;padding-bottom:12px}
h2{font-size:18px;margin:0 0 6px}
.muted{font-size:13px;color:#64748b}
.status{display:inline-block;padding:7px 11px;border-radius:20px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:bold;height:max-content}
.status.ditolak{background:#fee2e2;color:#991b1b}
.status.diterima,.status.selesai,.status.dikirim{background:#dcfce7;color:#166534}
.details{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:14px}
.details p{overflow-wrap:anywhere;margin:6px 0}
.reject{margin-top:14px;padding:14px;border:1px solid #fecaca;background:#fff7f7;border-radius:10px}
.archive{margin-top:14px;padding:14px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:10px}
.archive.ditolak{border-color:#fecaca;background:#fff7f7}
.archive.selesai{border-color:#bbf7d0;background:#f0fdf4}
button{border:0;border-radius:8px;background:#0f766e;color:white;padding:11px 15px;font-weight:bold;cursor:pointer}
button:disabled{background:#94a3b8;cursor:default}
.notice{padding:13px;background:#d1fae5;color:#065f46;border-radius:9px;margin-bottom:15px}
.error{padding:13px;background:#fee2e2;color:#991b1b;border-radius:9px;margin-bottom:15px}
.empty{padding:25px;background:white;text-align:center;border-radius:12px}
.section-title{font-size:21px;margin:26px 0 14px}
.small{font-size:12px;color:#64748b}
@media(max-width:600px){main{margin-top:16px}.order{padding:14px}}
</style>
</head>

<body>
<header class="buyer-dashboard-header">
    <h1>🛍️ Pesanan Saya</h1>
    <p>Lihat dan pantau pesanan makananmu.</p>
    <?php require __DIR__ . '/buyer_navigation.php'; ?>
</header>

<main>

<?php if (($_GET['berhasil'] ?? '') === 'konfirmasi'): ?>
    <div class="notice">
        Konfirmasi berhasil disimpan. Penjual dapat melihat bahwa kamu sudah membaca alasannya.
    </div>
<?php elseif (($_GET['error'] ?? '') === 'konfirmasi'): ?>
    <div class="error">
        Konfirmasi tidak dapat diproses. Pastikan pesanan sudah ditolak oleh penjual.
    </div>
<?php elseif (($_GET['error'] ?? '') === 'data'): ?>
    <div class="error">Data pesanan tidak valid.</div>
<?php elseif (($_GET['error'] ?? '') === 'csrf'): ?>
    <div class="error">Formulir kedaluwarsa atau tidak valid. Muat ulang halaman lalu coba lagi.</div>
<?php endif; ?>

<?php if (($_GET['rating'] ?? '') === 'berhasil'): ?>
    <div class="notice" role="status">Terima kasih! Rating dan ulasan untuk toko berhasil disimpan.</div>
<?php elseif (($_GET['rating'] ?? '') === 'error'): ?>
    <div class="error" role="alert">
        <?= htmlspecialchars(
            $_GET['pesan'] ?? 'Rating tidak dapat disimpan.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>
<?php endif; ?>

<div class="notice">
    ℹ️ Masa arsip dihitung selama 3 hari setelah pesanan ditolak atau selesai.
    Riwayat pesanan tetap tersimpan setelah masa arsip berakhir.
</div>

<?php if (!$orders): ?>
    <div class="empty">
        <h2>Belum ada pesanan</h2>
        <p>Pesanan yang kamu buat akan tampil di sini.</p>
    </div>
<?php else: ?>

    <h2 class="section-title">📋 Riwayat Pesanan</h2>

    <?php foreach ($orders as $o): ?>
        <?php
        $status = $o['status'] ?? '';
        $isArsip = in_array($status, ['ditolak', 'selesai'], true);
        $waktuArsip = $isArsip ? waktuArsipPembeli($o) : null;
        $sisaArsip = $isArsip ? hitungSisaArsipPembeli($waktuArsip) : null;
        ?>

        <article class="order">
            <div class="head">
                <div>
                    <h2>
                        <?= htmlspecialchars(
                            $o['nama_menu'] ?? 'Menu tidak tersedia',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h2>

                    <div class="muted">
                        Pesanan #<?= (int) $o['id'] ?> ·
                        <?= htmlspecialchars(
                            $o['created_at'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>

                    <div class="muted">
                        Toko:
                        <?= htmlspecialchars(
                            $o['nama_toko'] ?? 'Tidak tersedia',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                </div>

                <span class="status <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars([
                        'menunggu' => 'Menunggu',
                        'diterima' => 'Diterima',
                        'diproses' => 'Diproses',
                        'dikirim' => 'Sedang dikirim',
                        'selesai' => 'Selesai',
                        'ditolak' => 'Ditolak',
                        'dibatalkan' => 'Dibatalkan'
                    ][$status] ?? 'Status tidak dikenal', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <div class="details">
                <div>
                    <b>Informasi pengantaran</b>
                    <p>
                        <?= htmlspecialchars(
                            $o['nama_penerima'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p>
                        <?= htmlspecialchars(
                            $o['telepon'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p>
                        <?= nl2br(htmlspecialchars(
                            $o['alamat'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        )) ?>
                    </p>
                </div>

                <div>
                    <b>Ringkasan pesanan</b>
                    <p>Jumlah: <?= (int) ($o['jumlah'] ?? 0) ?></p>
                    <p>
                        Harga satuan:
                        <?= rupiahPembeli($o['harga_satuan'] ?? 0) ?>
                    </p>
                    <p>
                        <b>Total: <?= rupiahPembeli($o['total_harga'] ?? 0) ?></b>
                    </p>

                    <?php if (!empty($o['catatan'])): ?>
                        <p>
                            Catatan:
                            <?= nl2br(htmlspecialchars(
                                $o['catatan'],
                                ENT_QUOTES,
                                'UTF-8'
                            )) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status === 'ditolak'): ?>
                <div class="reject">
                    <b>❌ Pesan dari penjual</b>

                    <p>
                        <?= nl2br(htmlspecialchars(
                            $o['alasan_penolakan'] ?: 'Penjual belum memberikan alasan.',
                            ENT_QUOTES,
                            'UTF-8'
                        )) ?>
                    </p>

                    <?php if (empty($o['konfirmasi_pembeli'])): ?>
                        <p>Silakan konfirmasi bahwa kamu sudah membaca pesan penjual.</p>

                        <form method="post"
                              action="konfirmasi_penolakan.php"
                              onsubmit="return confirm('Apakah kamu sudah membaca alasan penolakan ini?')">
                            <input type="hidden"
                                   name="order_id"
                                   value="<?= (int) $o['id'] ?>">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit">OK, saya mengerti</button>
                        </form>
                    <?php else: ?>
                        <p><b>✓ Kamu sudah mengonfirmasi pesan ini.</b></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($isArsip): ?>
                <div class="archive <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                    <b>
                        📦
                        <?= $status === 'ditolak'
                            ? 'Pesanan Ditolak — Dalam Arsip'
                            : 'Pesanan Selesai — Dalam Arsip' ?>
                    </b>

                    <p>
                        <?= htmlspecialchars(
                            formatSisaArsipPembeli($sisaArsip),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <?php if ($waktuArsip): ?>
                        <p class="small">
                            Mulai arsip:
                            <?= htmlspecialchars($waktuArsip, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php else: ?>
                        <p class="small">
                            Waktu arsip belum tercatat pada data pesanan ini.
                        </p>
                    <?php endif; ?>
                </div>
            <?php elseif ($status === 'menunggu'): ?>
                <p class="muted">⏳ Menunggu keputusan dari penjual.</p>
            <?php elseif ($status === 'diterima'): ?>
                <p class="muted">✓ Pesanan diterima penjual dan sedang dipersiapkan.</p>
            <?php elseif ($status === 'dikirim'): ?>
                <p class="muted">🚚 Pesanan sedang dalam proses pengantaran.</p>
            <?php elseif ($status === 'selesai'): ?>
                <section class="review-form">
                    <h3>⭐ Beri rating toko</h3>
                    <p class="muted">Rating hanya tersedia untuk pesanan yang sudah selesai.</p>
                    <form method="post" action="pesanan_saya.php">
                        <input type="hidden" name="action" value="rating">
                        <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <label for="rating-<?= (int) $o['id'] ?>">Rating</label>
                        <select id="rating-<?= (int) $o['id'] ?>" name="rating" required>
                            <option value="">Pilih rating...</option>
                            <?php for ($ratingValue = 5; $ratingValue >= 1; $ratingValue--): ?>
                                <option value="<?= $ratingValue ?>"
                                    <?= (int) ($o['review_rating'] ?? 0) === $ratingValue ? 'selected' : '' ?>>
                                    <?= $ratingValue ?> bintang
                                </option>
                            <?php endfor; ?>
                        </select>
                        <label for="ulasan-<?= (int) $o['id'] ?>">Ulasan (opsional)</label>
                        <textarea id="ulasan-<?= (int) $o['id'] ?>"
                                  name="ulasan" maxlength="2000"
                                  placeholder="Bagikan pengalamanmu dengan toko ini"><?= htmlspecialchars(
                                      $o['review_ulasan'] ?? '',
                                      ENT_QUOTES,
                                      'UTF-8'
                                  ) ?></textarea>
                        <button type="submit">
                            <?= !empty($o['review_id']) ? 'Perbarui Rating' : 'Kirim Rating' ?>
                        </button>
                    </form>
                </section>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

<?php endif; ?>
</main>
</body>
</html>
