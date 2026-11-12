<?php
// Banc navigateur : les fichiers de public/, et l'API des profils servie par le vrai backend (faux panel, MariaDB locale).
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($u === '/__banc.html') { readfile(__DIR__ . '/banc.html'); return true; }
if ($u === '/__banc-coachs.html') { readfile(__DIR__ . '/banc-coachs.html'); return true; }
// Les avatars des coachs, servis comme au cockpit (dashboard/avatars/ à côté de index.html).
if (str_starts_with($u, '/api/cockpit/')) {
    require __DIR__ . '/lib.php';
    header('Content-Type: application/json');
    $p = substr($u, strlen('/api/cockpit'));
    $r = match (true) {
        $_SERVER['REQUEST_METHOD'] === 'POST' && $p === '/fidelite/profils' => wr_fidelite_profils_post(),
        $p === '/fidelite/profils' => ep_fidelite_profils(),
        $p === '/fidelite/profils/magasin' => ep_fidelite_profils_magasin(),
        $p === '/fidelite/client' => ep_fidelite_client(),
        $p === '/assistant/magasin/questions' => (require_once __DIR__ . '/../../src/assistant_magasin.php') ? ep_assistant_questions() : [],
        // Les coachs et les questions posées : le vrai backend sur la MariaDB du banc.
        $p === '/assistant/coachs' => (require_once __DIR__ . '/../../src/assistant_magasin.php') ? ($_SERVER['REQUEST_METHOD'] === 'POST' ? wr_assistant_coachs() : ep_assistant_coachs()) : [],
        $p === '/assistant/questions-posees' => (require_once __DIR__ . '/../../src/assistant_magasin.php') ? ep_assistant_questions_posees() : [],
        // L'assistant : sans clé Anthropic au banc, une réponse type au format du schéma (l'appel réel se vérifie au serveur).
        $_SERVER['REQUEST_METHOD'] === 'POST' && $p === '/assistant/magasin' => (static function () { usleep(600000); $b = json_decode((string) file_get_contents('php://input'), true);
            return ['luLe' => date('Y-m-d H:i'), 'modele' => 'banc', 'reponse' => ['titre' => (string) $b['question'], 'resume' => 'Réponse type du banc (' . count($b['fil'] ?? []) . ' échange(s) avant celui-ci).',
                'chiffres' => [['valeur' => '14 255 €', 'libelle' => 'CA · +4,1 %'], ['valeur' => '1 086', 'libelle' => 'tickets'], ['valeur' => '13,13 €', 'libelle' => 'panier moyen']],
                'points' => [['titre' => 'Le week-end porte la semaine :', 'texte' => 'samedi 3 362 €, dimanche 3 243 €.']],
                'priorites' => [['titre' => 'Faire monter le panier moyen', 'constat' => '13,13 €, sous le seuil bas de 18 €.', 'gain' => '+ 1 € × 1 086 tickets = + 1 086 € par semaine', 'ecran' => 'produits'],
                    ['titre' => 'Remettre les contrôles en route', 'constat' => '0 tâche cochée sur 75 à midi.', 'gain' => '', 'ecran' => 'taches']],
                'conclusion' => 'Ordre de marche : une vente additionnelle proposée à chaque ticket jusqu’à la fermeture.',
                'suites' => ['Détaille le samedi', 'Compare avec le mois dernier']]]; })(),
        default => ['error' => 'route du banc inconnue : ' . $p],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    return true;
}
return false;
