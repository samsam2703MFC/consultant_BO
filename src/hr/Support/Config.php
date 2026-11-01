<?php

declare(strict_types=1);

namespace Hr\Support;

use Hr\Dimona\Generated\Spec;

/**
 * La configuration du module RH. Ordre de priorité, pour chaque valeur :
 *   1. la variable d'environnement HR_* ;
 *   2. le bloc 'hr' de config/config.php (écrit par bin/deploy.sh à partir des variables du workflow) ;
 *   3. pour les adresses ONSS seulement : ce que dit le YAML OpenAPI (Hr\Dimona\Generated\Spec).
 *
 * Aucune adresse, clé ni identifiant n'est écrit dans le code : ce qui n'est ni dans l'environnement, ni dans
 * config.php, ni dans le YAML reste vide, et l'écran le dit.
 */
final class Config
{
    public const ENV_SIM = 'SIM';
    public const ENV_PROD = 'PROD';

    /** @var array<string, mixed> */
    private array $v;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->v = $values;
    }

    /** @param array<string, mixed> $file le bloc 'hr' de config.php */
    public static function load(array $file = []): self
    {
        $env = static function (string $k): ?string {
            $v = getenv($k);
            return $v === false || trim($v) === '' ? null : trim($v);
        };
        $pick = static function (string $envKey, string $fileKey, mixed $default = null) use ($env, $file): mixed {
            $e = $env($envKey);
            if ($e !== null) {
                return $e;
            }
            $f = $file[$fileKey] ?? null;
            return is_string($f) ? (trim($f) === '' ? $default : trim($f)) : ($f ?? $default);
        };
        $bool = static fn (mixed $v): bool => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true) || $v === true;

        return new self([
            // PROD fermée tant qu'on ne l'ouvre pas explicitement (validé par Sam : SIM seule au départ).
            'prodEnabled' => $bool($pick('HR_DIMONA_PROD_ENABLED', 'prodEnabled', false)),
            'baseUrl' => [
                self::ENV_SIM => (string) $pick('HR_DIMONA_BASE_URL_SIM', 'baseUrlSim', Spec::SERVER_SIM),
                self::ENV_PROD => (string) $pick('HR_DIMONA_BASE_URL_PROD', 'baseUrlProd', Spec::SERVER_PROD),
            ],
            'tokenUrl' => [
                self::ENV_SIM => (string) $pick('HR_DIMONA_TOKEN_URL_SIM', 'tokenUrlSim', Spec::TOKEN_URL),
                self::ENV_PROD => (string) $pick('HR_DIMONA_TOKEN_URL_PROD', 'tokenUrlProd', Spec::TOKEN_URL),
            ],
            // Durée de vie de l'assertion JWT envoyée au serveur OAuth (5 minutes au plus).
            'assertionTtl' => max(30, min(300, (int) $pick('HR_DIMONA_ASSERTION_TTL', 'assertionTtl', 300))),
            'httpTimeout' => max(5, (int) $pick('HR_DIMONA_HTTP_TIMEOUT', 'httpTimeout', 20)),
            'masterKey' => $pick('HR_DIMONA_MASTER_KEY', 'masterKey'),
            'privateDir' => $pick('HR_PRIVATE_DIR', 'privateDir'),
            // La console franchisé (dépôt WebShop) : son API vue du navigateur, et vue du serveur (boucle locale).
            'consoleApi' => $pick('HR_CONSOLE_API_BASE', 'consoleApi'),
            'consoleApiInternal' => $pick('HR_CONSOLE_API_INTERNAL', 'consoleApiInternal'),
            'certAlertDays' => max(1, (int) $pick('HR_DIMONA_CERT_ALERT_DAYS', 'certAlertDays', 60)),
        ]);
    }

    public function get(string $key): mixed
    {
        return $this->v[$key] ?? null;
    }

    public function prodEnabled(): bool
    {
        return (bool) $this->v['prodEnabled'];
    }

    /** @return list<string> */
    public function enabledEnvs(): array
    {
        return $this->prodEnabled() ? [self::ENV_SIM, self::ENV_PROD] : [self::ENV_SIM];
    }

    public function baseUrl(string $env): string
    {
        return rtrim((string) ($this->v['baseUrl'][$env] ?? ''), '/');
    }

    public function tokenUrl(string $env): string
    {
        return (string) ($this->v['tokenUrl'][$env] ?? '');
    }

    public function consoleApi(): ?string
    {
        $v = $this->v['consoleApi'] ?? null;
        return is_string($v) && $v !== '' ? rtrim($v, '/') : null;
    }

    public function consoleApiInternal(): ?string
    {
        $v = $this->v['consoleApiInternal'] ?? null;
        return is_string($v) && $v !== '' ? rtrim($v, '/') : null;
    }

    /**
     * Le dossier privé, hors du site web et que la livraison ne touche pas — le même que celui du profil des
     * employés (src/profil_employe.php) : /var/lib/consulant_bo/prive, créé par bin/deploy.sh.
     */
    public function privateDir(): string
    {
        foreach ($this->privateCandidates() as $c) {
            if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) {
                return rtrim($c, '/');
            }
        }
        throw new \RuntimeException('aucun dossier privé où écrire (module RH)');
    }

    /**
     * La clé maître (32 octets) qui chiffre les clés privées, les NISS et les jetons. HR_DIMONA_MASTER_KEY
     * (base64) prime ; à défaut, une clé tirée une fois et gardée dans le dossier privé (0600), comme
     * profil.key. La perdre rend ces données illisibles : elle se sauvegarde avec la base.
     */
    public function masterKey(): string
    {
        $raw = $this->v['masterKey'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $k = base64_decode($raw, true);
            if ($k === false || strlen($k) !== 32) {
                throw new \RuntimeException('HR_DIMONA_MASTER_KEY doit être 32 octets encodés en base64');
            }
            return $k;
        }
        // Une clé déjà posée (bin/deploy.sh la tire pour www-data) se lit même si le dossier n'est pas
        // inscriptible pour PHP ; il ne faut pouvoir écrire que pour la créer.
        foreach ($this->privateCandidates() as $c) {
            if (is_readable($c . '/hr_dimona.key')) {
                return $this->readKey($c . '/hr_dimona.key');
            }
        }
        $f = $this->privateDir() . '/hr_dimona.key';
        if (!is_file($f)) {
            $tmp = $f . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, base64_encode(random_bytes(32)));
            chmod($tmp, 0600);
            if (!@rename($tmp, $f) && !is_file($f)) {
                throw new \RuntimeException('clé maître du module RH impossible à écrire');
            }
            @unlink($tmp);
        }
        return $this->readKey($f);
    }

    private function readKey(string $f): string
    {
        $k = base64_decode(trim((string) @file_get_contents($f)), true);
        if ($k === false || strlen($k) !== 32) {
            throw new \RuntimeException('clé maître du module RH illisible (' . basename($f) . ')');
        }
        return $k;
    }

    /** @return list<string> */
    private function privateCandidates(): array
    {
        return array_values(array_filter([
            is_string($this->v['privateDir'] ?? null) ? $this->v['privateDir'] : '',
            (string) (getenv('COCKPIT_PRIVE') ?: ''),
            '/var/lib/consulant_bo/prive',
            dirname(__DIR__, 3) . '/var/prive',
        ]));
    }
}
