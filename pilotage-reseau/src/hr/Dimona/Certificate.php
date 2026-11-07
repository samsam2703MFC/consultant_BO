<?php

declare(strict_types=1);

namespace Hr\Dimona;

/**
 * Le certificat X.509 et la clé privée qu'une société dépose (procédure Chaman : certificat auto-signé
 * OpenSSL, RSA 2048, SHA-256 — docs/dimona.md). Contrôles : le certificat se lit (PEM ou DER), la clé se
 * lit (avec sa phrase de passe si elle en a une), la clé correspond au certificat, RSA 2048 bits au moins, et
 * le certificat n'est pas déjà expiré. La clé est rendue réexportée en PEM non chiffré : c'est la clé maître
 * du module qui la chiffre au repos.
 */
final class Certificate
{
    /**
     * @return array{certificatePem: string, privateKeyPem: string, subject: string, fingerprint: string, expiresAt: int, bits: int}
     */
    public static function inspect(string $certificate, string $privateKey, ?string $passphrase = null): array
    {
        $certPem = self::toPem(trim($certificate), 'CERTIFICATE');
        $x = @openssl_x509_read($certPem);
        if ($x === false) {
            throw new \InvalidArgumentException('Le certificat ne se lit pas : un fichier .crt ou .pem est attendu.');
        }
        $info = openssl_x509_parse($x) ?: [];
        $exp = (int) ($info['validTo_time_t'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            throw new \InvalidArgumentException('Ce certificat a expiré le ' . gmdate('d/m/Y', $exp) . ' : générez-en un nouveau.');
        }
        $key = @openssl_pkey_get_private(trim($privateKey), $passphrase ?? '');
        if ($key === false) {
            throw new \InvalidArgumentException($passphrase ? 'La clé privée ne s’ouvre pas avec cette phrase de passe.' : 'La clé privée ne se lit pas (un fichier .key ou .pem est attendu ; protégée, il faut sa phrase de passe).');
        }
        $details = openssl_pkey_get_details($key) ?: [];
        if (($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
            throw new \InvalidArgumentException('La clé doit être une clé RSA (signature RS256).');
        }
        $bits = (int) ($details['bits'] ?? 0);
        if ($bits < 2048) {
            throw new \InvalidArgumentException('La clé fait ' . $bits . ' bits : 2048 au moins.');
        }
        if (!openssl_x509_check_private_key($x, $key)) {
            throw new \InvalidArgumentException('La clé privée ne correspond pas à ce certificat.');
        }
        $plain = '';
        if (!openssl_pkey_export($key, $plain)) {
            throw new \InvalidArgumentException('La clé privée ne s’exporte pas.');
        }
        $subject = '';
        foreach ((array) ($info['subject'] ?? []) as $k => $v) {
            $subject .= ($subject === '' ? '' : ', ') . $k . '=' . (is_array($v) ? implode('+', $v) : $v);
        }
        return [
            'certificatePem' => $certPem,
            'privateKeyPem' => $plain,
            'subject' => mb_substr($subject, 0, 255),
            'fingerprint' => (string) openssl_x509_fingerprint($x, 'sha256'),
            'expiresAt' => $exp,
            'bits' => $bits,
        ];
    }

    /** PEM tel quel ; DER (binaire, envoyé en base64 par la page) converti. */
    private static function toPem(string $data, string $label): string
    {
        if (str_contains($data, '-----BEGIN ')) {
            return $data . "\n";
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);
        if ($bin === false || $bin === '') {
            return $data;
        }
        return '-----BEGIN ' . $label . "-----\n" . chunk_split(base64_encode($bin), 64, "\n") . '-----END ' . $label . "-----\n";
    }
}
