<?php
/**
 * Extraction best-effort des identifiants MySQL depuis le config du panel
 * consultant (config/db.local.php). Gère les trois formes courantes :
 *   (a) le fichier `return [...]` un tableau (éventuellement imbriqué) ;
 *   (b) il `define()` des constantes ;
 *   (c) il affecte des `$variables`.
 * Écrit sur stdout un JSON {host,port,name,user,password} avec ce qu'il a pu
 * trouver. N'échoue pas si le fichier a des effets de bord bénins.
 *
 * Usage : php extract_panel_db.php /chemin/vers/db.local.php
 */
error_reporting(0);
ini_set('display_errors', '0');

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "config introuvable\n");
    echo '{}';
    exit(0);
}

$constsBefore = get_defined_constants();
$ret = @include $file;
$constsAfter = get_defined_constants();
$vars = get_defined_vars();

$newConsts = array_diff_key($constsAfter, $constsBefore);

// Sources de recherche, par priorité : tableau retourné (+ 1 niveau
// d'imbrication), constantes nouvellement définies, variables locales.
$sources = [];
if (is_array($ret)) {
    $sources[] = $ret;
    foreach ($ret as $v) {
        if (is_array($v)) { $sources[] = $v; }
    }
}
$sources[] = $newConsts;
$sources[] = $vars;

$aliases = [
    'host'     => ['host', 'db_host', 'dbhost', 'hostname', 'server', 'mysql_host'],
    'port'     => ['port', 'db_port', 'dbport', 'mysql_port'],
    'name'     => ['name', 'dbname', 'db_name', 'database', 'db', 'mysql_db', 'schema'],
    'user'     => ['user', 'username', 'db_user', 'dbuser', 'login', 'mysql_user', 'uid'],
    'password' => ['password', 'pass', 'pwd', 'db_pass', 'dbpass', 'passwd', 'mysql_pass', 'secret'],
];

$pick = static function (array $bag, array $keys) {
    foreach ($keys as $wanted) {
        foreach ($bag as $k => $v) {
            if ((is_string($v) || is_int($v)) && strcasecmp((string) $k, $wanted) === 0) {
                $s = (string) $v;
                if ($s !== '') { return $s; }
            }
        }
    }
    return null;
};

$out = [];
foreach ($aliases as $field => $keys) {
    foreach ($sources as $src) {
        $v = $pick($src, $keys);
        if ($v !== null) { $out[$field] = $v; break; }
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
