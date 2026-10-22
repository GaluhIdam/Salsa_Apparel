SELECT
    column_name,
    data_type,
    character_maximum_length
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'payments'
  AND column_name IN (
      'idempotency_key',
      'provider_order_id',
      'provider_transaction_id'
  )
ORDER BY column_name;
