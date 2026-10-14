-- VIP membership payments (code in payments/). Import once on ipa_store after users.sql.
-- orders: one row per checkout attempt (pending until the gateway confirms).
-- memberships: one row per VIP member (created when an order is paid, or by an admin: order_id NULL).
CREATE TABLE IF NOT EXISTS orders (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  kind        VARCHAR(20)  NOT NULL DEFAULT 'vip',
  amount      DECIMAL(10,2) NOT NULL,
  currency    VARCHAR(3)   NOT NULL,
  gateway     VARCHAR(30)  NOT NULL,
  gateway_ref VARCHAR(100) NULL,
  status      ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  created_at  DATETIME NOT NULL,
  paid_at     DATETIME NULL,
  KEY idx_orders_user (user_id, id),
  KEY idx_orders_status (status, created_at),
  KEY idx_orders_ref (gateway_ref),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
  user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  order_id   BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
