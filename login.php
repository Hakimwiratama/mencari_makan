
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Jika sudah login, jangan tampilkan halaman login lagi.
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

// Akun demo hanya untuk pengembangan lokal.
// Hapus atau nonaktifkan bagian ini saat website dipublikasikan.
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', [
    '127.0.0.1',
    '::1'
], true);

if ($isLocal) {
    $demoEmail = 'demo@mencarimakan.test';
    $demoPassword = 'demo1234';

    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$demoEmail]);

    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (nama, email, password, peran)
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            'Pengguna Demo',
            $demoEmail,
            password_hash($demoPassword, PASSWORD_DEFAULT),
            'pencari'
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Masukkan alamat email yang valid.';
    } elseif (!is_string($password) || $password === '') {
        $error = 'Kata sandi wajib diisi.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, nama, email, password, peran
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $user &&
            password_verify($password, $user['password'])
        ) {
            session_regenerate_id(true);

            // Simpan data sementara sebelum verifikasi OTP.
            $_SESSION['pending_user'] = [
                'id'    => (int) $user['id'],
                'nama'  => $user['nama'],
                'email' => $user['email'],
                'peran' => $user['peran']
            ];

            $_SESSION['otp'] = (string) random_int(100000, 999999);
            $_SESSION['otp_expiry'] = time() + 300;

            header('Location: verify.php');
            exit;
        }

        // Pesan umum supaya tidak membocorkan apakah email terdaftar.
        $error = 'Email atau kata sandi salah.';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Mencari Makan</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>🍽️ Mencari Makan</h1>
    <p>Masuk untuk melanjutkan.</p>
</header>

<main>
    <section class="panel" style="max-width:520px;margin:30px auto">
        <h2>Login</h2>

        <?php if ($error !== ''): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($email) ?>"
                autocomplete="email"
                required
            >

            <label for="password">Kata sandi</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Login</button>
        </form>

        <?php if ($isLocal): ?>
            <p class="muted">
                Akun demo lokal:<br>
                Email: demo@mencarimakan.test<br>
                Kata sandi: demo1234
            </p>
        <?php endif; ?>

        <p>
            Belum punya akun?
            <a href="register.php">Daftar sekarang</a>
        </p>
    </section>
</main>

</body>
</html>
