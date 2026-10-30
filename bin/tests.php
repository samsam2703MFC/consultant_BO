#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests Brand Guard et module Marketing — sans base, sans réseau, sans token.
 *
 *   php bin/tests.php
 *
 * Ce qui se vérifie ici est ce qui doit rester vrai quoi qu'il arrive à
 * l'extérieur : la règle de décision du verdict, la signature des webhooks,
 * la résolution URL → page_id contre un Graph simulé, la détection d'un post
 * sauvage, la lecture d'un événement `feed`, la version de charte ; pour le
 * module Marketing, la phase d'une campagne et la liste « à faire ».
 *
 * Le code métier lit la base par `Db` et les réglages par `setting()` : ils
 * sont remplacés par des doublures minimales, le temps du test.
 */

// --- doublures : pas de base, pas de réglage, pas de journal ---------------------
final class Db
{
    public static array $rows = [];
    public static function rows(string $sql, array $a = []): array { return self::$rows[$sql] ?? []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void {}
    public static function pdo(): never { throw new RuntimeException('pas de base en test'); }
    public static function config(): array { return []; }
}
// Un réglage se pose dans $GLOBALS['reglages'] le temps d'un test ; sinon, la valeur par défaut.
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return []; }

require __DIR__ . '/../src/meta_graph.php';
require __DIR__ . '/../src/brand_guard.php';
require __DIR__ . '/../src/marketing_hub.php';
require __DIR__ . '/../src/marketing_sources.php';
require __DIR__ . '/../src/marketing_tables.php';
require __DIR__ . '/../src/con_tables.php';
require __DIR__ . '/../src/linkedin_api.php';
require __DIR__ . '/../src/social_publication.php';
require __DIR__ . '/../src/fbcontrole.php';
require __DIR__ . '/../src/rayons_photos.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- 1. la règle de décision ---------------------------------------------------------
echo "Règle de décision\n";
$d = bgDecision([]);
verifie('aucun écart ⇒ conforme, 100', $d['statut'] === 'conforme' && $d['score'] === 100);
$d = bgDecision([['gravite' => 'mineur'], ['gravite' => 'mineur']]);
verifie('mineurs seuls ⇒ conforme', $d['statut'] === 'conforme' && $d['score'] === 86, json_encode($d));
$d = bgDecision([['gravite' => 'majeur'], ['gravite' => 'mineur']], 95);
verifie('un majeur ⇒ à corriger, score plafonné à 79', $d['statut'] === 'a_corriger' && $d['score'] === 79, json_encode($d));
$d = bgDecision([['gravite' => 'bloquant']], 90);
verifie('un bloquant ⇒ bloqué, score plafonné à 49', $d['statut'] === 'bloque' && $d['score'] === 49, json_encode($d));
$d = bgDecision([['gravite' => 'bloquant', 'statut' => 'ignore'], ['gravite' => 'mineur']]);
verifie('un bloquant écarté ne compte plus', $d['statut'] === 'conforme', json_encode($d));
$d = bgDecision([['gravite' => 'majeur']], 250);
verifie('score hors bornes ramené', $d['score'] <= 79 && $d['score'] >= 0);
$d = bgDecision([['gravite' => 'inconnue']]);
verifie('gravité inconnue lue comme mineure', $d['statut'] === 'conforme');

// --- 2. la signature des webhooks -----------------------------------------------------
echo "Signature X-Hub-Signature-256\n";
$secret = 'secret-de-test';
$corps = '{"object":"page","entry":[]}';
$sig = 'sha256=' . hash_hmac('sha256', $corps, $secret);
verifie('signature juste acceptée', MetaGraph::signatureOk($corps, $sig, $secret));
verifie('signature fausse refusée', !MetaGraph::signatureOk($corps, 'sha256=' . str_repeat('0', 64), $secret));
verifie('corps modifié refusé', !MetaGraph::signatureOk($corps . ' ', $sig, $secret));
verifie('en-tête absent refusé', !MetaGraph::signatureOk($corps, null, $secret));
verifie('mauvais préfixe refusé', !MetaGraph::signatureOk($corps, 'sha1=abc', $secret));
verifie('sans secret configuré, rien ne passe', !MetaGraph::signatureOk($corps, $sig, ''));

