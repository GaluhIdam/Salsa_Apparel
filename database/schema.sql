BEGIN;
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE roles (
  id SMALLSERIAL PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL
);
CREATE TABLE permissions (
  id SMALLSERIAL PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL
);
CREATE TABLE role_permissions (
  role_id SMALLINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  permission_id SMALLINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE users (
  id BIGSERIAL PRIMARY KEY,
  email CITEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30),
  role_id SMALLINT REFERENCES roles(id),            -- NULL = pelanggan biasa
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE password_reset_tokens (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash CHAR(64) NOT NULL UNIQUE,              -- simpan hash SHA-256, bukan token asli
  expires_at TIMESTAMPTZ NOT NULL,
  used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE login_attempts (
  id BIGSERIAL PRIMARY KEY,
  identifier VARCHAR(255) NOT NULL,
  ip_address INET,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_attempts ON login_attempts (identifier, attempted_at);

CREATE TABLE addresses (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  recipient_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  address_line TEXT NOT NULL,
  city VARCHAR(100) NOT NULL,
  province VARCHAR(100) NOT NULL,
  postal_code VARCHAR(10) NOT NULL,
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_addresses_default ON addresses (user_id) WHERE is_default;

CREATE TABLE categories (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  slug VARCHAR(100) NOT NULL UNIQUE,
  description TEXT,
  image_path VARCHAR(255),
  sort_order INT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE products (
  id BIGSERIAL PRIMARY KEY,
  category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  short_description VARCHAR(255),
  description TEXT,
  price NUMERIC(12,2) NOT NULL CHECK (price >= 0),
  sale_price NUMERIC(12,2) CHECK (sale_price IS NULL OR (sale_price >= 0 AND sale_price < price)),
  status VARCHAR(12) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
  is_new_arrival BOOLEAN NOT NULL DEFAULT FALSE,
  is_best_seller BOOLEAN NOT NULL DEFAULT FALSE,
  is_featured BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_products_category ON products (category_id) WHERE status = 'active';
CREATE INDEX idx_products_flags ON products (is_new_arrival, is_featured, is_best_seller) WHERE status = 'active';
CREATE INDEX idx_products_name ON products (lower(name));

CREATE TABLE product_images (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  path VARCHAR(255) NOT NULL,
  alt_text VARCHAR(160),
  sort_order INT NOT NULL DEFAULT 0
);
CREATE INDEX idx_product_images_product ON product_images (product_id, sort_order);

CREATE TABLE product_variants (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  sku VARCHAR(60) NOT NULL UNIQUE,
  size VARCHAR(20) NOT NULL,
  color VARCHAR(40) NOT NULL,
  stock INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (product_id, size, color)
);

CREATE TABLE inventory_movements (
  id BIGSERIAL PRIMARY KEY,
  variant_id BIGINT NOT NULL REFERENCES product_variants(id),
  change_qty INT NOT NULL,
  reason VARCHAR(30) NOT NULL CHECK (reason IN ('initial','adjustment','order_reserved','order_cancelled','order_expired','return')),
  reference_type VARCHAR(30),
  reference_id BIGINT,
  note TEXT,
  created_by BIGINT REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_inventory_variant ON inventory_movements (variant_id, created_at DESC);

CREATE TABLE carts (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  guest_token_hash CHAR(64) UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (user_id IS NOT NULL OR guest_token_hash IS NOT NULL)
);
CREATE TABLE cart_items (
  id BIGSERIAL PRIMARY KEY,
  cart_id BIGINT NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
  variant_id BIGINT NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
  quantity INT NOT NULL CHECK (quantity > 0),
  UNIQUE (cart_id, variant_id)
);

CREATE TABLE wishlists (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE wishlist_items (
  wishlist_id BIGINT NOT NULL REFERENCES wishlists(id) ON DELETE CASCADE,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  added_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (wishlist_id, product_id)
);

CREATE TABLE coupons (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  discount_type VARCHAR(10) NOT NULL CHECK (discount_type IN ('fixed','percent')),
  discount_value NUMERIC(12,2) NOT NULL CHECK (discount_value > 0),
  max_discount NUMERIC(12,2),
  min_purchase NUMERIC(12,2) NOT NULL DEFAULT 0,
  starts_at TIMESTAMPTZ,
  ends_at TIMESTAMPTZ,
  usage_limit INT,
  per_user_limit INT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CHECK (discount_type <> 'percent' OR discount_value <= 100)
);

CREATE TABLE shipping_rates (
  id BIGSERIAL PRIMARY KEY,
  courier VARCHAR(40) NOT NULL,
  service VARCHAR(60) NOT NULL,
  cost NUMERIC(12,2) NOT NULL CHECK (cost >= 0),
  etd_text VARCHAR(40),
  is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE orders (
  id BIGSERIAL PRIMARY KEY,
  order_number VARCHAR(30) NOT NULL UNIQUE,
  user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  access_token_hash CHAR(64) NOT NULL UNIQUE,       -- akses guest ke order
  customer_email CITEXT NOT NULL,
  recipient_name VARCHAR(120) NOT NULL,
  recipient_phone VARCHAR(30) NOT NULL,
  ship_address TEXT NOT NULL,
  ship_city VARCHAR(100) NOT NULL,
  ship_province VARCHAR(100) NOT NULL,
  ship_postal_code VARCHAR(10) NOT NULL,
  shipping_courier VARCHAR(40) NOT NULL,
  shipping_service VARCHAR(60) NOT NULL,
  subtotal NUMERIC(12,2) NOT NULL CHECK (subtotal >= 0),
  discount_total NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
  shipping_cost NUMERIC(12,2) NOT NULL CHECK (shipping_cost >= 0),
  grand_total NUMERIC(12,2) NOT NULL CHECK (grand_total >= 0),
  coupon_code VARCHAR(40),
  payment_status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (payment_status IN ('pending','paid','failed','expired','refunded')),
  fulfillment_status VARCHAR(12) NOT NULL DEFAULT 'unprocessed' CHECK (fulfillment_status IN ('unprocessed','processing','shipped','completed','cancelled')),
  payment_expires_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_orders_user ON orders (user_id, created_at DESC);
CREATE INDEX idx_orders_status ON orders (payment_status, fulfillment_status);

CREATE TABLE order_items (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  variant_id BIGINT REFERENCES product_variants(id) ON DELETE SET NULL,
  product_name VARCHAR(160) NOT NULL,               -- snapshot
  sku VARCHAR(60) NOT NULL,
  size VARCHAR(20) NOT NULL,
  color VARCHAR(40) NOT NULL,
  unit_price NUMERIC(12,2) NOT NULL CHECK (unit_price >= 0),
  quantity INT NOT NULL CHECK (quantity > 0),
  line_total NUMERIC(12,2) NOT NULL CHECK (line_total >= 0)
);

CREATE TABLE order_status_history (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  status_type VARCHAR(12) NOT NULL CHECK (status_type IN ('payment','fulfillment')),
  from_status VARCHAR(12),
  to_status VARCHAR(12) NOT NULL,
  changed_by BIGINT REFERENCES users(id),
  note TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE payments (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  provider VARCHAR(20) NOT NULL,
  provider_transaction_id VARCHAR(100),
  amount NUMERIC(12,2) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  raw_payload JSONB,                                -- tanpa data kartu
  verified_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (provider, provider_transaction_id)
);
CREATE TABLE payment_webhook_events (
  id BIGSERIAL PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  event_key VARCHAR(150) NOT NULL,                  -- untuk idempotensi
  payload JSONB NOT NULL,
  processed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (provider, event_key)
);

CREATE TABLE shipments (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
  courier VARCHAR(40) NOT NULL,
  tracking_number VARCHAR(80),
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  shipped_at TIMESTAMPTZ,
  delivered_at TIMESTAMPTZ
);

CREATE TABLE coupon_redemptions (
  id BIGSERIAL PRIMARY KEY,
  coupon_id BIGINT NOT NULL REFERENCES coupons(id),
  order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
  user_id BIGINT REFERENCES users(id),
  customer_email CITEXT NOT NULL,
  discount_amount NUMERIC(12,2) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE newsletter_subscribers (
  id BIGSERIAL PRIMARY KEY,
  email CITEXT NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE site_settings (
  key VARCHAR(80) PRIMARY KEY,
  value TEXT NOT NULL DEFAULT '',
  is_secret BOOLEAN NOT NULL DEFAULT FALSE,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE audit_logs (
  id BIGSERIAL PRIMARY KEY,
  actor_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT,
  old_values JSONB,
  new_values JSONB,
  ip_address INET,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id, created_at DESC);

-- updated_at otomatis
CREATE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END $$ LANGUAGE plpgsql;
DO $$ DECLARE t TEXT; BEGIN
  FOREACH t IN ARRAY ARRAY['users','addresses','categories','products','carts','orders','site_settings'] LOOP
    EXECUTE format('CREATE TRIGGER trg_%1$s_updated BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION set_updated_at()', t);
  END LOOP;
END $$;
COMMIT;
