<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;

final class SettingRepository
{
    /** @return array<string,string> */
    public function all(): array
    {
        $rows = Database::connection()->query('SELECT key, value FROM site_settings WHERE is_secret = FALSE');
        return $rows->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
