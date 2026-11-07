<?php
namespace App\Controllers\Api\V1\Client;

use App\Core\Api\BaseController;
use App\Core\Api\ResponseHandler;
use App\Exceptions\CustomException;
use App\Services\Client\FranchiseNetwork\IntegrationOnboardingTokenService;

class ClientController extends BaseController
{
    private IntegrationOnboardingTokenService $integrationOnboardingTokenService;

    public function __construct(IntegrationOnboardingTokenService $integrationOnboardingTokenService)
    {
        $this->integrationOnboardingTokenService = $integrationOnboardingTokenService;
    }

    public function registerClientAsIntegrated()
    {
        $data = $this->getValueFromPostRequest();
        if(empty($data))
            ResponseHandler::sendError('MISSING_DATA', 400);

        try {
            $registeredClientData = $this->integrationOnboardingTokenService->registerClient($data);
            if(!$registeredClientData) {
                ResponseHandler::sendError('UNDEFINED_ERROR');
            }

            ResponseHandler::send(['data' => $registeredClientData]);

        } catch (CustomException $e) {
            ResponseHandler::sendError($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            // Ogólna obsługa innych wyjątków
            ResponseHandler::sendError('GENERAL_REGISTER_ERROR', 500);
        }
    }
}