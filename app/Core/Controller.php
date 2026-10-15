<?php
declare(strict_types=1);
namespace App\Core;

use App\Repositories\CategoryRepository;

abstract class Controller
{
    protected function view(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        $data += [
            'navCategories' => (new CategoryRepository())->allWithCounts(),
            'pageTitle' => null,
            'hasHero' => false,
        ];
        View::render($view, $data);
    }

    /** Wajib login. Guest diarahkan ke /login (GET membawa ?next= ke halaman semula). */
    protected function requireAuth(): array
    {
        $user = Auth::user();
        if ($user !== null) {
            return $user;
        }
        flash_set('auth', 'info', 'Please sign in to continue.');
        $isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
        $this->redirect($isGet
            ? url_with_query('/login', ['next' => (string) ($_SERVER['REQUEST_URI'] ?? '')])
            : '/login');
    }

    protected function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
