<?php

declare(strict_types=1);

namespace Hr\Directory;

/**
 * L'annuaire en production, par les moyens du cockpit : l'API du panel (PanelApi, compte consultant côté
 * serveur) d'abord — /shops et /shops/{id}/employees — et, s'il ne répond pas, les tables partagées que le
 * cockpit lit déjà (shops, franchisee_employee). Le profil vient du module « profil de l'employé »
 * (src/profil_employe.php, chiffré à part, sa propre clé).
 *
 * De ce que rend le panel, on ne garde que l'identité : la route des employés porte aussi des secrets
 * (hachages, jetons), qui ne quittent pas cette classe.
 */
final class CockpitDirectory implements Directory
{
    /** @var array<string, string>|null */
    private ?array $shops = null;

    /** @var array<string, list<array{id: int, givenName: string, familyName: string, name: string, active: bool}>> */
    private array $employees = [];

    public function shops(): array
    {
        if ($this->shops !== null) {
            return $this->shops;
        }
        // Gardée dix minutes (hr_dimona_meta) : la liste des boutiques change rarement, la page la demande à
        // chaque chargement.
        try {
            $c = \Hr\Support\Database::row('SELECT v FROM hr_dimona_meta WHERE k = ?', ['shops_cache']);
            $j = $c !== null ? json_decode((string) $c['v'], true) : null;
            if (is_array($j) && ($j['at'] ?? 0) > time() - 600 && is_array($j['shops'] ?? null) && $j['shops'] !== []) {
                return $this->shops = $j['shops'];
            }
        } catch (\Throwable $e) {
            // pas de cache : on relit
        }
        $out = [];
        if (class_exists('PanelApi') && \PanelApi::configured()) {
            $r = \PanelApi::get('/shops');
            foreach (is_array($r) ? $r : [] as $s) {
                if (is_array($s) && isset($s['id'])) {
                    $out[(string) $s['id']] = trim((string) ($s['name'] ?? '')) ?: 'Boutique ' . $s['id'];
                }
            }
        }
        if ($out === [] && class_exists('Db')) {
            try {
                foreach (\Db::rows('SELECT id, name FROM shops ORDER BY name') as $s) {
                    $out[(string) $s['id']] = trim((string) $s['name']) ?: 'Boutique ' . $s['id'];
                }
            } catch (\Throwable $e) {
                // ni API ni table : la liste reste vide, l'écran affiche les numéros
            }
        }
        if ($out !== []) {
            try {
                \Hr\Support\Database::exec('DELETE FROM hr_dimona_meta WHERE k = ?', ['shops_cache']);
                \Hr\Support\Database::insert('hr_dimona_meta', ['k' => 'shops_cache', 'v' => (string) json_encode(['at' => time(), 'shops' => $out], JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable $e) {
                // le cache n'est qu'un raccourci
            }
        }
        return $this->shops = $out;
    }

    public function employees(string $shopId): array
    {
        if (isset($this->employees[$shopId])) {
            return $this->employees[$shopId];
        }
        $rows = null;
        if (class_exists('PanelApi') && \PanelApi::configured() && ctype_digit($shopId)) {
            $r = \PanelApi::get('/shops/' . $shopId . '/employees');
            $rows = is_array($r) ? $r : null;
        }
        if ($rows === null && class_exists('Db')) {
            try {
                $rows = \Db::rows('SELECT id, name, surname, display_name, active FROM franchisee_employee WHERE id_shop = ?', [$shopId]);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        $out = [];
        foreach ($rows ?? [] as $e) {
            if (!is_array($e) || !isset($e['id'])) {
                continue;
            }
            $given = trim((string) ($e['name'] ?? ''));
            $family = trim((string) ($e['surname'] ?? ''));
            $name = trim((string) ($e['display_name'] ?? '')) ?: trim($given . ' ' . $family);
            $out[] = ['id' => (int) $e['id'], 'givenName' => $given, 'familyName' => $family, 'name' => $name !== '' ? $name : 'Employé ' . $e['id'],
                'active' => !isset($e['active']) || (bool) $e['active']];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $this->employees[$shopId] = $out;
    }

    public function profile(int $employeeId): ?array
    {
        if (!function_exists('profilLire')) {
            return null;
        }
        try {
            $p = \profilLire($employeeId);
        } catch (\Throwable $e) {
            return null;
        }
        if ($p === []) {
            return null;
        }
        $ssin = preg_replace('/\D/', '', (string) ($p['paie']['niss'] ?? '')) ?: null;
        return [
            'givenName' => ($p['identite']['prenom'] ?? '') ?: null,
            'familyName' => ($p['identite']['nom'] ?? '') ?: null,
            'birthDate' => ($p['identite']['naissance'] ?? '') ?: null,
            'ssin' => $ssin,
            'status' => ($p['paie']['statut'] ?? '') ?: null,
        ];
    }
}
