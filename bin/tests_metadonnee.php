#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests MétaDonnée — sans base, sans réseau, sans jeton.
 *
 *   php bin/tests_metadonnee.php
 *
 * Ce qui se vérifie ici doit rester vrai quoi qu'il arrive chez Meta, à l'ERP
 * ou chez SMSAPI : la lecture des réponses d'un lead, les règles du mapping
 * (nom, téléphone, tranche, date, code, consentement), le magasin par code
 * postal, la lecture des statuts de l'ERP, le webhook `leadgen`, le retard, le
 * plan des SMS et des escalades, le texte du SMS, l'appel SMSAPI ; et le flux
 * Meta recopié du CRM : « Connecter et tester » (qui ne détourne jamais le
 * webhook du CRM), la lecture d'un lead et ses replis, les coordonnées.
 */

// --- doublures : pas de base, pas de réglage, pas de journal ---------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int
    {
        // Un réglage écrit se relit (mdSettingSet) : le bilan de la connexion, le jeton échangé.
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['REGLAGES'][$a[0]] = json_decode((string) $a[1], true); }
        return 0;
    }
    public static function config(): array { return []; }
}
$REGLAGES = [];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return []; }

require __DIR__ . '/../src/metadonnee.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- 1. les règles d'une réponse -----------------------------------------------------
echo "Règles\n";
verifie('0476 12 34 56 → +32476123456', mdTelE164('0476 12 34 56') === '+32476123456');
verifie('+32 (0)476 12 34 56 → +32476123456', mdTelE164('+32 (0)476 12 34 56') === '+32476123456');
verifie('0032 476… → +32476…', mdTelE164('0032 476 123 456') === '+32476123456');
verifie('fixe 071 12 34 56 → +3271123456', mdTelE164('071/12.34.56') === '+3271123456');
verifie('numéro français gardé', mdTelE164('+33 6 12 34 56 78') === '+33612345678');
verifie('téléphone illisible → null', mdTelE164('pas de gsm') === null && mdTelE164('12') === null);
verifie('+32476123456 lisible → 0476 12 34 56', mdTelLisible('+32476123456') === '0476 12 34 56');
verifie('e-mail réparé (majuscules, espaces, mailto)', mdEmailPropre(' Mailto:C.Mertens@Fiduciaire-Delvaux.BE. ') === 'c.mertens@fiduciaire-delvaux.be');
verifie('e-mail dans une phrase', mdEmailPropre('écrivez à jean@exemple.be merci') === 'jean@exemple.be');
verifie('e-mail illisible → null', mdEmailPropre('jean arobase exemple') === null);
verifie('« 35 » → 35', mdNombre('35') === 35);
verifie('« 26 à 50 » → 38', mdNombre('26 à 50') === 38);
verifie('« 11-25 personnes » → 18', mdNombre('11-25 personnes') === 18);
verifie('« plus de 50 » → 50', mdNombre('plus de 50') === 50);
$ref = (int) strtotime('2026-10-28 10:00:00');
verifie('« Dès le 4 novembre » → 2026-11-04', mdDate('Dès le 4 novembre', $ref) === '2026-11-04');
verifie('« 12/01/2027 » → 2027-01-12', mdDate('12/01/2027', $ref) === '2027-01-12');
verifie('« 3 mars » passé → l’année suivante', mdDate('3 mars', $ref) === '2027-03-03');
verifie('date illisible → null', mdDate('au plus vite', $ref) === null);
verifie('nom coupé en deux', mdCouperNom('  Claire   Van Mertens ') === ['Claire', 'Van Mertens']);
verifie('consentement « Oui » / « J’accepte »', mdOui('Oui') && mdOui('J’accepte les conditions') && !mdOui('non'));

