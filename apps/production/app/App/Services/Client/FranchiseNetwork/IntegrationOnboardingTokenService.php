<?php
namespace App\Services\Client\FranchiseNetwork;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\FranchiseNetwork\FranchiseNetworkRepository;
use App\Repositories\Client\FranchiseNetwork\IntegrationOnboardingTokenRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

class IntegrationOnboardingTokenService
{
    private IntegrationOnboardingTokenRepository $integrationOnboardingTokenRepository;
    private ClientRepository $clientRepository;
    private FranchiseNetworkRepository $franchiseNetworkRepository;

    private TransactionManager $transactionManager;

    public function __construct(
        IntegrationOnboardingTokenRepository $integrationOnboardingTokenRepository,
        ClientRepository $clientRepository,
        FranchiseNetworkRepository $franchiseNetworkRepository,
        TransactionManager $transactionManager
    )
    {
        $this->integrationOnboardingTokenRepository = $integrationOnboardingTokenRepository;
        $this->clientRepository = $clientRepository;
        $this->franchiseNetworkRepository = $franchiseNetworkRepository;
        $this->transactionManager = $transactionManager;
    }

    public function getForClient($id)
    {
        $client = $this->clientRepository->first(['id' => $id]);
        if(!$client) throw new CustomException("MISSING_CLIENT", 'errors', 404);

        if(is_null($client['franchise_network_id'])) throw new CustomException("CLIENT_NOT_IN_FRANCHISE_NETWORK", 'errors', 404);

        $unsuedToken = $this->getUnusedTokenByClient($id);

        if($unsuedToken && $unsuedToken['expires_at'] > date('Y-m-d H:i:s')) {
            return $unsuedToken['token_hash'];
        } else if($unsuedToken && $unsuedToken['expires_at'] <= date('Y-m-d H:i:s')) {
            $this->closeToken($unsuedToken['token_hash']);
        }

        $tokenData = $this->generateOnboardingToken();

        $resInsertToken = $this->integrationOnboardingTokenRepository->insert([
            'token_hash' => $tokenData['hash'],
            'customer_id' => $client['id'],
            'purpose' => 'register_shop',
            'expires_at' => $tokenData['expires_at'],
            'used_at' => null,
            'revoked_at' => null,
            'created_by' => 1
        ]);
        if(!$resInsertToken) throw new CustomException("INTEGRATION_ONBOARDING_TOKEN_INSERT_FAILED", 'errors', 500);

        return $tokenData['hash'];
    }

    private function generateOnboardingToken(int $ttlHours = 24): array {
        $raw  = random_bytes(32);
        $code = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $hash = hash('sha256', $code);
        $expiresAt = (new DateTimeImmutable("+{$ttlHours} hours"))->format('Y-m-d H:i:s');

        return [
            'hash'         => $hash,
            'expires_at'   => $expiresAt,
        ];
    }

    private function getUnusedTokenByClient($idClient)
    {
        return $this->integrationOnboardingTokenRepository->first(['customer_id' => $idClient, 'used_at' => null, 'revoked_at' => null, 'purpose' => 'register_shop']);
    }

    private function closeToken($hash)
    {
        $res = $this->integrationOnboardingTokenRepository->update($hash, ['revoked_at' => date('Y-m-d H:i:s')], 'token_hash');
        if(!$res) throw new CustomException("INTEGRATION_ONBOARDING_TOKEN_UPDATE_FAILED", 'errors', 500);
        return $res;
    }

    public function registerClient($data)
    {
        if (empty($data['token'])) throw new CustomException("VALIDATION_ERROR", 'errors', 400);

        return $this->transactionManager->execute(function () use ($data) {

            $onboardingRecord = $this->integrationOnboardingTokenRepository->first(['token_hash' => $data['token']]);
            if (!$onboardingRecord) throw new CustomException("TOKEN_NOT_FOUND", 'errors', 404);
            if ($onboardingRecord['used_at'] || $onboardingRecord['revoked_at']) throw new CustomException("TOKEN_ALREADY_USED_OR_REVOKED", 'errors', 400);

            if (new DateTimeImmutable($onboardingRecord['expires_at']) < new DateTimeImmutable()) {
                throw new CustomException("TOKEN_EXPIRED", 'errors', 400);
            }

            $client = $this->clientRepository->first(['id' => $onboardingRecord['customer_id']]);
            if (!$client) throw new CustomException("CLIENT_NOT_FOUND", 'errors', 404);

            $uid = new Ulid();
            $franchiseCode = $this->generateFranchiseeCode($client['company']);

            $resUpdateClient = $this->clientRepository->update($onboardingRecord['customer_id'], ['franchisee_uid' => $uid, 'franchisee_code' => $franchiseCode]);
            if (!$resUpdateClient) throw new CustomException("CLIENT_UPDATE_FAILED", 'errors', 500);

            $resUpdateOnboarding = $this->integrationOnboardingTokenRepository->update($data['token'], [
                'used_at' => date('Y-m-d H:i:s'),
                'revoked_at' => null,
            ], 'token_hash');
            if (!$resUpdateOnboarding) throw new CustomException("ONBOARDING_TOKEN_UPDATE_FAILED", 'errors', 500);


            return [
                'ok'          => true,
                'franchisee_uid'    => $uid,
                'franchisee_code'    => $franchiseCode
            ];
        });
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