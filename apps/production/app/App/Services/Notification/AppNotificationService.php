<?php

namespace App\Services\Notification;

use App\Exceptions\CustomException;
use App\Repositories\Notification\AppNotificationRepository;

class AppNotificationService
{
    public function __construct(private AppNotificationRepository $repository) {}

    public function publish(string $type, string $severity, string $title, string $body, ?string $linkUrl, array $payload, string $dedupKey): void
    {
        if (!in_array($severity, ['info', 'warning', 'danger'], true)) {
            throw new \InvalidArgumentException('Invalid notification severity.');
        }
        $this->repository->insertOnce([
            'type' => $type,
            'severity' => $severity,
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'link_url' => $linkUrl,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dedup_key' => mb_substr($dedupKey, 0, 191),
            'expires_at' => null,
        ]);
    }

    public function active(): array
    {
        return array_map(static function (array $notification): array {
            $notification['payload'] = json_decode($notification['payload_json'] ?? '{}', true) ?: [];
            unset($notification['payload_json']);
            return $notification;
        }, $this->repository->getActive());
    }

    public function acknowledge(int $id, string $via): void
    {
        if (!in_array($via, ['read', 'dismiss'], true)) {
            throw new CustomException('INVALID_NOTIFICATION_ACKNOWLEDGEMENT', 'errors', 422);
        }
        if (!$this->repository->first(['id' => $id])) {
            throw new CustomException('NOTIFICATION_NOT_FOUND', 'errors', 404);
        }
        if (!$this->repository->acknowledge($id, $via)) {
            throw new CustomException('NOTIFICATION_ACKNOWLEDGEMENT_FAILED', 'errors', 500);
        }
    }

    public function acknowledgeAll(): void
    {
        if ($this->repository->acknowledgeAll('read') === false) {
            throw new CustomException('NOTIFICATIONS_ACKNOWLEDGEMENT_FAILED', 'errors', 500);
        }
    }
}
