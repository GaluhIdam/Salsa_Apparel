<?php
declare(strict_types=1);
namespace App\Services\Mail;

interface MailerInterface
{
    /** @return bool true hanya jika pesan benar-benar diserahkan ke transport (log driver di produksi mengembalikan false). */
    public function send(string $toEmail, string $toName, string $subject, string $textBody): bool;
}
