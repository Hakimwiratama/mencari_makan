
<?php
require_once __DIR__ . '/config.php';
$maptilerApiKey = getenv('MAPTILER_API_KEY') ?: '';

// Helper untuk mengamankan output HTML.
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

// Pastikan pengguna sudah login.
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['peran'] ?? '') !== 'pemilik') {
    header('Location: pilih_peran.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$error = '';
$sukses = isset($_GET['sukses']);
$wilayahError = '';
$provinceById = [];
$regionById = [];
$regionId = '';
$selectedProvinceId = '';
$selectedIsland = '';
$wilayahByIsland = [
    'Sumatera' => [
        'Aceh',
        'Sumatera Utara',
        'Sumatera Barat',
        'Riau',
        'Jambi',
        'Sumatera Selatan',
        'Bengkulu',
        'Lampung',
        'Kepulauan Bangka Belitung',
        'Kepulauan Riau'
    ],
    'Jawa' => [
        'Daerah Khusus Ibukota Jakarta',
        'Jawa Barat',
        'Jawa Tengah',
        'Daerah Istimewa Yogyakarta',
        'Jawa Timur',
        'Banten'
    ],
    'Bali dan Nusa Tenggara' => [
        'Bali',
        'Nusa Tenggara Barat',
        'Nusa Tenggara Timur'
    ],
    'Kalimantan' => [
        'Kalimantan Barat',
        'Kalimantan Tengah',
        'Kalimantan Selatan',
        'Kalimantan Timur',
        'Kalimantan Utara'
    ],
    'Sulawesi' => [
        'Sulawesi Utara',
        'Sulawesi Tengah',
        'Sulawesi Selatan',
        'Sulawesi Tenggara',
        'Gorontalo',
        'Sulawesi Barat'
    ],
    'Maluku' => [
        'Maluku',
        'Maluku Utara'
    ],
    'Papua' => [
        'Papua',
        'Papua Barat',
        'Papua Selatan',
        'Papua Tengah',
        'Papua Pegunungan',
        'Papua Barat Daya'
    ]
];

try {
    $wilayahJson = file_get_contents(__DIR__ . '/wilayah-indonesia.json');
    if ($wilayahJson === false) {
        throw new RuntimeException('File data wilayah tidak dapat dibaca.');
    }

    $wilayahData = json_decode($wilayahJson, true, 512, JSON_THROW_ON_ERROR);
    if (
        !isset($wilayahData['provinces'], $wilayahData['regencies']) ||
        !is_array($wilayahData['provinces']) ||
        !is_array($wilayahData['regencies'])
    ) {
        throw new RuntimeException('Format data wilayah tidak valid.');
    }

    foreach ($wilayahData['regencies'] as $region) {
        if (
            !isset(
                $region['province_id'],
                $region['province_name'],
                $region['id'],
                $region['name'],
                $region['latitude'],
                $region['longitude']
            )
        ) {
            continue;
        }
        $regionById[(string) $region['id']] = $region;
    }

    foreach ($wilayahData['provinces'] as $province) {
        $provinceById[(string) $province['id']] = $province;
    }

    $regionId = (string) ($_POST['region_id'] ?? '');
    $selectedRegion = $regionById[$regionId] ?? null;

    $selectedProvinceId = $selectedRegion
        ? (string) $selectedRegion['province_id']
        : '';
    $selectedProvince = $provinceById[$selectedProvinceId] ?? null;
    $selectedIsland = '';
    if ($selectedProvince) {
        foreach ($wilayahByIsland as $island => $provinceNames) {
            if (in_array($selectedProvince['name'], $provinceNames, true)) {
                $selectedIsland = $island;
                break;
            }
        }
    }

    foreach ($wilayahData['provinces'] as $province) {
        $islandFound = false;
        foreach ($wilayahByIsland as $provinceNames) {
            if (in_array($province['name'], $provinceNames, true)) {
                $islandFound = true;
                break;
            }
        }
        if (!$islandFound) {
            throw new RuntimeException(
                'Provinsi tidak termasuk dalam kelompok pulau: ' . $province['name']
            );
        }
    }
} catch (Throwable $ex) {
    error_log('Daftar kota/kabupaten gagal dimuat: ' . $ex->getMessage());
    $wilayahError = 'Daftar kota/kabupaten gagal dimuat. Lokasi tetap dapat dipilih langsung pada peta.';
    $wilayahData = ['provinces' => [], 'regencies' => []];
    $provinceById = [];
    $regionById = [];
}

// Token untuk melindungi form dari CSRF.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Mengunggah foto profil toko.
 * Format yang diperbolehkan: JPG, PNG, WEBP.
 * Maksimal ukuran: 5 MB.
 */
function uploadFotoToko($field)
{
    if (
        !isset($_FILES[$field]) ||
        $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Gagal mengunggah foto. Silakan coba kembali.'
        );
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException(
            'Ukuran foto maksimal 5 MB.'
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(
            'Berkas foto tidak valid.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $format = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($format[$mime])) {
        throw new RuntimeException(
            'Gunakan foto berformat JPG, PNG, atau WEBP.'
        );
    }

    $direktori = __DIR__ . '/uploads';

    if (
        !is_dir($direktori) &&
        !mkdir($direktori, 0755, true) &&
        !is_dir($direktori)
    ) {
        throw new RuntimeException(
            'Folder uploads gagal dibuat.'
        );
    }

    $namaFile = 'toko_' .
        bin2hex(random_bytes(16)) .
        '.' . $format[$mime];

    $tujuan = $direktori . '/' . $namaFile;

    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
        throw new RuntimeException(
            'Foto gagal disimpan ke folder uploads.'
        );
    }

    return 'uploads/' . $namaFile;
}

// Ambil profil toko milik pengguna yang sedang login.
$stmt = $pdo->prepare("
    SELECT id, owner_id, nama, alamat, latitude, longitude, wilayah_id, deskripsi, foto_profil
    FROM restaurants
    WHERE owner_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);

$toko = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    $toko &&
    !empty($toko['wilayah_id'])
) {
    $regionId = (string) $toko['wilayah_id'];
    $selectedRegion = $regionById[$regionId] ?? null;
    if ($selectedRegion) {
        $selectedProvinceId = (string) $selectedRegion['province_id'];
        $selectedProvince = $provinceById[$selectedProvinceId] ?? null;
        foreach ($wilayahByIsland as $island => $provinceNames) {
            if (
                $selectedProvince &&
                in_array($selectedProvince['name'], $provinceNames, true)
            ) {
                $selectedIsland = $island;
                break;
            }
        }
    }
}

// Proses penyimpanan profil toko.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new RuntimeException(
                'Sesi formulir tidak valid. Muat ulang halaman.'
            );
        }

        $nama = trim($_POST['nama'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $latitudeInput = trim($_POST['latitude'] ?? '');
        $longitudeInput = trim($_POST['longitude'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        if ($nama === '') {
            throw new RuntimeException(
                'Nama toko wajib diisi.'
            );
        }

        $regionId = trim($_POST['region_id'] ?? '');
        $selectedRegion = $regionById[$regionId] ?? null;
        if (!$selectedRegion) {
            throw new RuntimeException(
                'Pilih pulau, provinsi, dan kota/kabupaten yang valid.'
            );
        }
        $selectedProvince = $provinceById[
            (string) $selectedRegion['province_id']
        ] ?? null;
        if (!$selectedProvince) {
            throw new RuntimeException(
                'Provinsi untuk kota/kabupaten ini tidak ditemukan.'
            );
        }

        foreach ([$selectedRegion['name'], $selectedProvince['name'], 'Indonesia'] as $index => $area) {
            $areaSearchName = $index === 0
                ? preg_replace('/^(Kabupaten|Kota Administrasi|Kota)\s+/i', '', $area)
                : $area;
            if (mb_stripos($alamat, $areaSearchName, 0, 'UTF-8') === false) {
                $alamat .= ', ' . $area;
            }
        }

        if ($alamat === '') {
            throw new RuntimeException(
                'Alamat gagal dibuat. Isi alamat jalan atau pilih kembali titik peta.'
            );
        }

        if (mb_strlen($nama) > 150) {
            throw new RuntimeException(
                'Nama toko maksimal 150 karakter.'
            );
        }

        if (mb_strlen($alamat) > 255) {
            throw new RuntimeException(
                'Alamat beserta kota/kabupaten dan provinsi maksimal 255 karakter. Persingkat alamat jalan.'
            );
        }

        if (($latitudeInput === '') !== ($longitudeInput === '')) {
            throw new RuntimeException(
                'Pilih titik lokasi toko pada peta.'
            );
        }

        if ($latitudeInput === '') {
            throw new RuntimeException(
                'Pilih kota/kabupaten dan klik titik lokasi toko pada peta.'
            );
        }

        $latitude = null;
        $longitude = null;

        if ($latitudeInput !== '') {
            if (
                !is_numeric($latitudeInput) ||
                !is_numeric($longitudeInput) ||
                (float) $latitudeInput < -90 ||
                (float) $latitudeInput > 90 ||
                (float) $longitudeInput < -180 ||
                (float) $longitudeInput > 180
            ) {
                throw new RuntimeException(
                    'Koordinat lokasi toko tidak valid.'
                );
            }

            $latitude = (float) $latitudeInput;
            $longitude = (float) $longitudeInput;
        }

        $fotoBaru = uploadFotoToko('foto_profil');

        if ($toko) {
            if ($fotoBaru !== null) {
                $stmt = $pdo->prepare("
                    UPDATE restaurants
                    SET nama = ?,
                        alamat = ?,
                        latitude = ?,
                        longitude = ?,
                        wilayah_id = ?,
                        deskripsi = ?,
                        foto_profil = ?
                    WHERE owner_id = ?
                ");

                $stmt->execute([
                    $nama,
                    $alamat,
                    $latitude,
                    $longitude,
                    $regionId,
                    $deskripsi,
                    $fotoBaru,
                    $userId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE restaurants
                    SET nama = ?,
                        alamat = ?,
                        latitude = ?,
                        longitude = ?,
                        wilayah_id = ?,
                        deskripsi = ?
                    WHERE owner_id = ?
                ");

                $stmt->execute([
                    $nama,
                    $alamat,
                    $latitude,
                    $longitude,
                    $regionId,
                    $deskripsi,
                    $userId
                ]);
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO restaurants
                    (owner_id, nama, alamat, latitude, longitude, wilayah_id, deskripsi, foto_profil)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $nama,
                $alamat,
                $latitude,
                $longitude,
                $regionId,
                $deskripsi,
                $fotoBaru
            ]);
        }

        // Regenerasi token setelah berhasil menyimpan.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: kelola_menu.php?toko=tersimpan');
        exit;

    } catch (Throwable $ex) {
        // Catat kesalahan untuk pemeriksaan di log server.
        error_log(
            'Kesalahan profil toko: ' . $ex->getMessage()
        );

        // Tampilkan pesan validasi yang aman kepada pengguna.
        if ($ex instanceof RuntimeException) {
            $error = $ex->getMessage();
        } else {
            $error = 'Profil toko gagal disimpan. Periksa database dan coba kembali.';
        }
    }

    // Ambil ulang profil setelah proses POST.
    $stmt = $pdo->prepare("
        SELECT id, owner_id, nama, alamat, latitude, longitude, wilayah_id, deskripsi, foto_profil
        FROM restaurants
        WHERE owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $toko = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Hitung jumlah menu dan pesanan.
$jumlahMenu = 0;
$jumlahMenunggu = 0;
$jumlahDiterima = 0;

if ($toko) {
    $restaurantId = (int) $toko['id'];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM restaurant_menus
        WHERE restaurant_id = ?
    ");
    $stmt->execute([$restaurantId]);

    $jumlahMenu = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(
                SUM(CASE
                    WHEN status = 'menunggu' THEN 1
                    ELSE 0
                END),
                0
            ) AS menunggu,
            COALESCE(
                SUM(CASE
                    WHEN status = 'diterima' THEN 1
                    ELSE 0
                END),
                0
            ) AS diterima
        FROM orders
        WHERE restaurant_id = ?
    ");
    $stmt->execute([$restaurantId]);

    $pesanan = $stmt->fetch(PDO::FETCH_ASSOC);

    $jumlahMenunggu = (int) $pesanan['menunggu'];
    $jumlahDiterima = (int) $pesanan['diterima'];
}

$namaPemilik = $_SESSION['nama'] ?? 'Pemilik Toko';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="dashboard.css">

    <title>Profil Penjual - Mencari Makan</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="">

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
            padding: 22px max(18px, calc((100% - 1100px) / 2));
        }

        header h1 {
            margin: 0 0 8px;
        }

        header p {
            line-height: 1.6;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px;
            margin-top: 16px;
            border: 1px solid #ffffff55;
            border-radius: 12px;
            background: #ffffff12;
        }

        nav a {
            display: inline-block;
            padding: 10px 13px;
            border: 1px solid rgba(255, 255, 255, .4);
            background: white;
            color: #0f766e;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            transition: background .2s, transform .2s;
        }

        nav a:hover {
            background: #d9f4ee;
            transform: translateY(-1px);
        }

        nav a.logout {
            color: #0f766e;
        }

        main {
            max-width: 1100px;
            margin: 26px auto;
            padding: 0 16px;
        }

        .panel {
            background: white;
            border: 1px solid #e1e9e3;
            border-radius: 15px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 5px 18px #00000006;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 20px;
            margin-bottom: 22px;
            border-radius: 15px;
            background: linear-gradient(120deg, #0f766e, #115e59);
            color: white;
            overflow-wrap: anywhere;
        }

        .avatar {
            width: 100px;
            height: 100px;
            flex: 0 0 100px;
            border: 3px solid white;
            border-radius: 14px;
            object-fit: cover;
            background: #e5e7eb;
        }

        .profile h2 {
            margin: 0 0 10px;
        }

        .profile p {
            margin: 7px 0;
            line-height: 1.5;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(190px, 1fr)
            );
            gap: 15px;
            margin-top: 18px;
        }

        .stat-card {
            display: flex;
            flex-direction: column;
            gap: 9px;
            min-width: 0;
            padding: 19px;
            border: 1px solid #ccfbf1;
            border-radius: 12px;
            background: #f0fdfa;
            color: #0f766e;
            text-decoration: none;
            transition: .2s;
        }

        .stat-card:hover {
            background: #ccfbf1;
            transform: translateY(-2px);
        }

        .stat-card .emoji {
            font-size: 27px;
        }

        .stat-card strong {
            font-size: 29px;
        }

        .stat-card small {
            font-size: 14px;
            line-height: 1.5;
        }

        .field {
            display: block;
            margin-top: 16px;
            font-weight: bold;
        }

        input,
        textarea {
            width: 100%;
            margin-top: 7px;
            padding: 12px;
            border: 1px solid #cbd5ce;
            border-radius: 8px;
            font: inherit;
            background: white;
        }

        input:focus,
        textarea:focus {
            outline: 2px solid #5eead4;
            border-color: #0f766e;
        }

        textarea {
            min-height: 95px;
            resize: vertical;
        }

        input[type="file"] {
            padding: 10px;
        }

        .hint {
            color: #64756f;
            font-size: 13px;
            line-height: 1.6;
        }

        .btn,
        button {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            background: #0f766e;
            color: white;
            font: inherit;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover,
        button:hover {
            background: #115e59;
        }

        .btn.secondary {
            background: #e5eeea;
            color: #24554a;
        }

        .btn.secondary:hover {
            background: #d1e2da;
        }

        .message {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 9px;
            line-height: 1.6;
        }

        .ok {
            background: #dcfce7;
            color: #166534;
        }

        .err {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            padding: 18px;
            border-radius: 10px;
            background: #f8faf8;
            line-height: 1.7;
        }

        footer {
            padding: 25px 16px;
            text-align: center;
            color: #64756f;
        }

        @media (max-width: 600px) {
            header {
                padding: 20px 16px;
            }

            header h1 {
                font-size: 24px;
            }

            nav {
                gap: 7px;
                padding: 9px;
            }

            nav a {
                padding: 9px 10px;
                font-size: 12px;
            }

            .panel {
                padding: 17px;
            }

            .profile {
                align-items: flex-start;
                gap: 12px;
                padding: 14px;
            }

            .avatar {
                width: 68px;
                height: 68px;
                flex-basis: 68px;
            }

            .profile h2 {
                font-size: 18px;
            }

            .profile p {
                font-size: 13px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .btn,
            button {
                text-align: center;
            }
        }
    </style>
</head>

<body>

<header class="seller-dashboard-header">
    <h1>🏪 Profil Penjual</h1>

    <p>
        Halo, <?= e($namaPemilik) ?>!
        Kelola toko dan pantau pesanan pelangganmu.
    </p>

    <?php require __DIR__ . '/seller_navigation.php'; ?>
</header>

<main>

    <?php if ($sukses): ?>
        <div class="message ok">
            ✅ Profil toko berhasil disimpan.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message err">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN DASHBOARD -->
    <section class="panel">
        <h2>📊 Dashboard Penjual</h2>

        <p>
            Pantau menu dan pesanan toko melalui ringkasan berikut.
        </p>

        <div class="stats">
            <a class="stat-card" href="kelola_menu.php">
                <span class="emoji">🍽️</span>
                <strong><?= $jumlahMenu ?></strong>
                <small>Menu Terdaftar</small>
            </a>

            <a class="stat-card" href="pesanan_penjual.php">
                <span class="emoji">📦</span>
                <strong><?= $jumlahMenunggu ?></strong>
                <small>Pesanan Menunggu</small>
            </a>

            <a class="stat-card"
               href="pesanan_penjual.php?status=diterima">
                <span class="emoji">✅</span>
                <strong><?= $jumlahDiterima ?></strong>
                <small>Pesanan Diterima</small>
            </a>
        </div>

        <p>
            <a class="btn" href="index.php">
                🏠 Kembali ke Dashboard Utama
            </a>

            <a class="btn secondary" href="pesanan_penjual.php">
                📦 Kelola Pesanan
            </a>
        </p>
    </section>

    <!-- PROFIL TOKO -->
    <section class="profile">
        <?php if (!empty($toko['foto_profil'])): ?>
            <img
                class="avatar"
                src="<?= e($toko['foto_profil']) ?>"
                alt="Foto toko"
            >
        <?php else: ?>
            <div class="avatar"
                 style="display:grid;place-items:center;font-size:42px">
                🏪
            </div>
        <?php endif; ?>

        <div>
            <h2><?= e($toko['nama'] ?? 'Nama Toko Kamu') ?></h2>

            <p>👤 <?= e($namaPemilik) ?></p>

            <p>
                📍 <?= e($toko['alamat'] ?? 'Alamat belum diisi') ?>
            </p>

            <p>
                <?= e($toko['deskripsi'] ?? 'Tambahkan deskripsi toko.') ?>
            </p>
        </div>
    </section>

    <!-- FORM PROFIL -->
    <section class="panel">
        <h2>
            <?= $toko ? '✏️ Edit Profil Toko' : '🏪 Buat Profil Toko' ?>
        </h2>

        <?php if (!$toko): ?>
            <div class="empty">
                Kamu belum memiliki profil toko.
                Isi formulir di bawah agar tokomu dapat ditampilkan
                kepada pelanggan.
            </div>
        <?php endif; ?>

        <form method="post"
              action="profil_penjual.php"
              enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <label class="field" for="nama">
                Nama Toko *
            </label>

            <input
                id="nama"
                type="text"
                name="nama"
                required
                maxlength="150"
                value="<?= e(
                    $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? ($_POST['nama'] ?? '')
                        : ($toko['nama'] ?? '')
                ) ?>"
                placeholder="Contoh: Rumah Makan Minang"
            >

            <label class="field" for="alamat">
                Alamat Lengkap *
            </label>

            <textarea
                id="alamat"
                name="alamat"
                required
                maxlength="255"
                placeholder="Jalan, kelurahan, kecamatan, kota"
            ><?= e(
                $_SERVER['REQUEST_METHOD'] === 'POST'
                    ? ($_POST['alamat'] ?? '')
                    : ($toko['alamat'] ?? '')
            ) ?></textarea>
            <p class="hint">
                Alamat otomatis diisi dari titik peta dan pilihan wilayah.
                Kamu masih bisa menambahkan detail alamat atau patokan.
            </p>
            <span class="hint" data-address-status role="status" aria-live="polite"></span>

            <label class="field" for="map-picker">
                Titik Lokasi Toko
            </label>

            <p class="hint">
                Klik titik toko pada peta atau gunakan lokasi perangkat
                saat berada di toko. Lokasi ini akan ditampilkan kepada pembeli.
            </p>

            <label class="field" for="map-island-select">Pulau</label>
            <select id="map-island-select"
                    aria-describedby="map-region-hint"
                    <?= $wilayahError !== '' ? 'disabled' : '' ?>>
                <option value="">Pilih pulau...</option>
                <?php foreach (array_keys($wilayahByIsland) as $island): ?>
                    <option value="<?= e($island) ?>"
                        <?= $selectedIsland === $island ? 'selected' : '' ?>
                    ><?= e($island) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="field" for="map-province-select">Provinsi</label>
            <select id="map-province-select"
                    <?= $selectedIsland === '' || $wilayahError !== '' ? 'disabled' : '' ?>>
                <option value="">Pilih provinsi...</option>
                <?php foreach ($wilayahData['provinces'] as $province): ?>
                    <?php
                    $provinceIsland = '';
                    foreach ($wilayahByIsland as $island => $provinceNames) {
                        if (in_array($province['name'], $provinceNames, true)) {
                            $provinceIsland = $island;
                            break;
                        }
                    }
                    ?>
                    <option
                        value="<?= e($province['id']) ?>"
                        data-island="<?= e($provinceIsland) ?>"
                        <?= $selectedProvinceId === (string) $province['id'] ? 'selected' : '' ?>
                    ><?= e($province['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="field" for="map-region-select">Kota/Kabupaten</label>
            <select id="map-region-select"
                    name="region_id"
                    required
                    <?= $selectedProvinceId === '' || $wilayahError !== '' ? 'disabled' : '' ?>>
                <option value="">Pilih kota/kabupaten...</option>
                <?php foreach ($wilayahData['regencies'] as $region): ?>
                    <option
                        value="<?= e($region['id']) ?>"
                        data-province="<?= e($region['province_id']) ?>"
                        data-lat="<?= e($region['latitude']) ?>"
                        data-lng="<?= e($region['longitude']) ?>"
                        <?= $regionId === (string) $region['id'] ? 'selected' : '' ?>
                    ><?= e($region['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($wilayahError !== ''): ?>
                <div class="error" role="alert"><?= e($wilayahError) ?></div>
            <?php endif; ?>
            <p id="map-region-hint" class="hint">
                Pilih pulau, provinsi, lalu salah satu dari 514 kota/kabupaten.
                Peta otomatis menuju wilayah; klik titik toko yang tepat pada peta.
            </p>

            <div class="map-actions">
                <button type="button" class="secondary"
                        data-use-current-location="map-picker">
                    Gunakan lokasi perangkat
                </button>
                <span data-map-status role="status" aria-live="polite">
                    Pilih lokasi toko pada peta.
                </span>
            </div>

            <div
                id="map-picker"
                class="location-map location-map-picker"
                data-location-picker
                data-maptiler-key="<?= e($maptilerApiKey) ?>"
                data-lat="<?= e(
                    $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? ($_POST['latitude'] ?? '')
                        : ($toko['latitude'] ?? '')
                ) ?>"
                data-lng="<?= e(
                    $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? ($_POST['longitude'] ?? '')
                        : ($toko['longitude'] ?? '')
                ) ?>"
            ></div>

            <input
                type="hidden"
                name="latitude"
                value="<?= e(
                    $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? ($_POST['latitude'] ?? '')
                        : ($toko['latitude'] ?? '')
                ) ?>"
            >
            <input
                type="hidden"
                name="longitude"
                value="<?= e(
                    $_SERVER['REQUEST_METHOD'] === 'POST'
                        ? ($_POST['longitude'] ?? '')
                        : ($toko['longitude'] ?? '')
                ) ?>"
            >

            <label class="field" for="deskripsi">
                Deskripsi Toko
            </label>

            <textarea
                id="deskripsi"
                name="deskripsi"
                placeholder="Ceritakan menu dan keunggulan tokomu"
            ><?= e(
                $_SERVER['REQUEST_METHOD'] === 'POST'
                    ? ($_POST['deskripsi'] ?? '')
                    : ($toko['deskripsi'] ?? '')
            ) ?></textarea>

            <label class="field" for="foto_profil">
                Foto Profil Toko
            </label>

            <input
                id="foto_profil"
                type="file"
                name="foto_profil"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            >

            <p class="hint">
                Format JPG, PNG, atau WEBP. Maksimal 5 MB.
                Kosongkan jika ingin mempertahankan foto lama.
            </p>

            <button type="submit">
                💾 Simpan Profil Toko
            </button>

            <a class="btn secondary" href="index.php">
                Batal
            </a>
        </form>
    </section>

</main>

<footer>
    © 2026 Mencari Makan
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="maps.js"></script>
</body>
</html>
