<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Env;
use App\Services\PasswordResetService;

final class PasswordResetController extends Controller
{
    public function showForgot(): void
    {
        $this->view('auth/forgot-password', [
            'pageTitle' => 'Forgot password',
            'flash' => flash_get('auth'),
            'devMail' => config('app')['env'] === 'local'
                && strtolower((string) Env::get('MAIL_DRIVER', 'log')) === 'log',
        ]);
    }

    public function sendForgot(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('auth', 'error', 'Your session expired. Please try again.');
            $this->redirect('/forgot-password');
        }
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        try {
            (new PasswordResetService())->requestReset($email);
        } catch (\Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
        }
        // Selalu pesan yang sama: tidak membocorkan apakah email terdaftar atau sedang dibatasi.
        flash_set('auth', 'success', 'If an account exists for that email, we have sent instructions to reset the password.');
        $this->redirect('/forgot-password');
    }

    /** Token di URL hanya dipakai sekali: divalidasi, dipindah ke session, lalu URL dibersihkan (tidak bocor lewat Referer). */
    public function showReset(): void
    {
        $service = new PasswordResetService();
        $fromUrl = $_GET['token'] ?? null;
        if (is_string($fromUrl)) {
            if ($service->isTokenValid($fromUrl)) {
                $_SESSION['reset_token'] = $fromUrl;
                $this->redirect('/reset-password');
            }
            unset($_SESSION['reset_token']);
            flash_set('auth', 'error', PasswordResetService::INVALID_TOKEN);
            $this->redirect('/forgot-password');
        }

        $token = $_SESSION['reset_token'] ?? null;
        if (!is_string($token) || !$service->isTokenValid($token)) {
            unset($_SESSION['reset_token']);
            flash_set('auth', 'error', PasswordResetService::INVALID_TOKEN);
            $this->redirect('/forgot-password');
        }
        $this->view('auth/reset-password', ['pageTitle' => 'Reset password', 'flash' => flash_get('auth')]);
    }

    public function reset(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('auth', 'error', 'Your session expired. Please try again.');
            $this->redirect('/reset-password');
        }
        $token = $_SESSION['reset_token'] ?? null;
        if (!is_string($token)) {
            flash_set('auth', 'error', PasswordResetService::INVALID_TOKEN);
            $this->redirect('/forgot-password');
        }
        $result = (new PasswordResetService())->resetPassword($token, $_POST);
        if (!$result['ok']) {
            if (isset($result['errors']['token'])) {
                unset($_SESSION['reset_token']);
                flash_set('auth', 'error', $result['errors']['token']);
                $this->redirect('/forgot-password');
            }
            form_state_set([], $result['errors']);
            $this->redirect('/reset-password');
        }
        unset($_SESSION['reset_token']);
        flash_set('auth', 'success', 'Your password has been reset. Please sign in with your new password.');
        $this->redirect('/login');
    }
}
