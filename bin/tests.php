#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests Brand Guard — sans base, sans réseau, sans token.
 *
 *   php bin/tests.php
 *
 * Ce qui se vérifie ici est ce qui doit rester vrai quoi qu'il arrive à
 * l'extérieur : la règle de décision du verdict, la signature des webhooks,
 * la résolution URL → page_id contre un Graph simulé, la détection d'un post
 * sauvage, la lecture d'un événement `feed`, la version de charte.
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
}
function setting(string $k, mixed $d = null): mixed { return $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return []; }

require __DIR__ . '/../src/meta_graph.php';
require __DIR__ . '/../src/brand_guard.php';

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

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
