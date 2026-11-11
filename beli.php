<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_login();

if (($_SESSION['peran'] ?? 'pencari') !== 'pencari') {
    header('Location: pilih_peran.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$maptilerApiKey = getenv('MAPTILER_API_KEY') ?: '';
$menuId = (int) ($_GET['menu_id'] ?? $_POST['menu_id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT m.*, r.id AS toko_id, r.nama AS nama_toko, r.alamat AS alamat_toko
    FROM restaurant_menus m
    JOIN restaurants r ON r.id = m.restaurant_id
    WHERE m.id = ? AND m.tersedia = 1
');
$stmt->execute([$menuId]);
$menu = $stmt->fetch();
if (!$menu) {
    http_response_code(404);
    exit('Menu tidak ditemukan atau sedang tidak tersedia.');
}

$stmt = $pdo->prepare('
    SELECT id, label, alamat, province_id, region_id, latitude, longitude, is_default
    FROM buyer_addresses
    WHERE buyer_id = ?
    ORDER BY created_at ASC, id ASC
');
$stmt->execute([$userId]);
$savedAddresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$savedAddressById = [];
foreach ($savedAddresses as $savedAddress) {
    $savedAddressById[(string) $savedAddress['id']] = $savedAddress;
}

$stmt = $pdo->prepare("
    SELECT alamat, MAX(created_at) AS terakhir_digunakan
    FROM orders
    WHERE buyer_id = ?
      AND TRIM(alamat) <> ''
    GROUP BY alamat
    ORDER BY terakhir_digunakan DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$alamatTersimpan = $stmt->fetchAll(PDO::FETCH_ASSOC);

$wilayahError = '';
$provinceById = [];
$regionById = [];
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
    foreach ($wilayahData['provinces'] as $province) {
        $provinceById[(string) $province['id']] = $province;
    }
    foreach ($wilayahData['regencies'] as $region) {
        $regionById[(string) $region['id']] = $region;
    }
} catch (Throwable $ex) {
    error_log('Daftar wilayah alamat pembeli gagal dimuat: ' . $ex->getMessage());
    $wilayahError = 'Daftar provinsi dan kota/kabupaten tidak dapat dimuat. Coba muat ulang halaman.';
    $wilayahData = ['provinces' => [], 'regencies' => []];
}

$errors = [];
$nama = trim($_POST['nama_penerima'] ?? ($_SESSION['nama'] ?? ''));
$telepon = trim($_POST['telepon'] ?? '');
$catatan = trim($_POST['catatan'] ?? '');
$jumlah = (int) ($_POST['jumlah'] ?? 1);
$selectedDelivery = trim($_POST['delivery_address'] ?? '');
$submittedAddresses = $_POST['new_addresses'] ?? [];
if (!is_array($submittedAddresses)) {
    $submittedAddresses = [];
}
$newAddresses = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($nama === '') {
        $errors[] = 'Nama penerima wajib diisi.';
    }
    if ($telepon === '') {
        $errors[] = 'Nomor telepon wajib diisi.';
    }
    if ($jumlah < 1 || $jumlah > 99) {
        $errors[] = 'Jumlah makanan harus antara 1 sampai 99.';
    }
    if ($wilayahError !== '') {
        $errors[] = $wilayahError;
    }

    foreach ($submittedAddresses as $index => $input) {
        if (!is_array($input)) {
            $errors[] = 'Data alamat baru tidak valid.';
            continue;
        }

        $label = trim((string) ($input['label'] ?? ''));
        $streetAddress = trim((string) ($input['alamat'] ?? ''));
        $provinceId = trim((string) ($input['province_id'] ?? ''));
        $regionId = trim((string) ($input['region_id'] ?? ''));
        $latitudeInput = trim((string) ($input['latitude'] ?? ''));
        $longitudeInput = trim((string) ($input['longitude'] ?? ''));
        $hasInput = $label !== '' || $streetAddress !== '' || $provinceId !== '' ||
            $regionId !== '' || $latitudeInput !== '' || $longitudeInput !== '';
        if (!$hasInput) {
            continue;
        }

        $cardErrors = [];
        $region = $regionById[$regionId] ?? null;
        $province = $provinceById[$provinceId] ?? null;
        if ($streetAddress === '') {
            $cardErrors[] = 'alamat jalan harus diisi';
        }
        if (!$region || !$province || (string) $region['province_id'] !== $provinceId) {
            $cardErrors[] = 'provinsi dan kota/kabupaten harus dipilih dengan benar';
        }
        if (
            !is_numeric($latitudeInput) ||
            !is_numeric($longitudeInput) ||
            !is_finite((float) $latitudeInput) ||
            !is_finite((float) $longitudeInput) ||
            (float) $latitudeInput < -90 ||
            (float) $latitudeInput > 90 ||
            (float) $longitudeInput < -180 ||
            (float) $longitudeInput > 180
        ) {
            $cardErrors[] = 'titik lokasi harus dipilih pada peta atau dari lokasi perangkat';
        }

        if ($cardErrors) {
            $errors[] = 'Alamat baru ' . ((int) $index + 1) . ': ' . implode(', ', $cardErrors) . '.';
            continue;
        }

        foreach ([$region['name'], $province['name'], 'Indonesia'] as $area) {
            if (stripos($streetAddress, $area) === false) {
                $streetAddress .= ', ' . $area;
            }
        }
        $newAddresses[(string) $index] = [
            'label' => $label !== ''
                ? mb_substr($label, 0, 100, 'UTF-8')
                : 'Alamat ' . (count($savedAddresses) + (int) $index + 1),
            'alamat' => $streetAddress,
            'province_id' => $provinceId,
            'region_id' => $regionId,
            'latitude' => (float) $latitudeInput,
            'longitude' => (float) $longitudeInput
        ];
    }

    $selectedSavedId = null;
    $selectedNewIndex = null;
    if (preg_match('/^saved:(\d+)$/', $selectedDelivery, $match)) {
        $selectedSavedId = $match[1];
        if (!isset($savedAddressById[$selectedSavedId])) {
            $errors[] = 'Alamat yang dipilih tidak ditemukan. Silakan pilih alamat Anda.';
        }
    } elseif (preg_match('/^new:(\d+)$/', $selectedDelivery, $match)) {
        $selectedNewIndex = $match[1];
        if (!isset($newAddresses[$selectedNewIndex])) {
            $errors[] = 'Lengkapi alamat baru yang dipilih, termasuk kota/kabupaten dan titik lokasinya.';
        }
    } else {
        $errors[] = 'Pilih alamat pengantaran atau lengkapi alamat baru.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $insertAddress = $pdo->prepare('
                INSERT INTO buyer_addresses
                    (buyer_id, label, alamat, province_id, region_id, latitude, longitude, is_default)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ');
            $insertedIds = [];
            foreach ($newAddresses as $index => $newAddress) {
                $insertAddress->execute([
                    $userId,
                    $newAddress['label'],
                    $newAddress['alamat'],
                    $newAddress['province_id'],
                    $newAddress['region_id'],
                    $newAddress['latitude'],
                    $newAddress['longitude']
                ]);
                $insertedIds[$index] = (int) $pdo->lastInsertId();
            }

            if ($selectedSavedId !== null) {
                $deliveryAddressId = (int) $selectedSavedId;
                $deliveryAddress = $savedAddressById[$selectedSavedId]['alamat'];
            } else {
                $deliveryAddressId = $insertedIds[$selectedNewIndex];
                $deliveryAddress = $newAddresses[$selectedNewIndex]['alamat'];
            }

            $pdo->prepare('UPDATE buyer_addresses SET is_default = 0 WHERE buyer_id = ?')
                ->execute([$userId]);
            $pdo->prepare('
                UPDATE buyer_addresses SET is_default = 1
                WHERE id = ? AND buyer_id = ?
            ')->execute([$deliveryAddressId, $userId]);

            $harga = (float) $menu['harga'];
            $total = $harga * $jumlah;
            $insertOrder = $pdo->prepare('
                INSERT INTO orders
                    (buyer_id, restaurant_id, menu_id, nama_penerima, telepon, alamat,
                     catatan, jumlah, harga_satuan, total_harga)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertOrder->execute([
                $userId,
                (int) $menu['toko_id'],
                $menuId,
                $nama,
                $telepon,
                $deliveryAddress,
                trim($_POST['catatan'] ?? ''),
                $jumlah,
                $harga,
                $total
            ]);
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }

        header('Location: pesanan_saya.php?berhasil=1');
        exit;
    }
} elseif ($savedAddresses) {
    foreach ($savedAddresses as $savedAddress) {
        if ((int) $savedAddress['is_default'] === 1) {
            $selectedDelivery = 'saved:' . $savedAddress['id'];
            break;
        }
    }
    if ($selectedDelivery === '') {
        $selectedDelivery = 'saved:' . $savedAddresses[0]['id'];
    }
} else {
    $selectedDelivery = 'new:0';
}

$initialAddressCards = $submittedAddresses;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$savedAddresses) {
    $initialAddressCards = [[]];
}

function rupiah($value)
{
    return 'Rp' . number_format((float) $value, 0, ',', '.');
}

$renderAddressCard = static function ($index, $input) use ($wilayahData, $maptilerApiKey, $selectedDelivery) {
    $index = (string) $index;
    $provinceId = (string) ($input['province_id'] ?? '');
    $regionId = (string) ($input['region_id'] ?? '');
    $latitude = (string) ($input['latitude'] ?? '');
    $longitude = (string) ($input['longitude'] ?? '');
    $label = (string) ($input['label'] ?? '');
    $streetAddress = (string) ($input['alamat'] ?? '');
    ?>
    <article class="buyer-address-card" data-address-card>
        <div class="buyer-address-heading">
            <label class="buyer-address-choice">
                <input type="radio" name="delivery_address"
                       value="new:<?= e($index) ?>"
                       <?= $selectedDelivery === 'new:' . $index ? 'checked' : '' ?>>
                <strong>Alamat baru</strong>
            </label>
            <button type="button" class="secondary buyer-remove-address"
                    data-remove-address>Hapus alamat</button>
        </div>
        <label for="address-label-<?= e($index) ?>">Nama alamat (opsional)</label>
        <input id="address-label-<?= e($index) ?>"
               name="new_addresses[<?= e($index) ?>][label]" maxlength="100"
               value="<?= e($label) ?>" placeholder="Rumah, kantor, kos...">

        <label for="address-province-<?= e($index) ?>">Provinsi *</label>
        <select id="address-province-<?= e($index) ?>"
                name="new_addresses[<?= e($index) ?>][province_id]"
                data-address-province>
            <option value="">Pilih provinsi...</option>
            <?php foreach ($wilayahData['provinces'] as $province): ?>
                <option value="<?= e($province['id']) ?>"
                    <?= $provinceId === (string) $province['id'] ? 'selected' : '' ?>>
                    <?= e($province['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="address-region-<?= e($index) ?>">Kota/Kabupaten *</label>
        <select id="address-region-<?= e($index) ?>"
                name="new_addresses[<?= e($index) ?>][region_id]"
                data-address-region>
            <option value="">Pilih kota/kabupaten...</option>
            <?php foreach ($wilayahData['regencies'] as $region): ?>
                <option value="<?= e($region['id']) ?>"
                        data-province="<?= e($region['province_id']) ?>"
                        data-lat="<?= e($region['latitude']) ?>"
                        data-lng="<?= e($region['longitude']) ?>"
                    <?= $regionId === (string) $region['id'] ? 'selected' : '' ?>>
                    <?= e($region['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="address-street-<?= e($index) ?>">Alamat jalan, nomor rumah, dan patokan *</label>
        <textarea id="address-street-<?= e($index) ?>"
                  name="new_addresses[<?= e($index) ?>][alamat]"
                  data-address-street
                  placeholder="Tulis jalan, nomor rumah, kelurahan, kecamatan, dan patokan..."><?= e($streetAddress) ?></textarea>
        <span class="hint" data-address-status role="status" aria-live="polite"></span>

        <p class="hint">Pilih titik tepat pada peta atau gunakan lokasi perangkat Anda.</p>
        <div class="map-actions">
            <button type="button" class="secondary"
                    data-use-current-location="buyer-address-map-<?= e($index) ?>">
                Gunakan lokasi perangkat
            </button>
            <span data-map-status role="status" aria-live="polite">
                Pilih provinsi dan kota/kabupaten, lalu tentukan titik alamat.
            </span>
        </div>
        <div id="buyer-address-map-<?= e($index) ?>"
             class="location-map location-map-picker"
             data-location-picker
             data-address-map
             data-maptiler-key="<?= e($maptilerApiKey) ?>"
             data-lat="<?= e($latitude) ?>"
             data-lng="<?= e($longitude) ?>"></div>
        <input type="hidden" name="new_addresses[<?= e($index) ?>][latitude]"
               data-address-latitude value="<?= e($latitude) ?>">
        <input type="hidden" name="new_addresses[<?= e($index) ?>][longitude]"
               data-address-longitude value="<?= e($longitude) ?>">
    </article>
    <?php
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Beli Makanan - Mencari Makan</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="">
    <style>
        .order-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.8fr);gap:20px}
        .order-photo{width:100%;max-width:220px;height:150px;object-fit:cover;border-radius:12px}
        .field-error{background:#fff0f0;color:#a51d1d;padding:10px;border-radius:8px;margin-bottom:12px}
        .buyer-address-list{display:grid;gap:12px;margin:12px 0}
        .buyer-saved-address,.buyer-address-card{padding:16px;border:1px solid #dce5de;border-radius:12px;background:#fbfdfb}
        .buyer-saved-address.is-current{border-color:#15803d}
        .buyer-address-heading{display:flex;justify-content:space-between;align-items:center;gap:12px}
        .buyer-address-choice{display:flex;align-items:center;gap:9px;margin:0}
        .buyer-address-choice input{width:auto;margin:0}
        .buyer-remove-address{padding:7px 10px}
        .buyer-address-card .hint{display:block;margin:6px 0}
        .buyer-address-card .location-map-picker{height:260px}
        .buyer-add-address{margin:8px 0 16px}
        @media(max-width:700px){.order-layout{grid-template-columns:1fr}}
    </style>
</head>
<body>
<header class="buyer-dashboard-header">
    <h1>🍽️ Mencari Makan</h1>
    <p>Lengkapi alamat sebelum membuat pesanan</p>
    <?php $buyerChatRestaurantId = (int) $menu['toko_id']; ?>
    <?php require __DIR__ . '/buyer_navigation.php'; ?>
</header>

<main>
    <h2>Konfirmasi pembelian</h2>
    <div class="order-layout">
        <section class="panel">
            <h3>Alamat pengantaran</h3>
            <?php foreach ($errors as $error): ?>
                <div class="field-error" role="alert"><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($wilayahError !== ''): ?>
                <div class="field-error" role="alert"><?= e($wilayahError) ?></div>
            <?php endif; ?>

            <form method="post" action="beli.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="menu_id" value="<?= (int) $menuId ?>">

                <label for="nama_penerima">Nama penerima *</label>
                <input id="nama_penerima" name="nama_penerima" required maxlength="120"
                       value="<?= e($nama) ?>">

                <label for="telepon">Nomor telepon *</label>
                <input id="telepon" type="tel" name="telepon" required maxlength="30"
                       value="<?= e($telepon) ?>" placeholder="Contoh: 08xxxxxxxxxx">

                <h4>Alamat Anda Saat Ini</h4>
                <?php if ($savedAddresses): ?>
                    <div class="buyer-address-list">
                        <?php foreach ($savedAddresses as $savedAddress): ?>
                            <?php $savedValue = 'saved:' . $savedAddress['id']; ?>
                            <article class="buyer-saved-address <?= (int) $savedAddress['is_default'] === 1 ? 'is-current' : '' ?>">
                                <label class="buyer-address-choice">
                                    <input type="radio" name="delivery_address"
                                           value="<?= e($savedValue) ?>"
                                           <?= $selectedDelivery === $savedValue ? 'checked' : '' ?>>
                                    <strong><?= e($savedAddress['label']) ?></strong>
                                    <?php if ((int) $savedAddress['is_default'] === 1): ?>
                                        <span class="muted">(Alamat saat ini)</span>
                                    <?php endif; ?>
                                </label>
                                <p><?= e($savedAddress['alamat']) ?></p>
                                <small class="muted">
                                    Titik koordinat: <?= e($savedAddress['latitude']) ?>, <?= e($savedAddress['longitude']) ?>
                                </small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Belum ada alamat tersimpan. Buat alamat pertama Anda di bawah.</p>
                <?php endif; ?>

                <?php if ($alamatTersimpan): ?>
                    <label for="alamat-tersimpan">Isi cepat dari alamat pesanan sebelumnya</label>
                    <select id="alamat-tersimpan" data-saved-address-select>
                        <option value="">Pilih alamat lama (opsional)</option>
                        <?php foreach ($alamatTersimpan as $alamatLama): ?>
                            <option value="<?= e($alamatLama['alamat']) ?>"><?= e($alamatLama['alamat']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted">
                        Alamat dari pesanan lama hanya mengisi detail jalan. Pilih provinsi, kota/kabupaten, dan titik lokasinya sebelum disimpan.
                    </p>
                <?php endif; ?>

                <div id="new-address-list" class="buyer-address-list">
                    <?php foreach ($initialAddressCards as $index => $input): ?>
                        <?php $renderAddressCard($index, is_array($input) ? $input : []); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="secondary buyer-add-address" data-add-address>
                    + Buat alamat baru
                </button>

                <label for="catatan">Catatan untuk penjual (opsional)</label>
                <textarea id="catatan" name="catatan"
                          placeholder="Contoh: tidak pedas, pagar warna hitam..."><?= e($_POST['catatan'] ?? '') ?></textarea>

                <label for="jumlah">Jumlah *</label>
                <input id="jumlah" type="number" name="jumlah" min="1" max="99"
                       required value="<?= (int) $jumlah ?>">
                <button type="submit">Buat Pesanan</button>
            </form>
        </section>

        <section class="panel">
            <h3>Ringkasan pesanan</h3>
            <?php if (!empty($menu['foto'])): ?>
                <img class="order-photo" src="<?= e($menu['foto']) ?>"
                     alt="<?= e($menu['nama_menu']) ?>">
            <?php endif; ?>
            <h3><?= e($menu['nama_menu']) ?></h3>
            <p class="muted">Toko: <?= e($menu['nama_toko']) ?></p>
            <p>Harga satuan: <strong><?= rupiah($menu['harga']) ?></strong></p>
            <p>Jumlah: <strong><?= (int) $jumlah ?></strong></p>
            <hr>
            <p>Total makanan:
                <strong><?= rupiah((float) $menu['harga'] * max(1, (int) $jumlah)) ?></strong>
            </p>
            <p class="muted">Pastikan alamat dan nomor telepon benar sebelum membuat pesanan.</p>
        </section>
    </div>
</main>
<footer>© 2026 Mencari Makan</footer>

<template id="new-address-template">
    <?php $renderAddressCard('__INDEX__', []); ?>
</template>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script src="maps.js"></script>
<script>
(() => {
    const addressList = document.getElementById('new-address-list');
    const addressTemplate = document.getElementById('new-address-template');
    const addAddressButton = document.querySelector('[data-add-address]');
    const savedAddressSelect = document.querySelector('[data-saved-address-select]');
    let nextAddressIndex = Math.max(
        -1,
        ...Array.from(addressList.querySelectorAll('[data-address-card]')).map((card) => {
            const radio = card.querySelector('[name="delivery_address"]');
            return Number((radio?.value || '').replace('new:', ''));
        })
    ) + 1;

    function addAddress(selectIt = true, source = null) {
        const template = document.createElement('template');
        template.innerHTML = addressTemplate.innerHTML.replace(/__INDEX__/g, String(nextAddressIndex));
        const card = template.content.firstElementChild;
        if (!card) return null;
        nextAddressIndex += 1;
        addressList.append(card);
        if (selectIt) {
            card.querySelector('[name="delivery_address"]').checked = true;
        }
        if (source) {
            card.querySelector('[data-address-street]').value = source;
        }
        window.initializeLocationPicker?.(card.querySelector('[data-address-map]'));
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return card;
    }

    addAddressButton.addEventListener('click', () => addAddress());

    addressList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-address]');
        if (!removeButton) return;
        const card = removeButton.closest('[data-address-card]');
        const wasSelected = card.querySelector('[name="delivery_address"]').checked;
        card.remove();
        if (wasSelected) {
            const firstSavedAddress = document.querySelector('.buyer-saved-address [name="delivery_address"]');
            if (firstSavedAddress) {
                firstSavedAddress.checked = true;
            } else if (addressList.querySelector('[data-address-card]')) {
                addressList.querySelector('[name="delivery_address"]').checked = true;
            } else {
                addAddress();
            }
        }
    });

    savedAddressSelect?.addEventListener('change', () => {
        if (savedAddressSelect.value === '') return;
        const selectedCard = Array.from(
            addressList.querySelectorAll('[data-address-card]')
        ).find((card) => card.querySelector('[name="delivery_address"]').checked);
        const card = selectedCard || addAddress();
        if (card) {
            card.querySelector('[data-address-street]').value = savedAddressSelect.value;
            card.querySelector('[name="delivery_address"]').checked = true;
        }
    });
})();
</script>
</body>
</html>