// --- 2. questions et mapping suggéré ----------------------------------------------------
echo "Questions et mapping\n";
$qs = mdQuestions([
    ['key' => 'full_name', 'type' => 'FULL_NAME'],
    ['key' => 'email', 'type' => 'EMAIL', 'label' => ''],
    ['key' => 'phone_number', 'type' => 'PHONE'],
    ['key' => 'company_name', 'type' => 'COMPANY_NAME'],
    ['key' => 'zip', 'type' => 'ZIP'],
    ['key' => 'combien_de_personnes', 'type' => 'CUSTOM', 'label' => 'Combien de personnes au bureau ?'],
    ['key' => 'que_souhaitez_vous', 'type' => 'CUSTOM', 'label' => 'Que souhaitez-vous ?', 'options' => [['key' => 'a', 'value' => 'Un lunch tous les jours'], ['key' => 'b', 'value' => 'Des plateaux pour nos réunions']]],
    ['key' => 'pour_quand', 'type' => 'CUSTOM', 'label' => 'Pour quand ?'],
    ['key' => 'un_mot', 'type' => 'CUSTOM', 'label' => 'Un mot pour nous ?'],
    ['key' => 'couleur', 'type' => 'CUSTOM', 'label' => 'Votre couleur préférée'],
]);
verifie('libellé standard posé quand Meta n’en donne pas', $qs[1]['label'] === 'E-mail' && $qs[0]['label'] === 'Nom complet');
verifie('options lues', $qs[6]['options'] === ['Un lunch tous les jours', 'Des plateaux pour nos réunions']);
verifie('case de consentement ajoutée', end($qs)['key'] === 'consentement');
$sug = mdMappingSuggere($qs);
verifie('suggestion : nom, e-mail, téléphone, entreprise, CP', ($sug['full_name'] ?? '') === 'contact.full_name' && ($sug['email'] ?? '') === 'contact.email'
    && ($sug['phone_number'] ?? '') === 'contact.phone' && ($sug['company_name'] ?? '') === 'company.name' && ($sug['zip'] ?? '') === 'company.zip', json_encode($sug));
verifie('suggestion : personnes, besoin, date, note, consentement', ($sug['combien_de_personnes'] ?? '') === 'lead.headcount' && ($sug['que_souhaitez_vous'] ?? '') === 'lead.need'
    && ($sug['pour_quand'] ?? '') === 'lead.wanted_date' && ($sug['un_mot'] ?? '') === 'lead.note' && ($sug['consentement'] ?? '') === 'lead.consent', json_encode($sug));
verifie('question inconnue sans suggestion (→ la note)', !isset($sug['couleur']));
verifie('l’empreinte suit les questions, pas leur ordre', mdEmpreinte($qs) === mdEmpreinte(array_reverse($qs)) && mdEmpreinte($qs) !== mdEmpreinte(array_slice($qs, 1)));

// --- 3. un lead, de Meta à l'ERP --------------------------------------------------------
echo "Lead → ERP\n";
$raw = ['id' => '1234567890', 'created_time' => '2026-10-28T08:24:00+0000', 'platform' => 'ig', 'field_data' => [
    ['name' => 'full_name', 'values' => ['Claire Mertens']],
    ['name' => 'email', 'values' => ['C.Mertens@fiduciaire-delvaux.be ']],
    ['name' => 'phone_number', 'values' => ['+32476123456']],
    ['name' => 'company_name', 'values' => ['Fiduciaire Delvaux & associés']],
    ['name' => 'zip', 'values' => ['6041']],
    ['name' => 'combien_de_personnes', 'values' => ['35']],
    ['name' => 'que_souhaitez_vous', 'values' => ['Un lunch tous les jours']],
    ['name' => 'pour_quand', 'values' => ['Dès le 4 novembre']],
    ['name' => 'un_mot', 'values' => ['Nous avons 3 végétariens']],
    ['name' => 'couleur', 'values' => ['bleu']],
], 'custom_disclaimer_responses' => [['checkbox_key' => 'rgpd', 'is_checked' => '1']]];
$reps = mdReponses($raw, $qs);
verifie('réponses libellées par les questions', $reps[5]['label'] === 'Combien de personnes au bureau ?' && $reps[5]['valeur'] === '35');
verifie('consentement lu à part (coché → oui)', end($reps)['key'] === 'consentement' && end($reps)['valeur'] === 'oui');
$valeurs = ['que_souhaitez_vous' => ['Un lunch tous les jours' => 'daily_lunch']];
$t = mdTransformer($reps, $sug, $valeurs, $ref);
$p = $t['payload'];
verifie('contact : prénom, nom, e-mail réparé, téléphone', ($p['contact']['first_name'] ?? '') === 'Claire' && ($p['contact']['last_name'] ?? '') === 'Mertens'
    && ($p['contact']['email'] ?? '') === 'c.mertens@fiduciaire-delvaux.be' && ($p['contact']['phone'] ?? '') === '+32476123456', json_encode($p));
