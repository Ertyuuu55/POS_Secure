-- Nama database pos_database (Masukkan pada sql sesuai nama ini)
DROP USER IF EXISTS 'pos_app'@'localhost';
CREATE USER 'pos_app'@'localhost' IDENTIFIED BY 'PasswordKuat123!';
GRANT SELECT, INSERT, UPDATE ON pos_database.* TO 'pos_app'@'localhost';
GRANT DELETE ON pos_database.login_attempts TO 'pos_app'@'localhost';
FLUSH PRIVILEGES;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  full_name     VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL, -- hasil password_hash(), BUKAN plaintext
  role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku         VARCHAR(40)  NOT NULL UNIQUE,
  name        VARCHAR(150) NOT NULL,
  category_id INT UNSIGNED NULL,
  price       DECIMAL(12,2) NOT NULL, -- harga jual
  cost        DECIMAL(12,2) NOT NULL DEFAULT 0, -- harga modal (hanya tampil untuk admin)
  stock       INT NOT NULL DEFAULT 0,
  min_stock   INT NOT NULL DEFAULT 5,
  is_active   TINYINT(1) NOT NULL DEFAULT 1, -- soft delete: riwayat stok tetap utuh
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_price     CHECK (price >= 0),
  CONSTRAINT chk_cost      CHECK (cost >= 0),
  CONSTRAINT chk_stock     CHECK (stock >= 0), -- lapisan pertahanan terakhir: stok tak boleh negatif
  CONSTRAINT chk_min_stock CHECK (min_stock >= 0),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)
    REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_products_name (name),
  INDEX idx_products_active_stock (is_active, stock)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('in','out','adjust') NOT NULL,
  quantity    INT NOT NULL, -- selisih +masuk / -keluar
  stock_after INT NOT NULL,
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mv_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE RESTRICT,
  INDEX idx_mv_product_time (product_id, created_at),
  INDEX idx_mv_time (created_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(50) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_time (attempted_at),
  INDEX idx_la_user (username, attempted_at),
  INDEX idx_la_ip   (ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(50)  NOT NULL,
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  ip         VARCHAR(45)  NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_time (created_at)
) ENGINE=InnoDB;

INSERT INTO categories (name) VALUES ('Makanan'), ('Minuman'), ('Kebersihan'), ('Alat Tulis'), ('Lainnya');

-- **Security:
-- Principle of Least Privilege (User database diberi akses minimum).
-- Aplikasi TIDAK memakai root. Jalankan sebagai root, ganti passwordnya:
-- CREATE USER 'pos_app'@'localhost' IDENTIFIED BY 'GANTI_DENGAN_PASSWORD_KUAT';
-- GRANT SELECT, INSERT, UPDATE ON pos_secure.* TO 'pos_app'@'localhost';
-- GRANT DELETE ON pos_secure.login_attempts TO 'pos_app'@'localhost';
-- FLUSH PRIVILEGES;
