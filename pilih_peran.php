<?php
// =============================================
// PILIH PERAN - MENCARI MAKAN
// =============================================

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();

$pesanError = '';
$namaUser = $_SESSION['nama'] ?? 'Pengguna';

// =============================================
// PROSES PEMILIHAN PERAN
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = $_POST['peran'] ?? '';

    $map = [
        'pembeli' => 'pencari',
        'penjual' => 'pemilik'
    ];

    if (!isset($map[$choice])) {
        $pesanError = 'Pilihan peran tidak valid.';
    } else {
        $peranBaru = $map[$choice];
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId < 1) {
            header('Location: login.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE users SET peran = ? WHERE id = ?'
            );

            $stmt->execute([$peranBaru, $userId]);

            if ($stmt->rowCount() === 0) {
                // Pastikan akun memang masih tersedia.
                $cek = $pdo->prepare(
                    'SELECT id FROM users WHERE id = ? LIMIT 1'
                );
                $cek->execute([$userId]);

                if (!$cek->fetchColumn()) {
                    $pesanError = 'Akun tidak ditemukan.';
                }
            }

            if ($pesanError === '') {
                $_SESSION['peran'] = $peranBaru;

                if ($peranBaru === 'pemilik') {
                    header('Location: profil_penjual.php');
                } else {
                    header('Location: index.php');
                }

                exit;
            }
        } catch (PDOException $e) {
            error_log('Kesalahan pilih_peran: ' . $e->getMessage());
            $pesanError =
                'Pilihan peran gagal disimpan. Periksa koneksi database.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Pilih Peran - Mencari Makan</title>

    <link rel="stylesheet" href="style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f7f6;
            color: #24312e;
            font-family: Arial, sans-serif;
        }

        header {
            background: #0f766e;
            color: white;
            padding: 24px 5%;
        }

        header h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        header p {
            margin: 0;
            line-height: 1.6;
        }

        main {
            width: 100%;
            max-width: 900px;
            margin: 35px auto;
            padding: 0 16px;
        }

        .panel {
            padding: 28px;
            background: white;
            border: 1px solid #e1e9e5;
            border-radius: 16px;
            box-shadow: 0 5px 18px #00000008;
            text-align: center;
        }

        .panel h2 {
            margin-top: 0;
        }

        .muted {
            color: #64756f;
            line-height: 1.6;
        }

        .pesan-error {
            padding: 12px 15px;
            margin: 18px 0;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            color: #991b1b;
            text-align: left;
        }

        .pilihan-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .pilihan {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-width: 0;
            padding: 28px 20px;
            border: 1px solid #dce7e2;
            border-radius: 14px;
            background: white;
            color: #24312e;
            font-family: inherit;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s,
                        border-color .2s;
        }

        .pilihan:hover {
            transform: translateY(-3px);
            border-color: #0f766e;
            box-shadow: 0 8px 22px #0f766e18;
        }

        .ikon {
            margin-bottom: 10px;
            font-size: 48px;
        }

        .pilihan h3 {
            margin: 8px 0;
            font-size: 22px;
        }

        .pilihan p {
            min-height: 48px;
            color: #64756f;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            margin-top: 10px;
            padding: 11px 16px;
            border: 0;
            border-radius: 8px;
            background: #0f766e;
            color: white;
            font-weight: bold;
        }

        footer {
            padding: 24px;
            color: #64756f;
            text-align: center;
        }

        @media (max-width: 600px) {
            header {
                padding: 22px 16px;
            }

            header h1 {
                font-size: 24px;
            }

            .panel {
                padding: 20px 14px;
            }

            .pilihan-grid {
                grid-template-columns: 1fr;
            }

            .pilihan p {
                min-height: auto;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>🍽️ Mencari Makan</h1>

    <p>
        Halo, <?= e($namaUser) ?>.
        Silakan pilih peran yang ingin kamu gunakan.
    </p>
</header>

<main>
    <section class="panel">
        <h2>Kamu ingin menjadi apa?</h2>

        <p class="muted">
            Pilih pengalaman yang sesuai dengan kebutuhanmu.
        </p>

        <?php if ($pesanError !== ''): ?>
            <div class="pesan-error" role="alert">
                <?= e($pesanError) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="pilih_peran.php">
            <div class="pilihan-grid">

                <button
                    type="submit"
                    name="peran"
                    value="pembeli"
                    class="pilihan"
                >
                    <span class="ikon">🛍️</span>

                    <h3>Pembeli</h3>

                    <p>
                        Cari toko, lihat menu makanan,
                        pesan makanan, dan chat penjual.
                    </p>

                    <span class="btn">Pilih Pembeli</span>
                </button>

                <button
                    type="submit"
                    name="peran"
                    value="penjual"
                    class="pilihan"
                >
                    <span class="ikon">🏪</span>

                    <h3>Penjual</h3>

                    <p>
                        Buat profil toko, tambahkan menu,
                        dan kelola pesanan pelanggan.
                    </p>

                    <span class="btn">Pilih Penjual</span>
                </button>

            </div>
        </form>
    </section>
</main>

<footer>
    © 2026 Mencari Makan
</footer>

</body>
</html>