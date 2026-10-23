<?php
namespace App\Employee\app\Services\Primes;

/**
 * « Comment y arriver » (demande du 23/10/2026 : tourner l'app vers la motivation — ce que tu peux gagner,
 * comment y arriver, où tu en es) : pour chaque prime du mois pas encore gagnée, ce qu'elle rapporte, où elle
 * en est et ce qu'il faut faire. Tout se lit dans la fiche du cockpit (GET /ventes/moi : les cartes, les
 * briques, la prime du magasin, les heures) ; rien n'est inventé, la vue met les mots.
 *
 * Les ventes croisées et le record sont des moyennes du mois : chaque nouveau ticket compte aussi au
 * dénominateur. Ce qu'il faut viser se calcule donc sur les tickets qui restent, estimés à son rythme
 * (ses tickets par heure prestée × les heures qui lui restent au planning). Un cran hors de portée ce
 * mois-ci est dit comme tel, jamais promis.
 */
class PrimesPlan
{
    /** Sous ce nombre de tickets estimés d'ici la fin du mois, pas de cible « sur tes prochains tickets ». */
    private const TICKETS_MIN_FUTURS = 10;

    /** Les motifs qui ne se rattrapent pas en vendant : pas de ligne dans le plan. */
    private const SANS_PLAN = ['pas_compte', 'sans_vente', 'pas_objectif', 'pas_note', 'heures', 'pas_classee'];

