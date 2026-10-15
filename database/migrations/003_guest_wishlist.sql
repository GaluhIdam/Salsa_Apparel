-- Phase 6: wishlist guest. Schema awal mewajibkan wishlists.user_id (NOT NULL) sehingga guest
-- tidak bisa memiliki wishlist. Perubahan minimal & aditif (aman dijalankan ulang):
--   1) user_id boleh NULL (wishlist guest),
--   2) guest_token_hash (hash SHA-256 token cookie, sama polanya dengan carts),
--   3) updated_at untuk retensi/pembersihan wishlist guest yang kedaluwarsa,
--   4) CHECK: setiap wishlist wajib punya pemilik (user ATAU token guest).
-- Jalankan: psql -U salsa_app -d salsa_apparel -f database/migrations/003_guest_wishlist.sql
BEGIN;
ALTER TABLE wishlists ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS guest_token_hash CHAR(64);
ALTER TABLE wishlists ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
CREATE UNIQUE INDEX IF NOT EXISTS uq_wishlists_guest_token ON wishlists (guest_token_hash);
CREATE INDEX IF NOT EXISTS idx_wishlists_guest_updated ON wishlists (updated_at) WHERE user_id IS NULL;
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wishlists_owner_check') THEN
    ALTER TABLE wishlists ADD CONSTRAINT wishlists_owner_check
      CHECK (user_id IS NOT NULL OR guest_token_hash IS NOT NULL);
  END IF;
END $$;
COMMIT;
