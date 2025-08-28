<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service\ScheduledTask;


use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use \Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Content\Product\ProductEvents;

#[AsMessageHandler(handles: StockUpdateTask::class)]
class OrderUpdateTaskHandler extends ScheduledTaskHandler

{
    private const LIMIT = 10;
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;

    public function __construct(
        private readonly MergeGuestAccountService $mergeGuestAccountService,
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly LoggerInterface $logger
    ) {
        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    private function getApiHeaders(): array
    {
        return [
            'Accept-Encoding' => 'gzip,deflate',
            'Content-Type' => 'application/json',
            'ApiKey' => $this->apiKey,
            'Username' => $this->apiUser,
        ];
    }

    private function decodeSilvasoftResponse(string $content): array|string|null
    {
        $decoded = @gzdecode($content) ?: $content;
        return json_decode($decoded, true) ?? $decoded;
    }

   public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void{
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();
        $context = $event->getContext();

        $criteria = new Criteria([$order->getId()]);
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('orderCustomer.customer');

        $orderFromDb = $this->orderRepository->search($criteria, $context)->first();

        if (!$orderFromDb) {
            $this->logToFile('Order not found in repository', ['orderId' => $order->getId()]);
            return;
        }

        $billingAddress = $orderFromDb->getBillingAddress();
        $delivery = $order->getDeliveries()?->first();
        $shippingAddress = $delivery?->getShippingOrderAddress();

        if (!$billingAddress || !$shippingAddress) {
            $this->logToFile('Missing billing or shipping address', ['orderId' => $order->getId()]);
            return;
        }

        $email = strtolower($customer->getEmail());
        $customerNumber = $orderFromDb->getOrderCustomer()?->getCustomer()?->getCustomerNumber() ?? 'GUEST-' . $order->getOrderNumber();

        $checkCustomerPayload = ["Email" => $email];

        try {
            $checkResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/checkrelation/', [
                'headers' => $this->getApiHeaders(),
                'json' => $checkCustomerPayload
            ]);

            $checkData = $this->decodeSilvasoftResponse($checkResponse->getContent(false));

            if (isset($checkData['RelationFound']) && $checkData['RelationFound'] === false) {
                $customerPayload = [
                    "Address_City" => $billingAddress->getCity(),
                    "Address_Street" => $billingAddress->getStreet(),
                    "Address_PostalCode" => $billingAddress->getZipcode(),
                    "Address_CountryCode" => $billingAddress->getCountry()?->getIso() ?? '',
                    "IsCustomer" => true,
                    "CustomerNumber" => $customerNumber,
                    "OnExistingRelationEmail" => "ABORT",
                    "OnExistingRelationNumber" => "ABORT",
                    "Relation_Contact" => [[
                        "Email" => $email,
                        "Phone" => $billingAddress->getPhoneNumber() ?? '',
                        "FirstName" => $customer->getFirstName(),
                        "LastName" => $customer->getLastName()
                    ]]
                ];

                $addCustomerResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                    'headers' => $this->getApiHeaders(),
                    'json' => $customerPayload
                ]);

