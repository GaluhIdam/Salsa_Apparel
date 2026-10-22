<?php
declare(strict_types=1);
namespace App\Validators;

final class AddressValidator
{
    /** @return array{0:array{recipient_name:string,phone:string,address_line:string,city:string,province:string,postal_code:string,is_default:bool},1:array<string,string>} */
    public function validate(array $in): array
    {
        $errors = [];
        $name = $this->line($in['recipient_name'] ?? null);
        $phone = is_string($in['phone'] ?? null) ? (string) preg_replace('/[\s\-().]/', '', trim($in['phone'])) : '';
        $address = $this->line($in['address_line'] ?? null);
        $city = $this->line($in['city'] ?? null);
        $province = $this->line($in['province'] ?? null);
        $postal = is_string($in['postal_code'] ?? null) ? trim($in['postal_code']) : '';

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['recipient_name'] = 'Please enter the recipient name (2–120 characters).';
        }
        if (preg_match('/^\+?\d{8,15}$/', $phone) !== 1) {
            $errors['phone'] = 'Please enter a valid phone number (8–15 digits, optionally starting with +).';
        }
        if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
            $errors['address_line'] = 'Please enter the street address (5–255 characters).';
        }
        if (mb_strlen($city) < 2 || mb_strlen($city) > 100) {
            $errors['city'] = 'Please enter the city (2–100 characters).';
        }
        if (mb_strlen($province) < 2 || mb_strlen($province) > 100) {
            $errors['province'] = 'Please enter the province (2–100 characters).';
        }
        if (preg_match('/^\d{5}$/', $postal) !== 1) {
            $errors['postal_code'] = 'Please enter a 5-digit postal code.';
        }

        return [[
            'recipient_name' => $name, 'phone' => $phone, 'address_line' => $address,
            'city' => $city, 'province' => $province, 'postal_code' => $postal,
            'is_default' => !empty($in['is_default']),
        ], $errors];
    }

    /** Satu baris: spasi dirapikan; karakter kontrol dibuang. */
    private function line(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }
}
