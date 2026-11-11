<?php
declare(strict_types=1);

function expectNotification(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$migration = file_get_contents(__DIR__ . '/../migrations/034_app_notifications.sql');
$bell = file_get_contents(__DIR__ . '/../public/assets/js/notifications.js');
$twigNavbar = file_get_contents(__DIR__ . '/../App/Views/page_components/navbar.twig');
$footer = file_get_contents(__DIR__ . '/../App/Views/page_components/footer.view.php');
$twigLayout = file_get_contents(__DIR__ . '/../App/Views/layouts/base.twig');
$twigBanner = file_get_contents(__DIR__ . '/../App/Views/page_components/app_notifications.twig');
$orderService = file_get_contents(__DIR__ . '/../App/Services/Client/Order/OrderService.php');
$complaintService = file_get_contents(__DIR__ . '/../App/Services/Client/Complaint/ComplaintService.php');

expectNotification(str_contains($migration, 'app_notification'), 'Persistent notification table is required.');
expectNotification(!str_contains($migration, 'user_id'), 'Global notifications must not depend on users or sessions.');
expectNotification(str_contains($bell, 'setInterval(refresh, 20000)'), 'Notifications must poll while the page stays open.');
expectNotification(str_contains($bell, 'data-notification-read') && str_contains($bell, '/acknowledge'), 'Notifications must support the read action.');
expectNotification(str_contains($bell, '/ajax/app-notifications/acknowledge-all'), 'Notifications must support marking all notifications as read.');
expectNotification(str_contains($bell, "n.severity === 'danger'") && str_contains($bell, 'appNotificationBanner'), 'Urgent notifications must stay visible as a banner.');
// Pages PHP et Twig : header.view.php appelle view('page_components/navbar'), qui rend navbar.twig
expectNotification(str_contains($twigNavbar, 'id="atBell"') && !file_exists(__DIR__ . '/../App/Views/page_components/navbar.view.php'), 'PHP and Twig pages must show the notification bell (shared navbar.twig).');
expectNotification(str_contains($footer, 'page_components/app_notifications') && str_contains($twigLayout, 'page_components/app_notifications.twig'), 'Every page must load the notification script.');
expectNotification(str_contains($twigBanner, 'notifications.js'), 'The shared partial must load the notification script.');
expectNotification(str_contains($orderService, "'new_order'"), 'Order importer must publish a notification.');
expectNotification(str_contains($complaintService, "'new_complaint'"), 'Complaint importer must publish a notification.');

echo "app_notification_banner_contract_test: OK\n";
