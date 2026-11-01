<?php
namespace App\Employee\app\Services\Pointage;

use App\Employee\app\Services\Cockpit\CockpitClient;

/**
 * Le pointage (01/11/2026) : le QR code de la borne du magasin, lu par la caméra (ou son code à six chiffres), part au
 * cockpit (src/pointage.php), qui décide de l'arrivée ou du départ face au planning. La liste des jours (service prévu,
 * arrivée, départ) se lit au même endroit ; un départ oublié s'y déclare.
 */
class PointageService
{
    public function __construct(private CockpitClient $cockpit)
    {
    }

    /** Ce qui a été lu (texte du QR, lien « …?c= », ou code à six chiffres). 422 : data.motif (illisible, faux, expire, trop_tot). */
    public function pointer(string $lu): array
    {
        return $this->cockpit->appel('POST', '/ventes/moi/pointage', ['lu' => mb_substr(trim($lu), 0, 400)]);
    }

    /** Les jours du … au … : services prévus, pointages, totaux, le pointage ouvert. */
    public function liste(string $du, string $au): array
    {
        return $this->cockpit->appel('GET', '/ventes/moi/pointages?' . http_build_query(['du' => $du, 'au' => $au]));
    }

    /** Un départ oublié : l'heure dite par la personne, à valider par le magasin. */
    public function declarerDepart(int $id, string $heure): array
    {
        return $this->cockpit->appel('POST', '/ventes/moi/pointage/depart', ['id' => $id, 'heure' => $heure]);
    }

    /**
     * L'état du jour pour l'Accueil : le service prévu, le pointage ouvert, ceux d'aujourd'hui. Null si le cockpit ne
     * répond pas (l'Accueil n'affiche alors pas la carte).
     */
    public function aujourdhui(?int $maintenant = null): ?array
    {
        $maintenant ??= time();
        $j = date('Y-m-d', $maintenant);
        $r = $this->liste($j, $j);
        if (!$r['ok']) { return null; }
        $jour = $r['data']['jours'][0] ?? ['prevus' => [], 'pointages' => []];
        $ouvert = $r['data']['ouvert'] ?? null;
        $m = (int) date('G', $maintenant) * 60 + (int) date('i', $maintenant);
        // Le service à montrer : celui en cours ou le prochain du jour, sinon le dernier.
        $prevu = null;
        foreach ((array) $jour['prevus'] as $p) {
            $prevu = $p;
            if (self::minutes((string) $p['fin']) >= $m) { break; }
        }
        $de = $prevu ? self::minutes((string) $prevu['debut']) : null;
        $a = $prevu ? self::minutes((string) $prevu['fin']) : null;
        $fait = array_values(array_filter((array) $jour['pointages'], static fn ($p) => $p['sortie'] !== null));
        return ['prevu' => $prevu, 'ouvert' => $ouvert, 'fait' => $fait ? end($fait) : null,
            'dans' => $de !== null ? $de - $m : null,                         // minutes avant le début (négatif : commencé)
            'reste' => $a !== null ? max(0, $a - $m) : null,                  // minutes avant la fin
            'avance' => $de !== null && $a > $de ? round(100 * min(1, max(0, ($m - $de) / ($a - $de))), 1) : 0];
    }

    /**
     * La semaine prête à montrer (Profil › Mes pointages) : chaque jour avec ses barres sur la journée (de 6 h à 22 h,
     * élargie si besoin) — le service prévu en contour, le pointé plein —, son total et ce qu'il demande.
     * statut : repos | a_venir | pas_pointe | ok | en_service | a_verifier | hors_planning.
     */
    public static function semaineVue(array $liste, string $aujourdhui, int $maintenant): array
    {
        $bas = 6 * 60; $haut = 22 * 60;
        $m = (int) date('G', $maintenant) * 60 + (int) date('i', $maintenant);
        $shops = [];
        foreach ($liste['jours'] as $j) {
            foreach ($j['prevus'] as $p) { $bas = min($bas, self::minutes($p['debut'])); $haut = max($haut, self::minutes($p['fin'])); $shops[$p['shop_name']] = ($shops[$p['shop_name']] ?? 0) + 1; }
            foreach ($j['pointages'] as $p) { $bas = min($bas, self::minutes($p['entree'])); if ($p['sortie']) { $haut = max($haut, self::minutes($p['sortie'])); } }
        }
        $bas = (int) floor($bas / 60) * 60; $haut = (int) min(1440, ceil($haut / 60) * 60);
        arsort($shops);
        $principal = (string) (array_key_first($shops) ?? '');
        $barre = static function (int $de, int $a) use ($bas, $haut): array {
            $a = max($a, $de);
            return ['gauche' => round(100 * ($de - $bas) / ($haut - $bas), 2), 'largeur' => round(max(1.2, 100 * (min($a, $haut) - $de) / ($haut - $bas)), 2)];
        };
        $jours = [];
        foreach ($liste['jours'] as $j) {
            $d = $j['date'];
            $prevus = array_map(static fn ($p) => $p + ['autreShop' => count($shops) > 1 && $p['shop_name'] !== $principal]
                + $barre(self::minutes($p['debut']), self::minutes($p['fin']) <= self::minutes($p['debut']) ? 1440 : self::minutes($p['fin'])), $j['prevus']);
            $points = array_map(static function ($p) use ($barre, $d, $aujourdhui, $m) {
                $fin = $p['sortie'] ? self::minutes($p['sortie']) : ($p['etat'] === 'en_service' && $d === $aujourdhui ? $m : self::minutes($p['entree']));
                return $p + $barre(self::minutes($p['entree']), $fin < self::minutes($p['entree']) ? 1440 : $fin);
            }, $j['pointages']);
            $total = array_sum(array_map(static fn ($p) => in_array($p['etat'], ['fait', 'a_valider', 'en_service'], true) ? (int) $p['duree_min'] : 0, $points));
            $etats = array_column($points, 'etat');
            $statut = match (true) {
                in_array('en_service', $etats, true) => 'en_service',
                in_array('non_pointe', $etats, true) || in_array('a_valider', $etats, true) => 'a_verifier',
                $points !== [] && $prevus === [] => 'hors_planning',
                $points !== [] => 'ok',
                $prevus === [] => 'repos',
                $d > $aujourdhui || ($d === $aujourdhui && self::minutes($prevus[0]['fin']) > $m) => 'a_venir',
                default => 'pas_pointe',
            };
            $jours[] = ['date' => $d, 'aujourdhui' => $d === $aujourdhui, 'passe' => $d < $aujourdhui, 'prevus' => $prevus, 'pointages' => $points,
                'total' => $total, 'statut' => $statut];
        }
        return ['jours' => $jours, 'totaux' => $liste['totaux'], 'axe' => ['de' => intdiv($bas, 60), 'a' => intdiv($haut, 60)]];
    }

    /** « 07:30 » → 450. */
    private static function minutes(string $hm): int
    {
        [$h, $mi] = array_map('intval', explode(':', $hm . ':0'));
        return $h * 60 + $mi;
    }
}
