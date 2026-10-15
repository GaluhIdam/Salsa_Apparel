<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Core\Csrf;

final class NewsletterController extends Controller
{
    public function subscribe(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('newsletter', 'error', 'Your session expired. Please try again.');
            $this->redirect('/#newsletter');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('newsletter', 'error', 'Please enter a valid email address.');
            $this->redirect('/#newsletter');
        }

        try {
            $st = Database::connection()->prepare(
                'INSERT INTO newsletter_subscribers (email) VALUES (:e) ON CONFLICT (email) DO NOTHING'
            );
            $st->execute(['e' => $email]);
            if ($st->rowCount() === 1) {
                flash_set('newsletter', 'success', 'You are subscribed. Welcome to Salsa Apparel.');
            } else {
                flash_set('newsletter', 'info', 'This email is already subscribed.');
            }
        } catch (\Throwable $e) {
            error_log('Newsletter subscribe failed: ' . $e->getMessage());
            flash_set('newsletter', 'error', 'We could not save your email. Please try again later.');
        }
        $this->redirect('/#newsletter');
    }
}
