<?php
namespace App\Controllers\Api\V1\ClientOrder;


use App\Core\Api\BaseController;
use App\Core\Api\ResponseHandler;
use App\Exceptions\CustomException;
use App\Services\Client\Order\OrderService;
use App\Services\Document\ProformaService;

class ClientOrderController extends BaseController
{

    private OrderService $orderService;
    private ProformaService $proformaService;
    private \App\Services\Client\ApiCommunication\ProformaService $communicationProformaService;

    public function __construct(
        OrderService $orderService,
        ProformaService $proformaService,
        \App\Services\Client\ApiCommunication\ProformaService $communicationProformaService,
    )
    {
        $this->orderService = $orderService;
        $this->proformaService = $proformaService;
        $this->communicationProformaService = $communicationProformaService;
    }

    public function insertOrder()
    {
        $data = $this->getValueFromPostRequest();
        if(empty($data))
            ResponseHandler::sendError('MISSING_DATA', 400);

        try {
            $this->orderService->validateOrderFromApi($data);

            $idOrder = $this->orderService->insertFromApiWithProforma($data);
            if (!$idOrder) {
                ResponseHandler::sendError('UNDEFINED_ERROR');
            }

            ResponseHandler::send(['data' => [
                'internal_id_order' => $idOrder,
                'order_reference' => $data['order_key'],
                'status' => 'received']
            ]);
        } catch (CustomException $e) {
            ResponseHandler::sendError('ORDER_ERROR:' . $e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            // Ogólna obsługa innych wyjątków
            ResponseHandler::sendError('GENERAL_ORDER_ERROR', 500);
        }
    }

    public function test()
    {
//        $result = $this->proformaService->doSomething();
        $result = "XD";
        return ['status' => 'ok', 'data' => $result];
    }
}