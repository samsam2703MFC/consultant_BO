<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
foreach (['ceo_fid_profil', 'ceo_fid_jour', 'ceo_fid_ticket'] as $t) { Db::exec("DROP TABLE IF EXISTS $t"); }
Db::exec('DELETE FROM ceo_app_setting');

$ok = 0; $ko = 0;
function verifie(string $quoi, bool $c, string $detail = ''): void { global $ok, $ko; $c ? $ok++ : $ko++; echo ($c ? '  ok   ' : '  ÉCHEC ') . $quoi . ($detail !== '' ? ' — ' . $detail : '') . "\n"; }

echo "== Les profils de départ\n";
$P = fpProfils();
verifie('six profils', count($P) === 6, implode(', ', array_column($P, 'nom')));
verifie('ordre du plus fidèle', array_column($P, 'nom') === ['Fanbase', 'Fidèle', 'Hebdo', 'Régulier', 'Occasionnel', 'Découverte']);
verifie('seuils 12 8 4 2 1 0', array_column($P, 'seuil') === [12, 8, 4, 2, 1, 0]);
foreach ($P as $p) { echo '       ', str_pad($p['nom'], 12), str_pad($p['condition'], 28), $p['equivalent'], "\n"; }
verifie('Régulier = 2 à 3 fois par mois', $P[3]['condition'] === '2 à 3 fois par mois');
verifie('Fanbase = 3 fois par semaine ou plus', $P[0]['condition'] === '3 fois par semaine ou plus');
verifie('fenêtre 30 j, mémoire 90 j', fpReglages() === ['fenetre' => 30, 'memoire' => 90]);

echo "== Le magasin\n";
$t0 = microtime(true);
$m = fpMagasin(4);
printf("       %d appels au faux panel, %.2f s\n", PanelApi::$appels, microtime(true) - $t0);
$par = array_column($m['profils'], 'clients', 'nom');
echo '       ', json_encode($par, JSON_UNESCAPED_UNICODE), "\n";
// Rythmes sur 30 jours : c1 tous les 2 j (15) Fanbase ; c3 tous les 3,5 j (≈8,6) Fidèle ; c2 tous les 4 j (7,5) Hebdo ;
// c9 tous les 7 j (≈4,3 → 4 ou 5 passages) Hebdo ; c4 tous les 12 j (3) Régulier ; c5 tous les 30 j (1) Occasionnel ;
// c7 il y a 10 j (1) Occasionnel ; c6 il y a 60 j : Découverte ; c8 il y a 100 j, c10 il y a 200 j : hors mémoire.
verifie('Fanbase 1', $par['Fanbase'] === 1); verifie('Fidèle 2 (tous les 3,5 j et tous les 4 j : 8 passages et plus)', $par['Fidèle'] === 2); verifie('Hebdo 1 (tous les 7 j : 5 passages)', $par['Hebdo'] === 1);
verifie('Régulier 1', $par['Régulier'] === 1); verifie('Occasionnel 2', $par['Occasionnel'] === 2, (string) $par['Occasionnel']); verifie('Découverte 1', $par['Découverte'] === 1);
verifie('8 clients scannés (pro et hors mémoire exclus)', $m['scannes'] === 8, (string) $m['scannes']);
verifie('90 journées lues', $m['joursLus'] === 90, (string) $m['joursLus']);
verifie('parts qui font 100 %', abs(array_sum(array_column($m['profils'], 'part')) - 100) < 0.5);
verifie('clients qui reviennent : 7, avec noms', count($m['clients']) === 7 && str_starts_with($m['clients'][0]['nom'], 'Prénom1 N.'), $m['clients'][0]['nom'] ?? '');
verifie('radar et familles du Fanbase', is_array($m['profils'][0]['radar']) && is_array($m['profils'][0]['familles']), json_encode($m['profils'][0]['radar']) . ' ' . json_encode($m['profils'][0]['familles']));
verifie('moyenne des radars', is_array($m['moyenne']['radar']));
$avant = PanelApi::$appels;
$m2 = fpMagasin(4);
verifie('seconde lecture : seuls aujourd’hui et la veille se relisent (+ noms)', PanelApi::$appels - $avant <= 2 + 7, (string) (PanelApi::$appels - $avant) . ' appels');
verifie('mêmes comptes à la relecture', array_column($m2['profils'], 'clients', 'nom') === $par);

