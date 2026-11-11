-- Jalankan satu kali pada database mencari_makan yang sudah memiliki tabel orders.
-- Pastikan pembaruan_orders.sql sudah dijalankan agar alasan_penolakan dan
-- konfirmasi_pembeli tersedia.
ALTER TABLE orders
    MODIFY COLUMN status ENUM(
        'menunggu',
        'diterima',
        'diproses',
        'dikirim',
        'selesai',
        'dibatalkan',
        'ditolak'
    ) NOT NULL DEFAULT 'menunggu',
    ADD COLUMN ditolak_at DATETIME NULL AFTER konfirmasi_pembeli,
    ADD COLUMN selesai_at DATETIME NULL AFTER ditolak_at;

UPDATE orders
SET ditolak_at = created_at
WHERE status = 'ditolak'
  AND ditolak_at IS NULL;

UPDATE orders
SET selesai_at = created_at
WHERE status = 'selesai'
  AND selesai_at IS NULL;
