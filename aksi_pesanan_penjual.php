
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

if (($_SESSION['peran'] ?? '') !== 'pemilik') {
    header('Location: pilih_peran.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pesanan_penjual.php');
    exit;
}

verify_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$aksi = $_POST['aksi'] ?? '';
$alasan = trim($_POST['alasan_penolakan'] ?? '');

$aksiValid = ['terima', 'tolak', 'kirim', 'selesai'];

if (!$orderId || !in_array($aksi, $aksiValid, true)) {
    header('Location: pesanan_penjual.php?error=data');
    exit;
}

if ($aksi === 'tolak' && mb_strlen($alasan) < 5) {
    header('Location: pesanan_penjual.php?error=alasan');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT o.id, o.status
        FROM orders o
        INNER JOIN restaurants r ON r.id = o.restaurant_id
        WHERE o.id = ?
          AND r.owner_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        header('Location: pesanan_penjual.php?error=akses');
        exit;
    }

    $status = $order['status'];

    if ($aksi === 'terima') {
        if ($status !== 'menunggu') {
            $pdo->rollBack();
            header('Location: pesanan_penjual.php?error=akses');
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'diterima',
                alasan_penolakan = NULL,
                ditolak_at = NULL,
                selesai_at = NULL,
                konfirmasi_pembeli = 0
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);

    } elseif ($aksi === 'tolak') {
        if ($status !== 'menunggu') {
            $pdo->rollBack();
            header('Location: pesanan_penjual.php?error=akses');
            exit;
        }
        if (mb_strlen($alasan) > 1000) {
            $pdo->rollBack();
            header('Location: pesanan_penjual.php?error=alasan');
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'ditolak',
                alasan_penolakan = ?,
                ditolak_at = NOW(),
                selesai_at = NULL,
                konfirmasi_pembeli = 0
            WHERE id = ?
        ");
        $stmt->execute([$alasan, $orderId]);

    } elseif ($aksi === 'kirim') {
        if ($status !== 'diterima') {
            $pdo->rollBack();
            header('Location: pesanan_penjual.php?error=akses');
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'dikirim'
            WHERE id = ?
              AND status = 'diterima'
        ");
        $stmt->execute([$orderId]);

    } elseif ($aksi === 'selesai') {
        if ($status !== 'dikirim') {
            $pdo->rollBack();
            header('Location: pesanan_penjual.php?error=akses');
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'selesai',
                selesai_at = NOW()
            WHERE id = ?
              AND status = 'dikirim'
        ");
        $stmt->execute([$orderId]);
    }

    $pdo->commit();

    $hasil = [
        'terima' => 'diterima',
        'tolak' => 'ditolak',
        'kirim' => 'dikirim',
        'selesai' => 'selesai'
    ];

    header('Location: pesanan_penjual.php?berhasil=' . $hasil[$aksi]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Aksi pesanan penjual: ' . $e->getMessage());

    http_response_code(500);
    exit('Gagal memproses pesanan. Periksa log PHP dan struktur database.');
}
