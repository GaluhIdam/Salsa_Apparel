<?php
declare(strict_types=1);
namespace App\Validators;

/** Validasi server-side untuk registrasi, profil, dan password. Mengembalikan [bersih, error]. */
final class AuthValidator
{
    public const PASSWORD_MIN = 8;       // karakter
    public const PASSWORD_MAX_BYTES = 72; // batas bcrypt; tidak pernah dipotong diam-diam

    /** @return array{0:array{name:string,email:string,phone:?string,password:string},1:array<string,string>} */
    public function register(array $in): array
    {
        $errors = [];
        $name = $this->name($in['full_name'] ?? null, $errors);
        $email = $this->email($in['email'] ?? null, $errors);
        $phone = $this->phone($in['phone'] ?? null, $errors);

        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $confirm = is_string($in['password_confirmation'] ?? null) ? $in['password_confirmation'] : '';
        $this->password($password, 'password', $errors);
        if (!isset($errors['password'])) {
            if ($email !== '' && strcasecmp($password, $email) === 0) {
                $errors['password'] = 'Password must not be the same as your email.';
            } elseif (!hash_equals($password, $confirm)) {
                $errors['password_confirmation'] = 'Passwords do not match.';
            }
        }
        return [['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password], $errors];
    }

    /** @return array{0:array{name:string,phone:?string},1:array<string,string>} */
    public function profile(array $in): array
    {
        $errors = [];
        $name = $this->name($in['full_name'] ?? null, $errors);
        $phone = $this->phone($in['phone'] ?? null, $errors);
        return [['name' => $name, 'phone' => $phone], $errors];
    }

    /** @return array{0:array{current:string,new:string},1:array<string,string>} */
    public function passwordChange(array $in): array
    {
        $errors = [];
        $current = is_string($in['current_password'] ?? null) ? $in['current_password'] : '';
        $new = is_string($in['new_password'] ?? null) ? $in['new_password'] : '';
        $confirm = is_string($in['new_password_confirmation'] ?? null) ? $in['new_password_confirmation'] : '';

        if ($current === '') {
            $errors['current_password'] = 'Please enter your current password.';
        }
        $this->password($new, 'new_password', $errors);
        if (!isset($errors['new_password'])) {
            if ($current !== '' && hash_equals($current, $new)) {
                $errors['new_password'] = 'Your new password must be different from the current one.';
            } elseif (!hash_equals($new, $confirm)) {
                $errors['new_password_confirmation'] = 'Passwords do not match.';
            }
        }
        return [['current' => $current, 'new' => $new], $errors];
    }

    /** @return array{0:string,1:array<string,string>} password baru untuk reset (tanpa password lama). */
    public function newPassword(array $in): array
    {
        $errors = [];
        $new = is_string($in['new_password'] ?? null) ? $in['new_password'] : '';
        $confirm = is_string($in['new_password_confirmation'] ?? null) ? $in['new_password_confirmation'] : '';
        $this->password($new, 'new_password', $errors);
        if (!isset($errors['new_password']) && !hash_equals($new, $confirm)) {
            $errors['new_password_confirmation'] = 'Passwords do not match.';
        }
        return [$new, $errors];
    }

    /** Normalisasi email untuk pencarian dan rate limiting. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function name(mixed $v, array &$errors): string
    {
        $name = is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $v)) : '';
        if ($name === '' || mb_strlen($name) < 2) {
            $errors['full_name'] = 'Please enter your full name (at least 2 characters).';
        } elseif (mb_strlen($name) > 120) {
            $errors['full_name'] = 'Name must be at most 120 characters.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            $errors['full_name'] = 'Name contains invalid characters.';
        }
        return $name;
    }

    private function email(mixed $v, array &$errors): string
    {
        $email = is_string($v) ? self::normalizeEmail($v) : '';
        if ($email === '') {
            $errors['email'] = 'Please enter your email address.';
        } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        return $email;
    }

    private function phone(mixed $v, array &$errors): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $phone = preg_replace('/[\s\-().]/', '', trim($v)) ?? '';
        if (preg_match('/^\+?\d{8,15}$/', $phone) !== 1) {
            $errors['phone'] = 'Please enter a valid phone number (8–15 digits, optionally starting with +).';
            return null;
        }
        return $phone;
    }

    private function password(string $p, string $field, array &$errors): void
    {
        if (mb_strlen($p) < self::PASSWORD_MIN) {
            $errors[$field] = 'Password must be at least ' . self::PASSWORD_MIN . ' characters.';
        } elseif (strlen($p) > self::PASSWORD_MAX_BYTES) {
            $errors[$field] = 'Password must be at most ' . self::PASSWORD_MAX_BYTES . ' bytes (some characters use more than one byte).';
        }
    }
}
