<?php

/**
 * EN: Test replacement for the optional Notification service in a standalone Workspace test.
 *
 * HR: Testna zamjena opcionalnog Notification servisa u samostalnom Workspace testu.
 */

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleNotification\Service;

/**
 * EN: Minimal optional Notification-service marker for the standalone Workspace test.
 *
 * HR: Minimalna oznaka opcionalnog Notification servisa za samostalni Workspace test.
 */
if (!class_exists(NotificationService::class)) {
    final class NotificationService
    {
    }
}
