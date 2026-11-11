<?php
/**
 * Chiffre en bcrypt les mots de passe des comptes de l'ERP encore en clair (table user). Idempotent : une valeur
 * déjà chiffrée n'est pas touchée ; les connexions continuent de fonctionner avec le même mot de passe.
 * Lancé à chaque déploiement (deploy/server.sh) ; à la main : php Bin/hash_passwords.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Security\Passwords;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    $pdo = (new App\Core\Database())->getConnection();
    $update = $pdo->prepare('UPDATE user SET password = ? WHERE id = ? AND password = ?');
    $done = 0;
    $total = 0;
    foreach ($pdo->query('SELECT id, password FROM user')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total++;
        $stored = (string) $row['password'];
        if ($stored === '' || Passwords::isHash($stored)) continue;
        $update->execute([Passwords::hash($stored), $row['id'], $stored]);
        $done += $update->rowCount();
    }
    echo "hash_passwords: {$done} compte(s) chiffré(s) sur {$total}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'hash_passwords: ' . $e->getMessage() . "\n");
    exit(1);
}
