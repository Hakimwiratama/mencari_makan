<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_login();
$maptilerApiKey = getenv('MAPTILER_API_KEY') ?: '';

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId < 1) {
    header('Location: login.php');
    exit;
}

// Ambil data akun terbaru dari database.
$stmt = $pdo->prepare("
    SELECT id, nama, peran
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$akun = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$akun) {
    http_response_code(403);
    exit('Akun tidak ditemukan. Silakan login kembali.');
}

$namaUser = $akun['nama'];
$peranDatabase = $akun['peran'];
$peranSession = $_SESSION['peran'] ?? '';

$peranValid = ['pemilik', 'pencari', 'pembeli'];

// Gunakan peran database jika dikenali.
if (in_array($peranDatabase, $peranValid, true)) {
    $peran = $peranDatabase;
} elseif (in_array($peranSession, $peranValid, true)) {
    $peran = $peranSession;
} else {
    http_response_code(403);
    exit('Peran akun tidak dikenali.');
}

// Perbarui session akun.
$_SESSION['nama'] = $namaUser;
$_SESSION['peran'] = $peran;

$toko = null;
$jumlahMenunggu = 0;
$jumlahDiterima = 0;
$shops = [];
$q = trim($_GET['q'] ?? '');

// =====================================================
// DATA PEMILIK TOKO
// =====================================================
if ($peran === 'pemilik') {
    $stmt = $pdo->prepare("
        SELECT id, nama
        FROM restaurants
        WHERE owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $toko = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($toko) {
        $restaurantId = (int) $toko['id'];

        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE
                    WHEN status = 'menunggu' THEN 1
                    ELSE 0
                END) AS jumlah_menunggu,
                SUM(CASE
                    WHEN status = 'diterima' THEN 1
                    ELSE 0
                END) AS jumlah_diterima
            FROM orders
            WHERE restaurant_id = ?
        ");
        $stmt->execute([$restaurantId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        $jumlahMenunggu = (int) ($counts['jumlah_menunggu'] ?? 0);
        $jumlahDiterima = (int) ($counts['jumlah_diterima'] ?? 0);
    }
}

// =====================================================
// DAFTAR TOKO UNTUK PEMBELI
// =====================================================
else {
    $sql = "
        SELECT
            r.*,
            u.nama AS nama_pemilik,
            (
                SELECT COUNT(*)
                FROM restaurant_menus m
                WHERE m.restaurant_id = r.id
                  AND m.tersedia = 1
            ) AS jumlah_menu
        FROM restaurants r
        INNER JOIN users u ON u.id = r.owner_id
    ";

    $params = [];

    if ($q !== '') {
        $sql .= "
            WHERE r.nama LIKE ?
               OR r.alamat LIKE ?
               OR r.deskripsi LIKE ?
               OR EXISTS (
                   SELECT 1
                   FROM restaurant_menus m
                   WHERE m.restaurant_id = r.id
                     AND m.tersedia = 1
                     AND m.nama_menu LIKE ?
               )
        ";

        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }

    $sql .= " ORDER BY r.nama ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$shopsWithLocations = array_values(array_filter(
    array_map(static function (array $shop): array {
        return [
            'id' => (int) $shop['id'],
            'name' => (string) $shop['nama'],
            'address' => (string) $shop['alamat'],
            'latitude' => $shop['latitude'] === null
                ? null
                : (float) $shop['latitude'],
            'longitude' => $shop['longitude'] === null
                ? null
                : (float) $shop['longitude']
        ];
    }, $shops),
    static fn (array $shop): bool =>
        $shop['latitude'] !== null && $shop['longitude'] !== null
));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mencari Makan</title>
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
            padding: 24px 5%;
        }

        header h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        header > p {
            margin: 0 0 18px;
            line-height: 1.6;
        }

        /* BORDER NAVIGASI ATAS */
        .nav-atas {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px;
            margin-top: 18px;
            border: 2px solid rgba(255, 255, 255, .8);
            border-radius: 12px;
            background: rgba(255, 255, 255, .10);
        }

        .nav-atas a {
            display: inline-block;
            padding: 10px 13px;
            border: 1px solid rgba(255, 255, 255, .4);
            border-radius: 8px;
            background: white;
            color: #0f766e;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            transition: background .2s, transform .2s;
        }

        .nav-atas a:hover {
            background: #d9f4ee;
            transform: translateY(-1px);
        }

        .nav-atas a.nav-pesanan {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }

        .nav-atas a.nav-keluar {
            color: #b91c1c;
        }

        main {
            width: 100%;
            max-width: 1150px;
            margin: 25px auto;
            padding: 0 16px;
        }

        .panel,
        .card,
        .dashboard-card {
            background: white;
            border: 1px solid #e1e9e5;
            border-radius: 14px;
            box-shadow: 0 5px 18px #00000008;
        }

        .panel {
            padding: 20px;
            margin-bottom: 24px;
        }

        input {
            width: 100%;
            max-width: 500px;
            padding: 12px;
            border: 1px solid #cbd5d1;
            border-radius: 8px;
            font-size: 15px;
        }

        button {
            padding: 12px 17px;
            background: #0f766e;
            color: white;
            border: 0;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #115e59;
        }

        .search-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .map-toggle {
            display: block;
            width: 100%;
            padding: 12px 16px;
            text-align: left;
        }

        .map-content[hidden] {
            display: none;
        }

        .map-content .panel {
            margin-top: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(245px, 1fr)
            );
            gap: 18px;
        }

        .card {
            overflow: hidden;
            min-width: 0;
        }

        .card img {
            display: block;
            width: 100%;
            height: 180px;
            object-fit: cover;
        }

        .card-body {
            padding: 17px;
        }

        .card h3 {
            margin-top: 0;
            overflow-wrap: anywhere;
        }

        .card p {
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .muted {
            color: #64756f;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            padding: 11px 15px;
            border-radius: 8px;
            background: #0f766e;
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .btn:hover {
            background: #115e59;
        }

        .btn.secondary {
            background: #e5eeea;
            color: #24554a;
        }

        .btn.secondary:hover {
            background: #d1e2da;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(220px, 1fr)
            );
            gap: 16px;
        }

        .dashboard-card {
            padding: 22px;
            min-width: 0;
        }

        .dashboard-card .emoji {
            font-size: 32px;
        }

        .dashboard-card h3 {
            margin: 12px 0 8px;
        }

        .dashboard-card p {
            color: #64756f;
            line-height: 1.6;
        }

        .count {
            display: inline-block;
            margin: 8px 6px 8px 0;
            padding: 7px 11px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-weight: bold;
        }

        .count.diterima {
            background: #dcfce7;
            color: #166534;
        }

        .empty {
            padding: 24px;
            text-align: center;
            background: white;
            border: 1px solid #e1e9e5;
            border-radius: 12px;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #64756f;
        }

        @media (max-width: 600px) {
            header {
                padding: 20px 16px;
            }

            header h1 {
                font-size: 24px;
            }

            .nav-atas {
                gap: 6px;
                padding: 8px;
            }

            .nav-atas a {
                padding: 9px 10px;
                font-size: 12px;
            }

            .search-form input {
                max-width: none;
            }

            .search-form button,
            .search-form .btn {
                flex: 1;
                text-align: center;
            }

            .directions-panel {
                padding: 14px;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>🍽️ Mencari Makan</h1>

    <p>
        Halo, <?= e($namaUser) ?>!
        <?php if ($peran === 'pemilik'): ?>
            Kelola toko dan pesanan pelangganmu.
        <?php else: ?>
            Temukan toko dan menu favoritmu.
        <?php endif; ?>
    </p>

    <nav class="nav-atas">
        <a href="index.php">🏠 Beranda</a>

        <?php if ($peran === 'pemilik'): ?>
            <a href="profil_penjual.php">🏪 Profil Toko</a>
            <a href="kelola_menu.php">🍽️ Kelola Menu</a>
            <a href="chat.php">💬 Chat</a>

            <a class="nav-pesanan" href="pesanan_penjual.php">
                📦 Pesanan Masuk (<?= $jumlahMenunggu ?>)
            </a>

            <a class="nav-pesanan"
               href="pesanan_penjual.php?status=diterima">
                ✅ Pesanan Diterima (<?= $jumlahDiterima ?>)
            </a>

            <a href="pilih_peran.php">🔄 Ganti Peran</a>
        <?php else: ?>
            <a href="chat.php">💬 Chat</a>
            <a href="pesanan_saya.php">📋 Pesanan Saya</a>
            <a href="pilih_peran.php">🔄 Ganti Peran</a>
        <?php endif; ?>

        <a class="nav-keluar"
           href="logout.php"
           onclick="return confirm('Yakin ingin keluar dari akun?')">
            🚪 Keluar
        </a>
    </nav>
</header>

<main>
<?php if ($peran === 'pemilik'): ?>

    <section class="panel">
        <h2>Dashboard Penjual</h2>

        <?php if (!$toko): ?>
            <p>
                Profil toko kamu belum tersedia. Buat profil toko
                terlebih dahulu agar pelanggan dapat menemukan tokomu.
            </p>
            <a class="btn" href="profil_penjual.php">
                🏪 Buat Profil Toko
            </a>
        <?php else: ?>
            <p>
                Selamat datang di dashboard
                <strong><?= e($toko['nama']) ?></strong>.
            </p>

            <span class="count">
                <?= $jumlahMenunggu ?> pesanan menunggu
            </span>

            <span class="count diterima">
                <?= $jumlahDiterima ?> pesanan diterima
            </span>
        <?php endif; ?>
    </section>

    <h2>Menu Pengelolaan Toko</h2>

    <section class="dashboard-grid">
        <article class="dashboard-card">
            <div class="emoji">🏪</div>
            <h3>Profil Toko</h3>
            <p>Kelola informasi, alamat, foto, dan deskripsi tokomu.</p>
            <a class="btn" href="profil_penjual.php">Buka Profil Toko</a>
        </article>

        <article class="dashboard-card">
            <div class="emoji">🍽️</div>
            <h3>Kelola Menu</h3>
            <p>Tambah, ubah, dan kelola ketersediaan menu makanan.</p>
            <a class="btn" href="kelola_menu.php">Buka Kelola Menu</a>
        </article>

        <article class="dashboard-card">
            <div class="emoji">💬</div>
            <h3>Chat Pembeli</h3>
            <p>Buka percakapan dengan pembeli yang menghubungi tokomu.</p>
            <a class="btn" href="chat.php">Buka Chat</a>
        </article>

        <article class="dashboard-card">
            <div class="emoji">🔔</div>
            <h3>Pesanan Masuk</h3>
            <p>Periksa pesanan baru dan informasi pengantaran.</p>
            <a class="btn" href="pesanan_penjual.php">Lihat Pesanan Masuk</a>
        </article>

        <article class="dashboard-card">
            <div class="emoji">✅</div>
            <h3>Pesanan Diterima</h3>
            <p>Lihat pesanan yang sudah diterima oleh toko.</p>
            <a class="btn" href="pesanan_penjual.php?status=diterima">
                Lihat Pesanan Diterima
            </a>
        </article>
    </section>

<?php else: ?>

    <section class="panel">
        <h2>🔎 Cari Toko atau Menu</h2>

        <form class="search-form" method="get" action="index.php">
            <input
                type="text"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Nama makanan, toko, atau alamat"
                aria-label="Cari toko atau menu"
            >
            <button type="submit">Cari</button>
            <a class="btn secondary" href="index.php">Reset</a>
        </form>
    </section>

    <section class="directions-panel">
        <button type="button" class="map-toggle" data-map-toggle
                aria-expanded="false" aria-controls="shop-map-content">
            🗺️ Lihat peta toko terdekat
        </button>
        <div id="shop-map-content" class="map-content" data-map-content hidden>
            <section class="panel">
                <h2>🗺️ Peta Toko Terdekat</h2>
                <p>
                    Izinkan akses lokasi untuk melihat posisi kamu dan membuat rute
                    berkendara ke toko. Pilih pin toko lain untuk mengganti tujuan.
                </p>
                <div class="map-actions">
                    <button type="button" data-locate-user="shop-map">
                        📍 Temukan lokasi saya &amp; rute terdekat
                    </button>
                    <span data-map-status role="status" aria-live="polite">
                        <?php if (!$shopsWithLocations): ?>
                            Belum ada toko yang menambahkan titik lokasi.
                        <?php else: ?>
                            Tekan tombol untuk mencari lokasi dan rute ke toko terdekat.
                        <?php endif; ?>
                    </span>
                </div>
                <div id="shop-map" class="location-map"
                     data-shops-map
                     data-map-lazy
                     data-maptiler-key="<?= e($maptilerApiKey) ?>"></div>
                <p class="hint">
                    Peta © OpenStreetMap. Rute mengikuti jalan yang tersedia;
                    jika titik berada jauh dari jalan, rute dimulai dari jalan terdekat.
                </p>
            </section>
        </div>
        <script type="application/json" id="shop-map-data"><?= json_encode(
            $shopsWithLocations,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    </section>

    <h2>🍜 Daftar Toko</h2>

    <?php if (!$shops): ?>
        <section class="empty">
            <h3>Toko belum ditemukan</h3>
            <p>Coba kata kunci lain atau periksa kembali nanti.</p>
        </section>
    <?php else: ?>
        <section class="store-catalog">
            <?php foreach ($shops as $s): ?>
                <article class="store-product">
                    <div class="store-product-image">
                        <?php if (!empty($s['foto_profil'])): ?>
                            <img
                                src="<?= e($s['foto_profil']) ?>"
                                alt="Foto <?= e($s['nama']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <span aria-hidden="true">🏪</span>
                        <?php endif; ?>
                    </div>

                    <div class="store-product-body">
                        <h3><?= e($s['nama']) ?></h3>
                        <p class="store-product-address">
                            📍 <?= e($s['alamat'] ?? '') ?>
                        </p>
                        <?php if (trim((string) ($s['deskripsi'] ?? '')) !== ''): ?>
                            <p class="store-product-description">
                                <?= e($s['deskripsi']) ?>
                            </p>
                        <?php endif; ?>
                        <span class="store-product-count">
                            <?= (int) $s['jumlah_menu'] ?> menu tersedia
                        </span>
                        <a class="btn"
                           href="detail_toko.php?id=<?= (int) $s['id'] ?>">
                            Lihat Toko &amp; Menu
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>
</main>

<footer>© 2026 Mencari Makan</footer>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="maps.js"></script>
<script>
(() => {
    const toggle = document.querySelector('[data-map-toggle]');
    const content = document.querySelector('[data-map-content]');
    const map = document.querySelector('[data-shops-map]');

    toggle?.addEventListener('click', () => {
        const isOpening = content.hidden;
        content.hidden = !isOpening;
        toggle.setAttribute('aria-expanded', String(isOpening));
        toggle.textContent = isOpening
            ? '✕ Tutup peta toko'
            : '🗺️ Lihat peta toko terdekat';

        if (isOpening) {
            map.dataset.mapOpen = 'true';
            window.initializeShopLocationMap?.(map);
            content.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });
})();
</script>
</body>
</html>