// --- 3. la résolution URL → page_id contre un Graph simulé ------------------------------
echo "Résolution des pages (Graph simulé)\n";
$appels = [];
MetaGraph::$attendre = static function (int $s): void {};
MetaGraph::$transport = static function (string $m, string $url, array $p) use (&$appels): array {
    $appels[] = $url;
    if (str_contains($url, '/latelierby.halle')) { return [200, ['id' => '1122334455', 'name' => "L'Atelier by Halle"]]; }
    if (str_contains($url, '/123456789012')) { return [200, ['id' => '123456789012', 'name' => 'Page numérique']]; }
    if (str_contains($url, '/inconnue')) { return [404, ['error' => ['message' => 'Unsupported get request', 'code' => 100]]]; }
    if (str_contains($url, '/expiree')) { return [400, ['error' => ['message' => 'Error validating access token', 'code' => 190]]]; }
    if (str_contains($url, '/limitee')) {
        static $n = 0; $n++;
        return $n < 3 ? [429, ['error' => ['message' => 'rate', 'code' => 4]]] : [200, ['id' => '99', 'name' => 'Après retry']];
    }
    return [500, null];
};
$r = MetaGraph::resoudrePage('https://www.facebook.com/latelierby.halle', 'tok');
verifie('URL classique → id', $r['id'] === '1122334455' && $r['nom'] === "L'Atelier by Halle");
$r = MetaGraph::resoudrePage('https://www.facebook.com/profile.php?id=123456789012', 'tok');
verifie('URL profile.php?id= → id', $r['id'] === '123456789012');
$r = MetaGraph::resoudrePage('latelierby.halle', 'tok');
verifie('nom d’utilisateur nu → id', $r['id'] === '1122334455');
try { MetaGraph::resoudrePage('https://www.facebook.com/XXXX', 'tok'); verifie('URL placeholder XXXX refusée', false); }
catch (MetaIndisponible $e) { verifie('URL placeholder XXXX refusée', true); }
try { MetaGraph::resoudrePage('https://www.facebook.com/inconnue', 'tok'); verifie('page inconnue ⇒ MetaIndisponible', false); }
catch (MetaIndisponible $e) { verifie('page inconnue ⇒ MetaIndisponible', str_contains($e->getMessage(), 'Unsupported')); }
try { MetaGraph::resoudrePage('https://www.facebook.com/expiree', 'tok'); verifie('code 190 ⇒ MetaTokenExpire', false); }
catch (MetaTokenExpire $e) { verifie('code 190 ⇒ MetaTokenExpire', true); }
catch (Throwable $e) { verifie('code 190 ⇒ MetaTokenExpire', false, get_class($e)); }
$r = MetaGraph::resoudrePage('https://www.facebook.com/limitee', 'tok');
verifie('rate limit rejoué avec backoff', $r['id'] === '99');
verifie('version d’API dans l’URL', str_contains($appels[0], 'graph.facebook.com/v21.0/'));
// Sans token : la voie publique (Page Plugin), dont le HTML est simulé.
MetaGraph::$transport = static function (string $m, string $url, array $p): array {
    if ($m === 'GET-HTML' && str_contains($url, 'plugins/page.php') && str_contains($url, 'atelierbyhalle')) {
        return [200, '<html>…"pageID":"883508651512115",…"name":"L\'Atelier by Halle"…</html>'];
    }
    if ($m === 'GET-HTML') { return [200, '<html>Log in or sign up</html>']; }
    return [500, null];
};
$r = MetaGraph::resoudrePage('https://www.facebook.com/atelierbyhalle', '');
verifie('sans token : identifiant lu par la voie publique', $r['id'] === '883508651512115' && $r['source'] === 'public', json_encode($r));
try { MetaGraph::resoudrePage('https://www.facebook.com/inconnue', ''); verifie('sans token, page non publique ⇒ MetaIndisponible', false); }
catch (MetaIndisponible $e) { verifie('sans token, page non publique ⇒ MetaIndisponible', true); }

// --- 4. la détection d'un post sauvage --------------------------------------------------
echo "Post sauvage\n";
$connus = ['1122334455_9001', '9002', ''];
verifie('id connu complet ⇒ pas sauvage', !bgEstSauvage('1122334455_9001', $connus));
verifie('id connu court ⇒ pas sauvage', !bgEstSauvage('1122334455_9002', $connus));
verifie('id inconnu ⇒ sauvage', bgEstSauvage('1122334455_9003', $connus));
verifie('id vide ⇒ jamais sauvage', !bgEstSauvage('', $connus));
verifie('aucune demande connue ⇒ sauvage', bgEstSauvage('1_2', []));

