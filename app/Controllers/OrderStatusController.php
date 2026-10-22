<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Repositories\PaymentRepository;
use Throwable;

final class OrderStatusController extends Controller
{
    public function show(): void
    {
        $pending = $_SESSION['pending_order'] ?? null;

        if (
            !is_array($pending)
            || !isset($pending['order_id'], $pending['access_token'])
            || !is_int($pending['order_id'])
            || !is_string($pending['access_token'])
            || !preg_match('/^[a-f0-9]{64}$/', $pending['access_token'])
        ) {
            http_response_code(404);
            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => null,
                'payment' => null,
            ]);
            return;
        }

        try {
            $pdo = Database::connection();
            $repository = new PaymentRepository();

            $order = $repository->findOrderForStatus(
                $pdo,
                $pending['order_id'],
                $pending['access_token']
            );

            if ($order === null) {
                http_response_code(404);
                $this->view('pages/order-status', [
                    'pageTitle' => 'Order Status',
                    'order' => null,
                    'payment' => null,
                ]);
                return;
            }

            $payment = $repository->findLatestAttemptForOrder(
                $pdo,
                $pending['order_id']
            );

            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => $order,
                'payment' => $payment,
            ]);
        } catch (Throwable $e) {
            error_log('Order status error: ' . $e->getMessage());

            http_response_code(500);
            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => null,
                'payment' => null,
                'loadError' => true,
            ]);
        }
    }
}