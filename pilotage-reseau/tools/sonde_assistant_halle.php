<?php
declare(strict_types=1);

/*
 * SONDE — l'assistant du dashboard magasin, en vrai, avant sa mise en ligne (12/11/2026). Le workflow dépose
 * src/assistant_magasin.php et src/fidelite_profils.php dans /tmp du serveur (pas dans le site), puis ce script :
 *   1. assemble la fiche de faits de Halle par l'API du panel (amFaits) et en montre la forme, sans secret ;
 *   2. pose « Que puis-je améliorer ? » à Claude (amDemander) et montre la réponse, le modèle, l'usage ;
 *   3. pose une question de suite avec le fil, pour vérifier la conversation et le cache.
 * Cinquième passage (12/11/2026) : pas de ligne anthropic_workspace_id ; la sonde cherche l'identifiant (format wrkspc_)
 * dans erp_cles et dit où il est, par son nom seulement.
 * Quatrième passage (12/11/2026) : l’identifiant d’espace de travail est dans erp_cles (anthropic_workspace_id) ; le
 * nouveau src/anthropic.php, qui l’envoie, part aussi dans /tmp (le serveur a encore l’ancien).
 * Troisième passage (12/11/2026) : crédit ajouté au compte Anthropic, l’appel réel se vérifie (relancé une seconde fois).
 * Deuxième passage (Sam, 12/11/2026 : « récupère-la dans la table erp keys ») : la clé se cherche dans erp_cles, par son
 * nom ; seule son existence et son nom s'affichent.
 * Lancée par .github/workflows/sonde-assistant-halle.yml :  php -- /var/www/consulant_bo < tools/sonde_assistant_halle.php
 * Écritures : le cache de la fiche (ceo_app_setting `assistantFaits|4`) et le cache des tickets de la fidélité
 * (ceo_fid_*), les mêmes que la première ouverture du dashboard. Rien d'autre.
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'writes.php', 'panel_api.php', 'fidelite.php'] as $f) { require_once "$dir/src/$f"; }
require_once '/tmp/sonde_anthropic.php';
require_once '/tmp/sonde_fidelite_profils.php';
require_once '/tmp/sonde_assistant_magasin.php';

echo "=== 0. La clé Claude dans erp_cles (les noms, jamais les valeurs)\n";
$cands = Db::rows("SELECT cle, portail, integration, secret, indice, CHAR_LENGTH(valeur) n, maj_le FROM erp_cles
    WHERE LOWER(cle) REGEXP 'anthropic|claude|sk-ant|ia_|_ia|llm' OR LOWER(COALESCE(integration,'')) REGEXP 'anthropic|claude|ia|llm' ORDER BY cle");
foreach ($cands as $r) { printf("  %-32s portail %-8s intégration %-20s secret %d · %d caractères · indice %s · %s\n", $r['cle'], $r['portail'], (string) $r['integration'], $r['secret'], $r['n'], (string) $r['indice'], $r['maj_le']); }
if (!$cands) { echo "  aucune clé IA dans erp_cles ; toutes les clés :\n"; foreach (Db::rows('SELECT cle, portail, integration FROM erp_cles ORDER BY portail, cle') as $r) { echo '    ', $r['portail'], ' · ', $r['cle'], ' · ', (string) $r['integration'], "\n"; } }
// La première clé qui ressemble à une clé Anthropic sert à l'essai, dans ce processus seulement (jamais affichée).
foreach ($cands as $r) {
    $v = trim(erpCle((string) $r['cle']));
    if (str_starts_with($v, 'sk-ant-')) { putenv('ANTHROPIC_API_KEY=' . $v); echo "  essai avec « {$r['cle']} » (préfixe sk-ant-)\n"; break; }
}
unset($v);
// Cinquième passage : où est l'identifiant d'espace de travail ? Les noms seulement. Un identifiant Anthropic d'espace de
// travail commence par « wrkspc_ » : la première valeur de erp_cles de ce format sert à l'essai (dans ce processus).
echo "=== 0b. L'espace de travail (les noms, jamais les valeurs)\n";
foreach (Db::rows("SELECT cle, portail, integration, maj_le FROM erp_cles WHERE maj_le >= CURDATE() - INTERVAL 1 DAY OR LOWER(cle) REGEXP 'work|wrk|espace|space|orga' ORDER BY maj_le DESC") as $r) {
    printf("  récente ou nommée comme un espace : %-34s portail %-8s %-22s %s\n", $r['cle'], $r['portail'], (string) $r['integration'], $r['maj_le']);
}
$esp = '';
foreach (Db::rows('SELECT cle, portail, valeur FROM erp_cles ORDER BY maj_le DESC') as $r) {
    $v = trim((string) $r['valeur']);
    if (preg_match('/^wrkspc_[A-Za-z0-9]+$/', $v)) { echo "  valeur au format wrkspc_ : « {$r['cle']} » (portail {$r['portail']})\n"; if ($esp === '') { $esp = $v; } }
    elseif (str_contains($v, 'wrkspc_')) { echo "  « {$r['cle']} » (portail {$r['portail']}) contient wrkspc_ au milieu d'autre chose (espaces, guillemets ?)\n"; }
}
unset($v);
$st = setting('anthropic', []);
printf("  réglage cockpit « anthropic » : clé %s · espace %s\n", trim((string) ($st['cle'] ?? '')) !== '' ? 'renseignée (elle passe avant erp_cles)' : 'vide', trim((string) ($st['espace'] ?? '')) !== '' ? 'renseigné' : 'vide');
if ($esp !== '' && Anthropic::config()['espace'] === '') { putenv('ANTHROPIC_WORKSPACE_ID=' . $esp); echo "  essai avec cet espace de travail\n"; }
printf("  espace de travail retenu pour l'appel : %s\n", Anthropic::config()['espace'] !== '' ? 'oui' : 'AUCUN');

echo "=== 1. La fiche de faits de Halle\n";
$t0 = microtime(true);
$f = amFaits(4);
printf("  lue en %.1f s · %d octets\n", microtime(true) - $t0, strlen((string) json_encode($f)));
foreach ($f as $k => $v) { echo '  ', str_pad($k, 20), is_array($v) ? json_encode(array_slice($v, 0, 3, true), JSON_UNESCAPED_UNICODE) : $v, "\n"; }
echo "  clé Anthropic : ", Anthropic::configured() ? 'présente' : 'ABSENTE', " · modèle de l'assistant : ", (setting('assistantMagasin', [])['modele'] ?? AM_MODELE), "\n";

echo "\n=== 2. « Que puis-je améliorer ? »\n";
$t0 = microtime(true);
$r = amDemander($f, 'Que puis-je améliorer ?', []);
printf("  %.1f s\n", microtime(true) - $t0);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

if (isset($r['reponse'])) {
    echo "\n=== 3. Question de suite (avec le fil)\n";
    $q2 = $r['reponse']['suites'][0] ?? 'Détaille le samedi';
    $t0 = microtime(true);
    $r2 = amDemander($f, $q2, [['q' => 'Que puis-je améliorer ?', 'r' => $r['reponse']]], 'coach');
    printf("  « %s » · %.1f s\n", $q2, microtime(true) - $t0);
    echo json_encode($r2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}
echo "\nfin de la sonde\n";