echo "== La fiche d'un client\n";
$c = ep_fidelite_client_test(4, 2);
function ep_fidelite_client_test(int $s, int $c): array { $_GET = ['shop' => (string) $s, 'client' => (string) $c]; return ep_fidelite_client(); }
verifie('client 2 : 45 passages en 180 jours', $c['passages'] === 45, (string) $c['passages']);
verifie('profil Fidèle (8 passages sur 30 jours)', ($c['profil'] ?? 0) === $P[1]['id']);
verifie('nom lu sur la fiche', $c['nom'] === 'Prénom2 N.', $c['nom']);
verifie('profil mois par mois', count($c['parMois']) >= 6, json_encode(array_map(static fn ($x) => $x['mois'] . ':' . $x['passages'] . '→' . $x['rythme'], $c['parMois'])));
verifie('articles', ($c['articles'][0]['nom'] ?? '') !== '', json_encode(array_slice($c['articles'], 0, 3), JSON_UNESCAPED_UNICODE));
verifie('tickets du plus récent au plus ancien', $c['tickets'][0]['quand'] >= end($c['tickets'])['quand']);
$_GET = ['shop' => '4', 'client' => '12345'];
verifie('client inconnu : un message', isset(ep_fidelite_client()['error']));

echo "== La publication (POST par un serveur PHP local)\n";
$post = static function (array $b): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($b), 'ignore_errors' => true]]);
    $r = file_get_contents('http://127.0.0.1:8765/', false, $ctx);
    return json_decode((string) $r, true) ?: ['brut' => $r];
};
$P = fpProfils();
$id = array_column($P, 'id', 'nom');
$r = $post(['profils' => [['id' => $id['Hebdo'], 'n' => 2, 'unite' => 'semaine']], 'qui' => 'Banc']);
verifie('deux profils au même seuil : refusé', isset($r['error']) && str_contains($r['error'], 'même seuil'), $r['error'] ?? json_encode($r));
$r = $post(['profils' => [['id' => $id['Découverte'], 'supprimer' => true]]]);
verifie('le profil de base ne se retire pas', isset($r['error']), $r['error'] ?? '');
$r = $post(['profils' => [['id' => $id['Hebdo'], 'nom' => 'Hebdomadaire']], 'fenetre' => 60, 'memoire' => 40]);
verifie('mémoire plus courte que la fenêtre : refusé', isset($r['error']), $r['error'] ?? '');
$r = $post(['profils' => [['id' => $id['Hebdo'], 'nom' => 'Hebdo'], ['nom' => 'Quotidien', 'n' => 5, 'unite' => 'semaine', 'couleur' => '#5c4033']], 'fenetre' => 30, 'memoire' => 90, 'qui' => 'Banc']);
verifie('ajout de « Quotidien » (5 fois par semaine)', ($r['ok'] ?? false) === true && count($r['profils']) === 7, json_encode(array_column($r['profils'] ?? [], 'condition', 'nom'), JSON_UNESCAPED_UNICODE));
verifie('Fanbase devient « 3 fois par semaine » (borne haute 20)', (array_column($r['profils'] ?? [], 'equivalent', 'nom')['Fanbase'] ?? '') === '12 à 19 passages par mois');
$m = fpMagasin(4);
verifie('le rangement suit : Quotidien 0, Fanbase 1', array_column($m['profils'], 'clients', 'nom')['Quotidien'] === 0 && array_column($m['profils'], 'clients', 'nom')['Fanbase'] === 1);
$jr = Db::row("SELECT message FROM ceo_journal_entry ORDER BY id DESC LIMIT 1");
verifie('journalisé', str_contains((string) ($jr['message'] ?? ''), 'Profils clients publiés'), (string) ($jr['message'] ?? ''));
echo "\n$ok vérifications réussies, $ko en échec\n";
