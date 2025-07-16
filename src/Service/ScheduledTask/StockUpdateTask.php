<?php declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class StockUpdateTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'boodo.silvasoft.stock_update';
    }

    public static function getDefaultInterval(): int
    {
        return 900; // 15 minutes
    }
}