// --- 5. l'événement feed et la normalisation d'un post -----------------------------------
echo "Webhook feed\n";
$ev = MetaGraph::postsDuWebhook(['object' => 'page', 'entry' => [['id' => '1122334455', 'changes' => [
    ['field' => 'feed', 'value' => ['item' => 'post', 'verb' => 'add', 'post_id' => '1122334455_777']],
    ['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'post_id' => '1122334455_778']],
    ['field' => 'feed', 'value' => ['item' => 'post', 'verb' => 'remove', 'post_id' => '1122334455_779']],
    ['field' => 'mention', 'value' => ['item' => 'post', 'verb' => 'add', 'post_id' => '1122334455_780']],
]]]]);
verifie('seuls les posts ajoutés sont retenus', count($ev) === 1 && $ev[0]['post'] === '1122334455_777' && $ev[0]['page'] === '1122334455', json_encode($ev));
verifie('objet autre que page ignoré', MetaGraph::postsDuWebhook(['object' => 'user', 'entry' => []]) === []);
$p = MetaGraph::normaliser(['id' => '1_2', 'message' => 'Bonjour', 'created_time' => '2026-09-21T07:15:00+0000', 'permalink_url' => 'https://fb/x',
    'attachments' => ['data' => [['media' => ['image' => ['src' => 'https://cdn/a.jpg']], 'subattachments' => ['data' => [
        ['media' => ['image' => ['src' => 'https://cdn/1.jpg']]], ['media' => ['image' => ['src' => 'https://cdn/2.jpg']]]]]]]]]);
verifie('carrousel : toutes les images des sous-pièces', $p['images'] === ['https://cdn/1.jpg', 'https://cdn/2.jpg'], json_encode($p['images']));
verifie('date normalisée', $p['date'] === '2026-09-21 07:15:00', $p['date']);
$p = MetaGraph::normaliser(['id' => '1_3', 'full_picture' => 'https://cdn/full.jpg']);
verifie('sans pièce jointe : full_picture', $p['images'] === ['https://cdn/full.jpg'] && $p['texte'] === '');

// --- 6. la charte : version et texte -------------------------------------------------------
echo "Charte\n";
$regles = array_map(static fn ($r) => ['code' => $r[0], 'famille' => $r[1], 'libelle' => $r[2], 'description' => $r[3], 'gravite' => $r[4], 'actif' => true], bgCharteDemo());
$v1 = bgCharteVersion($regles);
$regles2 = $regles; $regles2[0]['gravite'] = 'bloquant';
verifie('la version change quand une règle change', $v1 !== bgCharteVersion($regles2) && strlen($v1) === 12);
verifie('la version ne dépend pas de l’ordre des champs inertes', $v1 === bgCharteVersion(array_map(static fn ($r) => $r + ['rang' => 99], $regles)));
$sys = bgSystem($regles);
verifie('le prompt cite chaque code de règle', !array_filter($regles, static fn ($r) => !str_contains($sys, '[' . $r['code'] . ']')));
verifie('le prompt porte la règle de décision', str_contains($sys, 'bloque') && str_contains($sys, 'a_corriger'));
verifie('la charte de démo a des bloquants, des majeurs et des mineurs',
    count(array_unique(array_column($regles, 'gravite'))) === 3);

// --- 7. le calendrier du cron ---------------------------------------------------------------
echo "Calendrier du cron\n";
$tz = new DateTimeZone('Europe/Brussels');
$pl = bgCronPlan(new DateTime('2026-09-22 07:10', $tz)); // mardi
verifie('mardi 7 h : audit 2 jours + rapport du jour, pas d’hebdo', $pl['audit'] && $pl['jours'] === 2 && $pl['quotidien'] && !$pl['hebdo'], json_encode($pl));
$pl = bgCronPlan(new DateTime('2026-09-21 07:00', $tz)); // lundi
verifie('lundi 7 h : audit 7 jours + rapport du jour + hebdo', $pl['audit'] && $pl['jours'] === 7 && $pl['quotidien'] && $pl['hebdo'], json_encode($pl));
$pl = bgCronPlan(new DateTime('2026-09-21 13:00', $tz));
verifie('lundi 13 h : rien', !$pl['audit'] && !$pl['quotidien'] && !$pl['hebdo'], json_encode($pl));
$pl = bgCronPlan(new DateTime('2026-09-23 15:00', $tz), true);
verifie('forcé : tout, quelle que soit l’heure', $pl['audit'] && $pl['quotidien'] && $pl['hebdo']);

// --- 8. le module Marketing : phase d'une campagne, vue magasin, à faire ---------------------
echo "Module Marketing\n";
$auj = '2026-10-13';
verifie('brouillon aux dates futures ⇒ brouillon', mhPhase('draft', '2026-10-20', '2026-10-30', $auj) === 'brouillon');
verifie('annulée en cours ⇒ brouillon', mhPhase('cancelled', '2026-10-01', '2026-10-30', $auj) === 'brouillon');
verifie('active qui démarre demain ⇒ à venir', mhPhase('active', '2026-10-14', '2026-10-30', $auj) === 'a_venir');
verifie('active finie hier ⇒ terminée', mhPhase('active', '2026-10-01', '2026-10-12', $auj) === 'terminee');
verifie('active qui finit aujourd’hui ⇒ en cours', mhPhase('active', '2026-10-01', '2026-10-13', $auj) === 'en_cours');
verifie('sans dates ⇒ sans_date', mhPhase('active', null, null, $auj) === 'sans_date');
$_GET = ['shop' => '4']; verifie('?shop=4 ⇒ vue magasin', mhShop() === '4');
$_GET = ['shop' => 'brand']; verifie('?shop=brand ⇒ vue réseau', mhShop() === null);
$_GET = ['shop' => "4' OR 1=1"]; verifie('?shop= douteux ⇒ vue réseau', mhShop() === null);
$_GET = [];
$camp = ['dispo' => true, 'liste' => [['id' => 1, 'nom' => 'Halloween', 'phase' => 'en_cours', 'debut' => '2026-10-10', 'fin' => '2026-10-31']]];
$post = static fn (string $statut, string $quand) => ['id' => 'p', 'statut' => $statut, 'message' => 'x', 'magasin' => 'Halle', 'publierLe' => $quand,
    'agent' => ['note' => null], 'decision' => ['le' => null], 'publie' => ['le' => null]];
$soc = static fn (array $l) => ['dispo' => true, 'liste' => $l];
$mail = ['dispo' => true, 'test' => false, 'liste' => []];
$off = ['dispo' => false];
$af = mhAFaire($camp, $mail, $soc([$post('a_valider', '2026-10-14 10:00'), $post('valide', '2026-10-12 10:00'), $post('a_controler', '2026-10-20 10:00')]), $off, null, $auj);
verifie('les posts vivent dans le portail social : aucun « à faire » de post', !array_filter($af, static fn ($a) => $a['canal'] === 'social'), json_encode($af));
verifie('campagne en cours sans newsletter ⇒ pas dite muette (ses posts sont dans le portail social)', !array_filter($af, static fn ($a) => str_starts_with($a['titre'], 'Aucune prise de parole')));
$campB = ['dispo' => true, 'liste' => [['id' => 2, 'nom' => 'Noël', 'phase' => 'brouillon', 'debut' => '2026-10-20', 'fin' => '2026-12-24']]];
$af = mhAFaire($campB, $mail, $soc([]), $off, null, $auj);
verifie('campagne en brouillon qui démarre sous 14 jours ⇒ urgent', ($af[0]['niveau'] ?? '') === 'urgent' && str_starts_with($af[0]['titre'], 'Campagne encore en brouillon'), json_encode($af[0] ?? null));

// --- 9. les règles de l'agent : un enregistrement sans paramètres ne les efface pas -----------
echo "Règles de l'agent\n";
$sansParams = fbReglesDefaut();
foreach ($sansParams['regles'] as &$r) { unset($r['params']); } unset($r);   // ce que l'écran renvoie (/referentiels/facebook-regles)
$iCasse = array_search('marque-casse', array_column($sansParams['regles'], 'code'), true);
$sansParams['regles'][$iCasse]['actif'] = false;                                 // une autre règle désactivée au passage
$GLOBALS['reglages'] = ['fbControle' => $sansParams];
$pack = array_column(fbRegles()['regles'], null, 'code');
verifie('les mots de « Conditions de promotion » reviennent du défaut', !empty($pack['promo-conditions']['params']['mots']));
verifie('le choix d’activation, lui, est gardé', $pack['marque-casse']['actif'] === false && $pack['promo-conditions']['actif'] === true);
$res = fbControler(['id' => 't', 'shopId' => null, 'shopNom' => null, 'auteur' => 'x', 'format' => 'Texte', 'message' => "Promo chez L'Atelier by : 2+1 sur les croissants ! #latelierby", 'lien' => null, 'medias' => [], 'publierLe' => null]);
verifie('une promotion sans période est encore relevée', (bool) array_filter($res['ecarts'], static fn ($e) => $e['code'] === 'promo-conditions'), json_encode(array_column($res['ecarts'], 'code')));
$GLOBALS['reglages'] = [];

// --- 10. l'inventaire des sources : lu dans le code ---------------------------------------
echo "Inventaire des sources\n";
$t = msTablesDe("SELECT * FROM mar_social_post p JOIN ceo_shop s ON 1; INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
verifie('FROM et JOIN ⇒ lue', ($t['mar_social_post'] ?? '') === 'lecture' && ($t['ceo_shop'] ?? '') === 'lecture', json_encode($t));
verifie('INSERT INTO ⇒ écrite', ($t['ceo_app_setting'] ?? '') === 'ecriture');
verifie('« ON DUPLICATE KEY UPDATE value » n’est pas une table', !isset($t['value']), json_encode($t));
$t = msTablesDe("CREATE TABLE IF NOT EXISTS ceo_x (id INT); SELECT * FROM ceo_x");
verifie('créée puis lue ⇒ créée', ($t['ceo_x'] ?? '') === 'creation');
$idx = msIndex(dirname(__DIR__));
$routeur = (string) file_get_contents(__DIR__ . '/../public/api/index.php');
$absentes = array_filter(MS_ROUTES, static fn ($r) => !isset($idx[$r[2]]));
verifie('chaque route du module a sa fonction dans src/', $absentes === [], json_encode(array_column($absentes, 2)));
$nonRoutees = array_filter(MS_ROUTES, static fn ($r) => !str_contains($routeur, $r[2] . '('));
verifie('chaque fonction est appelée par le routeur', $nonRoutees === [], json_encode(array_column($nonRoutees, 2)));
verifie('chercher une fiche Google ⇒ Google Places', in_array('google', msSuivre('ep_reputation_recherche', $idx)['apis'], true));
verifie('un envoi test ⇒ serveur SMTP', in_array('smtp', msSuivre('wr_newsletter_test_post', $idx)['apis'], true));
$dec = msSuivre('wr_fb_decision', $idx);
verifie('décider d’un post ⇒ mar_social_post écrite', ($dec['tables']['mar_social_post'] ?? '') === 'ecriture', json_encode($dec['tables']));
verifie('wr_param_put ⇒ seulement ceo_app_setting', array_keys(msSuivre('wr_param_put', $idx)['tables']) === ['ceo_app_setting']);
$cr = msTablesCreees(dirname(__DIR__));
verifie('origine : shops TFBuddy, mar_campaign module autonome, mar_social_post portail, ceo_shop cockpit',
    msOrigine('shops', $cr)['origine'] === 'tfbuddy' && msOrigine('mar_campaign', $cr)['origine'] === 'module'
    && msOrigine('mar_social_post', $cr)['origine'] === 'portail' && msOrigine('ceo_shop', $cr)['origine'] === 'cockpit');

// --- 11. la migration des tables sous mar_ -------------------------------------------------
echo "Migration mar_\n";
$p = marPlanMigration(['ceo_fb_post' => null, 'ceo_nl_campagne' => null]);
verifie('anciennes seules ⇒ renommées', $p['renommer'] === ['ceo_fb_post' => 'mar_social_post', 'ceo_nl_campagne' => 'mar_nl_campagne'] && !$p['effacer'], json_encode($p));
$p = marPlanMigration(['ceo_fb_post' => 12, 'mar_social_post' => 0]);
verifie('nouvelle vide, ancienne pleine ⇒ la vide s’efface, l’ancienne est renommée', $p['effacer'] === ['mar_social_post'] && $p['renommer'] === ['ceo_fb_post' => 'mar_social_post'], json_encode($p));
$p = marPlanMigration(['ceo_fb_post' => 12, 'mar_social_post' => 3]);
verifie('deux tables pleines ⇒ conflit, rien ne bouge', !$p['renommer'] && !$p['effacer'] && count($p['conflits']) === 1, json_encode($p));
$p = marPlanMigration(['ceo_fb_post' => 0, 'mar_social_post' => 5]);
verifie('ancienne vide, nouvelle pleine ⇒ rien', !$p['renommer'] && !$p['effacer'] && !$p['conflits'], json_encode($p));
verifie('déjà migrée ⇒ rien', marPlanMigration(['mar_social_post' => null]) === ['renommer' => [], 'effacer' => [], 'conflits' => []]);
$noms = array_keys(MAR_TABLES);
verifie('toutes les tables du portail commencent par mar_', !array_filter($noms, static fn ($n) => !str_starts_with($n, 'mar_')));
$pris = ['mar_review', 'mar_voucher', 'mar_shop', 'mar_campaign', 'mar_kit', 'mar_promotion'];
verifie('aucune ne reprend un nom du module autonome', !array_intersect($noms, $pris));
$creees = msTablesCreees(dirname(__DIR__));
$sansCreation = array_filter($noms, static fn ($n) => !isset($creees[$n]));
verifie('chaque table du registre est créée par le code (CREATE TABLE)', $sansCreation === [], json_encode(array_values($sansCreation)));
$restes = [];
foreach (array_merge(glob(dirname(__DIR__) . '/src/*.php'), [dirname(__DIR__) . '/sql/schema.sql', dirname(__DIR__) . '/sql/seed.sql']) as $f) {
    if (str_ends_with($f, 'marketing_tables.php')) { continue; }
    $t = (string) file_get_contents($f);
    foreach (array_filter(array_column(MAR_TABLES, 'ancien')) as $a) { if (preg_match('/\b' . $a . '\b/', $t)) { $restes[] = basename($f) . ':' . $a; } }
}
verifie('aucun ancien nom ne reste dans le code', $restes === [], implode(', ', $restes));

// --- 11 bis. la migration des tables des consultants et des visites sous con_ -----------------
echo "Migration con_\n";
$p = conPlanMigration(['ceo_visite' => null, 'ceo_visite_point' => null, 'ceo_tache_jour' => null]);
verifie('anciennes seules ⇒ renommées, le reste du nom intact', $p['renommer'] === ['ceo_visite' => 'con_visite', 'ceo_visite_point' => 'con_visite_point', 'ceo_tache_jour' => 'con_tache_jour'] && !$p['effacer'], json_encode($p));
$p = conPlanMigration(['ceo_visite' => 11, 'con_visite' => 0]);
verifie('con_ : nouvelle vide, ancienne pleine ⇒ la vide s’efface, l’ancienne est renommée', $p['effacer'] === ['con_visite'] && $p['renommer'] === ['ceo_visite' => 'con_visite'], json_encode($p));
$p = conPlanMigration(['ceo_visite' => 11, 'con_visite' => 2]);
verifie('con_ : deux tables pleines ⇒ conflit, rien ne bouge', !$p['renommer'] && !$p['effacer'] && count($p['conflits']) === 1, json_encode($p));
verifie('con_ : déjà migrée ⇒ rien', conPlanMigration(['con_visite' => null, 'con_msp' => null]) === ['renommer' => [], 'effacer' => [], 'conflits' => []]);
$noms = array_keys(CON_TABLES);
verifie('chaque table du registre : con_ + l’ancien nom sans ceo_', !array_filter(CON_TABLES, static fn ($d, $n) => $n !== 'con_' . substr($d['ancien'], 4) || !str_starts_with($d['ancien'], 'ceo_'), ARRAY_FILTER_USE_BOTH));
$sansCreation = array_filter($noms, static fn ($n) => !isset($creees[$n]));
verifie('chaque table con_ est créée par le code (CREATE TABLE)', $sansCreation === [], json_encode(array_values($sansCreation)));
verifie('chaque table con_ dit sa ressource d’API (chemin, clé)', !array_filter(CON_TABLES, static fn ($d) => empty($d['api']['chemin']) || empty($d['api']['cle'])));
$restes = [];
foreach (array_merge(glob(dirname(__DIR__) . '/src/*.php'), glob(dirname(__DIR__) . '/bin/*.php'), [dirname(__DIR__) . '/sql/schema.sql', dirname(__DIR__) . '/sql/seed.sql', dirname(__DIR__) . '/sql/grants.sql']) as $f) {
    if (str_ends_with($f, 'con_tables.php') || str_ends_with($f, 'tests.php')) { continue; }
    $t = (string) file_get_contents($f);
    foreach (array_column(CON_TABLES, 'ancien') as $a) { if (preg_match('/\b' . $a . '\b/', $t)) { $restes[] = basename($f) . ':' . $a; } }
}
verifie('con_ : aucun ancien nom ne reste dans le code', $restes === [], implode(', ', $restes));

// --- 12. la publication : ce que chaque réseau exige, et ce qu'on lui envoie ---------------
echo "Publication\n";
$img = static fn (int $n) => array_map(static fn ($i) => ['nom' => "v$i.jpg", 'url' => "https://cockpit.example.be/uploads/social/v$i.jpg", 'alt' => null], range(1, $n));
$post = ['message' => "L'Atelier by Halle — speculoos #latelierby", 'lien' => null, 'medias' => [], 'magasinId' => '4'];
verifie('Facebook : un texte suffit', spPreparer($post, 'facebook')['ok']);
verifie('Instagram : sans visuel ⇒ refusé', !spPreparer($post, 'instagram')['ok']);
verifie('Instagram : un visuel sans adresse ne compte pas', !spPreparer($post + ['medias' => [['nom' => 'a.jpg']]], 'instagram')['ok']);
verifie('Instagram : un visuel téléversé ⇒ prêt', spPreparer(array_merge($post, ['medias' => $img(1)]), 'instagram')['ok']);
verifie('Instagram : 11 visuels ⇒ refusé', !spPreparer(array_merge($post, ['medias' => $img(11)]), 'instagram')['ok']);
verifie('Instagram : 31 hashtags ⇒ refusé', !spPreparer(array_merge($post, ['medias' => $img(1), 'message' => str_repeat('#a ', 31)]), 'instagram')['ok']);
verifie('Instagram : un lien ⇒ averti, pas refusé', ($x = spPreparer(array_merge($post, ['medias' => $img(1), 'lien' => 'https://atelierby.be']), 'instagram'))['ok'] && $x['avertissements']);
verifie('LinkedIn : un post de magasin ⇒ refusé (page de la marque)', !spPreparer($post, 'linkedin')['ok']);
verifie('LinkedIn : un post réseau ⇒ prêt', spPreparer(array_merge($post, ['magasinId' => null]), 'linkedin')['ok']);
verifie('réseaux d’un post : inconnus écartés, Facebook par défaut', spReseauxDe(['reseaux' => 'instagram,myspace,linkedin']) === ['instagram', 'linkedin'] && spReseauxDe(['reseaux' => '']) === ['facebook']);

$envois = [];
MetaGraph::$attendre = static function (int $s): void {};
MetaGraph::$transport = static function (string $m, string $url, array $p) use (&$envois): array {
    $c = preg_replace('#^https://graph\.facebook\.com/v[\d.]+/#', '', $url);
    $envois[] = [$m, $c, $p];
    if ($m === 'POST' && $c === '444/photos') { return [200, ['id' => 'ph' . count($envois)]]; }
    if ($m === 'POST' && $c === '444/feed') { return [200, ['id' => '444_1']]; }
    if ($m === 'POST' && $c === 'ig/media') { return [200, ['id' => 'c' . count($envois)]]; }
    if ($m === 'POST' && $c === 'ig/media_publish') { return [200, ['id' => 'm1']]; }
    return [200, []];
};
$r = MetaGraph::publierPage('444', 'tok', 'Bonjour', null, ['https://x/1.jpg', 'https://x/2.jpg']);
$feed = array_values(array_filter($envois, static fn ($e) => $e[1] === '444/feed'))[0] ?? [null, null, []];
verifie('Facebook, deux photos : déposées non publiées, puis un seul post qui les réunit',
    $r['id'] === '444_1' && $envois[0][2]['published'] === 'false' && $feed[1] === '444/feed' && isset($feed[2]['attached_media[0]'], $feed[2]['attached_media[1]']), json_encode($envois));
$envois = [];
MetaGraph::publierInstagram('ig', 'tok', 'Légende', ['https://x/1.jpg', 'https://x/2.jpg']);
$car = array_values(array_filter($envois, static fn ($e) => $e[0] === 'POST' && $e[1] === 'ig/media' && ($e[2]['media_type'] ?? '') === 'CAROUSEL'));
verifie('Instagram, deux visuels : un carrousel de deux enfants, publié', count($car) === 1 && count(explode(',', $car[0][2]['children'])) === 2 && end($envois)[1] !== '' , json_encode($envois));
MetaGraph::$transport = null;

$GLOBALS['reglages'] = ['socialPublication' => ['linkedinOrganisation' => '999']];
putenv('LINKEDIN_ACCESS_TOKEN=jeton-test');
$li = [];
LinkedInApi::$transport = static function (string $m, string $url, array $h, ?string $corps) use (&$li): array {
    $li[] = [$m, $url, $h, $corps];
    if (str_contains($url, 'initializeUpload')) { return [200, [], json_encode(['value' => ['uploadUrl' => 'https://up.test/' . count($li), 'image' => 'urn:li:image:' . count($li)]])]; }
    if (str_starts_with($url, 'https://up.test/')) { return [201, [], '']; }
    if (str_ends_with($url, '/rest/posts')) { return [201, ['x-restli-id' => 'urn:li:share:1'], '']; }
    return [500, [], ''];
};
$r = LinkedInApi::publier('Texte', [['octets' => 'A', 'alt' => 'un'], ['octets' => 'B', 'alt' => null]], null);
$corps = json_decode((string) end($li)[3], true);
verifie('LinkedIn, deux visuels : déposés puis cités en multiImage, au nom de l’organisation',
    $r['id'] === 'urn:li:share:1' && count($corps['content']['multiImage']['images'] ?? []) === 2 && $corps['author'] === 'urn:li:organization:999', json_encode($corps));
verifie('LinkedIn : en-têtes de version et Rest.li sur l’API, pas sur le dépôt binaire',
    in_array('X-Restli-Protocol-Version: 2.0.0', $li[0][2], true) && !preg_grep('/^LinkedIn-Version/', $li[1][2]));
LinkedInApi::$transport = static fn () => [401, [], json_encode(['message' => 'Expired'])];
try { LinkedInApi::publier('x', [], null); $exp = false; } catch (LinkedInTokenExpire $e) { $exp = true; }
verifie('LinkedIn : 401 ⇒ jeton expiré, dit comme tel', $exp);
LinkedInApi::$transport = null; putenv('LINKEDIN_ACCESS_TOKEN'); $GLOBALS['reglages'] = [];

// --- 13. les photos types des rayons : quel rayon pour quel libellé ----------------------------
echo "Photos types des rayons\n";
$rv = static fn (string $l): ?string => ($r = rvRayonDe($l)) !== null ? $r['cle'] : null;
verifie('clé sans accent ni signe', rvCle('Pains & Boulangerie') === 'pains-boulangerie' && rvCle('Épicerie') === 'epicerie');
verifie('les neuf rayons du panel, sans photo, sans base', count(rvListe()) === 9 && rvListe()[0]['photo'] === null);
verifie('la tâche du panel trouve son rayon', $rv('Photo du comptoir - Pâtisseries') === 'patisseries' && $rv('Photo du comptoir - Pains & Boulangerie') === 'pains-boulangerie');
verifie('au pluriel près', $rv('Comptoir viennoiserie') === 'viennoiseries');
verifie('par le début du nom, à la frontière d’un mot', $rv('Comptoir pains') === 'pains-boulangerie' && $rv('Comptoir tartes et sablés') === 'tartes');
verifie('« Tartelettes » ne prend pas « Tartes »', $rv('Photo du comptoir - Tartelettes') === null);
verifie('une tâche qui n’est pas un comptoir n’a pas de rayon', rvRayonDeTache('Contrôle Qualité – Tartes') === null && rvRayonDeTache('Comptoir pains') === null);

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
