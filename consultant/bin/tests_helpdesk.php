#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Le helpdesk des franchisés (20/11/2026) : le SMS au bureau à chaque nouvelle demande, et la date limite que le
 * consultant fixe à la réception. Sur une base SQLite en mémoire, sans réseau : le panel et SMSAPI sont des doublures.
 *
 *   php consultant/bin/tests_helpdesk.php
 *
 * Ce qui doit rester vrai : une demande envoyée du dashboard fait partir un SMS (magasin, sujet, titre, début de la
 * demande, référence ; sans lien) à chaque numéro du réglage, une seule fois par numéro, en alphabet SMS ; un
 * destinataire sans numéro ou un SMS refusé n'empêche jamais la demande ; SMS coupés, rien ne part. La date limite
 * n'accepte que 2, 5, 10, 30 ou 90 jours, se retire avec 0, fait passer « En cours » une demande « Nouvelle », et une
 * demande ouverte au-delà est en retard ; le franchisé voit la date, pas qui l'a fixée.
 */

set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- doublures : la base, les réglages, le journal, le panel, SMSAPI ------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        return self::$p;
    }
    public static function fx(string $q): string
    {
        $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
        $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
        $q = (string) preg_replace('/ON DUPLICATE KEY UPDATE (\w+) = VALUES\(\w+\)/', 'ON CONFLICT DO UPDATE SET $1 = excluded.$1', $q);
        return $q;
    }
    public static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
    public static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function body(): array { return $GLOBALS['corps'] ?? []; }
$REGLAGES = [];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['REGLAGES'][$k] ?? $d; }
function settingEcrire(string $k, mixed $v): void { $GLOBALS['REGLAGES'][$k] = json_decode((string) json_encode($v), true); }
$JOURNAL = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message): void { $GLOBALS['JOURNAL'][] = $message; }
function rapBaseUrl(): string { return 'https://cockpit.exemple/consulant_bo'; }
function viMagasins(): array { return ['4' => ['id' => '4', 'nom' => 'Atelier by Harmonie - Sombreffe', 'court' => 'Sombreffe']]; }
function vrcDeballer(mixed $d, array $cles = []): ?array
{
    if (!is_array($d) || $d === []) { return null; }
    foreach ($cles as $k) { if (isset($d[$k]) && is_array($d[$k]) && !array_is_list($d[$k])) { $d = $d[$k]; } }
    return array_is_list($d) ? null : $d;
}

/** Le panel : POST /cases rend la demande créée ; les PATCH sont notés. */
final class PanelApi
{
    public static ?string $lastError = null;
    public static ?string $jetonImpose = 'jeton-de-test';
    public static array $envois = [];
    public static array $cases = [];
    public static function configured(): bool { return true; }
    public static function liste(mixed $r): array { return is_array($r) && isset($r['data']) && is_array($r['data']) ? $r['data'] : []; }
    public static function get(string $path): mixed { return ['data' => self::$cases]; }
    public static function envoi(string $m, string $path, array $corps): array
    {
        self::$envois[] = [$m, $path, $corps];
        if ($m === 'POST' && $path === '/cases') {
            return [true, ['data' => ['id' => 501, 'ref' => 'CASE-20261120-AB12C', 'shop_id' => $corps['shop_id'], 'shop_name' => 'Atelier by Harmonie - Sombreffe',
                'requester_name' => 'Marie', 'title' => $corps['title'], 'area_key' => $corps['area_key'], 'status' => 'NEW']]];
        }
        return [true, ['ok' => true]];
    }
}

/** SMSAPI : chaque SMS noté ; un numéro listé dans $refuse est refusé. */
final class MdSms
{
    public static array $partis = [];
    public static array $refuse = [];
    public static function configured(): bool { return true; }
    public static function envoyer(string $tel, string $texte, string $from = ''): array
    {
        if (in_array($tel, self::$refuse, true)) { return [false, '(13) numéro refusé']; }
        self::$partis[] = ['a' => $tel, 'texte' => $texte];
        return [true, 'id' . count(self::$partis)];
    }
}

require __DIR__ . '/../../shared/src/sms.php';
require __DIR__ . '/../src/helpdesk.php';

function nouvelle(array $corps = []): array
{
    $GLOBALS['corps'] = $corps + ['shop' => 4, 'domaine' => 'sales', 'titre' => 'Démarchage sur Nivelles Sud',
        'description' => "En livraison chez GUEST Nivelles (sur Nivelles Sud), je souhaite développer les livraisons (et services traiteurs et boulangerie) sur ce secteur : l’hôpital, les écoles, les bureaux du zoning. Peut-on en parler à la prochaine visite ? Merci « d’avance » 🙂",
        'passage' => true, 'photos' => []];
    return wr_helpdesk_magasin();
}

echo "Le texte du SMS\n";
verifie('alphabet SMS : apostrophes et guillemets droits, accents hors alphabet retirés (ê, û, ç), émojis enlevés, é et è gardés',
    hdkGsm("L’été « fête » — crème brûlée 🙂 ça") === "L'été \"fete\" - crème brulée ca",
    hdkGsm("L’été « fête » — crème brûlée 🙂 ça"));