verifie('entreprise et code postal', ($p['company']['name'] ?? '') === 'Fiduciaire Delvaux & associés' && ($p['company']['zip'] ?? '') === '6041');
verifie('personnes, besoin codé, date', ($p['lead']['headcount'] ?? null) === 35 && ($p['lead']['need'] ?? '') === 'daily_lunch' && ($p['lead']['wanted_date'] ?? '') === '2026-11-04', json_encode($p['lead']));
verifie('note : le mot d’abord, puis la question sans champ', ($p['lead']['note'] ?? '') === "Nous avons 3 végétariens\nVotre couleur préférée : bleu", json_encode($p['lead']['note'] ?? null));
verifie('rien ne manque, consentement donné', $t['manques'] === [] && $t['consentement'] === true);
$t2 = mdTransformer($reps, ['que_souhaitez_vous' => 'lead.need'] + $sug, [], $ref);
verifie('réponse sans code → « other » + la réponse en note', ($t2['payload']['lead']['need'] ?? '') === 'other' && str_contains((string) ($t2['payload']['lead']['note'] ?? ''), 'Un lunch tous les jours'));
$t3 = mdTransformer($reps, ['couleur' => 'ignorer'] + $sug, $valeurs, $ref);
verifie('question « ignorer » : ne part pas', !str_contains((string) ($t3['payload']['lead']['note'] ?? ''), 'bleu'));
$sansTel = array_values(array_filter($reps, static fn ($r) => $r['key'] !== 'phone_number'));
verifie('téléphone absent → manque', mdTransformer($sansTel, $sug, $valeurs, $ref)['manques'] === ['contact.phone']);
$telFaux = array_map(static fn ($r) => $r['key'] === 'phone_number' ? ['valeur' => 'appelez-moi'] + $r : $r, $reps);
$tf = mdTransformer($telFaux, $sug, $valeurs, $ref);
verifie('téléphone illisible → manque + avertissement + note', $tf['manques'] === ['contact.phone'] && $tf['avertissements'] !== [] && str_contains((string) $tf['payload']['lead']['note'], 'appelez-moi'));
$refus = array_map(static fn ($r) => $r['key'] === 'consentement' ? ['valeur' => 'non'] + $r : $r, $reps);
verifie('case non cochée → consentement false', mdTransformer($refus, $sug, $valeurs, $ref)['consentement'] === false);
$l = ['meta_lead_id' => '1234567890', 'plateforme' => 'ig', 'campagne' => 'Lunch Charleroi', 'annonce' => null, 'recu_le' => '2026-10-28 10:24:00'];
$f = ['form_id' => '777', 'nom' => 'Lunch au bureau — Gosselies', 'page_nom' => 'L’Atelier by Gosselies'];
$payload = mdPayload($l, $f, $p, ['id' => '12', 'erpId' => 12, 'nom' => 'Gosselies']);
verifie('payload : numéro Meta, magasin ERP, source', $payload['meta_lead_id'] === '1234567890' && $payload['shop_id'] === 12
    && $payload['source']['platform'] === 'ig' && $payload['source']['form'] === 'Lunch au bureau — Gosselies');
verifie('groupe vide → objet JSON', json_encode(mdPayload($l, $f, ['company' => [], 'contact' => [], 'lead' => []], ['id' => '1']))
    !== false && str_contains((string) json_encode(mdPayload($l, $f, ['company' => [], 'contact' => [], 'lead' => []], ['id' => '1'])), '"company":{}'));

