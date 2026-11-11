
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
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

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

if (isset($_GET['poll']) && $_GET['poll'] === '1') {
    if (!$room || !$roomId || $roomId < 1) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Pilih percakapan yang valid.']);
        exit;
    }

    $afterId = filter_input(INPUT_GET, 'after_id', FILTER_VALIDATE_INT);
    if ($afterId === false || ($afterId !== null && $afterId < 0)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Penanda pesan tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.sender_id, cm.message, cm.created_at,
                u.nama AS nama_pengirim
         FROM chat_messages cm
         INNER JOIN users u ON u.id = cm.sender_id
         WHERE cm.room_id = ? AND cm.id > ?
         ORDER BY cm.id ASC
         LIMIT 100'
    );
    $stmt->execute([(int) $room['id'], (int) ($afterId ?? 0)]);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
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
            $messageId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'UPDATE chat_rooms
                 SET updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );

            $stmt->execute([(int) $room['id']]);

            $pdo->commit();

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'message' => [
                        'id' => $messageId,
                        'sender_id' => $userId,
                        'message' => $message,
                        'created_at' => date('Y-m-d H:i:s'),
                        'nama_pengirim' => $_SESSION['nama'] ?? 'Anda'
                    ]
                ]);
                exit;
            }

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

    if ($isAjaxRequest && $error !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error]);
        exit;
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
                r.nama AS nama_toko,
                (SELECT cm.message FROM chat_messages cm
                 WHERE cm.room_id = cr.id ORDER BY cm.id DESC LIMIT 1) AS pesan_terakhir,
                (SELECT cm.created_at FROM chat_messages cm
                 WHERE cm.room_id = cr.id ORDER BY cm.id DESC LIMIT 1) AS pesan_terakhir_at
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
                r.nama AS nama_toko,
                (SELECT cm.message FROM chat_messages cm
                 WHERE cm.room_id = cr.id ORDER BY cm.id DESC LIMIT 1) AS pesan_terakhir,
                (SELECT cm.created_at FROM chat_messages cm
                 WHERE cm.room_id = cr.id ORDER BY cm.id DESC LIMIT 1) AS pesan_terakhir_at
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

function chatInitial(string $name): string
{
    $initial = function_exists('mb_substr')
        ? mb_substr($name, 0, 1, 'UTF-8')
        : substr($name, 0, 1);
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($initial, 'UTF-8')
        : strtoupper($initial);
}

