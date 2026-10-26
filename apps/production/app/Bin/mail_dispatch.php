#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * E-mails automatiques : transforme les nouveaux événements (commandes, livraisons prévues,
 * expéditions, WDT, factures, proformas) en messages en file selon les règles actives
 * (Komunikacja › Automatyczne e-maile), met en file le rapport mensuel de direction (une fois par
 * mois, si des destinataires sont configurés), publie les alertes d'échéances dans la cloche (réclamations sans
 * décision, essais de nouveaux produits en retard, coûts du mois non saisis), crée la version de départ des recettes
 * qui n'en ont pas encore (versions « en vigueur depuis »), alimente la file des écarts d'expédition, rafraîchit les
 * statuts KSeF en attente (si un token KSeF est configuré : l'e-mail « après acceptation KSeF » part sans ouvrir
 * Wysyłki), renvoie les domówienia remises en file (Stan wysyłek, « Ponów »), puis passe la file d'envoi.
 *   php Bin/mail_dispatch.php        à lancer par cron toutes les 5 minutes
 * L'envoi réel dépend de MAIL_DELIVERY_MODE (.env) : live, redirect ou log_only.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/erp_mail_dispatch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['skipped' => 'already running']) . PHP_EOL;
    exit(0);
}

try {
    // Statuts KSeF (factures envoyées en attente de réponse, UPO) : en premier : une WDT acceptée déclenche son e-mail dans le même passage.
    try {
        $ksef = $container->get(App\Services\Ksef\KsefSubmissionService::class)->refreshPendingStatusesIfConfigured();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch ksef: ' . $e->getMessage() . PHP_EOL);
        $ksef = ['error' => $e->getMessage()];
    }
    $dispatch = $container->get(App\Services\Communication\MailAutomationService::class)->dispatch();
    // Raport miesięczny zarządu : raz w miesiącu za poprzedni miesiąc (błąd raportu nie zatrzymuje wysyłki kolejki).
    try {
        $report = $container->get(App\Services\Analysis\MonthlyReportService::class)->autoSend();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch monthly report: ' . $e->getMessage() . PHP_EOL);
        $report = ['error' => $e->getMessage()];
    }
    // File des écarts d'expédition (différences proforma ↔ WDT, problèmes de livraison) : avant les alertes d'échéance.
    try {
        $shippingIssues = $container->get(App\Services\Shipping\ShippingIssueService::class)->sync();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch shipping issues: ' . $e->getMessage() . PHP_EOL);
        $shippingIssues = ['error' => $e->getMessage()];
    }
    // Alertes et échéances dans la cloche (réclamations sans décision, essais en retard, coûts du mois) : à part aussi.
    try {
        $alerts = $container->get(App\Services\Notification\DeadlineAlertService::class)->run();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch alerts: ' . $e->getMessage() . PHP_EOL);
        $alerts = ['error' => $e->getMessage()];
    }
    // Versions de recette : version de départ des recettes sans historique (rattrapage, à part aussi).
    try {
        $recipeVersions = $container->get(App\Services\Recipe\RecipeVersionService::class)->backfill();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch recipe versions: ' . $e->getMessage() . PHP_EOL);
        $recipeVersions = ['error' => $e->getMessage()];
    }
    // Domówienia remises en file depuis Stan wysyłek : une tentative de plus (à part aussi).
    try {
        $supplements = $container->get(App\Services\Client\Order\OrderSupplementPusherService::class)->pushRequeued();
    } catch (Throwable $e) {
        fwrite(STDERR, 'mail_dispatch supplements: ' . $e->getMessage() . PHP_EOL);
        $supplements = ['error' => $e->getMessage()];
    }
    $delivery = $container->get(App\Services\Communication\CommunicationEmailDeliveryWorker::class)->processDue(50);
    echo json_encode(['dispatch' => $dispatch, 'monthly_report' => $report, 'alerts' => $alerts, 'recipe_versions' => $recipeVersions, 'shipping_issues' => $shippingIssues, 'ksef' => $ksef, 'supplements' => $supplements, 'delivery' => $delivery], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'mail_dispatch: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
