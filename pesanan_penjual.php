
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$peran = $_SESSION['peran'] ?? '';

if ($peran !== 'pemilik') {
    header('Location: pilih_peran.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, nama FROM restaurants WHERE owner_id = ? LIMIT 1"
);
$stmt->execute([$userId]);
$restaurant = $stmt->fetch(PDO::FETCH_ASSOC);

$ordersAktif = [];
$ordersArsip = [];
$pesanHalaman = '';
$notifikasi = '';
$jenisNotifikasi = 'notice';

$notifikasiStatus = [
    'diterima' => 'Pesanan berhasil diterima.',
    'ditolak' => 'Pesanan berhasil ditolak dan alasan dikirim ke pembeli.',
    'dikirim' => 'Pesanan ditandai sedang dikirim.',
    'selesai' => 'Pesanan ditandai selesai dan dipindahkan ke arsip.'
];
$notifikasiError = [
    'data' => 'Permintaan pesanan tidak valid. Coba kembali.',
    'alasan' => 'Alasan penolakan harus berisi minimal 5 karakter.',
    'akses' => 'Pesanan sudah berubah atau bukan milik toko ini. Muat ulang halaman.',
    'csrf' => 'Formulir kedaluwarsa atau tidak valid. Muat ulang halaman lalu coba lagi.'
];

$berhasil = $_GET['berhasil'] ?? '';
$error = $_GET['error'] ?? '';
if (isset($notifikasiStatus[$berhasil])) {
    $notifikasi = $notifikasiStatus[$berhasil];
} elseif (isset($notifikasiError[$error])) {
    $notifikasi = $notifikasiError[$error];
    $jenisNotifikasi = 'error';
}

if (!$restaurant) {
    $pesanHalaman = 'Profil toko belum dibuat. Buat profil toko terlebih dahulu agar pesanan dapat masuk.';
} else {
    $stmt = $pdo->prepare("
        SELECT o.*, m.nama_menu, m.foto,
               u.nama AS nama_pembeli
        FROM orders o
        JOIN restaurant_menus m ON m.id = o.menu_id
        JOIN users u ON u.id = o.buyer_id
        WHERE o.restaurant_id = ?
          AND o.status NOT IN ('ditolak', 'selesai')
        ORDER BY
          CASE
            WHEN o.status = 'menunggu' THEN 0
            WHEN o.status = 'diterima' THEN 1
            WHEN o.status = 'dikirim' THEN 2
            ELSE 3
          END,
          o.created_at DESC
    ");
    $stmt->execute([(int) $restaurant['id']]);
    $ordersAktif = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT o.*, m.nama_menu, m.foto,
               u.nama AS nama_pembeli
        FROM orders o
        JOIN restaurant_menus m ON m.id = o.menu_id
        JOIN users u ON u.id = o.buyer_id
        WHERE o.restaurant_id = ?
          AND (
              o.status = 'ditolak'
              OR
              o.status = 'selesai'
          )
        ORDER BY
          GREATEST(
              COALESCE(o.ditolak_at, o.created_at),
              COALESCE(o.selesai_at, o.created_at)
          ) DESC
    ");
    $stmt->execute([(int) $restaurant['id']]);
    $ordersArsip = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rupiahPenjual($angka): string
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

function statusPesananPenjual(string $status): string
{
    $labels = [
        'menunggu' => 'Menunggu',
        'diterima' => 'Diterima',
        'diproses' => 'Diproses',
        'dikirim' => 'Sedang dikirim',
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
        'dibatalkan' => 'Dibatalkan'
    ];

    return $labels[$status] ?? 'Status tidak dikenal';
}

function sisaArsipPenjual(?string $waktu): string
{
    if (!$waktu) {
        return 'Waktu arsip belum tercatat.';
    }

    $batas = strtotime($waktu . ' +3 days');
    $sisa = $batas - time();

    if ($sisa <= 0) {
        return 'Masa arsip 3 hari telah lewat.';
    }

    $hari = (int) floor($sisa / 86400);
    $jam = (int) floor(($sisa % 86400) / 3600);

    if ($hari > 0) {
        return "Sisa arsip: {$hari} hari {$jam} jam.";
    }

    $menit = (int) floor(($sisa % 3600) / 60);
    return "Sisa arsip: {$jam} jam {$menit} menit.";
}

function waktuArsipPenjual(array $o): ?string
{
    if ($o['status'] === 'ditolak') {
        return $o['ditolak_at'] ?? $o['created_at'] ?? null;
    }

    if ($o['status'] === 'selesai') {
        return $o['selesai_at'] ?? $o['created_at'] ?? null;
    }

    return null;
}

function tampilkanPesananPenjual(array $o, bool $arsip = false): void
{
    ?>
    <article class="order">
        <div class="order-head">
            <div>
                <h3><?= htmlspecialchars($o['nama_menu'], ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="muted">
                    Pesanan #<?= (int) $o['id'] ?> ·
                    <?= htmlspecialchars($o['created_at'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

            <span class="status <?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(statusPesananPenjual($o['status']), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <div class="details">
            <div>
                <b>Data pembeli</b>
                <p><?= htmlspecialchars($o['nama_penerima'], ENT_QUOTES, 'UTF-8') ?></p>
                <p><?= htmlspecialchars($o['telepon'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="muted">
                    Akun: <?= htmlspecialchars($o['nama_pembeli'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <div>
                <b>Alamat pengantaran</b>
                <p><?= nl2br(htmlspecialchars($o['alamat'], ENT_QUOTES, 'UTF-8')) ?></p>

                <?php if (!empty($o['catatan'])): ?>
                    <p><b>Catatan:</b>
                        <?= nl2br(htmlspecialchars($o['catatan'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div>
                <b>Ringkasan</b>
                <p><?= (int) $o['jumlah'] ?> × <?= rupiahPenjual($o['harga_satuan']) ?></p>
                <p><b>Total: <?= rupiahPenjual($o['total_harga']) ?></b></p>
            </div>
        </div>

        <?php if ($o['status'] === 'ditolak'): ?>
            <div class="reject-box">
                <b>Alasan penolakan:</b>
                <p><?= nl2br(htmlspecialchars(
                    $o['alasan_penolakan'] ?? 'Tidak ada alasan yang dicantumkan.',
                    ENT_QUOTES,
                    'UTF-8'
                )) ?></p>

                <?php if ($arsip): ?>
                    <p class="small">
                        <?= htmlspecialchars(
                            sisaArsipPenjual(waktuArsipPenjual($o)),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                    <p class="small">
                        Konfirmasi pembeli:
                        <?= !empty($o['konfirmasi_pembeli'])
                            ? 'Sudah menekan OK'
                            : 'Belum dikonfirmasi' ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($arsip): ?>
            <div class="archive-info">
                <b>📦 Pesanan dalam arsip</b>
                <p>
                    <?= htmlspecialchars(
                        sisaArsipPenjual(waktuArsipPenjual($o)),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p class="small">
                    Arsip dihitung selama 3 hari sejak pesanan
                    <?= $o['status'] === 'ditolak' ? 'ditolak' : 'diselesaikan' ?>.
                </p>
            </div>

        <?php elseif ($o['status'] === 'menunggu'): ?>
            <div class="actions">
                <form method="post" action="aksi_pesanan_penjual.php"
                      onsubmit="return confirm('Terima pesanan ini?')">
                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                    <input type="hidden" name="aksi" value="terima">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <button class="accept" type="submit">✓ Terima Pesanan</button>
                </form>
            </div>

            <form method="post" action="aksi_pesanan_penjual.php"
                  onsubmit="return validasiAlasan(this)">
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <input type="hidden" name="aksi" value="tolak">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="reject-box">
                    <label for="alasan<?= (int) $o['id'] ?>">
                        <b>Alasan menolak pesanan</b>
                    </label>
                    <p class="small">Contoh: menu habis, toko tutup, atau kendala pengantaran.</p>
                    <textarea id="alasan<?= (int) $o['id'] ?>"
                              name="alasan_penolakan" maxlength="1000"
                              required
                              placeholder="Jelaskan alasan penolakan kepada pembeli..."></textarea>
                    <button class="reject" type="submit">Tolak Pesanan</button>
                </div>
            </form>

        <?php elseif ($o['status'] === 'diterima'): ?>
            <form method="post" action="aksi_pesanan_penjual.php"
                  onsubmit="return confirm('Tandai pesanan sudah dikirim?')">
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <input type="hidden" name="aksi" value="kirim">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="accept" type="submit">🚚 Tandai Dikirim</button>
            </form>

        <?php elseif ($o['status'] === 'dikirim'): ?>
            <form method="post" action="aksi_pesanan_penjual.php"
                  onsubmit="return confirm('Tandai pesanan selesai? Masa arsip 3 hari dimulai setelah ini.')">
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <input type="hidden" name="aksi" value="selesai">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="accept" type="submit">✓ Tandai Selesai</button>
            </form>
        <?php endif; ?>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pesanan Masuk - Mencari Makan</title>
<link rel="stylesheet" href="dashboard.css">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f7f6;color:#202733}
header{background:#0f766e;color:#fff;padding:20px 5%}
header h1{font-size:22px;margin:0}
main{max-width:1100px;margin:24px auto;padding:0 16px}
.notice{padding:14px;border-radius:10px;background:#e0f2fe;margin-bottom:18px}
.notice.success{background:#dcfce7;color:#166534}
.notice.error{background:#fee2e2;color:#991b1b}
.section-title{font-size:21px;margin:28px 0 14px}
.order{background:#fff;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 4px 14px #0000000b;border:1px solid #e6eaf0}
.order-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:1px solid #edf0f4;padding-bottom:12px;margin-bottom:12px}
h3{font-size:18px;margin:0 0 5px}
.muted{color:#687385;font-size:13px}
.status{display:inline-block;border-radius:20px;padding:7px 11px;background:#fef3c7;color:#92400e;font-weight:bold;font-size:12px;height:max-content}
.status.diterima,.status.dikirim,.status.selesai{background:#dcfce7;color:#166534}
.status.ditolak{background:#fee2e2;color:#991b1b}
.details{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0}
.details p{margin:5px 0;overflow-wrap:anywhere}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
textarea{width:100%;min-height:75px;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
button{border:0;border-radius:8px;padding:10px 14px;font-weight:bold;cursor:pointer}
.accept{background:#0f766e;color:white}
.reject{background:#dc2626;color:white}
.reject-box{width:100%;padding:12px;background:#fff7f7;border:1px solid #fecaca;border-radius:10px;margin-top:10px}
.archive-info{padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-top:12px}
.small{font-size:12px;color:#64748b}
.empty{background:#fff;padding:24px;border-radius:12px;text-align:center}
@media(max-width:600px){header{padding:16px}main{margin-top:16px}.order{padding:14px}}
</style>
</head>
<body>
<header class="seller-dashboard-header">
    <h1>🔔 Pesanan Masuk<?= isset($restaurant['nama']) ? ' — ' . htmlspecialchars($restaurant['nama'], ENT_QUOTES, 'UTF-8') : '' ?></h1>
    <p>Kelola pesanan dan pantau status pesanan pelangganmu.</p>
    <?php require __DIR__ . '/seller_navigation.php'; ?>
</header>

<main>
<?php if ($pesanHalaman !== ''): ?>
    <div class="notice"><?= htmlspecialchars($pesanHalaman, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
    <?php if ($notifikasi !== ''): ?>
        <div class="notice <?= htmlspecialchars($jenisNotifikasi, ENT_QUOTES, 'UTF-8') ?>"
             role="status"><?= htmlspecialchars($notifikasi, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="notice">
        Alur pesanan: Menunggu → Diterima → Dikirim → Selesai.
        Pesanan ditolak dan selesai tersimpan di bagian arsip.
    </div>

    <h2 class="section-title">📥 Pesanan Aktif</h2>
    <?php if (!$ordersAktif): ?>
        <div class="empty">Tidak ada pesanan aktif saat ini.</div>
    <?php else: ?>
        <?php foreach ($ordersAktif as $o): ?>
            <?php tampilkanPesananPenjual($o); ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="section-title">📦 Arsip Pesanan</h2>
    <?php if (!$ordersArsip): ?>
        <div class="empty">Belum ada pesanan yang masuk arsip.</div>
    <?php else: ?>
        <?php foreach ($ordersArsip as $o): ?>
            <?php tampilkanPesananPenjual($o, true); ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</main>

<script>
function validasiAlasan(form) {
    const alasan = form.querySelector('textarea[name="alasan_penolakan"]').value.trim();

    if (alasan.length < 5) {
        alert('Tolong jelaskan alasan penolakan minimal 5 karakter.');
        return false;
    }

    return confirm('Tolak pesanan dan kirim alasan ini kepada pembeli?');
}
</script>
</body>
</html>
