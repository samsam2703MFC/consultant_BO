<?php
declare(strict_types=1);

function expectNotification(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$migration = file_get_contents(__DIR__ . '/../migrations/034_app_notifications.sql');
$banner = file_get_contents(__DIR__ . '/../App/Views/page_components/app_notifications.view.php');
$twigLayout = file_get_contents(__DIR__ . '/../App/Views/layouts/base.twig');
$twigBanner = file_get_contents(__DIR__ . '/../App/Views/page_components/app_notifications.twig');
$orderService = file_get_contents(__DIR__ . '/../App/Services/Client/Order/OrderService.php');
$complaintService = file_get_contents(__DIR__ . '/../App/Services/Client/Complaint/ComplaintService.php');

expectNotification(str_contains($migration, 'app_notification'), 'Persistent notification table is required.');
expectNotification(!str_contains($migration, 'user_id'), 'Global notifications must not depend on users or sessions.');
expectNotification(str_contains($banner, 'setInterval(refresh, 20000)'), 'Banner must poll while the page stays open.');
expectNotification(str_contains($banner, 'data-notification-read') && str_contains($banner, 'data-notification-dismiss'), 'Banner must support read and dismiss actions.');
expectNotification(str_contains($banner, 'data-notifications-read-all'), 'Banner must support marking all notifications as read.');
expectNotification(str_contains($twigLayout, 'page_components/app_notifications.twig'), 'Twig pages must include the global notification banner.');
expectNotification(str_contains($twigBanner, 'setInterval(refresh, 20000)'), 'Twig notification banner must poll while the page stays open.');
expectNotification(str_contains($twigBanner, 'data-notifications-read-all'), 'Twig banner must support marking all notifications as read.');
expectNotification(str_contains($orderService, "'new_order'"), 'Order importer must publish a notification.');
expectNotification(str_contains($complaintService, "'new_complaint'"), 'Complaint importer must publish a notification.');

echo "app_notification_banner_contract_test: OK\n";
