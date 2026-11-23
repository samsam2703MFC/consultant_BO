<?php
/**
 * [372] Garde statique des contrôleurs : chaque appel « $this->prop->methode( » sur une dépendance injectée par le
 * constructeur (propriété promue typée) doit viser une méthode existante de la classe déclarée (ou d'une de ses
 * parentes / interfaces), et chaque « $this->methode( » une méthode du contrôleur ou de ses parents / traits.
 * Une méthode absente n'est vue qu'à l'exécution (erreur fatale → « Błąd serwera » ou page blanche) : ce test la voit avant.
 * Seuls les contrôleurs d'une route chargée (fichiers « Routes.php » des sous-dossiers de App/Core/Routes, comme routes.php) sont vérifiés ; les
 * commentaires sont ignorés. Sans base de données.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

$problems = [];
// Contrôleurs atteignables : cités par un fichier de routes chargé (FQCN, ou nom court résolu par ses « use »).
$routed = [];
foreach (glob(__DIR__ . '/../App/Core/Routes/*/*Routes.php') ?: [] as $rf) {
    $rs = (string) file_get_contents($rf);
    $ru = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $rs, $rum, PREG_SET_ORDER);
    foreach ($rum as $u) $ru[strtolower($u[2] ?? substr($u[1], strrpos($u[1], '\\') + 1))] = $u[1];
    preg_match_all('/([\\\\\w]+)::class/', $rs, $rc);
    foreach ($rc[1] as $c) {
        $c = ltrim($c, '\\');
        $routed[strtolower(str_contains($c, '\\') ? $c : ($ru[strtolower($c)] ?? $c))] = true;
    }
}
$checked = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../App/Controllers', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    // Sans commentaires : un appel commenté n'est pas exécuté.
    $src = '';
    foreach (token_get_all((string) file_get_contents($f->getPathname())) as $tok) $src .= is_array($tok) ? (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $tok[1]) : $tok;
    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns) || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cm)) continue;
    $class = $ns[1] . '\\' . $cm[1];
    if (!isset($routed[strtolower($class)])) continue;
    if (!class_exists($class)) { $problems[] = "{$cm[1]} : klasa się nie ładuje"; continue; }
    $uses = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $src, $um, PREG_SET_ORDER);
    foreach ($um as $u) $uses[strtolower($u[2] ?? substr($u[1], strrpos($u[1], '\\') + 1))] = $u[1]; // PHP : noms de classe sans casse
    $resolve = function (string $t) use ($uses, $ns): string {
        $t = ltrim($t, '?');
        if (str_starts_with($t, '\\')) return ltrim($t, '\\');
        $head = explode('\\', $t)[0];
        if (isset($uses[strtolower($head)])) return $uses[strtolower($head)] . substr($t, strlen($head));
        return $ns[1] . '\\' . $t;
    };
    // Propriétés typées : promues dans le constructeur, ou déclarées « private Type $x; ».
    $props = [];
    preg_match_all('/(?:private|protected|public)\s+(?:readonly\s+)?(\??[\\\\\w]+)\s+\$(\w+)/', $src, $pm, PREG_SET_ORDER);
    foreach ($pm as $p) {
        if (in_array(strtolower(ltrim($p[1], '?')), ['array', 'string', 'int', 'bool', 'float', 'mixed', 'callable', 'iterable', 'object', 'self', 'static'], true)) continue;
        $props[$p[2]] = $resolve($p[1]);
    }
    preg_match_all('/\$this->(\w+)->(\w+)\(/', $src, $calls, PREG_SET_ORDER);
    foreach ($calls as $c) {
        if (!isset($props[$c[1]])) continue;
        $type = $props[$c[1]];
        if (!class_exists($type) && !interface_exists($type)) { $problems[] = "{$cm[1]} : typ {$type} (\${$c[1]}) nie istnieje"; continue; }
        $checked++;
        if (!method_exists($type, $c[2]) && !method_exists($type, '__call')) $problems[] = "{$cm[1]} : \$this->{$c[1]}->{$c[2]}() — brak w " . $type;
    }
    preg_match_all('/\$this->(\w+)\(/', $src, $own);
    foreach (array_unique($own[1]) as $m) {
        $checked++;
        if (!method_exists($class, $m) && !method_exists($class, '__call')) $problems[] = "{$cm[1]} : \$this->{$m}() — brak metody";
    }
}
$problems = array_values(array_unique($problems));
foreach ($problems as $p) echo "FAIL {$p}\n";
echo ($problems ? count($problems) . ' FAIL' : 'all ok') . ' (' . count($routed) . " klas z tras, {$checked} wywołań)\n";
exit($problems ? 1 : 0);