function chatTime(?string $date): string
{
    $timestamp = $date !== null ? strtotime($date) : false;
    return $timestamp === false ? '' : date('H:i', $timestamp);
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
        body { background: #f0f2f5; color: #111b21; }
        .chat-topbar {
            padding: 12px max(16px, calc((100% - 1300px) / 2));
            background: #008069;
        }
        .chat-topbar h1 { margin: 0; font-size: 22px; }
        .chat-topbar p { margin: 4px 0 0; color: #e6f4f1; }
        .chat-navigation { display: flex; gap: 8px; margin-top: 10px; }
        .chat-navigation a {
            padding: 7px 10px;
            border-radius: 8px;
            background: #ffffff20;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
        }
        .chat-shell {
            display: grid;
            grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
            max-width: 1300px;
            height: min(760px, calc(100vh - 205px));
            min-height: 480px;
            margin: 20px auto;
            background: #fff;
            border: 1px solid #dfe3e5;
            box-shadow: 0 2px 8px #111b2114;
        }
        .chat-sidebar { min-width: 0; border-right: 1px solid #e9edef; }
        .chat-sidebar-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px 12px;
        }
        .chat-sidebar-heading h2 { margin: 0; font-size: 20px; }
        .chat-search-wrap { padding: 4px 12px 12px; }
        .chat-search {
            width: 100%;
            margin: 0;
            padding: 10px 14px;
            border: 0;
            border-radius: 8px;
            background: #f0f2f5;
        }
        .chat-contacts { height: calc(100% - 104px); overflow-y: auto; }
        .chat-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 76px;
            padding: 12px 16px;
            border-top: 1px solid #f0f2f5;
            color: inherit;
            text-decoration: none;
        }
        .chat-link:hover, .chat-link.active { background: #f0f2f5; }
        .chat-avatar {
            display: grid;
            flex: 0 0 48px;
            width: 48px;
            height: 48px;
            place-items: center;
            border-radius: 50%;
            background: #dfe7e9;
            color: #41545b;
            font-size: 20px;
            font-weight: 600;
        }
        .chat-link-content { min-width: 0; flex: 1; }
        .chat-link-topline { display: flex; justify-content: space-between; gap: 8px; }
        .chat-link-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chat-link-time { color: #667781; font-size: 11px; white-space: nowrap; }
        .chat-link-preview {
            display: block;
            overflow: hidden;
            margin-top: 6px;
            color: #667781;
            font-size: 13px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chat-empty-list { padding: 16px; color: #667781; font-size: 14px; }
        .chat-conversation { display: flex; min-width: 0; flex-direction: column; }
        .chat-conversation-header {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 72px;
            padding: 10px 18px;
            border-bottom: 1px solid #e9edef;
            background: #f0f2f5;
        }
        .chat-conversation-header h2 { margin: 0; font-size: 16px; }
        .chat-conversation-header p { margin: 4px 0 0; color: #667781; font-size: 12px; }
        .chat-back {
            display: none;
            color: #008069;
            font-size: 22px;
            text-decoration: none;
        }
        .chat-messages {
            display: flex;
            flex: 1;
            min-height: 0;
            flex-direction: column;
            gap: 3px;
            overflow-y: auto;
            padding: 20px clamp(14px, 5vw, 64px);
            background-color: #efeae2;
        }
        .chat-no-messages {
            align-self: center;
            margin: auto;
            padding: 9px 14px;
            border-radius: 8px;
            background: #fff;
            color: #667781;
            font-size: 13px;
            text-align: center;
        }
        .chat-message-row { display: flex; width: 100%; }
        .chat-message-row.mine { justify-content: flex-end; }
        .chat-message-row.received { justify-content: flex-start; }
        .chat-message {
            max-width: min(76%, 620px);
            padding: 8px 10px 6px;
            border-radius: 8px;
            box-shadow: 0 1px 1px #0b141a1a;
            background: #fff;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }
        .chat-message-row.mine .chat-message {
            background: #d9fdd3;
            border-top-right-radius: 2px;
        }
        .chat-message-row.received .chat-message { border-top-left-radius: 2px; }
        .chat-message-text { line-height: 1.45; }
        .chat-message time {
            display: block;
            margin-top: 4px;
            color: #667781;
            font-size: 10px;
            text-align: right;
        }
        .chat-composer {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            padding: 12px 16px;
            background: #f0f2f5;
        }
        .chat-form { display: flex; flex: 1; align-items: flex-end; gap: 10px; }
        .chat-form textarea {
            flex: 1;
            min-height: 44px;
            max-height: 120px;
            margin: 0;
            padding: 12px 16px;
            resize: none;
            border: 0;
            border-radius: 9px;
            background: #fff;
        }
        .chat-form button { min-height: 44px; padding: 10px 16px; background: #008069; }
        .chat-send-status { padding: 0 16px 8px; color: #a33; font-size: 13px; }
        .chat-start-panel {
            display: grid;
            flex: 1;
            place-content: center;
            padding: 28px;
            background: #f7f9fa;
            color: #667781;
            text-align: center;
        }
        .chat-start-panel h2 { color: #41545b; font-weight: 400; }
        .chat-hidden { display: none !important; }
        @media (max-width: 760px) {
            .chat-topbar { padding: 12px 16px; }
            .chat-topbar h1 { font-size: 19px; }
            .chat-shell {
                display: block;
                height: calc(100dvh - 150px);
                min-height: 400px;
                margin: 0;
                border: 0;
            }
            .chat-sidebar, .chat-conversation { height: 100%; }
            .chat-conversation { display: none; }
            body.chat-has-room .chat-sidebar { display: none; }
            body.chat-has-room .chat-conversation { display: flex; }
            .chat-back { display: inline-block; }
            .chat-messages { padding: 16px 12px; }
            .chat-message { max-width: 88%; }
            .chat-composer { padding: 8px; }
            .chat-form { gap: 6px; }
            .chat-form button { padding: 10px 12px; }
        }
    </style>
</head>
<body class="<?= $room ? 'chat-has-room' : '' ?>">

<header class="chat-topbar">
    <h1>💬 Mencari Makan</h1>
    <p>Chat langsung antara pembeli dan pemilik toko.</p>
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

<main class="chat-shell <?= $room ? 'has-room' : '' ?>">
    <aside class="chat-sidebar">
        <div class="chat-sidebar-heading">
            <h2>Percakapan</h2>
        </div>
        <?php if ($rooms): ?>
            <div class="chat-search-wrap">
                <input class="chat-search" type="search" data-chat-search
                       placeholder="Cari percakapan" aria-label="Cari percakapan">
            </div>
        <?php endif; ?>
        <div class="chat-contacts">
            <?php if (!$rooms): ?>
                <p class="chat-empty-list">
                    <?php if ($isBuyer): ?>
                        Belum ada percakapan. Buka halaman toko dan pilih Chat Penjual untuk memulai.
                    <?php else: ?>
                        Belum ada percakapan. Chat pembeli akan tampil di sini setelah mereka menghubungi toko.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php foreach ($rooms as $item): ?>
                    <?php
                    $lastMessage = (string) ($item['pesan_terakhir'] ?? '');
                    $lastMessageTime = $item['pesan_terakhir_at'] ?? null;
                    ?>
                    <a
                        class="chat-link <?= (
                            $room && (int) $room['id'] === (int) $item['id']
                        ) ? 'active' : '' ?>"
                        href="chat.php?room_id=<?= (int) $item['id'] ?>"
                        data-chat-search-item
                        data-chat-name="<?= e($item['nama_lawan']) ?>"
                        data-chat-preview="<?= e($lastMessage) ?>"
                    >
                        <span class="chat-avatar" aria-hidden="true"><?= e(chatInitial($item['nama_lawan'])) ?></span>
                        <span class="chat-link-content">
                            <span class="chat-link-topline">
                                <strong class="chat-link-name"><?= e($item['nama_lawan']) ?></strong>
                                <time class="chat-link-time"><?= e(chatTime($lastMessageTime)) ?></time>
                            </span>
                            <span class="chat-link-preview">
                                <?= $lastMessage !== '' ? e($lastMessage) : 'Mulai percakapan' ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
                <p class="chat-empty-list chat-no-search-results chat-hidden" data-chat-no-results>
                    Percakapan tidak ditemukan.
                </p>
            <?php endif; ?>
        </div>
    </aside>

    <section class="chat-conversation"
             <?= $room ? 'data-chat-conversation data-current-user="' . $userId . '" data-room-id="' . (int) $room['id'] . '"' : '' ?>>
        <?php if (!$room): ?>
            <div class="chat-start-panel">
                <h2>Chat Mencari Makan</h2>
                <p>Pilih percakapan di sebelah kiri untuk membaca dan membalas pesan.</p>
            </div>
        <?php else: ?>
            <?php $counterpartName = $isOwner ? $room['nama_pembeli'] : $room['nama_toko']; ?>
            <div class="chat-conversation-header">
                <a class="chat-back" href="chat.php" aria-label="Kembali ke daftar chat">‹</a>
                <span class="chat-avatar" aria-hidden="true"><?= e(chatInitial($counterpartName)) ?></span>
                <div>
                    <h2><?= e($counterpartName) ?></h2>
                    <p><?= $isOwner ? 'Pembeli' : 'Pemilik toko' ?></p>
                </div>
            </div>

            <div class="chat-messages" data-chat-messages
                 data-poll-url="chat.php?room_id=<?= (int) $room['id'] ?>&amp;poll=1">
                <?php if (!$messages): ?>
                    <p class="chat-no-messages" data-chat-empty>
                        Belum ada pesan. Kirim pesan untuk memulai percakapan.
                    </p>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="chat-message-row <?= (
                            (int) $msg['sender_id'] === $userId
                        ) ? 'mine' : 'received' ?>"
                             data-message-id="<?= (int) $msg['id'] ?>">
                            <div class="chat-message">
                                <div class="chat-message-text"><?= e($msg['message']) ?></div>
                                <time datetime="<?= e($msg['created_at']) ?>"><?= e(chatTime($msg['created_at'])) ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($error !== ''): ?>
                <div class="chat-send-status" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <div class="chat-composer">
                <form class="chat-form" method="post"
                      action="chat.php?room_id=<?= (int) $room['id'] ?>"
                      data-chat-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                    <textarea id="message" name="message" maxlength="2000" required
                              aria-label="Tulis pesan"
                              placeholder="Ketik pesan (Enter untuk mengirim)"
                              data-chat-input></textarea>
                    <button type="submit" data-chat-send>Kirim</button>
                </form>
            </div>
            <div class="chat-send-status" role="status" aria-live="polite" data-chat-send-status></div>
        <?php endif; ?>
    </section>
</main>

<?php if ($room): ?>
<script>
(() => {
    const conversation = document.querySelector('[data-chat-conversation]');
    const messages = conversation.querySelector('[data-chat-messages]');
    const form = conversation.querySelector('[data-chat-form]');
    const input = conversation.querySelector('[data-chat-input]');
    const status = conversation.querySelector('[data-chat-send-status]');
    const currentUserId = Number(conversation.dataset.currentUser);
    const roomId = Number(conversation.dataset.roomId);
    let lastMessageId = Math.max(
        0,
        ...Array.from(messages.querySelectorAll('[data-message-id]'))
            .map((item) => Number(item.dataset.messageId))
    );

    function scrollToLatest() {
        messages.scrollTop = messages.scrollHeight;
    }

    function appendMessage(message) {
        if (document.querySelector(`[data-message-id="${Number(message.id)}"]`)) return;
        messages.querySelector('[data-chat-empty]')?.remove();

        const row = document.createElement('div');
        row.className = `chat-message-row ${
            Number(message.sender_id) === currentUserId ? 'mine' : 'received'
        }`;
        row.dataset.messageId = String(message.id);

        const bubble = document.createElement('div');
        bubble.className = 'chat-message';
        const text = document.createElement('div');
        text.className = 'chat-message-text';
        text.textContent = message.message;
        const time = document.createElement('time');
        const date = new Date(String(message.created_at).replace(' ', 'T'));
        time.textContent = Number.isNaN(date.getTime())
            ? ''
            : date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        bubble.append(text, time);
        row.append(bubble);
        messages.append(row);
        lastMessageId = Math.max(lastMessageId, Number(message.id));
        scrollToLatest();
    }

    scrollToLatest();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!input.value.trim()) {
            input.focus();
            return;
        }

        const sendButton = form.querySelector('[data-chat-send]');
        sendButton.disabled = true;
        status.textContent = 'Mengirim pesan...';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Pesan gagal dikirim.');
            }
            appendMessage(result.message);
            input.value = '';
            status.textContent = '';
        } catch (error) {
            status.textContent = error.message || 'Koneksi gagal. Pesan belum terkirim.';
        } finally {
            sendButton.disabled = false;
            input.focus();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    async function refreshMessages() {
        try {
            const response = await fetch(
                `${messages.dataset.pollUrl}&after_id=${lastMessageId}`,
                { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
            );
            if (!response.ok) {
                throw new Error('Pesan baru belum dapat dimuat.');
            }
            const result = await response.json();
            result.messages.forEach(appendMessage);
        } catch (error) {
            status.textContent = error.message || 'Koneksi chat terputus. Coba muat ulang halaman.';
        }
    }

    window.setInterval(refreshMessages, 3000);
})();

const chatSearch = document.querySelector('[data-chat-search]');
chatSearch?.addEventListener('input', () => {
    const query = chatSearch.value.trim().toLocaleLowerCase('id-ID');
    let visibleCount = 0;
    document.querySelectorAll('[data-chat-search-item]').forEach((item) => {
        const haystack = `${item.dataset.chatName} ${item.dataset.chatPreview}`
            .toLocaleLowerCase('id-ID');
        const isVisible = haystack.includes(query);
        item.classList.toggle('chat-hidden', !isVisible);
        if (isVisible) visibleCount += 1;
    });
    document.querySelector('[data-chat-no-results]')
        ?.classList.toggle('chat-hidden', visibleCount > 0);
});
</script>
<?php else: ?>
<script>
const chatSearch = document.querySelector('[data-chat-search]');
chatSearch?.addEventListener('input', () => {
    const query = chatSearch.value.trim().toLocaleLowerCase('id-ID');
    let visibleCount = 0;
    document.querySelectorAll('[data-chat-search-item]').forEach((item) => {
        const haystack = `${item.dataset.chatName} ${item.dataset.chatPreview}`
            .toLocaleLowerCase('id-ID');
        const isVisible = haystack.includes(query);
        item.classList.toggle('chat-hidden', !isVisible);
        if (isVisible) visibleCount += 1;
    });
    document.querySelector('[data-chat-no-results]')
        ?.classList.toggle('chat-hidden', visibleCount > 0);
});
</script>
<?php endif; ?>
</body>
</html>
