<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Content\Product\ProductDefinition;
use BoodoSyncSilvasoft\Service\StockSyncService;
use Shopware\Core\Framework\Context;

class StockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly StockSyncService $stockSyncService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Reagiert auf alle Änderungen an Produkten
            EntityWrittenEvent::class => 'onProductWritten',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        // Prüfen, ob es sich um die Produkt-Entität handelt
        if ($event->getEntityName() !== ProductDefinition::ENTITY_NAME) {
            return;
        }
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();
            // Prüfen, ob das Feld 'stock' geändert wurde
            if (array_key_exists('stock', $payload)) {
                // Push für das einzelne Produkt
                $context = $event->getContext();
                $this->stockSyncService->pushStockToSilvasoft($context);
                // Optional: Nur das betroffene Produkt pushen (kann im Service erweitert werden)
                break; // Nur einmal pushen pro Event
            }
        }
    }
}
