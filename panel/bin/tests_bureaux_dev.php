#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); // http_response_code() des routes, sans en-têtes déjà partis

/**
 * Tests des bureaux en développement (panel/src/bureaux_dev.php) — sans base, sans réseau : la réponse au questionnaire
 * rendue propre (obligatoires, TVA, téléphone, jours, accord), la ligne d'une demande, l'id d'un client créé, le bon de
 * bienvenue (code commun ou code personnel), et les routes publiques qui refusent un jeton mal formé.
 */

final class Db
{
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { self::$exec[] = [$sql, $a]; return 1; }
}
final class ErpApi
{
    public static array $vus = [];
    public static function disponible(): bool { return true; }
    public static function ecrire(string $m, string $p, ?array $b = null): array { self::$vus[] = [$m, $p, $b]; return ['code' => 200, 'corps' => ['id' => 5], 'erreur' => null]; }
}
$GLOBALS['cible'] = 'clients';
function pmMienne(int $shop, int $id): ?array { return $id === 321 ? ['panelId' => 321, 'nom' => '−20 % le premier lunch', 'description' => 'une fois', 'statut' => 'en_caisse', 'bon' => ['campagne' => 900]] : null; }
function pmMiennes(int $shop): array { return [[pmMienne($shop, 321), ['panelId' => 4, 'nom' => 'Sans bon', 'statut' => 'en_caisse', 'bon' => null]], null]; }
function pbLire(int $camp, array $c = []): ?array { return ['campagne' => $camp, 'cible' => $GLOBALS['cible'], 'code' => $GLOBALS['cible'] === 'code' ? 'BUREAU10' : null]; }
function body(): array { return []; }

require __DIR__ . '/../src/bureaux_dev.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "La réponse rendue propre\n";
$bon = ['prenom' => ' Marie ', 'nom' => 'Dupont', 'email' => 'Marie.Dupont@Horizon.be', 'tel' => '+32 470 12 34 56', 'societe' => 'Centre Horizon', 'tva' => 'be 0789.114.256',
    'adresse' => 'Rue de la Station 14', 'cp' => '6040', 'ville' => 'Jumet', 'personnes' => '18', 'jours' => ['5', '1', '9', '1'], 'accord' => true, 'remarque' => str_repeat('x', 900)];
[$d, $e] = bdvNettoyer($bon, true);
verifie('une réponse complète passe', $e === [], implode(', ', $e));
verifie('TVA, e-mail, téléphone, jours mis en forme', $d['tva'] === 'BE0789114256' && $d['email'] === 'marie.dupont@horizon.be' && $d['tel'] === '+32470123456' && $d['jours'] === [1, 5] && $d['prenom'] === 'Marie');
verifie('le nom du bureau : la société, la remarque coupée', $d['bureau'] === 'Centre Horizon' && mb_strlen($d['remarque']) === 600 && $d['personnes'] === 18);
[, $e] = bdvNettoyer(['accord' => true], true);
verifie('vide : ce qui manque, dit en clair', count($e) === 5 && in_array('un e-mail valide', $e, true));
[, $e] = bdvNettoyer(['accord' => false] + $bon, true);
verifie('le questionnaire exige l’accord, le dashboard non', $e === ['votre accord pour être recontacté'] && bdvNettoyer(['accord' => false] + $bon, false)[1] === []);
[, $e] = bdvNettoyer(['tva' => '12'] + $bon, true);
verifie('une TVA fausse est refusée, une TVA absente non', $e === ['un numéro de TVA valide (BE0123456789), ou rien'] && bdvNettoyer(['tva' => ''] + $bon, true)[1] === []);
verifie('un téléphone trop court ne passe pas', bdvTel('0470 12') === '' && bdvTel('0032 470 12 34 56') === '0032470123456');

echo "La demande et TF Buddy\n";
$l = bdvLigne(['id' => '7', 'source' => 'questionnaire', 'statut' => 'a_valider', 'cree_le' => '2026-11-26 06:55:12', 'donnees' => json_encode($d), 'client_id' => '1999', 'societe_id' => null,
    'bon_code' => 'BC2N54QRT', 'bon_texte' => '−20 %', 'erreurs' => "société non créée\n", 'office_id' => null, 'traite_le' => null]);
verifie('la ligne d’une demande', $l['id'] === 7 && $l['personne'] === 'Marie Dupont' && $l['clientId'] === 1999 && $l['societeId'] === null && $l['bon']['code'] === 'BC2N54QRT' && $l['erreurs'] === ['société non créée'] && $l['le'] === '2026-11-26 06:55');
verifie('l’id d’un client créé, quelle que soit l’enveloppe', bdvIdClient(['data' => ['id' => 12]]) === 12 && bdvIdClient(['client' => ['id' => '13']]) === 13 && bdvIdClient(['id' => 14]) === 14 && bdvIdClient(['success' => false]) === null);

echo "Le bon de bienvenue\n";
[$c, $t, $err] = bdvBon(3, 321, 1999);
$w = ErpApi::$vus[0] ?? null;
verifie('un bon à codes personnels : un code au nom du client, une utilisation', $c !== null && $err === null && $w[1] === '/admin/voucher-campaigns/900/codes' && $w[2]['id_customer'] === 1999 && $w[2]['usage_limit'] === 1 && $w[2]['code'] === $c, json_encode($w));
$GLOBALS['cible'] = 'code'; ErpApi::$vus = [];
[$c, $t] = bdvBon(3, 321, null);
verifie('un bon à code commun : le code tel quel, rien d’écrit', $c === 'BUREAU10' && ErpApi::$vus === [] && $t === '−20 % le premier lunch — une fois');
verifie('pas de bon choisi : rien', bdvBon(3, 0, 1) === [null, null, null]);
$GLOBALS['cible'] = 'clients';
verifie('un code personnel sans client : dit', bdvBon(3, 321, null)[2] === 'pas de client pour un code personnel');
verifie('les promotions à bon seulement', array_column(bdvPromosABon(3), 'id') === [321]);

echo "La route publique\n";
$r = bdvPublic('GET', 'pas-un-jeton');
verifie('un jeton mal formé : introuvable, sans lire la base', ($r['error'] ?? '') === 'questionnaire introuvable' && !array_filter(Db::$exec, static fn ($x) => str_contains($x[0], 'SELECT')));
$r = bdvPublic('GET', str_repeat('a', 32));
verifie('un jeton inconnu : fermé', ($r['error'] ?? '') === 'ce questionnaire n’est plus ouvert');

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
