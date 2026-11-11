CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT UNSIGNED NOT NULL,
    restaurant_id INT UNSIGNED NOT NULL,
    menu_id INT UNSIGNED NOT NULL,
    nama_penerima VARCHAR(120) NOT NULL,
    telepon VARCHAR(30) NOT NULL,
    alamat TEXT NOT NULL,
    catatan TEXT NULL,
    jumlah INT UNSIGNED NOT NULL DEFAULT 1,
    harga_satuan DECIMAL(12,2) NOT NULL,
    total_harga DECIMAL(12,2) NOT NULL,
    status ENUM('menunggu','diterima','diproses','dikirim','selesai','dibatalkan','ditolak') NOT NULL DEFAULT 'menunggu',
    ditolak_at DATETIME NULL,
    selesai_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_menu FOREIGN KEY (menu_id) REFERENCES restaurant_menus(id) ON DELETE CASCADE,
    INDEX idx_orders_buyer (buyer_id),
    INDEX idx_orders_restaurant (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL UNIQUE,
    restaurant_id INT UNSIGNED NOT NULL,
    buyer_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    ulasan TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_restaurant_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reviews_restaurant_created (restaurant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
