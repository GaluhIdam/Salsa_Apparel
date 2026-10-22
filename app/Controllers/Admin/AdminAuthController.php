<?php
declare(strict_types=1);
namespace App\Controllers\Admin;

use App\Core\AdminAuth;
use App\Services\AuthService;

/** Login admin memakai AuthService yang sama (hash, rate limit, pesan generik); hanya akun ber-role yang diterima. */
final class AdminAuthController extends AdminController
{
    public function showLogin(): void
    {
        if (AdminAuth::check()) {
            $this->redirect('/admin');
        }
        $this->render('admin/login', [
            'pageTitle' => 'Admin sign in',
            'next' => $this->safeNext($_GET['next'] ?? null),
            'flash' => flash_get('admin'),
        ]);
    }

    public function login(): void
    {
        $next = $this->safeNext($_POST['next'] ?? null);
        $back = url_with_query('/admin/login', ['next' => $next]);
        $this->requireCsrf($back);

        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $result = (new AuthService())->login($email, $password, null, 'admin');
        if (!$result['ok']) {
            form_state_set(['email' => trim($email)], ['form' => (string) $result['error']]);
            $this->redirect($back);
        }
        AdminAuth::login((int) $result['user_id']);
        flash_set('admin', 'success', 'Welcome back.');
        $this->redirect($next !== '' ? $next : '/admin');
    }

    public function logout(): void
    {
        $this->requireCsrf('/admin');
        AdminAuth::logout();
        flash_set('admin', 'success', 'You have been signed out.');
        $this->redirect('/admin/login');
    }

    /** Hanya path internal di bawah /admin (mencegah open redirect). */
    private function safeNext(mixed $v): string
    {
        $path = safe_redirect_path($v, '');
        return ($path === '/admin' || str_starts_with($path, '/admin/')) ? $path : '';
    }
}
