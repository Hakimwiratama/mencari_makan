
<?php
require_once __DIR__ . '/config.php';

// Jika sudah login, arahkan ke beranda
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$nama = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi'] ?? '';

    // Validasi data
    if ($nama === '' || $email === '' ||
        $password === '' || $konfirmasi === '') {
        $error = 'Semua kolom wajib diisi.';
    } elseif (strlen($nama) > 100) {
        $error = 'Nama maksimal 100 karakter.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($email) > 150) {
        $error = 'Email maksimal 150 karakter.';
    } elseif (strlen($password) < 8) {
        $error = 'Kata sandi minimal 8 karakter.';
    } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi kata sandi tidak cocok.';
    } else {
        try {
            // Periksa apakah email sudah terdaftar
            $stmt = $pdo->prepare(
                'SELECT id FROM users WHERE email = ?'
            );
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'Email sudah terdaftar. Silakan login.';
            } else {
                // Hash kata sandi sebelum disimpan
                $hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                    (nama, email, password, peran)
                    VALUES (?, ?, ?, ?)'
                );

                $stmt->execute([
                    $nama,
                    $email,
                    $hash,
                    'pencari'
                ]);

                // Registrasi berhasil, lanjut ke login
                header('Location: login.php?daftar=berhasil');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Registrasi gagal: ' . $e->getMessage());
            $error = 'Registrasi gagal. Silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Registrasi - Mencari Makan</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1>🍽️ Mencari Makan</h1>
        <p>Buat akun dan temukan makanan favoritmu!</p>
    </header>

    <main>
        <section class="pencarian">
            <h2>Daftar Akun</h2>

            <?php if ($error !== ''): ?>
                <p style="color: #b42318;">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <p>
                    <label for="nama">Nama lengkap</label><br>
                    <input
                        id="nama"
                        type="text"
                        name="nama"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $nama,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="Masukkan nama lengkap"
                        autocomplete="name"
                        required>
                </p>

                <p>
                    <label for="email">Email</label><br>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="Masukkan email"
                        autocomplete="email"
                        required>
                </p>

                <p>
                    <label for="password">Kata sandi</label><br>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        minlength="8"
                        placeholder="Minimal 8 karakter"
                        autocomplete="new-password"
                        required>
                </p>

                <p>
                    <label for="konfirmasi">
                        Konfirmasi kata sandi
                    </label><br>
                    <input
                        id="konfirmasi"
                        type="password"
                        name="konfirmasi"
                        minlength="8"
                        placeholder="Ulangi kata sandi"
                        autocomplete="new-password"
                        required>
                </p>

                <button type="submit">Daftar Sekarang</button>
            </form>

            <p>
                Sudah punya akun?
                <a href="login.php">Login di sini</a>
            </p>

            <p>
                <a href="index.html">
                    Kembali ke pencarian tempat makan
                </a>
            </p>
        </section>
    </main>
</body>
</html>
