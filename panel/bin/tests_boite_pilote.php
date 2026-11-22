#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Le pilote automatique de la boîte mail (src/boite_ia.php), de bout en bout : une vraie base, une vraie boîte IMAP, un
 * faux SMTP qui garde ce qu'on lui envoie, un faux modèle. Il vérifie les garde-fous : rien avant 10 minutes, annulable,
 * rien si le magasin a répondu, une réponse par expéditeur et par jour, jamais aux listes ni à l'arriéré, l'arrêt annule.
 *
 *   COCKPIT_DB_NAME=bmtest COCKPIT_DB_USER=… COCKPIT_DB_PASSWORD=… BM_TEST_IMAP=127.0.0.1:1143:aucune
 *   BM_TEST_SMTP=127.0.0.1:2525:aucune BM_TEST_SMTP_DOSSIER=/chemin/des/eml BM_TEST_USER=… BM_TEST_PASS=… php bin/tests_boite_pilote.php
 *
 * Jamais contre une vraie base ni une vraie boîte : il VIDE ses trois tables (panel_boite_lecture, _pilote, _pilote_etat)
 * et dépose des e-mails. Il ne tourne que sur une base dont le nom contient « test » et des serveurs sur la machine.
 */

$db = (string) getenv('COCKPIT_DB_NAME'); $imap = (string) getenv('BM_TEST_IMAP'); $smtp = (string) getenv('BM_TEST_SMTP'); $puits = (string) getenv('BM_TEST_SMTP_DOSSIER');
if (!str_contains($db, 'test') || !preg_match('/^(127\.0\.0\.1|localhost):/', $imap) || !preg_match('/^(127\.0\.0\.1|localhost):/', $smtp) || !is_dir($puits)) {
    echo "non lancé : il faut une base de test (COCKPIT_DB_NAME contenant « test »), BM_TEST_IMAP et BM_TEST_SMTP sur la machine, BM_TEST_SMTP_DOSSIER\n";
    exit(0);
}
$R = dirname(__DIR__, 2);
require $R . '/shared/src/Db.php';
if (!function_exists('setting')) { function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$k]); return $r ? json_decode((string) $r['value'], true) : $d; } }
if (!function_exists('erpCle')) { function erpCle(string $k): string { return $k === 'anthropic_api_key' ? 'cle-de-test' : ''; } }
$JOURNAL = [];
if (!function_exists('journalAdd')) { function journalAdd(string $a, string $k, ?string $p, string $m, ?string $ts = null): void { global $JOURNAL; $JOURNAL[] = $m; } }
require $R . '/panel/src/boite_mail.php';
require $R . '/shared/src/anthropic.php';

