<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use App\Repositories\AddressRepository;
use App\Repositories\AuditLogRepository;
use App\Validators\AddressValidator;

/** Address book akun. Setiap operasi dibatasi pada user pemilik dan berjalan atomik (user dikunci). */
final class AddressService
{
    public const MAX_ADDRESSES = 10;

    private AddressRepository $repo;
    private AuditLogRepository $audit;
    private AddressValidator $validator;

    public function __construct()
    {
        $this->repo = new AddressRepository();
        $this->audit = new AuditLogRepository();
        $this->validator = new AddressValidator();
    }

    public function list(int $userId): array
    {
        return array_map([$this, 'normalize'], $this->repo->listByUser($userId));
    }

    public function find(int $userId, int $id): ?array
    {
        $row = $id > 0 ? $this->repo->findForUser($id, $userId) : null;
        return $row === null ? null : $this->normalize($row);
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,id:?int} */
    public function create(int $userId, array $input, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->validate($input);
        $old = $this->old($data, $input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old, 'id' => null];
        }
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($userId, $data, $old, $ip): array {
            $this->repo->lockUser($userId);
            $count = $this->repo->countByUser($userId);
            if ($count >= self::MAX_ADDRESSES) {
                return ['ok' => false, 'errors' => ['form' => 'You can save up to ' . self::MAX_ADDRESSES . ' addresses. Delete one to add another.'], 'old' => $old, 'id' => null];
            }
            $default = $data['is_default'] || $count === 0;
            if ($default) {
                $this->repo->clearDefault($userId);
            }
            $id = $this->repo->insert($userId, $data, $default);
            $this->audit->log($userId, 'address.create', 'address', $id, null, $ip);
            return ['ok' => true, 'errors' => [], 'old' => [], 'id' => $id];
        });
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,id:?int,not_found?:bool} */
    public function update(int $userId, int $id, array $input, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->validate($input);
        $old = $this->old($data, $input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old, 'id' => $id];
        }
        $ip ??= AuthService::clientIp();
        return Database::transaction(function () use ($userId, $id, $data, $old, $ip): array {
            $this->repo->lockUser($userId);
            $current = $this->repo->findForUser($id, $userId);
            if ($current === null) {
                return ['ok' => false, 'errors' => ['form' => 'Address not found.'], 'old' => $old, 'id' => $id, 'not_found' => true];
            }
            // Mencentang "default" memindahkan default; tidak mencentang pada default saat ini tidak menghapusnya
            // (pengguna harus memilih alamat lain sebagai default).
            $makeDefault = $data['is_default'] && !$this->truthy($current['is_default']);
            if ($makeDefault) {
                $this->repo->clearDefault($userId);
            }
            $this->repo->update($id, $userId, $data, $makeDefault);
            $this->audit->log($userId, 'address.update', 'address', $id, null, $ip);
            return ['ok' => true, 'errors' => [], 'old' => [], 'id' => $id];
        });
    }

    public function setDefault(int $userId, int $id, ?string $ip = null): bool
    {
        $ip ??= AuthService::clientIp();
        return (bool) Database::transaction(function () use ($userId, $id, $ip): bool {
            $this->repo->lockUser($userId);
            if ($this->repo->findForUser($id, $userId) === null) {
                return false;
            }
            $this->repo->clearDefault($userId);
            $this->repo->setDefault($id, $userId);
            $this->audit->log($userId, 'address.default', 'address', $id, null, $ip);
            return true;
        });
    }

    public function delete(int $userId, int $id, ?string $ip = null): bool
    {
        $ip ??= AuthService::clientIp();
        return (bool) Database::transaction(function () use ($userId, $id, $ip): bool {
            $this->repo->lockUser($userId);
            $current = $this->repo->findForUser($id, $userId);
            if ($current === null) {
                return false;
            }
            $this->repo->delete($id, $userId);
            if ($this->truthy($current['is_default'])) {
                $this->repo->promoteNewest($userId);
            }
            $this->audit->log($userId, 'address.delete', 'address', $id, null, $ip);
            return true;
        });
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_default'] = $this->truthy($row['is_default']);
        return $row;
    }

    /** @return array<string,string> */
    private function old(array $data, array $input): array
    {
        return [
            'recipient_name' => $data['recipient_name'],
            'phone' => is_string($input['phone'] ?? null) ? trim($input['phone']) : '',
            'address_line' => $data['address_line'],
            'city' => $data['city'],
            'province' => $data['province'],
            'postal_code' => $data['postal_code'],
            'is_default' => $data['is_default'] ? '1' : '',
        ];
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
