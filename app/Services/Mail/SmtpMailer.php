<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements MailerInterface
{
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $textBody
    ): bool {
        $host = trim((string) Env::get('MAIL_HOST', ''));
        $username = trim((string) Env::get('MAIL_USERNAME', ''));
        $password = (string) Env::get('MAIL_PASSWORD', '');
        $fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', $username));
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Salsa Apparel');
        $port = (int) Env::get('MAIL_PORT', '587');

        if (
            $host === '' ||
            $username === '' ||
            $password === '' ||
            $fromAddress === ''
        ) {
            throw new \RuntimeException(
                'Konfigurasi SMTP belum lengkap.'
            );
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(false);

        $mail->SMTPSecure = $port === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body = $textBody;

        return $mail->send();
    }
}