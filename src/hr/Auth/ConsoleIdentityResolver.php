<?php

declare(strict_types=1);

namespace Hr\Auth;

use Hr\Http\Request;
use Hr\Support\Config;
use Hr\Support\HttpClient;
use Hr\Support\HttpTransportException;

/**
 * L'identité de la console franchisé (décision de Sam, 01/11/2026), vérifiée par son API et non lue dans
 * ses tables : le cookie de session de la console (fb_franchisee_session / fb_franchisor_session, posé sur
 * le même hôte, chemin /) est relayé, serveur à serveur, à GET {console}/bo/franchisee/me puis
 * /bo/franchisor/me (dépôt WebShop, php-api/bo/auth.php). La console revérifie le compte (actif, rôle) et
 * rend ses boutiques (bo_user_shops) ; le franchiseur (rôle siege) voit tout le réseau.
 *
 * Le jeton admin réseau (X-Admin-Token) n'est PAS accepté : il est le même pour tout le réseau et ne dit pas
 * qui est connecté — une déclaration Dimona et un NISS ne peuvent pas reposer dessus.
 */
final class ConsoleIdentityResolver implements IdentityResolver
{
    /** @var array{bo: ?string, csrf: ?string} */
    private array $last = ['bo' => null, 'csrf' => null];

    public function __construct(private HttpClient $http, private Config $config)
    {
    }

    public function resolve(Request $r): ?Identity
    {
        $base = $this->config->consoleApiInternal();
        if ($base === null) {
            throw new ConsoleUnavailableException('La connexion par la console franchisé n’est pas configurée sur ce serveur (HR_CONSOLE_API_INTERNAL).');
        }
        $cookie = $r->header('cookie');
        if ($cookie === '') {
            return null;
        }
        $unconfigured = 0;
        foreach (['franchisee' => Identity::FRANCHISEE, 'franchisor' => Identity::FRANCHISOR] as $bo => $role) {
            try {
                $resp = $this->http->request('GET', $base . '/bo/' . $bo . '/me', ['Cookie' => $cookie, 'Accept' => 'application/json'], null, 8);
            } catch (HttpTransportException $e) {
                throw new ConsoleUnavailableException('La console franchisé ne répond pas : ' . $e->getMessage());
            }
            $j = $resp->json();
            if ($resp->status === 200 && is_array($j) && is_array($j['user'] ?? null)) {
                $u = $j['user'];
                $shops = $role === Identity::FRANCHISEE ? array_values(array_map('strval', (array) ($u['shops'] ?? []))) : null;
                $this->last = ['bo' => $bo, 'csrf' => is_string($j['csrf'] ?? null) ? $j['csrf'] : null];
                $name = trim((string) ($u['display_name'] ?? '')) ?: (string) ($u['email'] ?? 'Compte ' . $u['id']);
                return new Identity('bo:' . (int) $u['id'], $name, (string) ($u['email'] ?? ''), $role, $shops, 'console');
            }
            if ($resp->status === 500 && is_array($j) && str_contains((string) ($j['error'] ?? ''), 'non configur')) {
                $unconfigured++;
            }
        }
        if ($unconfigured === 2) {
            throw new ConsoleUnavailableException('Les comptes de la console franchisé ne sont pas encore activés sur ce serveur (section « bo » de la configuration de la console).');
        }
        return null;
    }

    /** @return array{bo: ?string, csrf: ?string} pour la déconnexion depuis la page */
    public function lastSession(): array
    {
        return $this->last;
    }
}