// --- 4. le magasin -------------------------------------------------------------------------
echo "Magasin\n";
$plages = mdCpNettoyer([['de' => 1499, 'a' => 1300, 'shopId' => 'cor'], ['de' => 6000, 'a' => 6599, 'shopId' => 'gos'], ['de' => 1360, 'a' => 1360, 'shopId' => ''], ['de' => 'x', 'a' => 2]]);
verifie('plages remises dans l’ordre, bornes inversées corrigées, ligne illisible écartée', count($plages) === 3 && $plages[0]['de'] === 1300 && $plages[0]['a'] === 1499);
verifie('1435 → Corbais', mdMagasinParCp('1435', $plages) === 'cor');
verifie('6041 → Gosselies', mdMagasinParCp('6041', $plages) === 'gos');
verifie('5000 → aucune plage (null)', mdMagasinParCp('5000', $plages) === null);
$reg = mdReglagesDefaut();
$reg['cp'] = [['de' => 6000, 'a' => 6599, 'shopId' => 'gos', 'libelle' => '']];
$fs = ['suivi' => 1, 'mapping_le' => '2026-10-21 10:00:00', 'affectation' => 'magasin', 'shop_id' => 'gos'];
verifie('formulaire non suivi → attente', mdEvaluer([], ['suivi' => 0] + $fs, $reg)[0] === 'attente');
verifie('mapping pas enregistré → attente', mdEvaluer([], ['mapping_le' => null] + $fs, $reg)[0] === 'attente');
verifie('formulaire d’un magasin → à envoyer', mdEvaluer([], $fs, $reg) === ['a_envoyer', '', 'gos', 'formulaire']);
verifie('par code postal, 5000 → à attribuer', mdEvaluer(['cp' => '5000'], ['affectation' => 'cp'] + $fs, $reg)[0] === 'a_attribuer');
verifie('par code postal, 6041 → Gosselies', mdEvaluer(['cp' => '6041'], ['affectation' => 'cp'] + $fs, $reg)[2] === 'gos');
verifie('magasin posé à la main l’emporte', mdEvaluer(['shop_par' => 'main', 'shop_id' => 'hal', 'cp' => '6041'], ['affectation' => 'cp'] + $fs, $reg)[2] === 'hal');
$mags = ['gos' => ['id' => 'gos', 'nom' => 'Gosselies'], 'hal' => ['id' => 'hal', 'nom' => 'Halle'], 'cha' => ['id' => 'cha', 'nom' => 'Bruxelles — Châtelain']];
verifie('page « L’Atelier by Gosselies » → Gosselies', mdSuggererMagasin('L’Atelier by Gosselies', $mags) === 'gos');
verifie('« Châtelain » reconnu dans « Bruxelles — Châtelain »', mdSuggererMagasin('Lunch Châtelain', $mags) === 'cha');
verifie('deux magasins nommés → rien', mdSuggererMagasin('Halle et Gosselies', $mags) === null);

// --- 5. l'ERP : réponses et statuts ------------------------------------------------------------
echo "ERP\n";
verifie('id et statut à la racine', mdErpReponse(['id' => 'L-20488', 'status' => 'new']) === ['L-20488', 'new']);
verifie('id et statut sous data', mdErpReponse(['data' => ['lead_id' => 20488, 'statut' => 'nouveau']]) === ['20488', 'nouveau']);
verifie('liste sous data / items / à la racine', count(mdListe(['data' => [['id' => 1], ['id' => 2]]])) === 2 && count(mdListe(['items' => [['id' => 1]]])) === 1 && count(mdListe([['id' => 1]])) === 1);
foreach (['new' => 'nouveau', 'Seen' => 'vu', 'contacted' => 'contacte', 'Devis envoyé' => 'contacte', 'office_created' => 'bureau_cree',
    'office-connected' => 'bureau_connecte', 'WON' => 'bureau_connecte', 'perdu' => 'perdu', 'cancelled' => 'perdu'] as $brut => $attendu) {
    verifie('statut « ' . $brut . ' » → ' . $attendu, mdStatutErp($brut) === $attendu, (string) mdStatutErp($brut));
}
verifie('statut inconnu → null', mdStatutErp('???') === null);

