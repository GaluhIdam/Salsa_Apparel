<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;

final class Mailer
{
    public static function make(): MailerInterface
    {
        $driver = strtolower((string) Env::get('MAIL_DRIVER', 'log'));

        return match ($driver) {
            'log' => new LogMailer(),
            'smtp' => new SmtpMailer(),
            default => throw new \RuntimeException(
                "Mail driver '{$driver}' tidak didukung. Gunakan log atau smtp."
            ),
        };
    }
}