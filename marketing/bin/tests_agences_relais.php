#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Les agences se lisent et s'écrivent au module Marketing (21/11/2026, lot 5b) : la liste (GET …/agencies), l'agence
 * qui signe la note d'une campagne (sa fiche, puis l'agence par défaut), la création, la modification et le retrait
 * (POST, PATCH, DELETE …/agencies, marketing#81) passent par l'API du module, plus par mar_agency ni
 * mar_campaign_channel. Sans base, sans réseau.
 *
 *   php marketing/bin/tests_agences_relais.php
 */
ob_start();   // http_response_code() ne retient plus le code en ligne de commande une fois une sortie partie

final class Db
{
    /** @var list<string> */
    public static array $sql = [];
    public static function rows(string $s, array $a = []): array { self::$sql[] = $s; return []; }
    public static function row(string $s, array $a = []): ?array { self::$sql[] = $s; return null; }
    public static function exec(string $s, array $a = []): int { self::$sql[] = $s; return 1; }
}
$GLOBALS['corps'] = [];
$GLOBALS['journal'] = [];
function body(): array { return $GLOBALS['corps']; }
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['journal'][] = [$project, $message]; }

/** @var list<array{0:string,1:string,2:?array}> */
$GLOBALS['appels'] = [];
$GLOBALS['fixes'] = [];      // lectures : chemin → réponse
$GLOBALS['reponses'] = [];   // écritures : file de réponses
// Comme le vrai marketingAppel : `data` déballé, message d'erreur lu dans description ou error.message, 204 sans corps.
function marketingAppel(string $methode, string $chemin, ?array $corps = null): array
{
    $GLOBALS['appels'][] = [$methode, $chemin, $corps];
    $r = ($methode === 'GET' ? ($GLOBALS['fixes'][$chemin] ?? null) : array_shift($GLOBALS['reponses']))
        ?? ['code' => 404, 'j' => ['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Route inconnue.']]];
    $j = $r['j'];
    if ($r['code'] === 0) { return ['code' => 0, 'corps' => null, 'erreur' => 'module injoignable']; }
    $msg = $r['code'] >= 400 ? (string) ($j['description'] ?? $j['error']['message'] ?? 'HTTP ' . $r['code']) : null;
    return ['code' => $r['code'], 'corps' => is_array($j) && array_key_exists('data', $j) ? $j['data'] : $j, 'erreur' => $msg, 'brut' => $j];
}

require __DIR__ . '/../src/mkt_types.php';
require __DIR__ . '/../src/campagnes.php';
require __DIR__ . '/../src/mkt_brief.php';     // mktBriefAdresse : rien ne s'y exécute au chargement
require __DIR__ . '/../src/mkt_agences.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function poser(array $fixes, array $reponses = [], array $corps = []): void
{
    Db::$sql = []; $GLOBALS['appels'] = []; $GLOBALS['journal'] = [];
    $GLOBALS['fixes'] = $fixes; $GLOBALS['reponses'] = $reponses; $GLOBALS['corps'] = $corps;
    agencesModule(true);   // le cache d'une requête ne passe pas à la suivante
    $GLOBALS['appels'] = [];
    http_response_code(200);
}
$ok200 = static fn ($j): array => ['code' => 200, 'j' => $j];

// Le module rend les agences par nom (Collectif, Studio, Zèbre) ; Studio est celle par défaut.
$agences = [
    ['id' => 1, 'name' => 'Collectif MKTG', 'email' => null, 'site' => 'yan@collectif.example', 'logo_uri' => 'data:image/png;base64,AA', 'is_default' => false, 'channel_campaigns' => 2],
    ['id' => 3, 'name' => 'Studio Vertigo', 'email' => 'contact@vertigo.example', 'site' => null, 'logo_uri' => null, 'is_default' => true, 'channel_campaigns' => 0],
    ['id' => 2, 'name' => 'Zèbre', 'email' => 'zebre@example', 'site' => 'zebre.example', 'logo_uri' => null, 'is_default' => false, 'channel_campaigns' => 1],
];
$jeu = ['/agencies' => $ok200($agences)];