// --- 6. le webhook ----------------------------------------------------------------------------------
echo "Webhook leadgen\n";
$wh = ['object' => 'page', 'entry' => [['id' => '111', 'changes' => [
    ['field' => 'leadgen', 'value' => ['leadgen_id' => '999', 'form_id' => '777', 'page_id' => '111', 'created_time' => 1761640000]],
    ['field' => 'feed', 'value' => ['item' => 'post', 'verb' => 'add', 'post_id' => '111_1']],
]]]];
verifie('un lead lu, le post ignoré', mdLeadsDuWebhook($wh) === [['lead' => '999', 'form' => '777', 'page' => '111']]);
verifie('autre objet → rien', mdLeadsDuWebhook(['object' => 'user'] + $wh) === []);
$corps = (string) json_encode($wh);
verifie('signature du webhook : la bonne passe', meta_signature_ok($corps, 'sha256=' . hash_hmac('sha256', $corps, 'secret-app'), 'secret-app'));
verifie('signature du webhook : une autre clé refusée', !meta_signature_ok($corps, 'sha256=' . hash_hmac('sha256', $corps, 'autre'), 'secret-app'));
verifie('signature du webhook : sans clé, tout est refusé', !meta_signature_ok($corps, 'sha256=' . hash_hmac('sha256', $corps, ''), ''));

// --- 6b. le flux Meta du CRM, recopié ---------------------------------------------------------------
echo "Meta : Connecter et tester, lecture d'un lead\n";
/** Un faux Graph : chaque appel est noté ; `$rappel` = l'adresse de rappel de l'application chez Meta. */
$appels = [];
$fauxGraph = static function (string $rappel) use (&$appels): callable {
    return static function (string $m, string $p, array $q, ?string $t) use (&$appels, $rappel): array {
        $appels[] = "$m $p";
        $ok = static fn (array $d): array => ['ok' => true, 'code' => 200, 'data' => $d, 'error' => null];
        if ($p === 'debug_token') {
            return $ok(['data' => $q['input_token'] === 'tok-systeme'
                ? ['is_valid' => true, 'app_id' => '42', 'type' => 'SYSTEM_USER', 'expires_at' => 0, 'scopes' => ['leads_retrieval', 'pages_manage_metadata', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'ads_management']]
                : ['is_valid' => true, 'app_id' => '42', 'type' => 'PAGE', 'expires_at' => 0, 'profile_id' => '501']]);
        }
        if ($p === '501' && str_contains((string) ($q['fields'] ?? ''), 'access_token')) { return $ok(['id' => '501', 'name' => "L'Atelier by", 'access_token' => 'tok-page']); }
        if ($p === '501') { return $ok(['id' => '501', 'name' => "L'Atelier by"]); }
        if ($p === '501/leadgen_forms') { return $ok(['data' => []]); }
        if ($p === '501/subscribed_apps') { return $m === 'POST' ? $ok(['success' => true]) : $ok(['data' => [['id' => '42', 'name' => 'Atelier', 'subscribed_fields' => ['leadgen']]]]); }
        if ($p === '42/subscriptions' && $m === 'GET') { return $ok(['data' => $rappel !== '' ? [['object' => 'page', 'callback_url' => $rappel, 'fields' => [['name' => 'leadgen']]]] : []]); }
        if ($p === '42/subscriptions' && $m === 'POST') { return $ok(['success' => true]); }
        return ['ok' => false, 'code' => 400, 'data' => [], 'error' => 'Meta (100) : inconnu ' . $p];
    };
};
$regler = static function (string $rappel) use (&$REGLAGES): void {
    $REGLAGES = ['mdMeta' => ['appId' => '42', 'pageId' => '501', 'verify' => 'verif', 'rappel' => $rappel], 'mdMetaSecret' => 'secret-app', 'mdMetaJeton' => 'tok-systeme'];
};

