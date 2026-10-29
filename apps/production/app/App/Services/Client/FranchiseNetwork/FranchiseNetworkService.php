<?php

namespace App\Services\Client\FranchiseNetwork;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\FranchiseNetwork\FranchiseNetworkRepository;

class FranchiseNetworkService
{

    private FranchiseNetworkRepository $franchiseNetworkRepository;
    private TransactionManager $transactionManager;
    private ClientRepository $clientRepository;

    public function __construct(
        FranchiseNetworkRepository $franchiseNetworkRepository,
        TransactionManager         $transactionManager,
        ClientRepository           $clientRepository
    )
    {
        $this->franchiseNetworkRepository = $franchiseNetworkRepository;
        $this->transactionManager = $transactionManager;
        $this->clientRepository = $clientRepository;
    }

    function validate(array $data): array
    {
        $errors = [];

        // Walidacja nazwy
        if (empty(trim($data['name'] ?? ''))) {
            $errors[] = "Pole 'Nazwa' jest wymagane.";
        } elseif (mb_strlen($data['name']) > 100) {
            $errors[] = "Nazwa nie może przekraczać 100 znaków.";
        }

        // Walidacja emaila
        if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Podany email kontaktowy jest niepoprawny.";
        }

        // Walidacja telefonu (opcjonalnie +48, cyfry, długość 9–15 znaków)
        if (!empty($data['contact_phone']) && !preg_match('/^\+?[0-9\s\-]{9,15}$/', $data['contact_phone'])) {
            $errors[] = "Numer telefonu jest niepoprawny. Dozwolone są cyfry, spacje, myślniki i opcjonalne + na początku.";
        }

        // Walidacja kodu
        if (empty(trim($data['code'] ?? ''))) {
            $errors[] = "Pole 'Kod' jest wymagane.";
        } elseif (!preg_match('/^[a-z0-9\-]+$/', $data['code'])) {
            $errors[] = "Kod może zawierać tylko małe litery, cyfry i myślniki.";
        } elseif (strlen($data['code']) > 100) {
            $errors[] = "Kod nie może być dłuższy niż 100 znaków.";
        }

        // Możesz dodać dodatkową walidację unikalności kodu, np. w bazie danych:
        // if (isCodeTaken($data['code'])) {
        //     $errors[] = "Podany kod jest już używany przez inną sieć.";
        // }

        return $errors;
    }

    public function getAll()
    {
        return $this->franchiseNetworkRepository->getAllWithShopQuantity();
    }

    public function getById($id)
    {
        return $this->franchiseNetworkRepository->first(['id' => $id]);
    }


    public function getFranchises($id)
    {
        return $this->clientRepository->getByFranchiseNetwork($id);
    }

    public function insert($data)
    {
        if(!isset($data['contact_phone'])) $data['contact_phone'] = null;
        if(!isset($data['contact_email'])) $data['contact_email'] = null;

        return $this->franchiseNetworkRepository->insert($data);
    }

    public function update($id, $data)
    {
        if(!isset($data['contact_phone'])) $data['contact_phone'] = null;
        if(!isset($data['contact_email'])) $data['contact_email'] = null;

        return $this->franchiseNetworkRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->transactionManager->execute(function () use ($id) {

            $deleted = $this->franchiseNetworkRepository->delete($id);
            if(!$deleted) throw new CustomException("NETWORK_DELETE_FAILED", 'errors', 500);

            $resUpdateClients = $this->clientRepository->update($id, ['franchise_network_id' => null], 'franchise_network_id');
            if(!$resUpdateClients) throw new CustomException("NETWORK_UNSET_FAILED", 'errors', 500);

            return true;
        });
    }


}