echo "La liste des agences\n";
poser($jeu);
$e = ep_mkt_agences();
verifie('l’agence par défaut d’abord, puis par nom ; aucune requête en base', array_column($e['agences'], 'id') === [3, 1, 2] && Db::$sql === [],
    json_encode(array_column($e['agences'] ?? [], 'id')));
verifie('une agence garde les clés de l’écran ; l’adresse écrite dans « site » sert d’adresse', $e['agences'][1] === ['id' => 1, 'nom' => 'Collectif MKTG',
    'email' => 'yan@collectif.example', 'site' => 'yan@collectif.example', 'logo' => 'data:image/png;base64,AA', 'defaut' => false, 'campagnes' => 2]
    && $e['agences'][0]['site'] === '' && $e['agences'][2]['email'] === '', json_encode($e['agences']));
agenceListe(); agenceListe(); agenceDeCampagne(9);
verifie('lue une fois par requête : les lectures suivantes ne rappellent pas le module (seule la fiche de la campagne)',
    $GLOBALS['appels'] === [['GET', '/campaigns/9', null]], json_encode($GLOBALS['appels']));
poser(['/agencies' => ['code' => 0, 'j' => null]]);
$e = ep_mkt_agences();
verifie('module injoignable : 502 et la raison, pas une liste vide', http_response_code() === 502 && str_contains((string) ($e['error'] ?? ''), 'module injoignable'), json_encode($e));
verifie('la note, elle, retombe sur son réglage (liste vide, aucune agence qui signe)', agenceListe() === [] && agenceDeCampagne(9) === null);

echo "L'agence qui signe la note d'une campagne\n";
poser($jeu + ['/campaigns/9' => $ok200(['id' => 9, 'channels' => [
    ['label' => 'Affiches', 'agency_id' => 1, 'budget_amount' => 300.0],
    ['label' => 'Radio', 'agency_id' => 2, 'budget_amount' => 900.0],
    ['label' => 'Flyers', 'agency_id' => null, 'budget_amount' => 5000.0],
    ['label' => 'Inconnue', 'agency_id' => 99, 'budget_amount' => 8000.0],
]])]);
$a = agenceDeCampagne(9);
verifie('celle du canal le mieux doté parmi ceux qui désignent une agence connue', ($a['id'] ?? null) === 2 && $a['campagnes'] === 0 && $a['nom'] === 'Zèbre' && Db::$sql === [], json_encode($a));
poser($jeu + ['/campaigns/9' => $ok200(['id' => 9, 'channels' => [['label' => 'Flyers', 'agency_id' => null, 'budget_amount' => 10.0]]])]);
verifie('aucun canal avec agence : celle par défaut', (agenceDeCampagne(9)['id'] ?? null) === 3);
poser(['/agencies' => $ok200(array_map(static fn ($x) => ['is_default' => false] + $x, $agences))]);
verifie('ni l’une ni l’autre (fiche illisible comprise) : aucune', agenceDeCampagne(9) === null);

echo "Créer, modifier, retirer\n";
$cree = ['code' => 201, 'j' => ['success' => true, 'data' => ['id' => 7, 'name' => 'Nouvelle']]];
poser($jeu, [$cree], ['nom' => '  Nouvelle  ', 'email' => 'pas-une-adresse', 'site' => ' nouvelle.example ', 'logo' => 'https://x.example/logo.png', 'defaut' => 1]);
$r = wr_mkt_agence(null);
verifie('création : POST au module, champs nettoyés comme avant (adresse et logo refusés vidés), par défaut',
    ($GLOBALS['appels'][0] ?? null) === ['POST', '/agencies', ['name' => 'Nouvelle', 'email' => '', 'site' => 'nouvelle.example', 'logoUri' => '', 'isDefault' => true]]
        && ($r['ok'] ?? false) === true && $GLOBALS['journal'] === [['Nouvelle', 'Agence ajoutée']] && Db::$sql === [],
    json_encode($GLOBALS['appels']));
