<?php
namespace App\Services\Client;


use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderProductRepository;
use App\Repositories\Client\Order\OrderRepository;
use App\Repositories\Product\Price\PriceListRepository;
use App\Services\Client\Vies\ViesClient;
use App\Services\Client\Vies\ViesUnavailableException;
use InvalidArgumentException;

class ClientService
{
    private ClientRepository $clientRepository;
    private TransactionManager $transactionManager;
    private PriceListRepository $priceListRepository;
    private ViesClient $viesClient;

    public function __construct(
        ClientRepository $clientRepository,
        TransactionManager $transactionManager,
        PriceListRepository $priceListRepository,
        ViesClient $viesClient
    )
    {
        $this->clientRepository = $clientRepository;
        $this->transactionManager = $transactionManager;
        $this->priceListRepository = $priceListRepository;
        $this->viesClient = $viesClient;
    }

    public function getAll()
    {
        return $this->clientRepository->findAll();
    }

    public function validate($data)
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = "Wprowadź imię właściciela";
        }

        if (empty($data['surname'])) {
            $errors['surname'] = "Wprowadź nazwisko właściciela";
        }

        if (empty($data['fac_street'])) {
            $errors['fac_street'] = "Wprowadź nazwę ulicę";
        }
        if (empty($data['fac_street_num'])) {
            $errors['fac_street_num'] = "Wprowadź numer budynku";
        }
        if (empty($data['fac_zip'])) {
            $errors['fac_zip'] = "Wprowadź kod pocztowy";
        }
        if (empty($data['fac_town'])) {
            $errors['fac_town'] = "Wprowadź miejscowość";
        }
        if (empty($data['id_country'])) {
            $errors['id_country'] = "Wybierz kraj";
        }

        return $errors;
    }

    public function getFranchisee()
    {
        return $this->clientRepository->getFranchisee();
    }
    public function getActive()
    {
        return $this->clientRepository->getActive();
    }
    public function getInactive()
    {
        return $this->clientRepository->getInactive();
    }

    public function update($id, $data)
    {
        $data = $this->normalizeTaxNumber($data);
        return $this->clientRepository->update($id, $data);
    }

    public function insert($data)
    {
        $data = $this->normalizeTaxNumber($data);
        return $this->transactionManager->execute(function () use ($data) {

            $resInsert = $this->clientRepository->insert($data);
            if(!$resInsert){
                throw new CustomException("INSERT_CLIENT_ERROR", 'errors', 500);
            }
            $insertedId = $resInsert;

            if(isset($data['price_list_type']) && $data['price_list_type'] == "standard_price_list"){
                $resPriceList = $this->priceListRepository->assignStandardPriceList(['id_customer' => $insertedId]);
                if(!$resPriceList){
                    throw new CustomException("INSERT_PRICE_LIST_ERROR", 'errors', 500);
                }
            } else {
                $resPriceList = $this->priceListRepository->copyStandardPriceList([
                    'id_customer' => $insertedId,
                    'id_copy_from_customer' => $data['customer_price_list_sel']
                ]);
                if(!$resPriceList){
                    throw new CustomException("INSERT_PRICE_LIST_ERROR", 'errors', 500);
                }
            }
            return $insertedId;
        });
    }

    public function lookupVies(string $vat): array
    {
        try {
            return ['ok' => true, 'data' => $this->viesClient->lookup($vat), 'code' => 200];
        } catch (InvalidArgumentException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'code' => 422];
        } catch (ViesUnavailableException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'code' => 503];
        }
    }

    private function normalizeTaxNumber(array $data): array
    {
        if (!isset($data['tax_number']) || trim((string) $data['tax_number']) === '') {
            return $data;
        }

        try {
            $data['tax_number'] = ViesClient::normalizeVat((string) $data['tax_number'])['vat'];
        } catch (InvalidArgumentException) {
            $data['tax_number'] = strtoupper(trim((string) $data['tax_number']));
        }

        return $data;
    }

    public function delete($id)
    {
        return $this->clientRepository->delete($id);
    }

    public function getById($id)
    {
        return $this->clientRepository->first(['id' => $id]);
    }

    function generateFranchiseeCode(string $name): string
    {
        // Normalize the name (e.g. "Donut Planet" → "donut")
        $short = strtolower(preg_replace('/[^a-z0-9]/i', '', explode(' ', $name)[0]));
        $short = substr($short, 0, 8); // limit to 8 chars

        // Generate random suffix
        $random = substr(bin2hex(random_bytes(3)), 0, 6); // e.g. 'a3f91c'

        // Combine into code
        return "frz-{$short}-{$random}";
    }
}
