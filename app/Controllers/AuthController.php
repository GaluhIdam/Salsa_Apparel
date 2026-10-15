<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/account');
        }
        $this->view('auth/login', [
            'pageTitle' => 'Sign in',
            'next' => safe_redirect_path($_GET['next'] ?? null, ''),
            'flash' => flash_get('auth'),
        ]);
    }

    public function login(): void
    {
        $next = safe_redirect_path($_POST['next'] ?? null, '');
        $loginUrl = url_with_query('/login', ['next' => $next]);
        $this->requireCsrf($loginUrl);

        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $service = new AuthService();
        $result = $service->login($email, $password);

        if (!$result['ok']) {
            form_state_set(['email' => trim($email)], ['form' => (string) $result['error']]);
            $this->redirect($loginUrl);
        }
        $this->completeLogin($service, (int) $result['user_id'], 'Welcome back.', $next !== '' ? $next : '/account');
    }

    public function showRegister(): void
    {
        if (Auth::check()) {
            $this->redirect('/account');
        }
        $this->view('auth/register', ['pageTitle' => 'Create account', 'flash' => flash_get('auth')]);
    }

    public function register(): void
    {
        $this->requireCsrf('/register');
        $service = new AuthService();
        $result = $service->register($_POST);

        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors']);
            $this->redirect('/register');
        }
        $this->completeLogin($service, (int) $result['user_id'], 'Your account has been created. Welcome to Salsa Apparel.', '/account');
    }

    public function logout(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('account', 'error', 'Your session expired. Please try again.');
            $this->redirect(Auth::check() ? '/account' : '/login');
        }
        Auth::logout();
        flash_set('auth', 'success', 'You have been signed out.');
        $this->redirect('/login');
    }

    /** Mulai session login (ID session + CSRF baru), gabungkan data guest, lalu redirect. */
    private function completeLogin(AuthService $service, int $userId, string $message, string $to): never
    {
        Auth::login($userId);
        $notices = $service->mergeGuestData($userId);
        flash_set('account', 'success', trim($message . ' ' . implode(' ', $notices)));
        $this->redirect($to);
    }

    private function requireCsrf(string $back): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('auth', 'error', 'Your session expired. Please try again.');
            $this->redirect($back);
        }
    }
}
