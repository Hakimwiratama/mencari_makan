-- Jalankan satu kali pada database mencari_makan yang sudah ada.
ALTER TABLE restaurants
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER alamat,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
