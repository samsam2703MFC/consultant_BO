<?php
namespace App\Employee\app\Services\Shop;



use App\Employee\app\Repositories\Schedule\ScheduleRepository;
use App\Employee\app\Repositories\Shop\ShopRepository;
use App\Employee\core\Support\GlobalRegistry;

class ShopService {


    public function __construct(private ShopRepository $shopRepository) {
    }

    public function getMe()
    {
        return $this->shopRepository->getById(GlobalRegistry::get('user')->getIdShop());
    }

}