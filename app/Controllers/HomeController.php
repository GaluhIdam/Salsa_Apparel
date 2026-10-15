<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Repositories\ProductRepository;

final class HomeController extends Controller
{
    public function index(): void
    {
        $products = new ProductRepository();
        $this->view('pages/home', [
            'hasHero' => true,
            'newArrivals' => $products->newArrivals(8),
            'featured' => $products->featured(3),
        ]);
    }
}
