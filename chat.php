
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['peran'] ?? '';

$isOwner = ($role === 'pemilik');
$isBuyer = in_array($role, ['pencari', 'pembeli'], true);

if (!$isOwner && !$isBuyer) {
    http_response_code(403);
    exit('Peran akun tidak diizinkan menggunakan chat.');
}

/**
 * Memastikan pengguna hanya mengakses percakapan miliknya.
 */
function findAccessibleRoom(
    PDO $pdo,
    int $roomId,
    int $userId,
    bool $isOwner
): ?array {
    if ($isOwner) {
        $sql = '
            SELECT cr.id, cr.buyer_id, cr.restaurant_id,
                   cr.updated_at,
                   r.nama AS nama_toko,
                   u.nama AS nama_pembeli,
                   r.owner_id
            FROM chat_rooms cr
            INNER JOIN restaurants r
                ON r.id = cr.restaurant_id
            INNER JOIN users u
                ON u.id = cr.buyer_id
            WHERE cr.id = ?
              AND r.owner_id = ?
            LIMIT 1
        ';
    } else {
        $sql = '
            SELECT cr.id, cr.buyer_id, cr.restaurant_id,
                   cr.updated_at,
                   r.nama AS nama_toko,
                   u.nama AS nama_pembeli,
                   r.owner_id
            FROM chat_rooms cr
            INNER JOIN restaurants r
                ON r.id = cr.restaurant_id
            INNER JOIN users u
                ON u.id = cr.buyer_id
            WHERE cr.id = ?
              AND cr.buyer_id = ?
            LIMIT 1
        ';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomId, $userId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    return $room ?: null;
}

$error = '';
$room = null;
$roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT);

/**
 * Pembeli memulai atau membuka chat dengan toko.
 * Contoh: chat.php?restaurant_id=3
 */
if ($isBuyer && isset($_GET['restaurant_id'])) {
    $restaurantId = filter_input(
        INPUT_GET,
        'restaurant_id',
        FILTER_VALIDATE_INT
    );

    if (!$restaurantId || $restaurantId < 1) {
        http_response_code(400);
        exit('ID restoran tidak valid.');
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM restaurants WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$restaurantId]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        exit('Restoran tidak ditemukan.');
    }

    // Unik berdasarkan pasangan pembeli dan restoran.
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO chat_rooms (buyer_id, restaurant_id)
         VALUES (?, ?)'
    );
    $stmt->execute([$userId, $restaurantId]);

    $stmt = $pdo->prepare(
        'SELECT id FROM chat_rooms
         WHERE buyer_id = ? AND restaurant_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $restaurantId]);

    $existingRoom = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingRoom) {
        http_response_code(500);
        exit('Percakapan gagal dibuat. Silakan coba lagi.');
    }

    header(
        'Location: chat.php?room_id=' . (int) $existingRoom['id']
    );
    exit;
}

/**
 * Periksa akses sebelum menampilkan riwayat percakapan.
 */
if ($roomId && $roomId > 0) {
    $room = findAccessibleRoom(
        $pdo,
        (int) $roomId,
        $userId,
        $isOwner
    );

    if (!$room) {
        http_response_code(403);
        exit('Kamu tidak memiliki akses ke percakapan ini.');
    }
}

/**
 * Memproses pesan baru.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $postedRoomId = filter_input(
        INPUT_POST,
        'room_id',
        FILTER_VALIDATE_INT
    );

    $message = trim($_POST['message'] ?? '');

    if (!$postedRoomId || $postedRoomId < 1) {
        http_response_code(400);
        exit('ID percakapan tidak valid.');
    }

    // Periksa ulang kepemilikan setiap kali pesan dikirim.
    $room = findAccessibleRoom(
        $pdo,
        (int) $postedRoomId,
        $userId,
        $isOwner
    );

    if (!$room) {
        http_response_code(403);
        exit('Kamu tidak memiliki izin mengirim pesan ke chat ini.');
    }

    // Batasi panjang pesan berdasarkan jumlah karakter.
    $messageLength = function_exists('mb_strlen')
        ? mb_strlen($message, 'UTF-8')
        : strlen($message);

    if ($message === '') {
        $error = 'Pesan tidak boleh kosong.';
    } elseif ($messageLength > 2000) {
        $error = 'Pesan maksimal 2.000 karakter.';
    } else {
        try {
            $pdo->beginTransaction();

            // Kunci ruang chat agar pembaruan pesan konsisten.
            $stmt = $pdo->prepare(
                'SELECT id FROM chat_rooms WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([(int) $room['id']]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'Percakapan tidak ditemukan.'
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO chat_messages
                    (room_id, sender_id, message)
                 VALUES (?, ?, ?)'
            );

            $stmt->execute([
                (int) $room['id'],
                $userId,
                $message
            ]);

            $stmt = $pdo->prepare(
                'UPDATE chat_rooms
                 SET updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );

            $stmt->execute([(int) $room['id']]);

            $pdo->commit();

            header(
                'Location: chat.php?room_id=' . (int) $room['id']
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $error = 'Pesan gagal dikirim. Silakan coba lagi.';
        }
    }

    $roomId = (int) $room['id'];
}

/**
 * Ambil daftar chat hanya untuk akun yang sedang login.
 */
