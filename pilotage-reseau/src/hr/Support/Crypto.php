<?php

declare(strict_types=1);

namespace Hr\Support;

/**
 * Chiffrement au repos du module RH : AES-256-GCM, une sous-clé par usage (HKDF-SHA256 depuis la clé maître),
 * et les données additionnelles authentifiées (AAD) qui lient un chiffré à sa ligne — un chiffré copié d'une
 * ligne à l'autre ne se déchiffre pas.
 *
 * Format : « hr1: » + base64(iv 12 | tag 16 | chiffré).
 */
final class Crypto
{
    private string $master;

    public function __construct(string $masterKey)
    {
        if (strlen($masterKey) !== 32) {
            throw new \InvalidArgumentException('clé maître : 32 octets attendus');
        }
        $this->master = $masterKey;
    }

    private function subKey(string $purpose): string
    {
        return hash_hkdf('sha256', $this->master, 32, 'hr-dimona:' . $purpose);
    }

    public function encrypt(string $plain, string $purpose, string $aad = ''): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', $this->subKey($purpose), OPENSSL_RAW_DATA, $iv, $tag, $purpose . '|' . $aad, 16);
        if ($c === false) {
            throw new \RuntimeException('chiffrement impossible');
        }
        return 'hr1:' . base64_encode($iv . $tag . $c);
    }

    /** null si la donnée a été altérée, déplacée (AAD) ou si la clé a changé. */
    public function decrypt(string $blob, string $purpose, string $aad = ''): ?string
    {
        if (!str_starts_with($blob, 'hr1:')) {
            return null;
        }
        $b = base64_decode(substr($blob, 4), true);
        if ($b === false || strlen($b) < 29) {
            return null;
        }
        $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $this->subKey($purpose), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), $purpose . '|' . $aad);
        return $p === false ? null : $p;
    }

    /** Empreinte stable (HMAC) d'une valeur sensible : retrouver un NISS sans le déchiffrer. */
    public function fingerprint(string $value, string $purpose): string
    {
        return hash_hmac('sha256', $value, $this->subKey('fp:' . $purpose));
    }
}
