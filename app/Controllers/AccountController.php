<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Services\AuthService;

final class AccountController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->view('account/index', [
            'pageTitle' => 'My Account',
            'user' => $user,
            'flash' => flash_get('account'),
        ]);
    }

    public function updateProfile(): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();

        $result = (new AuthService())->updateProfile((int) $user['id'], $_POST);
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors'] + ['_section' => 'profile']);
            flash_set('account', 'error', 'Please correct the highlighted fields.');
            $this->redirect('/account#profile');
        }
        flash_set('account', 'success', 'Your profile has been updated.');
        $this->redirect('/account');
    }

    public function changePassword(): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();

        $result = (new AuthService())->changePassword((int) $user['id'], $_POST);
        if (!$result['ok']) {
            form_state_set([], $result['errors'] + ['_section' => 'password']);
            flash_set('account', 'error', 'Your password was not changed.');
            $this->redirect('/account#password');
        }
        Auth::refresh(); // ID session + token CSRF baru setelah perubahan kredensial
        flash_set('account', 'success', 'Your password has been changed.');
        $this->redirect('/account');
    }

    private function requireCsrf(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('account', 'error', 'Your session expired. Please try again.');
            $this->redirect('/account');
        }
    }
}
