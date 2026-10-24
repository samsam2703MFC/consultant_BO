<?php
namespace App\Services\Finances;

use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Material\Price\PriceListSyncQueueRepository;

class PricelistSyncService
{

    private PricelistPusher $pricelistPusher;
    private PriceListSyncQueueRepository $priceListSyncQueueRepository;

    public function __construct(
        PricelistPusher $pricelistPusher,
        PriceListSyncQueueRepository $priceListSyncQueueRepository
    )
    {
        $this->pricelistPusher = $pricelistPusher;
        $this->priceListSyncQueueRepository = $priceListSyncQueueRepository;
    }

    public function runPricelistSyncWorker(int $limit = 50)
    {
        $rows = $this->priceListSyncQueueRepository->getPending($limit);

        if(!empty($rows)){
            foreach ($rows as $row) {
                $res = $this->pricelistPusher->pushPricelist($row['id_client']);

                if($res){
                    $this->priceListSyncQueueRepository->delete($row['id_client'], 'id_client');
                } else {
                    $this->priceListSyncQueueRepository->increaseAttempts($row['id_client']);
                }
            }
        }
    }


}