
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pesanan_saya.php');
    exit;
}

verify_csrf();

$userId = (int)($_SESSION['user_id'] ?? 0);
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if (!$orderId) {
    header('Location: pesanan_saya.php?error=data');
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE orders
        SET konfirmasi_pembeli = 1
        WHERE id = ?
          AND buyer_id = ?
          AND status = 'ditolak'
          AND alasan_penolakan IS NOT NULL
          AND TRIM(alasan_penolakan) <> ''
    ");

    $stmt->execute([$orderId, $userId]);

    if ($stmt->rowCount() > 0) {
        header('Location: pesanan_saya.php?berhasil=konfirmasi');
    } else {
        // Periksa apakah pesanan sudah dikonfirmasi sebelumnya.
        $check = $pdo->prepare("
            SELECT id
            FROM orders
            WHERE id = ?
              AND buyer_id = ?
              AND status = 'ditolak'
              AND konfirmasi_pembeli = 1
        ");
        $check->execute([$orderId, $userId]);

        header(
            $check->fetch()
                ? 'Location: pesanan_saya.php?berhasil=konfirmasi'
                : 'Location: pesanan_saya.php?error=konfirmasi'
        );
    }
    exit;

} catch (Throwable $e) {
    error_log('Konfirmasi penolakan: ' . $e->getMessage());
    http_response_code(500);
    exit('Konfirmasi gagal disimpan. Periksa log PHP.');
}
