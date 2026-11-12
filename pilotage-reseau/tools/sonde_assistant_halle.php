<?php
declare(strict_types=1);

/*
 * SONDE — l'assistant du dashboard magasin, en vrai, avant sa mise en ligne (12/11/2026). Le workflow dépose
 * src/assistant_magasin.php et src/fidelite_profils.php dans /tmp du serveur (pas dans le site), puis ce script :
 *   1. assemble la fiche de faits de Halle par l'API du panel (amFaits) et en montre la forme, sans secret ;
 *   2. pose « Que puis-je améliorer ? » à Claude (amDemander) et montre la réponse, le modèle, l'usage ;
 *   3. pose une question de suite avec le fil, pour vérifier la conversation et le cache.
 * Lancée par .github/workflows/sonde-assistant-halle.yml :  php -- /var/www/consulant_bo < tools/sonde_assistant_halle.php
 * Écritures : le cache de la fiche (ceo_app_setting `assistantFaits|4`) et le cache des tickets de la fidélité
 * (ceo_fid_*), les mêmes que la première ouverture du dashboard. Rien d'autre.
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'writes.php', 'panel_api.php', 'anthropic.php', 'fidelite.php'] as $f) { require_once "$dir/src/$f"; }
require_once '/tmp/sonde_fidelite_profils.php';
require_once '/tmp/sonde_assistant_magasin.php';

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
    $r2 = amDemander($f, $q2, [['q' => 'Que puis-je améliorer ?', 'r' => $r['reponse']]]);
    printf("  « %s » · %.1f s\n", $q2, microtime(true) - $t0);
    echo json_encode($r2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}
echo "\nfin de la sonde\n";
