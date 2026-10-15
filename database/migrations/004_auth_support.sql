-- Phase 7: dukungan autentikasi. Aditif & idempoten; tidak menghapus/mengubah data.
-- Rate limit login memakai tabel login_attempts yang sudah ada (indeks (identifier, attempted_at) sudah ada);
-- migrasi ini hanya menambah indeks untuk pencarian per IP.
--   psql -U salsa_app -d salsa_apparel -f database/migrations/004_auth_support.sql
BEGIN;
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts (ip_address, attempted_at);
COMMIT;
