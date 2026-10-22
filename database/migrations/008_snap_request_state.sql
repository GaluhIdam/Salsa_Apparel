BEGIN;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS snap_request_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS snap_request_state VARCHAR(20)
        NOT NULL DEFAULT 'not_started';

ALTER TABLE payments
    DROP CONSTRAINT IF EXISTS chk_payments_snap_request_state;

ALTER TABLE payments
    ADD CONSTRAINT chk_payments_snap_request_state
    CHECK (
        snap_request_state IN (
            'not_started',
            'in_progress',
            'unknown',
            'completed'
        )
    );

COMMIT;
