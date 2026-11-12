<?php
// Banc navigateur : les fichiers de public/, et l'API des profils servie par le vrai backend (faux panel, MariaDB locale).
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($u === '/__banc.html') { readfile(__DIR__ . '/banc.html'); return true; }
if (str_starts_with($u, '/api/cockpit/')) {
    require __DIR__ . '/lib.php';
    header('Content-Type: application/json');
    $p = substr($u, strlen('/api/cockpit'));
    $r = match (true) {
        $_SERVER['REQUEST_METHOD'] === 'POST' && $p === '/fidelite/profils' => wr_fidelite_profils_post(),
        $p === '/fidelite/profils' => ep_fidelite_profils(),
        $p === '/fidelite/profils/magasin' => ep_fidelite_profils_magasin(),
        $p === '/fidelite/client' => ep_fidelite_client(),
        default => ['error' => 'route du banc inconnue : ' . $p],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    return true;
}
return false;
