#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de « Adapter mon prix de vente » (panel/src/prix_produit.php) — sans base, sans réseau.
 *
 *   php panel/bin/tests_prix_produit.php
 *
 * Le passage du prix testé hors TVA au prix TTC du panel, l'arrondi aux 5 centimes, la portion d'un produit
 * divisible, le prix maximum du réseau, l'aperçu qui n'écrit rien, l'envoi, la relecture et le journal.
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static array $journal = [];
    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO panel_prix_modif')) { self::$journal[] = $a; }
        return 1;
    }
}
final class PanelApi
{
    public static array $dispo = [];
    public static array $envois = [];
    public static int $code = 200;
    public static ?float $relu = null;
    public static function configured(): bool { return true; }
    public static function produitsDisponibles(int $shop): array { return self::$dispo[$shop] ?? []; }
    public static function requete(string $m, string $path, ?array $body = null): array
    {
        self::$envois[] = [$m, $path, $body];
        return ['code' => self::$code, 'data' => null, 'erreur' => self::$code >= 300 ? 'HTTP ' . self::$code . ' : PRICE_ABOVE_MAX' : null];
    }
    public static function get(string $path): mixed { return self::$relu === null ? null : ['data' => ['portion_price' => self::$relu]]; }
}
$CORPS = [];
function body(): array { global $CORPS; return $CORPS; }

require __DIR__ . '/../src/prix_produit.php';

$ok = 0; $ko = 0;
function verifie(string $quoi, bool $vrai): void
{
    global $ok, $ko;
    if ($vrai) { $ok++; } else { $ko++; fwrite(STDERR, "ÉCHEC : $quoi\n"); }
}
function appel(array $corps): array
{
    global $CORPS; $CORPS = $corps; http_response_code(200);
    return wr_analyse_produit_prix();
}

// --- le plan (fonction pure) ---------------------------------------------------------
$tarte = ['id' => 2300010, 'base_name' => 'Fromage & Spéculoos - 120∅', 'tax_value_percent' => 6, 'portion_price' => 10.5, 'max_portion_price' => 13.5];
$p = pxpPlan($tarte, 11.90);
verifie('11,90 € HT à 6 % : 12,61 → 12,60 € TTC', $p['ecrit'] === 12.6 && $p['pieceTtc'] === 12.6 && $p['erreur'] === null && $p['change'] === true);
verifie('le hors TVA du prix écrit est rendu', $p['pieceHt'] === 11.89);
verifie('le prix d’avant est celui du panel', $p['avant'] === 10.5);
verifie('arrondi aux 5 centimes les plus proches', pxpArrondi5(12.62) === 12.6 && pxpArrondi5(12.63) === 12.65 && pxpArrondi5(0.01) === 0.0);
verifie('au-dessus du maximum du réseau : refusé', str_contains((string) pxpPlan($tarte, 13.00)['erreur'], 'maximum du réseau'));
verifie('le motif du blocage est dit : max, inchange, tva', pxpPlan($tarte, 13.00)['bloque'] === 'max' && pxpPlan($tarte, 9.90)['bloque'] === 'inchange' && pxpPlan(['id' => 1], 2.0)['bloque'] === 'tva' && pxpPlan($tarte, 11.90)['bloque'] === null);
verifie('au maximum du réseau exactement : permis', pxpPlan($tarte, 13.5 / 1.06)['erreur'] === null);
verifie('le même prix : rien à écrire', str_contains((string) pxpPlan($tarte, 9.90)['erreur'], 'déjà ce prix') && pxpPlan($tarte, 9.90)['change'] === false);
verifie('sans TVA au panel : pas de calcul', pxpPlan(['id' => 1, 'portion_price' => 2], 2.0)['erreur'] !== null && pxpPlan(['id' => 1, 'portion_price' => 2], 2.0)['ecrit'] === null);
verifie('sans maximum : pas de plafond', pxpPlan(['tax_val_perc' => '6.00', 'portion_price' => '2.00'], 50.0)['erreur'] === null);
$quart = ['id' => 7, 'tax_value_percent' => 6, 'is_divisible' => 1, 'portion_size' => 0.25, 'portion_price' => 3.5];
$q = pxpPlan($quart, 15.0);   // 15 × 1,06 × 0,25 = 3,975 → 4,00 la portion, 16,00 la pièce
verifie('produit divisible : le prix écrit est celui de la portion', $q['ecrit'] === 4.0 && $q['pieceTtc'] === 16.0 && $q['part'] === 0.25 && $q['avantPiece'] === 14.0);
verifie('la ligne du produit se trouve par son numéro', pxpLigne([['id' => 3], $tarte], 2300010) === $tarte && pxpLigne([$tarte], 5) === null);
verifie('l’envoi par défaut : le contrat du panel', pxpEnvoi(4, 2300010, 12.6) === ['methode' => 'PATCH', 'route' => '/shops/4/products/2300010/price', 'corps' => ['portion_price' => 12.6]]);

