#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tests de bin/console_bo.php (activation des comptes de la console franchisé) — contre une fausse API de
 * console servie par php -S et une base MySQL/MariaDB locale. Jamais contre le serveur.
 *
 *   CB_TEST_DSN='mysql:host=127.0.0.1;dbname=cb_test' CB_TEST_USER=hr CB_TEST_PASSWORD=hrpass \
 *   php tests/hr/console_bo_test.php
 *
 * Sans CB_TEST_DSN, seuls les tests sans base tournent (insertion du bloc dans config.php).
 */

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/bin/console_bo.php';

$ok = 0;
$ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) {
        $ok++;
        echo "  ok  $nom\n";
    } else {
        $ko++;
        echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$bo = ['franchisee' => ['secret' => str_repeat('a', 64)], 'franchisor' => ['secret' => str_repeat('b', 64)], 'cookie_secure' => true];
$tmp = sys_get_temp_dir() . '/cb-test-' . bin2hex(random_bytes(3));
mkdir($tmp . '/api/bo', 0777, true);

echo "Insertion du bloc « bo » dans config.php\n";
$cas = [
    'virgule finale' => "<?php\n// conf\nreturn [\n  'db' => ['host' => 'h'],\n  'admin_token' => 'x',\n];\n",
    'sans virgule finale' => "<?php\nreturn [\n  'db' => ['host' => 'h'],\n  'admin_token' => 'x'\n];\n",
    'getenv et commentaires' => "<?php\n/* c */\nreturn [\n  'db' => ['host' => getenv('H') ?: 'h'], // fin\n  'cors' => array_values(array_filter(['a'])),\n];\n",
];
foreach ($cas as $nom => $src) {
    $new = cbInsertBo($src, $bo);
    file_put_contents($tmp . '/c.php', (string) $new);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/c.php') . ' 2>&1', $o, $rc);
    file_put_contents($tmp . '/o.php', $src);
    $a = cbReadConfig($tmp . '/c.php');
    $b = cbReadConfig($tmp . '/o.php');
    verifie($nom . ' : syntaxe, mêmes clés + bo', $rc === 0 && $a !== null && cbHasBo($a) && json_encode(array_diff_key($a, ['bo' => 1])) === json_encode($b), (string) $new);
}
verifie('forme inattendue (return $c;) ⇒ refus', cbInsertBo("<?php\n\$c = [];\nreturn \$c;\n", $bo) === null);
verifie('secrets factices de l’exemple ⇒ pas « en place »', !cbHasBo(['bo' => ['franchisee' => ['secret' => 'change-me-franchisee-32o-min'], 'franchisor' => ['secret' => 'change-me-franchisor-32o-min']]]));

$dsn = (string) (getenv('CB_TEST_DSN') ?: '');
if ($dsn !== '') {
    echo "Activation complète (fausse console + base locale)\n";
    $pdo = new PDO($dsn, (string) getenv('CB_TEST_USER'), (string) getenv('CB_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['bo_user_shops', 'bo_users', 'shops'] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS $t");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    // les tables de la console telles que dans WebShop backend/schema/ws_schema.sql
    $pdo->exec("CREATE TABLE shops (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO shops (id, name) VALUES (2, 'Corbais'), (3, 'Gosselies'), (5, 'Sombreffe')");
    $pdo->exec("CREATE TABLE bo_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(200) NOT NULL, password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(120), role ENUM('siege','franchise') NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at TIMESTAMP NULL DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_bo_email (email)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE bo_user_shops (user_id INT NOT NULL, shop_id INT NOT NULL, PRIMARY KEY (user_id, shop_id),
        FOREIGN KEY (user_id) REFERENCES bo_users(id), FOREIGN KEY (shop_id) REFERENCES shops(id)) ENGINE=InnoDB");
    $demo = password_hash('Test1234!', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO bo_users (email, password_hash, display_name, role) VALUES ('siege@atelierby.be', ?, 'Démo', 'siege'), ('franchise@atelierby.be', ?, 'Démo', 'franchise'), ('vrai@x.be', ?, 'Vrai', 'franchise')")
        ->execute([$demo, $demo, password_hash('un-vrai-mot-de-passe-long', PASSWORD_BCRYPT)]);

    // fausse API de la console : /health et /bo/franchisee/me se comportent comme WebShop
    preg_match('/dbname=([^;]+)/', $dsn, $m);
    preg_match('/host=([^;]+)/', $dsn, $h);
    $conf = "<?php\nreturn [\n  'db' => ['host' => '" . $h[1] . "', 'port' => '3306', 'name' => '" . $m[1] . "', 'user' => '" . getenv('CB_TEST_USER') . "', 'pass' => '" . getenv('CB_TEST_PASSWORD') . "'],\n  'admin_token' => 'x'\n];\n";
    file_put_contents($tmp . '/api/config.php', $conf);
    file_put_contents($tmp . '/api/lib.php', "<?php\nfunction cfg() { static \$c; if (\$c === null) \$c = require __DIR__ . '/config.php'; return \$c; }\n");
    file_put_contents($tmp . '/api/bo/guard.php', "<?php\nfunction bo_cfg(\$bo) { return cfg()['bo'][\$bo] ?? null; }\n");
    file_put_contents($tmp . '/api/index.php', "<?php\nrequire __DIR__ . '/lib.php'; require __DIR__ . '/bo/guard.php';\n\$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        . "if (str_ends_with(\$p, '/health')) { http_response_code(200); echo '{\"ok\":true}'; return; }\n"
        . "if (str_ends_with(\$p, '/bo/franchisee/me')) { http_response_code(bo_cfg('franchisee') ? 401 : 500); return; }\nhttp_response_code(404);\n");
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $tmp . '/api/index.php'], [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    usleep(500000);
    $base = 'http://127.0.0.1:' . $port . '/webshop/api';
    $run = static function (string $accounts = '') use ($ROOT, $tmp, $base): string {
        return (string) shell_exec('CB_OPCACHE_WAIT=0 WEBSHOP_API_LOCAL=' . escapeshellarg($base) . ' CONSOLE_BO_ACCOUNTS=' . escapeshellarg($accounts)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/bin/console_bo.php') . ' --api-dir=' . escapeshellarg($tmp . '/api') . ' 2>&1');
    };
    verifie('avant : /bo/franchisee/me rend 500 (non configuré)', cbHealth($base . '/bo/franchisee/me') === 500);
    $out = $run();
    $cfg = cbReadConfig($tmp . '/api/config.php');
    verifie('section « bo » ajoutée, deux secrets distincts', cbHasBo($cfg) && $cfg['bo']['franchisee']['secret'] !== $cfg['bo']['franchisor']['secret'] && $cfg['bo']['cookie_secure'] === true, $out);
    verifie('après : /bo/franchisee/me rend 401', cbHealth($base . '/bo/franchisee/me') === 401);
    verifie('sauvegarde de l’ancien config.php', count(glob($tmp . '/api/config.php.avant-bo-*')) === 1);
    verifie('journal : ni secret ni e-mail', !str_contains($out, $cfg['bo']['franchisee']['secret']) && !str_contains($out, '@'), $out);
    $actifs = $pdo->query("SELECT email FROM bo_users WHERE active = 1 ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
    verifie('comptes au mot de passe de démo publié désactivés, le vrai compte gardé', $actifs === ['vrai@x.be'], json_encode($actifs));
    $avant = file_get_contents($tmp . '/api/config.php');
    $out2 = $run();
    verifie('deuxième passage : rien ne change', file_get_contents($tmp . '/api/config.php') === $avant && str_contains($out2, 'déjà présente'), $out2);

    $comptes = json_encode([
        ['email' => 'Sam@Exemple.be', 'name' => 'Sam (siège)', 'role' => 'siege', 'password' => 'mot-de-passe-solide-1'],
        ['email' => 'julie@exemple.be', 'name' => 'Julie', 'role' => 'franchise', 'shops' => [2, 3], 'password' => 'mot-de-passe-solide-2'],
        ['email' => 'court@exemple.be', 'role' => 'franchise', 'shops' => [2], 'password' => 'court'],
        ['email' => 'inconnue@exemple.be', 'role' => 'franchise', 'shops' => [99], 'password' => 'mot-de-passe-solide-3'],
    ]);
    $out3 = $run($comptes);
    $sam = $pdo->query("SELECT * FROM bo_users WHERE email = 'sam@exemple.be'")->fetch(PDO::FETCH_ASSOC);
    $julie = $pdo->query("SELECT * FROM bo_users WHERE email = 'julie@exemple.be'")->fetch(PDO::FETCH_ASSOC);
    $shops = $pdo->query('SELECT shop_id FROM bo_user_shops WHERE user_id = ' . (int) $julie['id'] . ' ORDER BY shop_id')->fetchAll(PDO::FETCH_COLUMN);
    verifie('siège créé (e-mail en minuscules), mot de passe haché', $sam && $sam['role'] === 'siege' && password_verify('mot-de-passe-solide-1', $sam['password_hash']));
    verifie('franchisé créé avec ses boutiques', $julie && $julie['role'] === 'franchise' && array_map('intval', $shops) === [2, 3], json_encode($shops));
    verifie('refus : mot de passe court, boutique inconnue', str_contains($out3, 'compte n° 3 : mot de passe trop court') && str_contains($out3, 'compte n° 4 : boutique inconnue : 99'), $out3);
    verifie('journal : ni e-mail ni mot de passe', !str_contains($out3, '@') && !str_contains($out3, 'solide'), $out3);
    $h = $julie['password_hash'];
    $run($comptes);
    $j2 = $pdo->query("SELECT password_hash FROM bo_users WHERE email = 'julie@exemple.be'")->fetchColumn();
    verifie('repasser le même secret ne change pas le hachage', $j2 === $h);
    $run((string) json_encode([['email' => 'julie@exemple.be', 'name' => 'Julie', 'role' => 'franchise', 'shops' => [5]]]));
    $shops = $pdo->query('SELECT shop_id FROM bo_user_shops WHERE user_id = ' . (int) $julie['id'])->fetchAll(PDO::FETCH_COLUMN);
    verifie('boutiques remplacées, mot de passe gardé sans en redonner', array_map('intval', $shops) === [5] && password_verify('mot-de-passe-solide-2', (string) $pdo->query("SELECT password_hash FROM bo_users WHERE email = 'julie@exemple.be'")->fetchColumn()));

    // une config qui casse la console : l'ancienne est remise
    file_put_contents($tmp . '/api/config.php', $conf);
    file_put_contents($tmp . '/api/index.php', "<?php\nrequire __DIR__ . '/lib.php';\n\$c = cfg();\nif (isset(\$c['bo'])) { http_response_code(500); return; }\nhttp_response_code(200);\n");
    $out4 = $run();
    verifie('si /health change après l’ajout : ancienne configuration remise', file_get_contents($tmp . '/api/config.php') === $conf && str_contains($out4, 'ancienne configuration remise'), $out4);
    proc_terminate($proc);
}

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko === 0 ? 0 : 1);
