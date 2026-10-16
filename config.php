
<?php
// Memulai session jika belum aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi database
$host = 'localhost';
$dbname = 'mencari_makan';
$username = 'root';
$password = '';

try {
    // Membuat koneksi ke MySQL menggunakan PDO
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {
    // Catat detail error di log server, bukan ditampilkan ke pengguna
    error_log('Koneksi database Mencari Makan gagal: ' . $e->getMessage());

    http_response_code(500);
    exit('Koneksi database gagal. Pastikan MySQL aktif dan database mencari_makan sudah tersedia.');
}
