<?php
declare(strict_types=1);

/**
 * LE VRAI PASSAGE DU MODÈLE sur les 19 photos rendues le 01/10/2026 (Gosselies 11, Halle 8).
 *
 * Chaque photo passe par la fonction du cockpit, telle quelle : Anthropic::noterPhoto
 * (src/anthropic.php), avec le barème par défaut du réseau (signalementDefaut) et le même
 * contexte que la route /ia/note (checklist, tâche obligatoire). Rien n'est écrit au panel.
 *
 * Seul ajout : le transport HTTP est doublé pour relever, en plus de la proposition, la raison
 * d'arrêt et les jetons consommés. Il envoie exactement la requête du cockpit (même adresse,
 * mêmes en-têtes, même corps).
 *
 *   ANTHROPIC_API_KEY=… php docs/maquettes/controle-qualite-ia/passage.php
 *   CQ_MODELE=claude-opus-5-5 php …/passage.php      (un autre modèle que celui du réglage)
 *   php …/passage.php --essai                        (à vide : réponses factices, aucun appel)
 *
 * Sortie : passage-reel.json (ou passage-essai.json), à côté de ce fichier.
 */

$racine = dirname(__DIR__, 3);
$essai = in_array('--essai', $argv, true);

/** Le réglage `anthropic` du cockpit : ici, seulement le modèle si on le force. */
function setting(string $key, mixed $default = null): mixed
{
    if ($key === 'anthropic' && getenv('CQ_MODELE')) { return ['modele' => (string) getenv('CQ_MODELE')]; }
    return $default;
}
require $racine . '/src/anthropic.php';

$cle = (string) (getenv('ANTHROPIC_API_KEY') ?: '');
if (!$essai && $cle === '') { fwrite(STDERR, "ANTHROPIC_API_KEY absente : rien n'est lancé.\n"); exit(2); }
if ($essai) { putenv('ANTHROPIC_API_KEY=essai'); }

// Le barème par défaut du réseau (src/installer.php, signalementDefaut) : niveaux et aides.
$niveaux = [
    ['n' => 5, 'nom' => 'Exemplaire', 'aide' => "au-dessus de l'attendu"],
    ['n' => 4, 'nom' => 'Conforme', 'aide' => 'livrable accepté'],
    ['n' => 3, 'nom' => 'Non conforme — mineur', 'aide' => 'détail à reprendre'],
    ['n' => 2, 'nom' => 'Non conforme — majeur', 'aide' => 'écart net, à reprendre'],
    ['n' => 1, 'nom' => 'Non conforme — critique', 'aide' => 'à reprendre immédiatement'],
];

// Les photos sont servies en local : noterPhoto les télécharge par une adresse, comme en production.
$port = 8131;
$serveur = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $racine], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes);
usleep(400000);

// Le transport : la même requête que Anthropic::http, plus la raison d'arrêt et les jetons.
$releve = [];
Anthropic::$transport = static function (array $corps, array $entetes) use (&$releve, $essai, $cle): array {
    if ($essai) {
        $releve = ['stop' => 'end_turn', 'usage' => null, 'images' => count(array_filter($corps['messages'][0]['content'], fn ($b) => $b['type'] === 'image'))];
        return [200, ['content' => [['type' => 'text', 'text' => '{"niveau": 4, "constats": ["essai"], "commentaire": "essai", "confiance": "haute"}']], 'stop_reason' => 'end_turn']];
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'x-api-key: ' . $cle, 'anthropic-version: 2023-06-01'],
            getenv('ANTHROPIC_WORKSPACE_ID') ? ['anthropic-workspace-id: ' . getenv('ANTHROPIC_WORKSPACE_ID')] : [], $entetes),
        CURLOPT_POSTFIELDS => json_encode($corps, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rep = $raw === false ? null : json_decode((string) $raw, true);
    $releve = ['stop' => $rep['stop_reason'] ?? null, 'usage' => $rep['usage'] ?? null, 'modeleServi' => $rep['model'] ?? null,
        'texte' => is_array($rep) ? implode('', array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', (array) ($rep['content'] ?? []))) : null];
    return [$code, $rep];
};

$D = json_decode((string) file_get_contents($racine . '/docs/maquettes/controle-taches-photos/donnees.json'), true);
$out = ['date' => $D['date'], 'lance' => date('c'), 'essai' => $essai, 'modele' => Anthropic::config()['modele'], 'p' => []];
foreach ($D['shops'] as $s) {
    foreach ($s['taches'] as $t) {
        if (empty($t['photo'])) { continue; }
        $k = $s['shopId'] . '-' . $t['taskId'];
        // Le contexte de la route /ia/note : la checklist, l'obligation (pas de produit attendu sur ces tâches).
        $ctx = trim(($t['checklist'] ? 'checklist « ' . $t['checklist'] . ' »' : '') . (!empty($t['obligatoire']) ? ', tâche obligatoire' : ''));
        $url = 'http://127.0.0.1:' . $port . '/docs/maquettes/controle-taches-photos/photos/' . $k . '.jpg';
        $releve = [];
        $t0 = microtime(true);
        $r = Anthropic::noterPhoto((string) $t['tache'], $url, null, $niveaux, $ctx);
        $out['p'][$k] = ['shop' => $s['shop'], 'tache' => $t['tache'], 'n' => $r['niveau'], 'nom' => $r['nom'], 'conf' => $r['confiance'],
            'constats' => $r['constats'], 'commentaire' => $r['commentaire'], 'erreur' => $r['erreur'],
            'secondes' => round(microtime(true) - $t0, 1)] + $releve;
        printf("%-8s %-45s %s %s%s\n", $k, mb_substr((string) $t['tache'], 0, 45), $r['niveau'] ?? '—', $r['confiance'] ?? '', $r['erreur'] ? '  ⚠ ' . $r['erreur'] : '');
    }
}
proc_terminate($serveur);
$fichier = __DIR__ . '/' . ($essai ? 'passage-essai.json' : 'passage-reel.json');
file_put_contents($fichier, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo '→ ', basename($fichier), ' · modèle ', $out['modele'], "\n";