$regler('https://cockpit.atelierby.be/api/cockpit/metadonnee/meta/leads');
$appels = [];
MdMeta::$transport = $fauxGraph('https://franchise.atelierby.be/api/meta/leads');
$r = mdMetaConnecter();
$rappelEtape = array_values(array_filter($r['etapes'], static fn ($e) => $e['titre'] === 'Adresse de rappel'))[0] ?? [];
verifie('webhook déjà au CRM : jamais détourné (aucun POST sur l’application)', !in_array('POST 42/subscriptions', $appels, true), implode(', ', $appels));
verifie('… et le Cockpit passe en relève', $r['ok'] && mdMetaMode() === 'releve' && str_contains((string) ($rappelEtape['detail'] ?? ''), 'franchise.atelierby.be'), json_encode($r['etapes'], JSON_UNESCAPED_UNICODE));
verifie('jeton d’utilisateur système échangé contre celui de la Page, gardé à sa place', ($REGLAGES['mdMetaJeton'] ?? '') === 'tok-page');
verifie('la Page abonnée au champ leadgen (vérifié après coup)', in_array('POST 501/subscribed_apps', $appels, true) && in_array('GET 501/subscribed_apps', $appels, true));

$regler('https://cockpit.atelierby.be/api/cockpit/metadonnee/meta/leads');
$appels = [];
MdMeta::$transport = $fauxGraph('');
$r = mdMetaConnecter();
verifie('adresse libre et en https : prise, mode webhook', $r['ok'] && in_array('POST 42/subscriptions', $appels, true) && mdMetaMode() === 'webhook');

$regler('https://185.180.206.46/consulant_bo/api/cockpit/metadonnee/meta/leads');
$appels = [];
MdMeta::$transport = $fauxGraph('');
$r = mdMetaConnecter();
verifie('adresse sur une IP : Meta la refuserait, rien n’est demandé, relève', $r['ok'] && !in_array('POST 42/subscriptions', $appels, true) && mdMetaMode() === 'releve');

$REGLAGES = ['mdMeta' => ['appId' => '42', 'pageId' => '', 'verify' => 'v'], 'mdMetaSecret' => '', 'mdMetaJeton' => ''];
$r = mdMetaConnecter();
verifie('réglages incomplets : dit ce qui manque', !$r['ok'] && str_contains($r['etapes'][0]['detail'], 'ID de la Page') && str_contains($r['etapes'][0]['detail'], 'clé secrète'));

$lus = [];
MdMeta::$transport = static function (string $m, string $p, array $q, ?string $t) use (&$lus): array {
    $lus[] = (string) ($q['fields'] ?? '');
    return str_contains((string) ($q['fields'] ?? ''), 'ad_id')
        ? ['ok' => false, 'code' => 400, 'data' => [], 'error' => 'Meta (200) : permission ads_management']
        : ['ok' => true, 'code' => 200, 'data' => ['created_time' => '2026-10-29T08:00:00+0000', 'form_id' => '9001', 'field_data' => []], 'error' => null];
};
$lead = mdLireLead('7101', 'tok-page');
verifie('sans ads_management : le lead relu sans la campagne plutôt que perdu', ($lead['id'] ?? '') === '7101' && ($lead['form_id'] ?? '') === '9001' && count($lus) === 3, implode(' | ', $lus));

$fCoord = ['form_id' => '9009', 'nom' => 'Bureau', 'mapping_le' => null, 'valeurs_json' => null,
    'questions_json' => json_encode([['key' => 'societe', 'label' => 'Votre société', 'type' => 'CUSTOM'], ['key' => 'comment_vous_joindre_?', 'label' => 'Comment vous joindre ?', 'type' => 'CUSTOM'],
        ['key' => 'numero_de_gsm', 'label' => 'Numéro de GSM', 'type' => 'CUSTOM']])];
$v = mdChampsRapides([['key' => 'societe', 'label' => 'Votre société', 'valeur' => 'Fiduciaire Delvaux'], ['key' => 'comment_vous_joindre_?', 'label' => 'Comment vous joindre ?', 'valeur' => 'Jean.Dupont @ exemple.be'],
    ['key' => 'numero_de_gsm', 'label' => 'Numéro de GSM', 'valeur' => '0476 12 34 56']], $fCoord);