$ok = 0; $ko = 0;
function verifie(string $n, bool $c, string $d = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok  $n\n"; } else { $ko++; echo "  KO  $n" . ($d !== '' ? " — $d" : '') . "\n"; } }

// Le faux modèle : il lit l'objet de chaque e-mail et rend une lecture plausible.
$APPELS = 0;
Anthropic::$transport = static function (array $corps) use (&$APPELS): array {
    $APPELS++;
    $t = $corps['messages'][0]['content'][0]['text'];
    preg_match_all('/\[(E\d+)\] De : .*? · Objet : (.*?) ·/u', $t, $m, PREG_SET_ORDER);
    $L = [];
    foreach ($m as [, $rep, $objet]) {
        $b = ['repere' => $rep, 'resume' => 'résumé de ' . $objet, 'categorie' => 'question', 'ton' => 'neutre', 'urgence' => 'normale', 'action' => 'repondre',
            'reponse' => "Bonjour,\n\nMerci pour votre message.\n\nL'équipe", 'dossier' => '', 'simple' => false, 'confiance' => 90, 'garde' => '', 'jour' => '', 'produits' => [], 'personnes' => 0, 'b2b' => false];
        if (str_contains($objet, 'Candidature')) { $b = ['categorie' => 'candidature', 'simple' => true, 'confiance' => 95] + $b; }
        if (str_contains($objet, 'Allergènes')) { $b = ['garde' => 'allergenes', 'reponse' => "Bonjour,\n\n[à compléter : allergènes]\n\nL'équipe"] + $b; }
        if (str_contains($objet, 'Prix')) { $b = ['garde' => 'prix', 'categorie' => 'commande', 'action' => 'offre', 'jour' => '2026-12-06', 'produits' => [['nom' => 'spéculoos', 'quantite' => 80]]] + $b; }
        if (str_contains($objet, 'Merci')) { $b = ['simple' => true, 'confiance' => 70] + $b; }   // simple mais pas assez sûr
        if (str_contains($objet, 'Newsletter')) { $b = ['categorie' => 'newsletter', 'action' => 'ranger', 'dossier' => 'Newsletters', 'reponse' => ''] + $b; }
        $L[] = $b;
    }
    return [200, ['stop_reason' => 'end_turn', 'model' => 'faux', 'content' => [['type' => 'text', 'text' => json_encode(['lectures' => $L], JSON_UNESCAPED_UNICODE)]]]];
};

$u = (string) getenv('BM_TEST_USER');
$c = ['adresse' => $u, 'utilisateur' => $u, 'motDePasse' => (string) getenv('BM_TEST_PASS'), 'nom' => 'L’Atelier By Test', 'imap' => bmServeur($imap, ''), 'smtp' => bmServeur($smtp, '')];
Db::exec('CREATE TABLE IF NOT EXISTS ceo_app_setting (`key` VARCHAR(190) PRIMARY KEY, value MEDIUMTEXT)');
$shop = '99';
foreach (['panel_boite_lecture', 'panel_boite_pilote', 'panel_boite_pilote_etat'] as $t) { Db::exec('DROP TABLE IF EXISTS ' . $t); }
bmTables();
$s = bmImap($c);
$deposer = static function (string $de, string $objet, string $plus = '') use ($s): void {
    $s->deposer('INBOX', "From: " . $de . "\r\nSubject: " . $objet . "\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@exemple.be>\r\n" . $plus . "Content-Type: text/plain; charset=utf-8\r\n\r\nBonjour, " . $objet . ".\r\n", '');
};
$deposer('Ancien <ancien@exemple.be>', 'Candidature arrivée AVANT l’allumage');

echo "Allumer\n";
$r = bmPiloteEcrire($shop, $c, ['niveau' => 2]);
verifie('cran « Simples »', ($r['niveau'] ?? null) === 2, json_encode($r));
$r = bmPiloteTourner($shop, $c);
verifie('premier passage : il part de maintenant (l’arriéré n’est pas lu)', isset($r['départ']) && $APPELS === 0, json_encode($r));

echo "Quatre e-mails arrivent\n";
$deposer('Jonas Peeters <jonas@exemple.be>', 'Candidature vendeur');
$deposer('Sophie Lambert <sophie@exemple.be>', 'Allergènes du cramique ?');
$deposer('Chiro Halle <chiro@exemple.be>', 'Prix pour 80 spéculoos');
$deposer('Puratos <promo@puratos.example>', 'Newsletter de décembre', "List-Unsubscribe: <mailto:x@puratos.example>\r\n");
$deposer('Lotte <lotte@exemple.be>', 'Merci pour samedi');
$r = bmPiloteTourner($shop, $c);
verifie('un appel au modèle pour le lot', $APPELS === 1, (string) $APPELS);
verifie('1 envoi planifié (la candidature) ; 3 brouillons laissés au magasin (allergènes, prix, « merci » pas assez sûr)', $r['planifies'] === 1 && $r['brouillons'] === 3 && $r['envoyes'] === 0, json_encode($r));
$merci = Db::row("SELECT etat, raison FROM panel_boite_pilote WHERE de_adresse = 'lotte@exemple.be'");
verifie('simple mais sûr à 70 % : pas d’envoi, la raison est dite', ($merci['etat'] ?? '') === 'brouillon' && str_contains((string) $merci['raison'], '70 %'), json_encode($merci, JSON_UNESCAPED_UNICODE));
$P = Db::rows('SELECT de_adresse, etat, raison FROM panel_boite_pilote WHERE shop_id = ? ORDER BY id', [$shop]);
$etats = array_column($P, 'etat', 'de_adresse');
verifie('la newsletter (liste) : jamais de ligne', !isset($etats['promo@puratos.example']), json_encode($P, JSON_UNESCAPED_UNICODE));
verifie('allergènes : brouillon, raison dite', ($etats['sophie@exemple.be'] ?? '') === 'brouillon' && str_contains(json_encode($P, JSON_UNESCAPED_UNICODE), 'allerg'), json_encode($P, JSON_UNESCAPED_UNICODE));
$s->ouvrirDossier(bmDossierRole($s, 'drafts'), true);
verifie('deux brouillons dans « Brouillons » (créé s’il manquait)', count($s->chercher(['DRAFT'])) >= 2);
$s->ouvrirDossier('INBOX');
$r = bmPiloteTourner($shop, $c);
verifie('un passage de plus, rien de nouveau : rien n’est relu ni envoyé (les 10 minutes)', $APPELS === 1 && $r['envoyes'] === 0, json_encode($r));

echo "L'envoi, 10 minutes après\n";
$avant = count(glob($puits . '/*.eml'));
Db::exec("UPDATE panel_boite_pilote SET envoi_le = NOW() - INTERVAL 1 MINUTE WHERE shop_id = ? AND etat = 'planifie'", [$shop]);
$r = bmPiloteTourner($shop, $c);
$e = glob($puits . '/*.eml'); sort($e);
verifie('envoyé : un e-mail part, à Jonas', $r['envoyes'] === 1 && count($e) === $avant + 1 && str_contains((string) file_get_contents(end($e)), 'jonas@exemple.be'), json_encode($r));
verifie('en réponse (In-Reply-To)', str_contains((string) file_get_contents(end($e)), 'In-Reply-To:'));
$jonas = (int) Db::row("SELECT uid FROM panel_boite_pilote WHERE de_adresse = 'jonas@exemple.be'")['uid'];
$s->ouvrirDossier('INBOX', true); $s->ouvrirDossier('INBOX');
verifie('l’original marqué « répondu »', in_array('\\Answered', (array) ($s->lire([$jonas], 'UID FLAGS')[$jonas]['FLAGS'] ?? []), true));
$r = bmPiloteTourner($shop, $c);
verifie('jamais deux fois', $r['envoyes'] === 0 && count(glob($puits . '/*.eml')) === $avant + 1);

echo "Annuler, répondre avant le pilote, une seule réponse par jour\n";
$deposer('Jonas Peeters <jonas@exemple.be>', 'Candidature : mon CV corrigé');
$deposer('An Mertens <an@exemple.be>', 'Candidature pâtissière');
$deposer('Bart <bart@exemple.be>', 'Candidature livreur');
bmPiloteTourner($shop, $c);
$x = array_column(Db::rows('SELECT id, de_adresse, etat, raison FROM panel_boite_pilote WHERE shop_id = ? AND cree_le > NOW() - INTERVAL 1 HOUR', [$shop]), null, 'de_adresse');
$jo2 = Db::row("SELECT etat, raison FROM panel_boite_pilote WHERE de_adresse = 'jonas@exemple.be' ORDER BY id DESC LIMIT 1");
verifie('Jonas une 2e fois le même jour : pas d’envoi seul', ($jo2['etat'] ?? '') !== 'planifie' && str_contains((string) $jo2['raison'], 'aujourd'), json_encode($jo2, JSON_UNESCAPED_UNICODE));
$an = (int) $x['an@exemple.be']['id'];
$r = bmPiloteEcrire($shop, $c, ['action' => 'annuler', 'id' => $an]);
verifie('annuler un envoi planifié', ($r['ok'] ?? false) && Db::row('SELECT etat FROM panel_boite_pilote WHERE id = ?', [$an])['etat'] === 'annule');
$r = @bmPiloteEcrire($shop, $c, ['action' => 'annuler', 'id' => $an]);
verifie('annuler deux fois : « trop tard »', isset($r['error']));
$bartUid = (int) Db::row("SELECT uid FROM panel_boite_pilote WHERE de_adresse = 'bart@exemple.be'")['uid'];
$s->ouvrirDossier('INBOX'); $s->drapeaux([$bartUid], '+', '\\Answered');   // le magasin a répondu de sa messagerie
Db::exec("UPDATE panel_boite_pilote SET envoi_le = NOW() - INTERVAL 1 MINUTE WHERE shop_id = ? AND etat = 'planifie'", [$shop]);
$n0 = count(glob($puits . '/*.eml'));
$r = bmPiloteTourner($shop, $c);
verifie('le magasin a répondu avant : rien ne part', $r['envoyes'] === 0 && count(glob($puits . '/*.eml')) === $n0 && Db::row("SELECT raison FROM panel_boite_pilote WHERE de_adresse = 'bart@exemple.be'")['raison'] === 'le magasin a déjà répondu', json_encode($r));

echo "La lecture (briefing, radar) et l'écran du pilote\n";
$_GET = ['quoi' => 'nonlus'];
$l = bmLecturesRoute($shop, $c);
verifie('lectures : les non-lus, avec la lecture gardée (aucun nouvel appel)', count($l['elements']) >= 5 && $APPELS >= 1 && count(array_filter($l['elements'], static fn ($e) => $e['lecture'] !== null)) >= 5, json_encode(['n' => count($l['elements']), 'aLire' => $l['aLire']]));
$avantAppels = $APPELS;
$r = bmLireRoute($shop, $c, ['uids' => $l['aLire'] ?: [1]]);
verifie('lire : l’e-mail d’avant l’allumage se lit à la demande', isset($r['lectures']) && ($l['aLire'] === [] || $APPELS === $avantAppels + 1), json_encode($l['aLire']));
$prix = array_values(array_filter($l['elements'], static fn ($e) => str_contains($e['objet'], 'Prix')))[0]['lecture'] ?? [];
verifie('la demande repérée (jour, produits) pour le radar', ($prix['jour'] ?? '') === '2026-12-06' && ($prix['produits'][0]['quantite'] ?? 0) === 80, json_encode($prix, JSON_UNESCAPED_UNICODE));
$v = bmPiloteLire($shop);
verifie('écran : le fil (envoyé, annulés) et ce qui attend (brouillons, pas assez sûr)', count($v['fil']) >= 3 && count($v['attente']) >= 3 && $v['stats']['envoyes'] === 1 && $v['stats']['brouillons'] >= 2, json_encode($v['stats']));
$id = (int) $v['attente'][0]['id'];
$v = bmPiloteEcrire($shop, $c, ['action' => 'vu', 'id' => $id]);
verifie('« C’est traité » retire de ce qui attend', !in_array($id, array_column($v['attente'], 'id'), true));

echo "Éteindre\n";
$deposer('Eva <eva@exemple.be>', 'Candidature caissière');
bmPiloteTourner($shop, $c);
$v = bmPiloteEcrire($shop, $c, ['niveau' => 0]);
verifie('éteint : les envois prévus sont annulés', $v['niveau'] === 0 && Db::row("SELECT etat FROM panel_boite_pilote WHERE de_adresse = 'eva@exemple.be'")['etat'] === 'annule');
$r = bmPiloteTourner($shop, $c);
verifie('éteint : il ne tourne plus', isset($r['éteint']));
$v = bmPiloteEcrire($shop, $c, ['niveau' => 1]);
$deposer('Arriéré <arriere@exemple.be>', 'Candidature pendant l’arrêt');
$r = bmPiloteTourner($shop, $c);
verifie('rallumé : il repart de maintenant (rien de l’arrêt)', isset($r['départ']), json_encode($r));
verifie('le journal dit les changements de cran', count(array_filter($JOURNAL, static fn ($m) => str_contains($m, 'Pilote automatique'))) >= 3, json_encode($JOURNAL, JSON_UNESCAPED_UNICODE));
echo "\n$ok ok, $ko en échec\n";
exit($ko ? 1 : 0);
