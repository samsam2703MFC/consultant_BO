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
        $p === '/fidelite/profils/tendance' => ep_fidelite_profils_tendance(),
        $p === '/fidelite/scan' => ep_fidelite_scan(),
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
        // Les cartes de fidélité : sans ERP au banc, des réponses types à la forme réelle (« Fidelity TEST 1 », noms fictifs).
        $p === '/fidelite/cartes' || $p === '/fidelite/carte-client' || $p === '/fidelite/carte' => (static function () use ($p) {
            $def = ['id' => 1, 'nom' => 'Fidelity TEST 1', 'timbres' => 20, 'recompense' => 'Une Galette des Rois', 'couleur' => '#8D1D2C', 'points' => false, 'du' => '2026-11-03', 'au' => '2026-11-30', 'active' => true, 'portee' => 'NETWORK'];
            $prog = static fn (int $n, int $ici) => ['id' => 1, 'nom' => 'Fidelity TEST 1', 'active' => true, 'timbres' => ['progres' => $n, 'requis' => 20, 'reste' => 20 - $n, 'pretes' => 0, 'dette' => 0], 'points' => null, 'gagnesIci' => ['timbres' => $ici, 'points' => 0]];
            $cl = [['Julie M.', 5, 16, 145.0], ['Karim B.', 3, 4, 38.2], ['Nadia L.', 1, 2, 12.4], ['Tom V.', 1, 1, 9.8], ['Élise D.', 1, 1, 6.1], ['Marc P.', 1, 1, 14.0], ['Inès R.', 1, 1, 8.3]];
            if ($p === '/fidelite/carte-client') { return ['inscrit' => true, 'cartes' => [$prog(5, 5) + ['definition' => $def]], 'achats' => 16, 'montant' => 145.0, 'dernierAchat' => date('Y-m-d', strtotime('-2 days')) . 'T11:42:25Z', 'reconnaissances' => 0, 'derniereReconnaissance' => null, 'disponibles' => [$def]]; }
            if ($p === '/fidelite/carte') { return ['id' => 1, 'nom' => 'Fidelity TEST 1', 'code' => 'FID-TEST-1', 'version' => 1, 'active' => true, 'portee' => 'NETWORK', 'magasins' => ['Corbais', 'Gosselies', 'Halle', 'Sombreffe'], 'du' => '2026-11-03 19:57:42', 'au' => '2026-11-30 21:23:14', 'couleur' => '#8D1D2C', 'timbres' => 20, 'recompense' => 'Une Galette des Rois', 'recompenseValideJours' => 30,
                'recompenseProduits' => ['Galette des Rois — 6 personnes'], 'recompensePortions' => 0, 'gagnerProduits' => [], 'gagnerCategories' => ['Pain', 'Petite Boulangerie', 'Viennoiserie Ind.'], 'gagnerGroupes' => 0, 'motsCles' => [], 'exclus' => ['Sac'], 'conditionsFigees' => true, 'points' => null]; }
            return ['shop' => '4', 'periode' => $_GET['periode'] ?? 'mois', 'du' => date('Y-m-01'), 'au' => date('Y-m-d'), 'lu' => date('Y-m-d H:i'),
                'participants' => ['unique_customers' => 7, 'stamp_customers' => 7, 'point_customers' => 0, 'both_customers' => 0, 'active_in_period' => 7],
                'reconnaissances' => ['count' => 0, 'unique_customers' => 0, 'devices' => 0], 'activite' => ['transactions' => 10, 'stamps_earned' => 13], 'reconnusCaisse90' => 207,
                'cartes' => [['id' => 1, 'nom' => 'Fidelity TEST 1', 'clients' => 7, 'actifsIci' => 7, 'pretes' => 0, 'presque' => 0, 'requis' => 20, 'repartition' => array_map(static fn ($i) => [1 => 5, 3 => 1, 5 => 1][$i] ?? 0, range(0, 19)), 'pointsSoldes' => [], 'timbresIci' => 13, 'pointsIci' => 0, 'definition' => $def]],
                'definitions' => [$def], 'clientsTotal' => 7, 'erreurClients' => null,
                'clients' => array_map(static fn ($c, $i) => ['id' => 100 + $i, 'nom' => $c[0], 'actif' => true, 'bloque' => false, 'cartes' => [$prog($c[1], $c[1])], 'achats' => $c[2], 'montant' => $c[3], 'dernierAchat' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')) . 'T10:00:00Z', 'reconnaissances' => 0, 'derniereReconnaissance' => null, 'pretes' => 0], $cl, array_keys($cl)),
                'reseau' => [['id' => '2', 'nom' => 'Corbais', 'inscrits' => 8, 'actifs' => 3, 'timbres' => 6, 'points' => 0, 'ventes' => 442.5, 'reconnaissances' => 0], ['id' => '3', 'nom' => 'Gosselies', 'inscrits' => 0, 'actifs' => 0, 'timbres' => 0, 'points' => 0, 'ventes' => 0, 'reconnaissances' => 0],
                    ['id' => '4', 'nom' => 'Halle', 'inscrits' => 7, 'actifs' => 7, 'timbres' => 13, 'points' => 0, 'ventes' => 233.78, 'reconnaissances' => 0], ['id' => '5', 'nom' => 'Sombreffe', 'inscrits' => 3, 'actifs' => 3, 'timbres' => 15, 'points' => 0, 'ventes' => 100.5, 'reconnaissances' => 0]]];
        })(),
        default => ['error' => 'route du banc inconnue : ' . $p],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    return true;
}
return false;
