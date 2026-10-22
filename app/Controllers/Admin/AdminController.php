<?php
declare(strict_types=1);
namespace App\Controllers\Admin;

use App\Controllers\PageController;
use App\Core\AdminAuth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\View;

/** Basis controller admin: wajib login staf, pemeriksaan izin (RBAC) di backend, CSRF, dan layout admin. */
abstract class AdminController extends Controller
{
    /** Wajib login staf; $permission (opsional) wajib dimiliki role. Guest -> /admin/login, tanpa izin -> 403. */
    protected function requireStaff(?string $permission = null): array
    {
        $user = AdminAuth::user();
        if ($user === null) {
            flash_set('admin', 'info', 'Please sign in to continue.');
            $isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
            $this->redirect($isGet
                ? url_with_query('/admin/login', ['next' => (string) ($_SERVER['REQUEST_URI'] ?? '')])
                : '/admin/login');
        }
        if ($permission !== null && !AdminAuth::can($permission)) {
            $this->forbidden();
        }
        return $user;
    }

    protected function forbidden(): never
    {
        $this->render('admin/forbidden', ['pageTitle' => 'Access denied'], 403);
        exit;
    }

    protected function notFound(): void
    {
        (new PageController())->notFound();
    }

    protected function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        View::render($view, $data + ['adminUser' => AdminAuth::user(), 'pageTitle' => 'Admin'], 'layouts/admin');
    }

    protected function requireCsrf(string $back): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('admin', 'error', 'Your session expired. Please try again.');
            $this->redirect($back);
        }
    }

    protected function idParam(string $v): int
    {
        return preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }
}
