<?php
declare(strict_types=1);

/**
 * Banc local — la reprise des clés (étape 1) : les réglages se lisent À L'IDENTIQUE avant et après, les champs-clés
 * quittent ceo_app_setting pour erp_cles, la reprise est rejouable, un conflit n'écrase rien, le retour arrière rend
 * l'état de départ. Valeurs FACTICES uniquement. Refuse toute base qui n'est pas locale (même garde que reorg/banc.php).
 *
 *   php reorg/banc_cles.php
 */

$h = getenv('COCKPIT_DB_HOST') ?: '';
$p = getenv('COCKPIT_DB_PORT') ?: '';
if (!in_array($h, ['127.0.0.1', 'localhost'], true) || $p === '' || $p === '3306') { fwrite(STDERR, "banc : base refusée\n"); exit(2); }

$racine = dirname(__DIR__);
$src = is_dir("$racine/shared/src") ? "$racine/shared/src" : "$racine/pilotage-reseau/src";
require_once "$src/Db.php";
require_once "$src/endpoints.php";   // setting(), et src/erp_cles.php

$ok = 0; $ko = 0;
function verifie(string $quoi, bool $vrai, string $detail = ''): void
{
    global $ok, $ko;
    if ($vrai) { $ok++; echo "  ok  $quoi\n"; } else { $ko++; echo "  KO  $quoi" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$faux = [
    'anthropic' => ['cle' => 'sk-ant-FAUX-0000000000000000', 'modele' => 'claude-haiku-5-5', 'espace' => 'wrkspc_FAUX'],
    'google' => ['cle' => 'FAUX-google-places-0000', 'langue' => 'fr'],
    'panelApi' => ['base' => 'https://panel.faux/api/v1', 'phone' => '+32000000000', 'password' => 'faux-mdp-panel'],
    'erpAdmin' => ['base' => 'https://erp.faux/api/v1', 'phone' => '+32000000001', 'password' => 'faux-mdp-erp'],
    'smtp' => ['hote' => 'smtp.faux', 'port' => 587, 'securite' => 'tls', 'utilisateur' => 'u@faux', 'motDePasse' => 'faux-mdp-smtp', 'expediteur' => 'Cockpit <c@faux>'],
    'pushVapid' => ['pem' => "-----FAUX PEM-----", 'publique' => 'FAUX-publique'],
    'mdMeta' => ['source' => 'cockpit', 'appId' => '42', 'pageId' => '501', 'verify' => 'faux-verify', 'rappel' => ''],
    'mdMetaSecret' => 'faux-secret-app',
    'mdMetaJeton' => 'faux-jeton-page',
    'mdMetaJetonCrm' => ['de' => 'abc', 'jeton' => 'faux-jeton-crm'],
    'mdSmsJeton' => 'faux-jeton-sms',
];
// Départ : tout dans ceo_app_setting, rien dans erp_cles pour ces noms ; une clé commune différente pour le conflit.
erpClesSchema();
foreach (ERP_CLES_REGLAGES as $k => $carte) {
    foreach ($carte as [$nom]) { Db::exec('DELETE FROM erp_cles WHERE cle = ?', [$nom]); }
}
foreach ($faux as $k => $v) {
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
erpCleEcrire('tfbuddy_password', 'AUTRE-mdp-deja-commun', true, 'TF Buddy', 'commun');   // conflit voulu
erpCle('');
$avant = [];
foreach (array_keys($faux) as $k) { $avant[$k] = setting($k); }

echo "Reprise\n";
$b = erpClesReprise();
verifie('les champs-clés sont rangés', count($b['rangees']) === 21, implode(', ', $b['rangees']));
verifie('le conflit (mot de passe commun différent) est noté et rien n’est écrasé', $b['conflits'] === ['panelApi.password → tfbuddy_password']
    && erpCle('tfbuddy_password') === 'AUTRE-mdp-deja-commun', json_encode($b['conflits']));
erpCle('');
foreach (array_keys($faux) as $k) { verifie("setting('$k') se lit à l'identique", setting($k) == $avant[$k], json_encode(setting($k))); }
$reste = static fn (string $k) => json_decode((string) (Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$k])['value'] ?? 'null'), true);
verifie('anthropic garde son modèle, plus sa clé', $reste('anthropic') === ['modele' => 'claude-haiku-5-5']);
verifie('panelApi garde seulement le mot de passe en conflit', $reste('panelApi') === ['password' => 'faux-mdp-panel']);
verifie('les réglages-valeurs ont quitté ceo_app_setting', $reste('mdMetaSecret') === null && $reste('mdSmsJeton') === null);
$inv = array_column(erpClesInventaire(), null, 'cle');
verifie('les secrets sont marqués secrets, avec leur portail', ($inv['pil_smtp_pass']['secret'] ?? 0) == 1 && ($inv['mar_meta_app_secret']['portail'] ?? '') === 'mar'
    && ($inv['pil_smtp_host']['secret'] ?? 1) == 0);
verifie('l’inventaire ne rend aucune valeur', !str_contains(json_encode(erpClesInventaire()), 'faux-mdp-smtp'));

echo "Rejouée\n";
$b2 = erpClesReprise();
verifie('rejouée : rien de plus n’est rangé', $b2['rangees'] === [] && $b2['conflits'] === ['panelApi.password → tfbuddy_password']);

echo "Écriture par l'écran (settingEcrire)\n";
$s = setting('smtp'); $s['motDePasse'] = 'nouveau-faux'; settingEcrire('smtp', $s);
verifie('un nouveau mot de passe SMTP va dans erp_cles', erpCle('pil_smtp_pass') === 'nouveau-faux' && !isset($reste('smtp')['motDePasse']));
$a = setting('anthropic'); unset($a['cle']); settingEcrire('anthropic', $a);
verifie('« effacer la clé » la retire d’erp_cles', erpCle('pil_anthropic_api_key') === '' && (setting('anthropic')['cle'] ?? null) === null);
settingEcrire('mdSmsJeton', '');
verifie('un réglage-valeur vidé disparaît', erpCle('mar_smsapi_token') === '' && setting('mdSmsJeton', 'défaut') === 'défaut');

echo "Retour arrière\n";
settingEcrire('smtp', $avant['smtp']); settingEcrire('anthropic', $avant['anthropic']); settingEcrire('mdSmsJeton', $avant['mdSmsJeton']);
$r = erpClesRendre();
verifie('les clés repartent dans les réglages', in_array('pil_smtp_pass', $r, true) && erpCle('pil_smtp_pass') === '');
foreach (array_keys($faux) as $k) { verifie("après retour, setting('$k') identique", setting($k) == $avant[$k], json_encode(setting($k))); }

// Ménage : rien de factice ne reste.
foreach (array_keys($faux) as $k) { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$k]); }
foreach (ERP_CLES_REGLAGES as $carte) { foreach ($carte as [$nom]) { Db::exec('DELETE FROM erp_cles WHERE cle = ?', [$nom]); } }
echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