verifie('coordonnées : l’adresse trouvée dans n’importe quelle réponse (lecture du CRM)', $v['email'] === 'jean.dupont@exemple.be', (string) $v['email']);
verifie('coordonnées : le GSM au format international', $v['telephone'] === '+32476123456', (string) $v['telephone']);
MdMeta::$transport = null;

// --- 7. retard, SMS, escalade ---------------------------------------------------------------------------
echo "Retard, SMS, escalade\n";
$reg = mdReglagesDefaut();
$reg['sms']['actif'] = true;
$t0 = (int) strtotime('2026-10-28 10:00:00');
$env = ['etat' => 'envoye', 'recu_le' => '2026-10-28 08:59:00', 'envoye_le' => '2026-10-28 09:00:00', 'statut_erp' => 'nouveau', 'sms_le' => null, 'sms_derniere' => null, 'sms_relances' => 0, 'escalade_le' => null];
$mag = ['sms_on' => 1, 'sms_tel' => '+32476000012', 'escalade_email' => 'sam@exemple.be'];
verifie('envoyé il y a 1 h, pas vu → pas en retard', mdRetard($env, $reg, $t0) === null);
verifie('pas vu après 2 h → en retard (pas vu)', mdRetard($env, $reg, $t0 + 2 * 3600) === 'pas vu');
verifie('vu mais pas rappelé après 24 h → en retard (pas rappelé)', mdRetard(['statut_erp' => 'vu'] + $env, $reg, $t0 + 24 * 3600) === 'pas rappelé');
verifie('contacté → jamais en retard', mdRetard(['statut_erp' => 'contacte'] + $env, $reg, $t0 + 48 * 3600) === null);
verifie('premier SMS dû à 10 h', mdSmsPlan($env, $mag, $reg, $t0, 10) === 'premier');
verifie('à 21 h : rien (il part à 8 h)', mdSmsPlan($env, $mag, $reg, $t0, 21) === null);
verifie('SMS éteints : rien', mdSmsPlan($env, $mag, ['sms' => ['actif' => false] + $reg['sms']] + $reg, $t0, 10) === null);
verifie('magasin sans numéro : rien', mdSmsPlan($env, ['sms_tel' => ''] + $mag, $reg, $t0, 10) === null);
verifie('lead pas dans l’ERP : rien', mdSmsPlan(['etat' => 'a_attribuer'] + $env, $mag, $reg, $t0, 10) === null);
verifie('lead déjà vu : pas de premier SMS', mdSmsPlan(['statut_erp' => 'vu'] + $env, $mag, $reg, $t0, 10) === null);
verifie('allumage : pas de SMS pour un lead de plus d’un jour', mdSmsPlan(['envoye_le' => '2026-10-26 09:00:00'] + $env, $mag, $reg, $t0, 10) === null);
verifie('historique importé (reçu il y a 10 j) : pas de SMS', mdSmsPlan(['recu_le' => '2026-10-18 09:00:00'] + $env, $mag, $reg, $t0, 10) === null);
verifie('… sauf attribué à la main', mdSmsPlan(['recu_le' => '2026-10-18 09:00:00', 'shop_par' => 'main'] + $env, $mag, $reg, $t0, 10) === 'premier');
$apres = ['sms_le' => '2026-10-28 09:01:00', 'sms_derniere' => '2026-10-28 09:01:00'] + $env;
verifie('relance avant 2 h : rien', mdSmsPlan($apres, $mag, $reg, $t0, 10) === null);
verifie('relance après 2 h, pas vu', mdSmsPlan($apres, $mag, $reg, $t0 + 3600 + 120, 11) === 'relance');
verifie('pas de relance une fois vu', mdSmsPlan(['statut_erp' => 'vu'] + $apres, $mag, $reg, $t0 + 4 * 3600, 14) === null);
verifie('relances plafonnées', mdSmsPlan(['sms_relances' => 2] + $apres, $mag, $reg, $t0 + 8 * 3600, 18) === null);
verifie('escalade après 24 h sans rappel', mdEscaladePlan($env, $mag, $reg, $t0 + 23 * 3600 + 60) === true && mdEscaladePlan($env, $mag, $reg, $t0 + 22 * 3600) === false);
verifie('escalade une seule fois', mdEscaladePlan(['escalade_le' => '2026-10-29 09:00:00'] + $env, $mag, $reg, $t0 + 30 * 3600) === false);
verifie('pas d’escalade sans adresse', mdEscaladePlan($env, ['escalade_email' => ''] + $mag, $reg, $t0 + 30 * 3600) === false);
$lsms = ['entreprise' => 'Fiduciaire Delvaux', 'contact' => 'Claire Mertens', 'telephone' => '+32476123456', 'cp' => '6041', 'erp_id' => 'L-20488',
    'envoye_le' => '2026-10-28 09:00:00', 'payload_json' => json_encode(['lead' => ['headcount' => 35]])];
