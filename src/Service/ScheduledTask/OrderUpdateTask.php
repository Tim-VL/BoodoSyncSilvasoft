<?php declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class OrderUpdateTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'boodo.silvasoft.order_update';
    }

    public static function getDefaultInterval(): int
    {
        return 900; // 15 minutes
    }
}
