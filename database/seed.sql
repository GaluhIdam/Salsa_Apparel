BEGIN;
INSERT INTO roles (name, label) VALUES
 ('super_admin','Super Admin'),('admin','Admin'),('order_manager','Order Manager')
ON CONFLICT DO NOTHING;

INSERT INTO permissions (code, label) VALUES
 ('products.manage','Kelola produk'),('categories.manage','Kelola kategori'),
 ('inventory.manage','Kelola inventori'),('orders.manage','Kelola pesanan'),
 ('customers.view','Lihat pelanggan'),('promotions.manage','Kelola promosi'),
 ('reports.view','Lihat laporan'),('content.manage','Kelola konten'),
 ('users.manage','Kelola pengguna admin'),('settings.manage','Kelola pengaturan toko')
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'super_admin'
ON CONFLICT DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'admin' AND p.code NOT IN ('users.manage','settings.manage')
ON CONFLICT DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'order_manager' AND p.code IN ('orders.manage','customers.view','inventory.manage')
ON CONFLICT DO NOTHING;

INSERT INTO categories (name, slug, description, sort_order) VALUES
 ('Accessories','accessories','Aksesori pelengkap gaya harian.',1),
 ('Men','men','Essentials pria untuk aktivitas sehari-hari.',2),
 ('Women','women','Essentials wanita yang timeless.',3),
 ('Hoodies','hoodies','Hoodie nyaman dengan siluet bersih.',4),
 ('T-Shirts','t-shirts','Kaos esensial berbahan lembut.',5),
 ('Shirts','shirts','Kemeja relaxed untuk berbagai suasana.',6),
 ('Shoes','shoes','Sepatu minimalis untuk setiap langkah.',7)
ON CONFLICT DO NOTHING;

INSERT INTO shipping_rates (courier, service, cost, etd_text)
SELECT v.courier, v.service, v.cost, v.etd_text
FROM (VALUES
 ('Kurir Reguler','Reguler',15000,'3-5 hari'),
 ('Kurir Express','Express',30000,'1-2 hari')
) AS v(courier, service, cost, etd_text)
WHERE NOT EXISTS (
 SELECT 1 FROM shipping_rates s
 WHERE s.courier = v.courier AND s.service = v.service
)
ON CONFLICT DO NOTHING;

INSERT INTO products (category_id, name, slug, short_description, description, price, status, is_new_arrival, is_best_seller, is_featured)
SELECT c.id, v.name, v.slug, v.short_desc, v.descr, v.price, 'active', v.is_new, v.is_best, v.is_feat
FROM (VALUES
 ('t-shirts','Essential T-Shirt','essential-t-shirt','T-Shirts','Kaos katun premium dengan potongan rapi.',149000,TRUE,TRUE,FALSE),
 ('shirts','Relaxed Fit Shirt','relaxed-fit-shirt','Shirts','Kemeja relaxed fit untuk tampilan santai.',229000,TRUE,FALSE,TRUE),
 ('hoodies','Everyday Hoodie','everyday-hoodie','Hoodies','Hoodie tebal-lembut untuk pemakaian harian.',299000,TRUE,TRUE,TRUE),
 ('shoes','Classic Sneakers','classic-sneakers','Shoes','Sneakers klasik dengan sol nyaman.',449000,TRUE,FALSE,FALSE),
 ('accessories','Minimalist Tote','minimalist-tote','Accessories','Tote minimalis untuk kebutuhan harian.',179000,TRUE,FALSE,TRUE)
) AS v(cat_slug, name, slug, short_desc, descr, price, is_new, is_best, is_feat)
JOIN categories c ON c.slug = v.cat_slug
ON CONFLICT DO NOTHING;

INSERT INTO product_variants (product_id, sku, size, color, stock)
SELECT p.id, upper(replace(p.slug,'-','')) || '-' || s.size || '-' || substr(upper(s.color),1,3), s.size, s.color, 20
FROM products p
CROSS JOIN (VALUES ('S','Black'),('M','Black'),('L','Black'),('M','Off White')) AS s(size,color)
WHERE p.slug IN ('essential-t-shirt','relaxed-fit-shirt','everyday-hoodie')
ON CONFLICT DO NOTHING;
INSERT INTO product_variants (product_id, sku, size, color, stock)
SELECT p.id, upper(replace(p.slug,'-','')) || '-' || s.size || '-' || substr(upper(s.color),1,3), s.size, s.color, 15
FROM products p
CROSS JOIN (VALUES ('40','Tan'),('41','Tan'),('42','Tan'),('43','Tan')) AS s(size,color)
WHERE p.slug = 'classic-sneakers'
ON CONFLICT DO NOTHING;
INSERT INTO product_variants (product_id, sku, size, color, stock)
SELECT p.id, 'MINIMALISTTOTE-ONE-TAN', 'One Size', 'Tan', 25 FROM products p WHERE p.slug = 'minimalist-tote'
ON CONFLICT DO NOTHING;

INSERT INTO site_settings (key, value) VALUES
 ('announcement_text','Discover your everyday essentials — New arrivals are here.'),
 ('hero_headline','Style That Speaks for You.'),
 ('hero_subheadline','Everyday essentials, thoughtfully styled for every occasion.'),
 ('hero_cta_label','Shop Collection')
ON CONFLICT DO NOTHING;
COMMIT;