$sms = mdSmsTexte($reg['sms']['modele'], $lsms, $reg, $t0);
verifie('SMS rempli', $sms === "L'Atelier by - Lead bureau : Fiduciaire Delvaux, 35 pers., 6041. Claire Mertens 0476 12 34 56. ERP : https://atelierby.tfbuddy.com/panel/leads/L-20488", $sms);
verifie('le SMS par défaut tient en un SMS (≤ 160)', mb_strlen($sms) <= 160, (string) mb_strlen($sms));
verifie('relance : l’attente dite', str_contains(mdSmsTexte($reg['sms']['modeleRelance'], $lsms, $reg, $t0 + 2 * 3600), 'attend depuis 3 h'));
verifie('sans entreprise ni personnes : « un bureau », « ? »', str_contains(mdSmsTexte($reg['sms']['modele'], ['entreprise' => '', 'contact' => '', 'payload_json' => '{}'] + $lsms, $reg, $t0), 'un bureau, ? pers.'));

// --- 8. SMSAPI ----------------------------------------------------------------------------------------------
echo "SMSAPI (simulé)\n";
$REGLAGES['mdSmsJeton'] = 'jeton-test';
$vu = null;
MdSms::$transport = static function (string $url, array $champs, string $jeton) use (&$vu): array {
    $vu = [$url, $champs, $jeton];
    return $champs['to'] === '32470000000' ? [200, ['error' => 101, 'message' => 'Authorization failed']] : [200, ['count' => 1, 'list' => [['id' => 'SMS1', 'status' => 'QUEUE']]]];
};
[$okS, $id] = MdSms::envoyer('0476 00 00 12', 'Bonjour é', 'AtelierBy');
verifie('SMS parti : numéro sans +, utf-8, jeton, expéditeur', $okS && $id === 'SMS1' && $vu[1]['to'] === '32476000012' && $vu[1]['encoding'] === 'utf-8'
    && $vu[2] === 'jeton-test' && $vu[1]['from'] === 'AtelierBy' && str_ends_with($vu[0], '/sms.do'), json_encode($vu));
[$okS, $err] = MdSms::envoyer('0470 00 00 00', 'x');
verifie('erreur 101 dite avec le remède', !$okS && str_contains($err, '(101)') && str_contains($err, 'permission « sms »'), $err);
[$okS, $err] = MdSms::envoyer('rien', 'x');
verifie('numéro illisible refusé sans appel', !$okS && str_contains($err, 'illisible'));
MdSms::$transport = static function (string $url, ?array $champs, string $jeton): array {
    return $champs === null && str_ends_with($url, '/profile') ? [200, ['username' => 'atelierby', 'points' => 412.5]] : [500, null];
};
[$okP, $infoP] = MdSms::profil();
verifie('profil SMSAPI lu sans envoyer de SMS', $okP && $infoP === 'atelierby · 412.5 points', $infoP);
$REGLAGES = [];
[$okS, $err] = MdSms::envoyer('0476000012', 'x');
verifie('sans jeton : rien ne part', !$okS && str_contains($err, 'jeton'));

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko === 0 ? 0 : 1);
