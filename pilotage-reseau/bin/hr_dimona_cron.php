<?php

declare(strict_types=1);

/**
 * Le cron du module RH, lancé chaque minute par bin/hr_dimona_cron.sh (posé par bin/deploy.sh) :
 *
 *   1. relit les déclarations en attente à leur échéance (5 s, 15 s, 30 s, 1 min, 5 min, puis 15 min
 *      pendant 24 h — Hr\Dimona\Backoff). Il tourne jusqu'à 55 s pour tenir les premières échéances, qui
 *      tombent sous la minute ;
 *   2. signale les certificats qui expirent dans moins de 60 jours (une fois par jour et par compte), au
 *      journal du module ; l'écran Connexion ONSS en montre le badge.
 *
 *   php bin/hr_dimona_cron.php [--duree=55]
 */

use Hr\Auth\Identity;
use Hr\Repository\AuditLog;
use Hr\Repository\CredentialRepository;
use Hr\Repository\DeclarationRepository;
use Hr\Support\Clock;
use Hr\Support\Schema;

$app = require __DIR__ . '/../src/hr/bootstrap.php';
Schema::ensure();

$duree = 55;
foreach ($argv as $a) {
    if (preg_match('/^--duree=(\d+)$/', $a, $m)) {
        $duree = max(1, min(58, (int) $m[1]));
    }
}
$fin = time() + $duree;

// 2. certificats proches de l'expiration
$jours = (int) $app->config->get('certAlertDays');
foreach (CredentialRepository::expiring($jours) as $c) {
    $deja = Clock::ts($c['expiry_alert_at'] ?? null);
    if ($deja !== null && $deja > Clock::now() - 86400) {
        continue;
    }
    $exp = Clock::ts($c['certificate_expires_at']);
    $reste = $exp !== null ? (int) floor(($exp - Clock::now()) / 86400) : 0;
    AuditLog::add(Identity::system(), 'cert.expiry_alert', (int) $c['employer_id'], 'credential', (string) $c['id'],
        'certificat ' . $c['env'] . ($reste < 0 ? ' expiré' : ' : expire dans ' . $reste . ' jour(s)') . ($exp !== null ? ' (' . gmdate('d/m/Y', $exp) . ')' : ''));
    CredentialRepository::markAlerted((int) $c['id']);
    fwrite(STDOUT, '[hr] alerte certificat ' . $c['id'] . ' (' . $reste . " j)\n");
}

// 1. relectures
$total = 0;
while (time() < $fin) {
    $n = $app->dimona->pollDue(25);
    $total += $n;
    if ($n > 0) {
        continue;
    }
    $prochaine = DeclarationRepository::nextDueAt();
    $attente = $prochaine === null ? $fin - time() : $prochaine - time();
    if ($attente <= 0) {
        continue;
    }
    if (time() + $attente >= $fin) {
        break;
    }
    sleep(min($attente, 5));
}
if ($total > 0) {
    fwrite(STDOUT, '[hr] ' . $total . " relecture(s)\n");
}
