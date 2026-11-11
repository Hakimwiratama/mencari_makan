<?php
$buyerChatHref = 'chat.php';
if (isset($buyerChatRestaurantId) && (int) $buyerChatRestaurantId > 0) {
    $buyerChatHref .= '?restaurant_id=' . (int) $buyerChatRestaurantId;
}
?>
<nav class="buyer-dashboard-nav" aria-label="Navigasi dashboard pembeli">
    <a href="index.php">🏠 Beranda</a>
    <a href="<?= e($buyerChatHref) ?>">💬 Chat</a>
    <a href="pesanan_saya.php">📋 Pesanan Saya</a>
    <a href="pilih_peran.php">🔄 Ganti Peran</a>
    <a href="logout.php" onclick="return confirm('Yakin ingin keluar dari akun?')">🚪 Keluar</a>
</nav>
