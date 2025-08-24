<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Subscriber;

use BoodoSyncSilvasoft\Service\MergeGuestAccountService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Content\Product\ProductEntity;

class GeneralSubscriber implements EventSubscriberInterface
{
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

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            'state_machine.order.state_changed' => 'onOrderStateChanged',
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            CustomerRegisterEvent::class => 'onCustomerRegister',
        ];
    }

    private function getApiHeaders(): array
    {
        return [
            'Accept-Encoding' => 'gzip,deflate',
            'Content-Type' => 'application/json',
            'ApiKey' => $this->apiKey,
            'Username' => $this->apiUser
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();

        if ($customer->getCustomer()?->getGuest()) {
            $updatesCustomers = $this->mergeGuestAccountService->executeMerge();
        }

        $criteria = new Criteria([$order->getId()]);
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('orderCustomer');

        $orderFromDb = $this->orderRepository->search($criteria, $event->getContext())->first();
        if (!$orderFromDb) {
            $this->logger->error('Order not found.');
            return;
        }

        if ($customer->getCustomer()?->getGuest() && isset($updatesCustomers[$customer->getEmail()])) {
            $customer = $updatesCustomers[$customer->getEmail()];
        }

        $billingAddress = $orderFromDb->getBillingAddress();
        $shippingAddress = $order->getDeliveries()->first()?->getShippingOrderAddress();

        if (!$billingAddress || !$shippingAddress) {
            $this->logger->error('Missing address for order: ' . $order->getOrderNumber());
            return;
        }

        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials not set.');
            return;
        }

        try {
            $this->createSilvasoftCustomerFromOrderContext($customer, $billingAddress);
        } catch (\Throwable $e) {
            $this->logger->error('Customer create failed: ' . $e->getMessage());
        }

        $paymentMethod = $order->getTransactions()->first()?->getPaymentMethod()?->getName() ?? '';
        $salesChannel = $orderFromDb->getSalesChannel()?->getTranslated()['name'] ?? '';
        $customerComment = $order->getCustomerComment() ?? '';
        $formattedOrderDate = $order->getCreatedAt()?->format('Y-m-d') ?? (new \DateTime())->format('Y-m-d'); // add suggestion

        $payload = [
             "CustomerNumber" => $customer->getCustomerNumber(),
                "InvoiceNotes" =>
                    (!empty(trim($customerComment)) ? "<h3>Customer Comment: " . nl2br(htmlspecialchars($customerComment)) . "</h3>\n<br>\n" : '') .
                    "<b>Paymentmethod:</b> " . $order->getTransactions()->first()?->getPaymentMethod()?->getName() . " on " . (new \DateTime())->format('Y-m-d H:i:s') . "<br>\n" .
                    "<b>OrderNumber:</b> " . $order->getOrderNumber() . "<br>\n" .
                    "<b>SalesChannel:</b> " . $salesChannel . "<br>\n" ,
                "InvoiceReference" => $order->getOrderNumber(),
                "InvoiceDate" => $formattedOrderDate,
                "TemplateName_Invoice" => "Standaard template",
                "TemplateName_Email" => "Standaard template",
            "Invoice_Contact" => [
                [
                    "ContactType" => "Invoice",
                    "Email" => $customer->getEmail(),
                    "FirstName" => $customer->getFirstName(),
                    "LastName" => $customer->getLastName(),
                    "DefaultContact" => true
                ]
            ],
            "Invoice_InvoiceLine" => array_values(array_map(function ($lineItem) {
                $price = $lineItem->getPrice();
                $unitPriceGross = $price->getUnitPrice();
                $taxRules = $price->getCalculatedTaxes();
                $taxRate = $taxRules->first()?->getTaxRate() ?? 21;
                $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));

                $payload = $lineItem->getPayload();
                $productNumber = $payload['productNumber'] ?? 'UNKNOWN';

                return [
                    "ProductNumber" => $productNumber,
                    "Quantity" => $lineItem->getQuantity(),
                    "TaxPc" => $taxRate,
                    "UnitPriceExclTax" => round($unitPriceNet, 2),
                    "Description" => $lineItem->getDescription()
                ];
            }, $order->getLineItems()->getElements())),
            'Invoice_Address' => [
                [
                    'Address_Street' => $billingAddress->getStreet(),
                    'Address_City' => $billingAddress->getCity(),
                    'Address_PostalCode' => $billingAddress->getZipcode(),
                    'Address_CountryCode' => $billingAddress->getCountry()?->getIso() ?? 'unknown',
                    'Address_Type' => 'InvoiceAddress'
                ],
                [
                    'Address_Street' => $shippingAddress->getStreet(),
                    'Address_City' => $shippingAddress->getCity(),
                    'Address_PostalCode' => $shippingAddress->getZipcode(),
                    'Address_CountryCode' => $shippingAddress->getCountry()?->getIso() ?? 'unknown',
                    'Address_Type' => 'ShippingAddress'
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                'headers' => $this->getApiHeaders(),
                'json' => $payload
            ]);

            try {
                $content = $response->getContent(false);
                $decoded = @gzdecode($content) ?: $content;
                $data = json_decode($decoded, true);
            } catch (\Throwable $e) {
                $this->logger->error('Response decode failed: ' . $e->getMessage());
                return;
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('JSON decode error: ' . json_last_error_msg());
                return;
            }

            $invoiceNumber = $data['InvoiceNumber'] ?? ($data[0]['InvoiceNumber'] ?? null);
            if (!$invoiceNumber) {
                $this->logger->error('InvoiceNumber missing in response', ['response' => $data]);
                return;
            }

            $customFields = $order->getCustomFields() ?? [];
            $customFields['silvasoft_ordernumber'] = $invoiceNumber;

            $this->orderRepository->upsert([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields
                ]
            ], $event->getContext());

            $this->logger->info('Order sent to Silvasoft: ' . $order->getOrderNumber());
        } catch (\Throwable $e) {
            $this->logger->error('Silvasoft API request failed: ' . $e->getMessage());
        }
    }

    private function createSilvasoftCustomerFromOrderContext($orderCustomer, $billingAddress): void
    {
        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials are missing.');
            return;
        }

        $customerNumber = $orderCustomer->getCustomerNumber() ?: '';
        $salutation = $orderCustomer->getSalutation()?->getDisplayName();

        $payload = [
            "Address_City" => $billingAddress->getCity() ?: '',
            "Address_Street" => $billingAddress->getStreet() ?: '',
            "Address_PostalCode" => $billingAddress->getZipcode() ?: '',
            "Address_CountryCode" => $billingAddress->getCountry()?->getIso() ?: '',
            "IsCustomer" => true,
            "CustomerNumber" => $customerNumber,
            "OnExistingRelationEmail" => "UPDATE",
            "OnExistingRelationNumber" => "UPDATE",
           // "OnExistingRelationName" => "ABORT",
            "Relation_Contact" => [
                [
                    "Email" => $orderCustomer->getEmail(),
                    "Phone" => $billingAddress->getPhoneNumber(),
                    "FirstName" => $orderCustomer->getFirstName(),
                    "LastName" => $orderCustomer->getLastName(),
                ]
            ]
        ];

        $sex = $this->mapSalutationToSex($salutation);
        if ($sex !== null) {
            $payload['Relation_Contact'][0]['Sex'] = $sex;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                'headers' => $this->getApiHeaders(),
                'json' => $payload
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("Silvasoft customer sync error: " . $response->getContent(false));
            }
        } catch (\Exception $e) {
            $this->logger->error("Silvasoft customer sync exception: " . $e->getMessage());
        }
    }

    private function mapSalutationToSex(?string $salutation): ?string
    {
        $map = [
            'Herr' => 'Man', 'Mr.' => 'Man', 'Dhr.' => 'Man', 'De heer' => 'Man',
            'Frau' => 'Woman', 'Mrs.' => 'Woman', 'Mevr.' => 'Woman',
            'Mevrouw' => 'Woman', 'Fräulein' => 'Woman', 'Miss' => 'Woman',
            'Mej.' => 'Woman', 'Mejuffrouw' => 'Woman'
        ];
        return $map[$salutation] ?? null;
    }
}
