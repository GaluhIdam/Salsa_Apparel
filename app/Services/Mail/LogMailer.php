<?php
declare(strict_types=1);
namespace App\Services\Mail;

use App\Core\Env;

/**
 * Driver email untuk development: menulis pesan ke storage/logs/mail.log, TIDAK mengirim email sungguhan.
 * Isi penuh (termasuk tautan reset) hanya ditulis saat APP_ENV adalah local/testing; di lingkungan lain hanya
 * metadata (penerima + subjek) agar token tidak masuk log produksi, dan send() mengembalikan false.
 */
final class LogMailer implements MailerInterface
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 3) . '/storage/logs/mail.log';
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): bool
    {
        $full = in_array(Env::get('APP_ENV', 'production'), ['local', 'testing'], true);
        $from = Env::get('MAIL_FROM_ADDRESS', 'no-reply@salsa-apparel.test');
        $entry = '[' . date('c') . "] TO: {$toName} <{$toEmail}>\nFROM: {$from}\nSUBJECT: {$subject}\n";
        $entry .= $full ? "-----\n{$textBody}\n" : "(isi pesan tidak dicatat di luar APP_ENV=local/testing)\n";
        $entry .= "=====\n";
        $written = @file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) !== false;
        return $written && $full;
    }
}
