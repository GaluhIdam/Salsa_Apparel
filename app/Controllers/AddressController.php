<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Services\AddressService;

final class AddressController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->view('account/addresses', [
            'pageTitle' => 'Address Book',
            'addresses' => (new AddressService())->list((int) $user['id']),
            'max' => AddressService::MAX_ADDRESSES,
            'flash' => flash_get('account'),
        ]);
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->view('account/address-form', [
            'pageTitle' => 'Add address',
            'address' => null,
            'action' => '/account/addresses',
            'heading' => 'Add address',
            'flash' => flash_get('account'),
        ]);
    }

    public function store(): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        $result = (new AddressService())->create((int) $user['id'], $_POST);
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors']);
            $this->redirect('/account/addresses/new');
        }
        flash_set('account', 'success', 'Address saved.');
        $this->redirect('/account/addresses');
    }

    public function edit(string $id): void
    {
        $user = $this->requireAuth();
        $address = (new AddressService())->find((int) $user['id'], $this->idParam($id));
        if ($address === null) {
            (new PageController())->notFound();
            return;
        }
        $this->view('account/address-form', [
            'pageTitle' => 'Edit address',
            'address' => $address,
            'action' => '/account/addresses/' . $address['id'],
            'heading' => 'Edit address',
            'flash' => flash_get('account'),
        ]);
    }

    public function update(string $id): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        $addressId = $this->idParam($id);
        $result = (new AddressService())->update((int) $user['id'], $addressId, $_POST);
        if (!empty($result['not_found'])) {
            (new PageController())->notFound();
            return;
        }
        if (!$result['ok']) {
            form_state_set($result['old'], $result['errors']);
            $this->redirect('/account/addresses/' . $addressId . '/edit');
        }
        flash_set('account', 'success', 'Address updated.');
        $this->redirect('/account/addresses');
    }

    public function makeDefault(string $id): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        $ok = (new AddressService())->setDefault((int) $user['id'], $this->idParam($id));
        flash_set('account', $ok ? 'success' : 'error', $ok ? 'Default address updated.' : 'Address not found.');
        $this->redirect('/account/addresses');
    }

    public function destroy(string $id): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        $ok = (new AddressService())->delete((int) $user['id'], $this->idParam($id));
        flash_set('account', $ok ? 'success' : 'error', $ok ? 'Address deleted.' : 'Address not found.');
        $this->redirect('/account/addresses');
    }

    private function requireCsrf(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash_set('account', 'error', 'Your session expired. Please try again.');
            $this->redirect('/account/addresses');
        }
    }

    /** ID dari URL: hanya digit; selain itu 0 (tidak pernah cocok dengan baris mana pun). */
    private function idParam(string $v): int
    {
        return preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }
}
