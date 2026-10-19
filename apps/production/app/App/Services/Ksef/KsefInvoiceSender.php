<?php
namespace App\Services\Ksef;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

final class KsefInvoiceSender
{
    public function __construct(private KsefApiClient $api, private KsefTokenCipher $cipher, private \App\Repositories\Ksef\KsefIntegrationSettingsRepository $settings) {}

    /** @return array{sessionReference:string,invoiceReference:string} */
    public function send(string $sellerNip, string $xml): array
    {
        $config = $this->settings->get();
        $env = $config['environment'] ?? 'test';
        $access = $this->authenticate($sellerNip);
        $certs = $this->api->get($env, '/security/public-key-certificates');
        $key = random_bytes(32); $iv=random_bytes(16); $sessionKey=$this->certificate($certs,'SymmetricKeyEncryption');
        $session=$this->api->post($env, '/sessions/online', ['formCode'=>['systemCode'=>'FA (3)','schemaVersion'=>'1-0E','value'=>'FA'],'encryption'=>['encryptedSymmetricKey'=>base64_encode($this->rsa($sessionKey['certificate'],$key)),'initializationVector'=>base64_encode($iv)],'publicKeyId'=>$sessionKey['publicKeyId']], $access);
        $encrypted=openssl_encrypt($xml,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);
        $invoice=$this->api->post($env, '/sessions/online/'.$session['referenceNumber'].'/invoices', ['invoiceHash'=>base64_encode(hash('sha256',$xml,true)),'invoiceSize'=>strlen($xml),'encryptedInvoiceHash'=>base64_encode(hash('sha256',$encrypted,true)),'encryptedInvoiceSize'=>strlen($encrypted),'encryptedInvoiceContent'=>base64_encode($encrypted)], $access);
        $this->api->post($env, '/sessions/online/'.$session['referenceNumber'].'/close', [], $access);
        return ['sessionReference'=>$session['referenceNumber'], 'invoiceReference'=>$invoice['referenceNumber']];
    }

    public function authenticate(string $sellerNip): string
    {
        $config = $this->settings->get();
        $token = $this->cipher->decrypt($config['token_encrypted'] ?? null);
        if (!$token) throw new \RuntimeException('Najpierw zapisz token KSeF w ustawieniach.');
        $env = $config['environment'] ?? 'test';
        $certs = $this->api->get($env, '/security/public-key-certificates');
        $challenge = $this->api->post($env, '/auth/challenge', []);
        $tokenKey = $this->certificate($certs, 'KsefTokenEncryption');
        $auth = $this->api->post($env, '/auth/ksef-token', ['challenge'=>$challenge['challenge'], 'contextIdentifier'=>['type'=>'Nip','value'=>preg_replace('/\D/','',$sellerNip)], 'encryptedToken'=>base64_encode($this->rsa($tokenKey['certificate'], $token . '|' . $challenge['timestampMs'])), 'publicKeyId'=>$tokenKey['publicKeyId']]);
        $authToken = $auth['authenticationToken']['token'];
        for ($i=0; $i<5; $i++) { $status=$this->api->get($env, '/auth/'.$auth['referenceNumber'], $authToken); if (($status['status']['code'] ?? null) === 200) break; usleep(250000); }
        return $this->api->post($env, '/auth/token/redeem', [], $authToken)['accessToken']['token'];
    }

    public function environment(): string
    {
        return $this->settings->get()['environment'] ?? 'test';
    }

    /** @return array<string,mixed> */
    public function invoiceStatus(string $accessToken, string $sessionReference, string $invoiceReference): array
    {
        $env = $this->settings->get()['environment'] ?? 'test';
        return $this->api->get($env, '/sessions/' . rawurlencode($sessionReference) . '/invoices/' . rawurlencode($invoiceReference), $accessToken);
    }

    public function invoiceUpo(string $accessToken, string $sessionReference, string $invoiceReference): string
    {
        return $this->api->getRaw($this->environment(), '/sessions/' . rawurlencode($sessionReference) . '/invoices/' . rawurlencode($invoiceReference) . '/upo', $accessToken);
    }
    private function certificate(array $certs,string $usage): array { foreach ($certs as $cert) if (in_array($usage,$cert['usage']??[],true)) return $cert; throw new \RuntimeException('KSeF nie udostępnił klucza '.$usage.'.'); }
    private function rsa(string $certificate,string $plain): string { $x509=new X509(); $x509->loadX509(base64_decode($certificate)); $key=$x509->getPublicKey(); return $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($plain); }
}
