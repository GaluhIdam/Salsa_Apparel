<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Config\Database;

final class DashboardController extends AdminController
{
    public function index(): void
    {
        $user = $this->requireStaff();
        $pdo = Database::connection();

        $stats = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM users
                WHERE role_id IS NULL) AS customers,

                (SELECT COUNT(*) FROM products
                WHERE status = 'active') AS products,

                (SELECT COUNT(*) FROM orders) AS orders,

                (SELECT COUNT(*) FROM orders
                WHERE payment_status = 'paid') AS paid_orders,

                (SELECT COALESCE(SUM(grand_total), 0)
                FROM orders
                WHERE payment_status = 'paid') AS revenue,

                (SELECT COUNT(*) FROM orders
                WHERE payment_status = 'pending') AS pending_payments,

                (SELECT COUNT(*) FROM orders
                WHERE fulfillment_status IN ('unprocessed', 'processing')
                ) AS pending_fulfillment
        ")->fetch();

        $sales = $pdo->query("
            SELECT
                days.day::date AS sale_date,
                COALESCE(SUM(o.grand_total), 0) AS revenue
            FROM generate_series(
                CURRENT_DATE - INTERVAL '6 days',
                CURRENT_DATE,
                INTERVAL '1 day'
            ) AS days(day)
            LEFT JOIN orders o
                ON o.created_at >= days.day
                AND o.created_at < days.day + INTERVAL '1 day'
                AND o.payment_status = 'paid'
            GROUP BY days.day
            ORDER BY days.day
        ")->fetchAll();

        $recentOrders = $pdo->query("
            SELECT
                id,
                order_number,
                customer_email,
                recipient_name,
                grand_total,
                payment_status,
                fulfillment_status,
                created_at
            FROM orders
            ORDER BY created_at DESC
            LIMIT 8
        ")->fetchAll();

        $topProducts = $pdo->query("
            SELECT
                oi.product_name,
                SUM(oi.quantity) AS units_sold,
                SUM(oi.line_total) AS sales_total
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.payment_status = 'paid'
            GROUP BY oi.product_name
            ORDER BY units_sold DESC, sales_total DESC
            LIMIT 5
        ")->fetchAll();

        $maxSales = 0;
        foreach ($sales as $sale) {
            $maxSales = max($maxSales, (float) $sale['revenue']);
        }

        $this->render('admin/home', [
            'pageTitle' => 'Overview',
            'user' => $user,
            'flash' => flash_get('admin'),
            'stats' => $stats,
            'sales' => $sales,
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
            'maxSales' => $maxSales,
        ]);
    }
}