    /**
     * Les primes du mois en cours encore à aller chercher, la plus proche d'abord (vide pour un mois clos ou à
     * venir). Chaque étape : {cle, carte, gain, total, equipe, magasin, de, a, unite, progres, …} ; « carte » est la carte
     * du cockpit (son nom, son icône, son cadeau), la vue en tire le reste.
     *
     * @return list<array<string, mixed>>
     */
    public static function pour(array $f): array
    {
        if (empty($f['enCours']) || !empty($f['aVenir'])) { return []; }
        $b = (array) ($f['briques'] ?? []);
        $pm = (array) ($f['prime'] ?? []);
        $h = (array) ($f['heures'] ?? []);
        $hFaites = (float) ($h['faites'] ?? 0);
        $hReste = max(0.0, (float) ($h['mois'] ?? 0) - $hFaites);
        $tickets = (int) ($b['croisees']['tickets'] ?? 0);
        // Les tickets qu'elle fera encore ce mois-ci, à son rythme.
        $futurs = ($hFaites > 0 && $tickets > 0) ? (int) round($tickets / $hFaites * $hReste) : 0;
        $avecFuturs = $futurs >= self::TICKETS_MIN_FUTURS;
        // Ce qu'elle vendra encore d'une quantité, à son rythme (les pièces d'une catégorie ou d'une promo).
        $rythme = static fn (?float $q) => ($q !== null && $q > 0 && $hFaites > 0 && $hReste > 0) ? (int) round($q / $hFaites * $hReste) : null;

        $out = [];
        foreach ((array) ($f['cartes'] ?? []) as $c) {
            $etat = (string) ($c['etat'] ?? '');
            if (in_array($etat, ['gagne', 'tete'], true) || in_array($c['motif'] ?? null, self::SANS_PLAN, true)) { continue; }
            $val = isset($c['val']) && $c['val'] !== null ? (float) $c['val'] : null;
            $e = ['cle' => (string) $c['cle'], 'carte' => $c, 'gain' => (int) ($c['montant'] ?? 0), 'total' => (int) ($c['montant'] ?? 0),
                // equipe : elle se joue avec tout le magasin (objectif, note Google) ; magasin : la prime va au magasin.
                'equipe' => false, 'magasin' => false, 'de' => $val, 'a' => null, 'unite' => (string) ($c['unite'] ?? ''), 'horsPortee' => false];

            if ($c['cle'] === 'croisees') {
                // Le taux du mois face à la cible ; sur ses prochains tickets, ce qu'il faut viser.
                $seuil = (float) ($c['cible'] ?? 0);
                $cr = (int) ($b['croisees']['croisees'] ?? 0);
                $e += ['tickets' => $tickets, 'minTickets' => (int) ($c['minTickets'] ?? 0), 'futurs' => null, 'vise' => null, 'surDix' => null,
                    'affilee' => isset($c['manque']) && $c['manque'] !== null ? max(1, (int) $c['manque']) : null];
                $e['a'] = $seuil;
                if ($avecFuturs && $val !== null) {
                    $besoin = (int) ceil($seuil / 100 * ($tickets + $futurs) - $cr - 1e-9);
                    // Déjà au-dessus (il ne manque que des tickets) : garder au moins la cible.
                    $vise = max(100 * $besoin / $futurs, $seuil);
                    $e['futurs'] = $futurs; $e['vise'] = (int) ceil($vise);
                    $e['surDix'] = min(10, max(1, (int) ceil($vise / 10 - 1e-9)));
                    $e['horsPortee'] = $vise > 100;
                } elseif ($e['affilee'] === null && ($val ?? 0) < $seuil) {
                    $e['horsPortee'] = true;
                }
            } elseif ($c['cle'] === 'record' && ($b['record']['record'] ?? null) === null) {
                // Pas encore de record (23/10/2026) : un mois d'au moins 30 tickets le pose, il se bat le mois suivant.
                // Rien à gagner ce mois-ci : l'étape se dit, sans être promise.
                if ($val === null) { continue; }
                $minT = (int) ($b['record']['minTickets'] ?? 30);
                $tk = (int) ($b['record']['tickets'] ?? $tickets);
                $e += ['pose' => true, 'tickets' => $tk, 'minTickets' => $minT, 'manqueTickets' => max(0, $minT - $tk),
                    'moisSuivant' => date('Y-m', strtotime(($f['m'] ?? date('Y-m')) . '-01 +1 month'))];
                $e['de'] = (float) $tk; $e['a'] = (float) $minT; $e['unite'] = 'tickets';
                $e['horsPortee'] = true;
            } elseif ($c['cle'] === 'record') {
                // Le record paie dès le 2e dixième au-dessus (la cible de la carte : record + 0,2), un dixième à la fois.
                $cible = isset($c['cible']) && $c['cible'] !== null ? (float) $c['cible'] : null;
                if ($cible === null || $val === null) { continue; }
                $e['gain'] = (int) ($b['record']['eurDixieme'] ?? $e['gain']);
                $e += ['ecart' => round(max(0.0, $cible - $val), 2), 'futurs' => null, 'vise' => null];
                $e['a'] = $cible;
                if ($avecFuturs) {
                    $vise = max(($cible * ($tickets + $futurs) - $val * $tickets) / $futurs, $cible);
                    $e['futurs'] = $futurs; $e['vise'] = round($vise, 1);
                    // Plus d'un article de plus par ticket que sa moyenne : hors de portée ce mois-ci.
                    $e['horsPortee'] = $vise > $val + 1;
                }
            } elseif ($c['cle'] === 'objectif') {
                // L'objectif du magasin : à jouer en équipe ; le CA qu'il manque, par jour qui reste.
                $e['equipe'] = true;
                $e['a'] = (float) ($c['cible'] ?? 100);
                $manque = isset($c['manqueCa']) && $c['manqueCa'] !== null ? (int) $c['manqueCa'] : null;
                $jours = (int) ($pm['joursRestants'] ?? 0);
                $e += ['manqueCa' => $manque, 'joursRestants' => $jours, 'parJour' => ($manque !== null && $manque > 0 && $jours > 0) ? (int) ceil($manque / $jours) : null];
            } elseif ($c['cle'] === 'google') {
                // La note du magasin : les avis 5★ qu'il manque.
                $e['equipe'] = true;
                $e['a'] = (float) ($c['cible'] ?? 0);
                $e['avis5'] = isset($c['avis5']) && $c['avis5'] !== null ? (int) $c['avis5'] : null;
            } elseif (($c['type'] ?? '') === 'classement') {
                // La meilleure vendeuse : le score de la 1re du magasin à reprendre.
                $a = isset($c['repereVal']) && $c['repereVal'] !== null ? (float) $c['repereVal'] : null;
                if ($a === null || $val === null) { continue; }
                $e += ['ecart' => max(1, (int) ceil($a - $val - 1e-9)), 'rang' => $c['rang'] ?? null];
                $e['a'] = $a;
            } else {
                // Une catégorie ou une promo : des pièces — pour passer la 1re, atteindre son objectif, ou celui du magasin.
                $regle = (string) ($c['regle'] ?? 'premiere');
                $a = isset($c['repereVal']) && $c['repereVal'] !== null ? (float) $c['repereVal'] : null;
                $manque = ($c['type'] ?? '') === 'categorie' && isset($c['pour1']) && $c['pour1'] !== null
                    ? (int) $c['pour1'] : ($a !== null ? max(1, (int) ceil($a - ($val ?? 0) - 1e-9)) : null);
                if ($manque === null) { continue; }
                $e['equipe'] = $e['magasin'] = $regle === 'magasin';
                $e += ['manque' => $manque, 'regle' => $regle,
                    'rythme' => $rythme($regle === 'magasin' ? (isset($c['moiPcs']) ? (float) $c['moiPcs'] : null) : $val)];
                $e['a'] = $a ?? (($val ?? 0) + $manque);
            }
            $e['progres'] = ($e['de'] !== null && $e['a'] !== null && $e['a'] > 0) ? round(max(0.0, min(1.0, $e['de'] / $e['a'])), 3) : null;
            if ($e['gain'] <= 0 && empty($c['cadeau'])) { continue; }
            $out[] = $e;
        }
        // Le plus proche d'abord ; les étapes sans mesure en dernier.
        usort($out, static fn ($x, $y) => ($y['progres'] ?? -1) <=> ($x['progres'] ?? -1));
        return $out;
    }

    /** Ce qu'il lui reste au planning ce mois-ci, en heures. */
    public static function heuresRestantes(array $f): ?float
    {
        $h = (array) ($f['heures'] ?? []);
        if (!isset($h['mois'])) { return null; }
        return round(max(0.0, (float) $h['mois'] - (float) ($h['faites'] ?? 0)), 1);
    }
}
