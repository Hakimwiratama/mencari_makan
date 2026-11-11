<?php
$dashboardPendingCount = isset($jumlahMenunggu)
    ? (int) $jumlahMenunggu
    : null;
$dashboardAcceptedCount = isset($jumlahDiterima)
    ? (int) $jumlahDiterima
    : null;

if ($dashboardPendingCount === null || $dashboardAcceptedCount === null) {
    $countStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(o.status = 'menunggu'), 0) AS pending_count,
            COALESCE(SUM(o.status = 'diterima'), 0) AS accepted_count
         FROM restaurants r
         LEFT JOIN orders o ON o.restaurant_id = r.id
         WHERE r.owner_id = ?"
    );
    $countStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $dashboardCounts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $dashboardPendingCount = (int) ($dashboardCounts['pending_count'] ?? 0);
    $dashboardAcceptedCount = (int) ($dashboardCounts['accepted_count'] ?? 0);
}
?>
<nav class="seller-dashboard-nav" aria-label="Navigasi dashboard penjual">
    <a href="index.php">🏠 Beranda</a>
    <a href="profil_penjual.php">🏪 Profil Toko</a>
    <a href="kelola_menu.php">🍽️ Kelola Menu</a>
    <a href="chat.php">💬 Chat</a>
    <a href="pesanan_penjual.php">📦 Pesanan Masuk (<?= $dashboardPendingCount ?>)</a>
    <a href="pesanan_penjual.php?status=diterima">✅ Pesanan Diterima (<?= $dashboardAcceptedCount ?>)</a>
    <a href="pilih_peran.php">🔄 Ganti Peran</a>
    <a href="logout.php" onclick="return confirm('Yakin ingin keluar dari akun?')">🚪 Keluar</a>
</nav>