// --- la route ------------------------------------------------------------------------
PanelApi::$dispo = [4 => [$tarte, $quart]];
$r = appel(['shop' => 4, 'pid' => '2300010', 'prixHt' => 11.9, 'apercu' => true]);
verifie('aperçu : le plan, sans rien écrire', $r['ok'] === true && $r['apercu'] === true && $r['ecrit'] === 12.6 && PanelApi::$envois === [] && Db::$journal === []);
$r = appel(['shop' => 4, 'pid' => '2300010', 'prixHt' => 13.0, 'apercu' => true]);
verifie('aperçu au-dessus du maximum : dit, sans erreur HTTP', $r['ok'] === false && str_contains($r['erreur'], 'maximum') && http_response_code() === 200);
$r = appel(['shop' => 4, 'pid' => '2300010', 'prixHt' => 13.0]);
verifie('écriture au-dessus du maximum : 422, rien d’envoyé', http_response_code() === 422 && isset($r['error']) && PanelApi::$envois === []);

PanelApi::$relu = 12.6;
$r = appel(['shop' => 4, 'pid' => 2300010, 'prixHt' => 11.9]);
verifie('écriture : PATCH du prix TTC au panel', PanelApi::$envois === [['PATCH', '/shops/4/products/2300010/price', ['portion_price' => 12.6]]]);
verifie('écriture relue : ok', $r['ok'] === true && $r['resultat'] === 'ok' && $r['relu'] === 12.6 && $r['message'] === '');
verifie('écriture gardée au journal', count(Db::$journal) === 1 && Db::$journal[0][0] === '4' && Db::$journal[0][1] === 2300010 && Db::$journal[0][3] === 10.5 && Db::$journal[0][4] === 12.6 && Db::$journal[0][8] === 'ok');

PanelApi::$relu = 10.5;
$r = appel(['shop' => 4, 'pid' => 2300010, 'prixHt' => 11.9]);
verifie('accepté mais inchangé à la relecture : sans effet', $r['ok'] === false && $r['resultat'] === 'sans_effet' && str_contains($r['message'], '10,50'));
PanelApi::$relu = null;
$r = appel(['shop' => 4, 'pid' => 2300010, 'prixHt' => 11.9]);
verifie('accepté, relecture muette : ok avec un mot', $r['ok'] === true && str_contains($r['message'], 'relu'));
PanelApi::$code = 422;
$r = appel(['shop' => 4, 'pid' => 2300010, 'prixHt' => 11.9]);
verifie('refus du panel : 502 et son motif', http_response_code() === 502 && str_contains($r['error'], 'PRICE_ABOVE_MAX') && end(Db::$journal)[8] === 'refuse');
PanelApi::$code = 200;

$n = count(PanelApi::$envois);
verifie('produit absent du magasin : 404', isset(appel(['shop' => 4, 'pid' => 99, 'prixHt' => 2])['error']) && http_response_code() === 404);
verifie('une portion (« produit:portion ») : 400', isset(appel(['shop' => 4, 'pid' => '2300010:ONE_HALF', 'prixHt' => 2])['error']) && http_response_code() === 400);
verifie('prix nul ou absent : 422', isset(appel(['shop' => 4, 'pid' => 7, 'prixHt' => 0])['error']) && http_response_code() === 422 && isset(appel(['shop' => 4, 'pid' => 7])['error']));
verifie('sans magasin : 400', isset(appel(['pid' => 7, 'prixHt' => 2])['error']) && http_response_code() === 400);
verifie('aucun de ces refus n’écrit au panel', count(PanelApi::$envois) === $n);

echo "$ok réussis, $ko échecs\n";
exit($ko ? 1 : 0);
