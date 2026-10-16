
CREATE DATABASE IF NOT EXISTS mencari_makan;
USE mencari_makan;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    peran ENUM('pencari', 'pemilik') NOT NULL DEFAULT 'pencari',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS restaurants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    kategori VARCHAR(100) NOT NULL,
    alamat VARCHAR(255) NOT NULL,
    deskripsi TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO restaurants (nama, kategori, alamat, deskripsi)
SELECT 'Warung Nasi Goreng', 'Nasi Goreng',
       'Padang, Sumatera Barat',
       'Nasi goreng lezat untuk makan siang dan malam.'
WHERE NOT EXISTS (
    SELECT 1 FROM restaurants WHERE nama = 'Warung Nasi Goreng'
);

INSERT INTO restaurants (nama, kategori, alamat, deskripsi)
SELECT 'Sate Padang Maknyus', 'Sate',
       'Padang, Sumatera Barat',
       'Sate dengan kuah khas Minangkabau.'
WHERE NOT EXISTS (
    SELECT 1 FROM restaurants WHERE nama = 'Sate Padang Maknyus'
);

INSERT INTO restaurants (nama, kategori, alamat, deskripsi)
SELECT 'Rumah Makan Rendang', 'Masakan Padang',
       'Tanah Datar, Sumatera Barat',
       'Aneka masakan Minang untuk keluarga.'
WHERE NOT EXISTS (
    SELECT 1 FROM restaurants WHERE nama = 'Rumah Makan Rendang'
);

INSERT INTO restaurants (nama, kategori, alamat, deskripsi)
SELECT 'Kedai Ayam Geprek', 'Ayam Geprek',
       'Padang, Sumatera Barat',
       'Ayam geprek dengan pilihan sambal.'
WHERE NOT EXISTS (
    SELECT 1 FROM restaurants WHERE nama = 'Kedai Ayam Geprek'
);
