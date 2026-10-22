-- Phase 8: dukungan address book dan reset password. Aditif & idempoten; tidak mengubah data.
--   psql -U salsa_app -d salsa_apparel -f database/migrations/005_account_support.sql
BEGIN;
CREATE INDEX IF NOT EXISTS idx_addresses_user ON addresses (user_id);
CREATE INDEX IF NOT EXISTS idx_password_reset_user_open ON password_reset_tokens (user_id) WHERE used_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_password_reset_expires ON password_reset_tokens (expires_at);
COMMIT;