                $this->decodeSilvasoftResponse($addCustomerResponse->getContent(false));
                $this->logToFile('Customer added to Silvasoft during order sync.');
            }
        } catch (\Throwable $e) {
            $this->logToFile('Error checking or adding customer to Silvasoft', [
                'error' => $e->getMessage(),
                'payload' => $checkCustomerPayload
            ]);
            return;
        }

        $customerComment = $order->getCustomerComment() ?? '';
        $paymentMethod = $order->getTransactions()->first()?->getPaymentMethod()?->getName() ?? '';
        $salesChannel = $orderFromDb->getSalesChannel()?->getTranslation('name') ?? '';
        $formattedOrderDate = $order->getCreatedAt()?->format('Y-m-d') ?? (new \DateTime())->format('Y-m-d');

        $invoicePayload = [
            "CustomerEmail" => $email,
            "InvoiceNotes" =>
                (!empty(trim($customerComment)) ? "<h3>Customer Comment: " . nl2br(htmlspecialchars($customerComment)) . "</h3><br>" : '') .
                "<b>Paymentmethod:</b> " . $paymentMethod . "<br>" .
                "<b>OrderNumber:</b> " . $order->getOrderNumber() . "<br>" .
                "<b>SalesChannel:</b> " . $salesChannel . "<br>" .
                "<b>Order Synced:</b> " . (new \DateTime())->format('c') . "<br>",
            "InvoiceReference" => $order->getOrderNumber(),
            "InvoiceDate" => $formattedOrderDate,
            "Invoice_Contact" => [[
                "ContactType" => "Invoice",
                "Email" => $email,
                "FirstName" => ucwords(strtolower($customer->getFirstName())),
                "LastName" => ucwords(strtolower($customer->getLastName())),
                "DefaultContact" => true
            ]],
            "Invoice_InvoiceLine" => array_values(array_map(function ($lineItem) {
                $price = $lineItem->getPrice();
                $unitPriceGross = $price->getUnitPrice();
                $taxRate = $price->getCalculatedTaxes()->first()?->getTaxRate() ?? 21;
                $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));
                $productNumber = $lineItem->getPayload()['productNumber'] ?? 'UNK';

                return [
                    "ProductNumber" => $productNumber,
                    "Quantity" => $lineItem->getQuantity(),
                    "TaxPc" => $taxRate,
                    "UnitPriceExclTax" => round($unitPriceNet, 2),
                    "Description" => $lineItem->getLabel() && trim($lineItem->getLabel()) !== '' ? $lineItem->getLabel() : 'UNK'
                ];
            }, $order->getLineItems()->getElements())),
            "Invoice_Address" => [
                [
                    "Address_Street" => ucwords(strtolower($billingAddress->getStreet() ?? '')),
                    "Address_City" => ucwords(strtolower($billingAddress->getCity() ?? '')),
                    "Address_PostalCode" => $billingAddress->getZipcode() ?? '',
                    "Address_CountryCode" => strtoupper($billingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address_Type" => "InvoiceAddress"
                ],
                [
                    "Address_Street" => ucwords(strtolower($shippingAddress->getStreet() ?? '')),
                    "Address_City" => ucwords(strtolower($shippingAddress->getCity() ?? '')),
                    "Address_PostalCode" => $shippingAddress->getZipcode() ?? '',
                    "Address_CountryCode" => strtoupper($shippingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address_Type" => "ShippingAddress"
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                'headers' => $this->getApiHeaders(),
                'json' => $invoicePayload
            ]);

            $json = $this->decodeSilvasoftResponse($response->getContent(false));
            $invoiceNumber = $json['InvoiceNumber'] ?? ($json[0]['InvoiceNumber'] ?? null);

            if (!$invoiceNumber) {
                $this->logToFile('InvoiceNumber not found in response.', ['response' => $json]);
                return;
            }

            $customFields = $orderFromDb->getCustomFields() ?? [];
            $this->orderRepository->upsert([
                [
                    'id' => $order->getId(),
                    'customFields' => array_merge($customFields, [
                        'silvasoft_ordernumber' => $invoiceNumber
                    ])
                ]
            ], $context);

            $this->logToFile('Order successfully synced to Silvasoft.'); // Success: no payload
        } catch (\Throwable $e) {
            $this->logToFile('Order sync to Silvasoft failed', [
                'error' => $e->getMessage(),
                'payload' => $invoicePayload
            ]);
        }
    }

    /**
    * Custom file logger to write to var/log/silvasoft/OrderTask-YYYY-MM-DD.log
    */
    private function logToFile(string $message, array $context = []): void
    {
        $date = (new \DateTime())->format('Y-m-d');
        $logDir = dirname(__DIR__) . '/../../../../var/log/silvasoft/';
        //$logDir = $this->kernel->getProjectDir() . '/var/log/silvasoft';
        $logFile = $logDir . "/OrderTask-{$date}.log";

        $filesystem = new Filesystem();
        if (!$filesystem->exists($logDir)) {
            $filesystem->mkdir($logDir, 0775);
        }

        $entry = '[' . (new \DateTime())->format('c') . '] ' . $message;
        if (!empty($context)) {
            $entry .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $entry .= PHP_EOL;
        file_put_contents($logFile, $entry, FILE_APPEND);
    }
    }
}
