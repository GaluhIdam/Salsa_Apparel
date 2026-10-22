BEGIN;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(128);

CREATE UNIQUE INDEX IF NOT EXISTS
    uq_payments_provider_idempotency_key
    ON payments (provider, idempotency_key)
    WHERE idempotency_key IS NOT NULL;

COMMIT;