verifie('le résumé coupe entre deux mots, avec « ... »', hdkResume(str_repeat('mot ', 100), 30) === 'mot mot mot mot mot mot mot...', hdkResume(str_repeat('mot ', 100), 30));
verifie('une demande courte reste entière, sur une ligne', hdkResume("Une ligne\n\net  une autre") === 'Une ligne et une autre');
$t = hdkSmsTexte(['magasin' => 'Sombreffe', 'domaine' => 'Ventes', 'titre' => 'Titre', 'description' => 'Texte', 'passage' => true, 'photos' => 2, 'ref' => 'CASE-20261120-AB12C', 'demandeur' => 'Marie']);
verifie('le SMS : magasin, sujet, demandeur, titre, demande, passage, photos, référence sans CASE-',
    $t === "Demande au bureau - Sombreffe (Ventes), de Marie\nTitre\nTexte\nPassage du consultant souhaité. 2 photos. Réf. 20261120-AB12C", $t);

echo "Le réglage\n";
$R = hdkSmsReglage();
verifie('par défaut : Sam (+48725038049) et Pierre Boseret, numéro à compléter', $R['actif'] === true && $R['destinataires'] === [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '']], json_encode($R));
$GLOBALS['corps'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre', 'tel' => '12']]];
$r = wr_helpdesk_sms();
verifie('un numéro illisible est refusé, rien n’est enregistré', $r['ok'] === false && !isset($REGLAGES['helpdeskSms']), json_encode($r));
$GLOBALS['corps'] = ['actif' => true, 'destinataires' => [['nom' => ' Sam ', 'tel' => '+48 725 038 049'], ['nom' => '', 'tel' => ''], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56']]];
$r = wr_helpdesk_sms();
verifie('enregistré : lignes vides retirées, noms nettoyés', $r['ok'] === true && $REGLAGES['helpdeskSms']['destinataires'] === [['nom' => 'Sam', 'tel' => '+48 725 038 049'], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56']], json_encode($REGLAGES['helpdeskSms'] ?? null));
verifie('la lecture rend le fournisseur et le numéro lisible', $r['fournisseur'] === 'smsapi' && $r['destinataires'][1]['lisible'] === '+32470123456', json_encode($r['destinataires']));

echo "La demande et ses SMS\n";
unset($REGLAGES['helpdeskSms']);
$r = nouvelle();
verifie('la demande part au panel', $r['ok'] === true && $r['id'] === 501 && $r['ref'] === 'CASE-20261120-AB12C', json_encode($r));
verifie('réglage par défaut : un SMS, à Sam seulement (Pierre n’a pas de numéro)', count(MdSms::$partis) === 1 && MdSms::$partis[0]['a'] === '+48725038049', json_encode(MdSms::$partis));
$sms = MdSms::$partis[0]['texte'] ?? '';
verifie('le SMS dit le magasin, le sujet, qui, le titre', str_starts_with($sms, "Demande au bureau - Sombreffe (Ventes), de Marie\nDémarchage sur Nivelles Sud\n"), $sms);
verifie('le SMS reprend le début de la demande, coupé', str_contains($sms, 'En livraison chez GUEST Nivelles') && str_contains($sms, '...') && !str_contains($sms, 'Merci'), $sms);
verifie('le SMS dit le passage souhaité et la référence, et finit par elle', str_ends_with($sms, 'Passage du consultant souhaité. Réf. 20261120-AB12C'), $sms);
verifie('le SMS ne porte aucun lien', !preg_match('~https?://|/#/~', $sms), $sms);
verifie('le SMS est en alphabet SMS (aucun ’, «, émoji)', !preg_match('/[’«»🙂]/u', $sms), $sms);
verifie('le SMS tient en trois SMS', mb_strlen($sms) <= 459, (string) mb_strlen($sms));
$der = $REGLAGES['helpdeskSmsDernier'] ?? null;
verifie('le dernier envoi est gardé : Sam parti, Pierre sans numéro', is_array($der) && $der['destinataires'] === [['nom' => 'Sam', 'ok' => true, 'motif' => null], ['nom' => 'Pierre Boseret', 'ok' => false, 'motif' => 'pas de numéro']], json_encode($der));
verifie('le journal le dit', str_contains(end($JOURNAL), 'SMS à Sam') && str_contains(end($JOURNAL), 'Pierre Boseret (pas de numéro)'), end($JOURNAL));

MdSms::$partis = [];
$REGLAGES['helpdeskSms'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '0470 12 34 56'], ['nom' => 'Sam (bis)', 'tel' => '0048 725 038 049']]];
nouvelle();
verifie('deux numéros : deux SMS ; le même numéro écrit autrement ne reçoit qu’un SMS', array_column(MdSms::$partis, 'a') === ['+48725038049', '+32470123456'], json_encode(array_column(MdSms::$partis, 'a')));

MdSms::$partis = []; MdSms::$refuse = ['+32470123456'];
$r = nouvelle(['passage' => false]);
verifie('un SMS refusé n’empêche pas la demande', $r['ok'] === true && count(MdSms::$partis) === 1, json_encode($r));
verifie('le refus est gardé et journalisé', ($REGLAGES['helpdeskSmsDernier']['destinataires'][1]['ok'] ?? null) === false && str_contains(end($JOURNAL), 'SMS non parti : Pierre Boseret ((13) numéro refusé)'), end($JOURNAL));
verifie('sans passage demandé, le SMS n’en parle pas', !str_contains(MdSms::$partis[0]['texte'], 'Passage'));
MdSms::$refuse = [];

MdSms::$partis = [];
$REGLAGES['helpdeskSms']['actif'] = false;
$r = nouvelle();
verifie('SMS coupés : la demande part, aucun SMS', $r['ok'] === true && MdSms::$partis === [] && str_contains(end($JOURNAL), 'SMS au bureau coupés'), end($JOURNAL));

echo "La date limite\n";
PanelApi::$envois = [];
$GLOBALS['corps'] = ['jours' => 7];
$r = wr_helpdesk_delai(501);
verifie('un délai hors liste est refusé', $r['ok'] === false && str_contains($r['error'], '2, 5, 10, 30, 90'), json_encode($r));
$GLOBALS['corps'] = ['jours' => 5, 'statut' => 'NEW', 'shop' => '4', 'ref' => 'CASE-20261120-AB12C'];
$r = wr_helpdesk_delai(501);
$attendu = date('Y-m-d', strtotime('+5 days'));
verifie('5 jours : la date limite est dans 5 jours', $r['ok'] === true && $r['echeance'] === $attendu && $r['delai'] === 5, json_encode($r));
verifie('une demande « Nouvelle » passe « En cours » à la réception', PanelApi::$envois === [['PATCH', '/cases/501/status', ['status' => 'IN_PROGRESS']]], json_encode(PanelApi::$envois));
$e = hdkEcheances([501, 502]);
verifie('elle est gardée, avec qui l’a fixée', ($e[501]['echeance'] ?? null) === $attendu && $e[501]['delai'] === 5 && $e[501]['par'] === 'Cockpit' && !isset($e[502]), json_encode($e));
PanelApi::$envois = [];
$GLOBALS['corps'] = ['jours' => 30, 'statut' => 'IN_PROGRESS', 'shop' => '9'];
$r = wr_helpdesk_delai(777);
verifie('une demande venue d’ailleurs (sans ligne au cockpit) reçoit sa date ; une demande « En cours » ne change pas de statut',
    $r['ok'] === true && (hdkEcheances([777])[777]['delai'] ?? null) === 30 && PanelApi::$envois === [], json_encode(PanelApi::$envois));
$GLOBALS['corps'] = ['jours' => 0];
$r = wr_helpdesk_delai(777);
verifie('0 retire la date limite', $r['ok'] === true && hdkEcheances([777]) === []);

$v = hdkEcheanceVue(['echeance' => '2026-11-18', 'delai' => 2, 'par' => 'Sam', 'le' => '2026-11-16 10:00:00'], true, '2026-11-20');
verifie('ouverte au-delà de sa date : en retard de 2 jours', $v['enRetard'] === true && $v['joursRestants'] === -2 && $v['echeancePar'] === 'Sam', json_encode($v));
verifie('fermée au-delà de sa date : pas en retard', hdkEcheanceVue(['echeance' => '2026-11-18'], false, '2026-11-20')['enRetard'] === false);
verifie('le jour même : 0 jour restant, pas en retard', hdkEcheanceVue(['echeance' => '2026-11-20'], true, '2026-11-20') === ['echeance' => '2026-11-20', 'delai' => null, 'echeancePar' => null, 'echeanceLe' => null, 'joursRestants' => 0, 'enRetard' => false]);
verifie('sans date : rien', hdkEcheanceVue(null, true)['echeance'] === null && hdkEcheanceVue(null, true)['enRetard'] === false);

PanelApi::$cases = [['id' => 501, 'ref' => 'CASE-20261120-AB12C', 'area_key' => 'sales', 'status' => 'IN_PROGRESS', 'title' => 'T', 'description' => 'D', 'shop_id' => 4, 'requester_name' => 'Marie', 'created_at' => '2026-11-20 10:00:00']];
$_GET['shop'] = '4';
$m = ep_helpdesk_magasin();
verifie('le franchisé voit la date limite, ni qui l’a fixée ni le demandeur', ($m['demandes'][0]['echeance'] ?? null) === $attendu && !isset($m['demandes'][0]['echeancePar']) && !isset($m['demandes'][0]['demandeur']), json_encode($m['demandes'][0] ?? null));
$c = ep_helpdesk_demandes();
verifie('le cockpit voit la date limite, les jours restants et les délais possibles', ($c['demandes'][0]['echeance'] ?? null) === $attendu && $c['demandes'][0]['joursRestants'] === 5 && $c['demandes'][0]['enRetard'] === false && $c['delais'] === [2, 5, 10, 30, 90], json_encode($c['demandes'][0] ?? null));

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko > 0 ? 1 : 0);