if ($isOwner) {
    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.updated_at,
                u.nama AS nama_lawan,
                r.nama AS nama_toko
         FROM chat_rooms cr
         INNER JOIN restaurants r
            ON r.id = cr.restaurant_id
         INNER JOIN users u
            ON u.id = cr.buyer_id
         WHERE r.owner_id = ?
         ORDER BY cr.updated_at DESC'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.updated_at,
                r.nama AS nama_lawan,
                r.nama AS nama_toko
         FROM chat_rooms cr
         INNER JOIN restaurants r
            ON r.id = cr.restaurant_id
         WHERE cr.buyer_id = ?
         ORDER BY cr.updated_at DESC'
    );
}

$stmt->execute([$userId]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Perbarui data ruang chat setelah proses POST,
 * lalu pastikan aksesnya tetap valid.
 */
if ($roomId && $roomId > 0) {
    $room = findAccessibleRoom(
        $pdo,
        (int) $roomId,
        $userId,
        $isOwner
    );

    if (!$room) {
        http_response_code(403);
        exit('Kamu tidak memiliki akses ke percakapan ini.');
    }
}

/**
 * Ambil riwayat pesan hanya setelah akses terverifikasi.
 */
$messages = [];

if ($room) {
    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.sender_id, cm.message, cm.created_at,
                u.nama AS nama_pengirim
         FROM chat_messages cm
         INNER JOIN users u
            ON u.id = cm.sender_id
         WHERE cm.room_id = ?
         ORDER BY cm.created_at ASC, cm.id ASC'
    );

    $stmt->execute([(int) $room['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat - Mencari Makan</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .chat-navigation {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin: 18px auto 0;
        }

        .chat-navigation a {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            background: #fff;
            color: #222;
            text-decoration: none;
            font-weight: bold;
            border: 1px solid #ddd;
        }

        .chat-navigation a:hover {
            background: #e8f2ff;
        }

        .chat-navigation .logout-link {
            background: #dc3545;
            color: #fff;
            border-color: #dc3545;
        }

        .chat-layout {
            display: grid;
            grid-template-columns: minmax(220px, 300px) minmax(0, 1fr);
            gap: 20px;
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .chat-list, .chat-panel {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 16px;
            min-width: 0;
        }

        .chat-link {
            display: block;
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            background: #f5f5f5;
            color: #222;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .chat-link.active {
            background: #dceafe;
        }

        .chat-messages {
            min-height: 250px;
            max-height: 55vh;
            overflow-y: auto;
            padding: 10px 0;
        }

        .chat-message {
            padding: 10px 12px;
            margin: 10px 0;
            border-radius: 10px;
            background: #f1f1f1;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .chat-message.mine {
            background: #dff5df;
        }

        .chat-message small {
            display: block;
            margin-top: 6px;
            color: #555;
        }

        .chat-form textarea {
            box-sizing: border-box;
            width: 100%;
            min-height: 80px;
            margin: 8px 0;
        }

        .chat-form button {
            cursor: pointer;
        }

        @media (max-width: 700px) {
            .chat-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<header>
    <h1>💬 Chat Mencari Makan</h1>
    <p>Pesan pribadi antara pembeli dan pemilik toko.</p>

    <nav class="chat-navigation">
        <a href="index.php">🏠 Beranda</a>

        <?php if ($isOwner): ?>
            <a href="pesanan_penjual.php">📦 Pesanan Masuk</a>
        <?php else: ?>
            <a href="pesanan_saya.php">📦 Pesanan Saya</a>
        <?php endif; ?>

        <a
            class="logout-link"
            href="logout.php"
            onclick="return confirm('Yakin ingin keluar dari akun?');"
        >🚪 Keluar</a>
    </nav>
</header>

<main class="chat-layout">
    <aside class="chat-list">
        <h2>Daftar Chat</h2>

        <?php if (!$rooms): ?>
            <p>Belum ada percakapan.</p>
        <?php else: ?>
            <?php foreach ($rooms as $item): ?>
                <a
                    class="chat-link <?= (
                        $room && (int) $room['id'] === (int) $item['id']
                    ) ? 'active' : '' ?>"
                    href="chat.php?room_id=<?= (int) $item['id'] ?>"
                >
                    <strong><?= e($item['nama_lawan']) ?></strong><br>
                    <small><?= e($item['updated_at']) ?></small>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>

    <section class="chat-panel">
        <?php if (!$room): ?>
            <h2>Pilih percakapan</h2>

            <p>
                <?php if ($isBuyer): ?>
                    Buka halaman toko, lalu pilih tombol Chat Pemilik Toko
                    untuk memulai percakapan.
                <?php else: ?>
                    Pilih percakapan dari daftar untuk membalas pembeli.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <h2>
                Chat dengan
                <?= e($isOwner ? $room['nama_pembeli'] : $room['nama_toko']) ?>
            </h2>

            <?php if ($error !== ''): ?>
                <p class="error"><?= e($error) ?></p>
            <?php endif; ?>

            <div class="chat-messages">
                <?php if (!$messages): ?>
                    <p>Belum ada pesan. Mulai percakapan sekarang.</p>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="chat-message <?= (
                            (int) $msg['sender_id'] === $userId
                        ) ? 'mine' : '' ?>">
                            <strong><?= e($msg['nama_pengirim']) ?></strong>
                            <div><?= e($msg['message']) ?></div>
                            <small><?= e($msg['created_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form
                class="chat-form"
                method="post"
                action="chat.php?room_id=<?= (int) $room['id'] ?>"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="room_id"
                    value="<?= (int) $room['id'] ?>"
                >

                <label for="message">Tulis pesan</label>
                <textarea
                    id="message"
                    name="message"
                    maxlength="2000"
                    required
                ></textarea>

                <button type="submit">Kirim Pesan</button>
            </form>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
