#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Rôles du module « Gestion de production atelier » pour les comptes de l'ERP.
 *
 *   php Bin/tfb_role.php list
 *   php Bin/tfb_role.php grant  <login> <rôle> [CODE_GROUPE]
 *   php Bin/tfb_role.php revoke <login> <rôle> [CODE_GROUPE]
 *
 * Rôles : prod_admin, prod_manager, team_lead (souvent limité à un groupe), direction, operator.
 * Révoquer un rôle révoque aussi les jetons de renouvellement de la personne.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../public/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$command = $argv[1] ?? 'list';
$pdo = (new App\Core\Database())->getConnection();

try {
    if ($command === 'list') {
        $rows = $pdo->query('SELECT u.login, ur.role_code, g.code AS group_code
                             FROM n_prod_user_role ur JOIN user u ON u.id = ur.id_user
                             LEFT JOIN n_prod_group g ON g.id = ur.id_group ORDER BY u.login, ur.role_code')->fetchAll();
        foreach ($rows as $r) printf("%-30s %-14s %s\n", $r['login'], $r['role_code'], $r['group_code'] ?? '(tous les groupes)');
        exit(0);
    }
    if (!in_array($command, ['grant', 'revoke'], true) || !isset($argv[2], $argv[3])) {
        fwrite(STDERR, "Usage: php Bin/tfb_role.php list | grant|revoke <login> <role> [GROUP_CODE]\n");
        exit(1);
    }

    $user = $pdo->prepare('SELECT id FROM user WHERE login = ?');
    $user->execute([$argv[2]]);
    $userId = $user->fetchColumn();
    if ($userId === false) throw new RuntimeException("Unknown ERP login: {$argv[2]}");

    $role = $pdo->prepare('SELECT 1 FROM n_prod_role WHERE code = ?');
    $role->execute([$argv[3]]);
    if (!$role->fetchColumn()) throw new RuntimeException("Unknown role: {$argv[3]}");

    $groupId = null;
    if (isset($argv[4])) {
        $group = $pdo->prepare('SELECT id FROM n_prod_group WHERE code = ?');
        $group->execute([strtoupper($argv[4])]);
        $groupId = $group->fetchColumn();
        if ($groupId === false) throw new RuntimeException("Unknown group code: {$argv[4]}");
    }

    if ($command === 'grant') {
        $exists = $pdo->prepare('SELECT 1 FROM n_prod_user_role WHERE id_user = ? AND role_code = ? AND id_group <=> ?');
        $exists->execute([$userId, $argv[3], $groupId]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (?, ?, ?)')->execute([$userId, $argv[3], $groupId]);
        }
        echo "granted {$argv[3]} to {$argv[2]}\n";
    } else {
        $pdo->prepare('DELETE FROM n_prod_user_role WHERE id_user = ? AND role_code = ? AND id_group <=> ?')->execute([$userId, $argv[3], $groupId]);
        $pdo->prepare('UPDATE n_prod_refresh_token SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id_user = ?')->execute([$userId]);
        echo "revoked {$argv[3]} from {$argv[2]} (refresh tokens revoked)\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'tfb_role: ' . $e->getMessage() . "\n");
    exit(1);
}
