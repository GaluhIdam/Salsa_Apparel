BEGIN;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS provider_order_id VARCHAR(100),
    ADD COLUMN IF NOT EXISTS provider_status VARCHAR(32),
    ADD COLUMN IF NOT EXISTS snap_token VARCHAR(255),
    ADD COLUMN IF NOT EXISTS redirect_url TEXT,
    ADD COLUMN IF NOT EXISTS attempt_number SMALLINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE UNIQUE INDEX IF NOT EXISTS
    uq_payments_provider_order_id
    ON payments (provider, provider_order_id)
    WHERE provider_order_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS
    idx_payments_pending_expiry
    ON payments (expires_at)
    WHERE status = 'pending';

COMMIT;
