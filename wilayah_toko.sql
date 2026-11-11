-- Jalankan satu kali jika database mencari_makan sudah memiliki
-- latitude dan longitude tetapi belum memiliki wilayah_id.
ALTER TABLE restaurants
    ADD COLUMN wilayah_id VARCHAR(10) NULL AFTER longitude;
