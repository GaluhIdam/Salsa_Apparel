
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Repositories\OrderHistoryRepository;
use Throwable;

final class OrderHistoryController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();

        try {
            $orders = (new OrderHistoryRepository())
                ->allByUser((int) $user['id']);

            $this->view('account/orders', [
                'pageTitle' => 'My Orders',
                'user' => $user,
                'orders' => $orders,
                'loadError' => false,
            ]);
        } catch (Throwable $e) {
            error_log(
                'Order history error: ' . $e->getMessage()
            );

            $this->view('account/orders', [
                'pageTitle' => 'My Orders',
                'user' => $user,
                'orders' => [],
                'loadError' => true,
            ], 500);
        }
    }
}
