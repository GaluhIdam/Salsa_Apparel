-- Phase 3: hubungkan gambar placeholder ke seed. Aman dijalankan ulang; tidak menimpa gambar yang sudah diisi.
--   psql -U salsa_app -d salsa_apparel -f database/migrations/002_placeholder_images.sql
BEGIN;
UPDATE categories c SET image_path = 'assets/images/placeholders/cat-' || c.slug || '.svg'
WHERE c.image_path IS NULL
  AND c.slug IN ('accessories','men','women','hoodies','t-shirts','shirts','shoes');

INSERT INTO product_images (product_id, path, alt_text, sort_order)
SELECT p.id, 'assets/images/placeholders/product-' || p.slug || '.svg', p.name, 0
FROM products p
WHERE p.slug IN ('essential-t-shirt','relaxed-fit-shirt','everyday-hoodie','classic-sneakers','minimalist-tote')
  AND NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id);

INSERT INTO site_settings (key, value) VALUES
 ('hero_image','assets/images/placeholders/hero.svg'),
 ('editorial_image','assets/images/placeholders/editorial.svg')
ON CONFLICT (key) DO NOTHING;
COMMIT;