verifie('puis la liste relue au module', ($GLOBALS['appels'][1] ?? null) === ['GET', '/agencies', null] && array_column($r['agences'], 'id') === [3, 1, 2]);
poser($jeu, [], ['nom' => '  ']);
$r = wr_mkt_agence(null);
verifie('création sans nom : 422, rien d’envoyé au module', http_response_code() === 422 && $GLOBALS['appels'] === [] && isset($r['error']));

poser($jeu, [$ok200(['success' => true, 'data' => ['id' => 2]])], ['nom' => '', 'email' => 'Zebre@Example.com']);
wr_mkt_agence(2);
verifie('modification : les champs reçus seuls (un nom vide garde l’ancien)',
    ($GLOBALS['appels'][0] ?? null) === ['PATCH', '/agencies/2', ['email' => 'Zebre@Example.com']] && $GLOBALS['journal'] === [], json_encode($GLOBALS['appels']));
poser($jeu, [$ok200(['success' => true, 'data' => ['id' => 2]])], ['defaut' => true]);
wr_mkt_agence(2);
verifie('« par défaut » : isDefault seul', ($GLOBALS['appels'][0][2] ?? null) === ['isDefault' => true]);
poser($jeu, [$ok200(['success' => true, 'data' => ['id' => 2]])], ['logo' => 'data:image/jpeg;base64,/9j/']);
wr_mkt_agence(2);
verifie('un logo embarqué passe tel quel', ($GLOBALS['appels'][0][2] ?? null) === ['logoUri' => 'data:image/jpeg;base64,/9j/']);
poser($jeu, [['code' => 404, 'j' => ['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Agence introuvable.']]]], ['nom' => 'X']);
$r = wr_mkt_agence(99);
verifie('agence inconnue du module : 404 « agence inconnue »', http_response_code() === 404 && ($r['error'] ?? '') === 'agence inconnue');
poser($jeu, [['code' => 422, 'j' => ['success' => false, 'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Agence invalide.', 'details' => []]]]], ['nom' => 'X']);
$r = wr_mkt_agence(2);
verifie('refus du module : son code (422) et son message', http_response_code() === 422 && ($r['error'] ?? '') === 'Agence invalide.');
poser($jeu, [['code' => 0, 'j' => null]], ['nom' => 'X']);
$r = wr_mkt_agence(2);
verifie('module injoignable : 502 et la raison', http_response_code() === 502 && str_contains((string) ($r['error'] ?? ''), 'module injoignable'));

poser($jeu, [['code' => 204, 'j' => null]]);
$r = wr_mkt_agence_suppr(2);
verifie('retrait : DELETE au module (204), journal au nom de l’agence, liste relue',
    $GLOBALS['appels'] === [['DELETE', '/agencies/2', null], ['GET', '/agencies', null]] && ($r['ok'] ?? false) === true
        && $GLOBALS['journal'] === [['Zèbre', 'Agence retirée']] && Db::$sql === [], json_encode($GLOBALS['appels']));
$refus = 'Collectif MKTG : 3 canal(aux) de campagne la désignent : elle ne peut pas être retirée.';
poser($jeu, [['code' => 409, 'j' => ['success' => false, 'error' => ['code' => 'RESOURCE_STATE_INVALID', 'message' => $refus, 'details' => []]]]]);
$r = wr_mkt_agence_suppr(1);
verifie('une agence que des canaux désignent : 409 et la raison du module, rien au journal',
    http_response_code() === 409 && ($r['error'] ?? '') === $refus && $GLOBALS['journal'] === []);
poser($jeu, [['code' => 404, 'j' => ['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Agence introuvable.']]]]);
$r = wr_mkt_agence_suppr(99);
verifie('retrait d’une agence inconnue : 404', http_response_code() === 404 && ($r['error'] ?? '') === 'agence inconnue');

echo "\n$ok ok, $ko KO\n";
ob_end_flush();
exit($ko === 0 ? 0 : 1);
