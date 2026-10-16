-- Jalankan pada database mencari_makan melalui phpMyAdmin > SQL.
-- Pastikan tabel orders sudah dibuat terlebih dahulu.
ALTER TABLE orders
    ADD COLUMN alasan_penolakan TEXT NULL AFTER status,
    ADD COLUMN konfirmasi_pembeli TINYINT(1) NOT NULL DEFAULT 0 AFTER alasan_penolakan;
