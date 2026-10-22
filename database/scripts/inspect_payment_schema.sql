SELECT
    table_name,
    column_name,
    data_type,
    is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
    AND table_name IN (
        'orders',
        'order_items',
        'payments',
        'payment_webhook_events',
        'inventory_movements'
    )
ORDER BY table_name, ordinal_position;
