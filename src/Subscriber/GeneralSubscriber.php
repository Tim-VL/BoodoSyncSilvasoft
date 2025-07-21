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
        // Get API key and credentials from Shopware config
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

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event)
    {
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();

        if ($customer->getCustomer()->getGuest()) {
            $updatesCustomers = $this->mergeGuestAccountService->executeMerge();
        }

        $criteria = new Criteria([$order->getId()]);
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('orderCustomer');

        /** @var OrderEntity|null $orderFromDb */
        $orderFromDb = $this->orderRepository->search($criteria, $event->getContext())->first();

        if (!$orderFromDb) {
            $this->logger->error('Order not found.');
            return;
        }

        if ($customer->getCustomer()->getGuest() && isset($updatesCustomers[$customer->getEmail()])) {
            $customer = $updatesCustomers[$customer->getEmail()];
        }

        $billingAddress = $orderFromDb->getBillingAddress();
        $shippingAddress = $order->getDeliveries()->first() ? $order->getDeliveries()->first()->getShippingOrderAddress() : null;

        if (!$billingAddress || !$shippingAddress) {
            $this->logger->error('Billing or Shipping address is missing for order ID: ' . $order->getOrderNumber());
            return;
        }

        $orderStatus = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';

        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials not set.');
            return;
        }

        // Convert order to Silvasoft format
        $payload = [
            "CustomerNumber" => $customer->getCustomerNumber(),
            "InvoiceNotes" => $order->getOrderNumber(),
            "InvoiceReference" => $order->getOrderNumber(),
             //  "OrderStatus" => $orderStatus,
            "TemplateName_Invoice" => "Standaard template",
            "TemplateName_Email" => "Standaard template",
            // "TemplateName_Order" => "Standaard template",
            // "TemplateName_PackingSlip" => "Standaard template",
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
                /** @var CalculatedPrice $price */
                $price = $lineItem->getPrice();
                $unitPriceGross = $price->getUnitPrice();
                $taxRules = $price->getCalculatedTaxes();
                $taxPercentage = $taxRules ? $taxRules->first()?->getTaxRate() : 21;

                $taxRate = $taxRules->first() ? $taxRules->first()->getTaxRate() : 21;
                $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));

                return [
                    "ProductNumber" => $lineItem->getPayload()['productNumber'],
                    "Quantity" => $lineItem->getQuantity(),
                    "TaxPc" => $taxPercentage,
                    "UnitPriceExclTax" => round($unitPriceNet, 2),
                    "Description" => $lineItem->getDescription()
                ];
            }, $order->getLineItems()->getElements())),
            'Invoice_Address' => [
                [
                    'Address_Street' => $billingAddress->getStreet(),
                    'Address_City' => $billingAddress->getCity(),
                    'Address_PostalCode' => $billingAddress->getZipcode(),
                    'Address_CountryCode' => !empty($billingAddress->getCountry()->getIso()) ? $billingAddress->getCountry()->getIso() : 'unknown',
                    'Address_Type' => 'InvoiceAddress'
                ],
                [
                    'Address_Street' => $shippingAddress->getStreet(),
                    'Address_City' => $shippingAddress->getCity(),
                    'Address_PostalCode' => $shippingAddress->getZipcode(),
                    'Address_CountryCode' => $shippingAddress->getCountry()->getIso(),
                    'Address_Type' => 'ShippingAddress'
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', (string) $this->apiUrl . '/rest/addsalesinvoice/', [
                'headers' => [
                    'Accept-Encoding' => 'gzip,deflate',
                    'Content-Type' => 'application/json',
                    'ApiKey' => $this->apiKey,
                    'Username' => $this->apiUser
                ],
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->error('Silvasoft API error: ' . $response->getContent(false));
            } else {
                $content = $response->getContent(false);
                $uncompressed = gzdecode($content);
                $data = json_decode($content, true);
                if ($uncompressed === false) {
                    $this->logger->error('API response decompression failed');
                } else {
                    $data = json_decode($uncompressed, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->error('JSON-Error: ' . json_last_error_msg());
                    } else {
                        if (isset($data['OrderNumber'])) {
                            $customFields = $order->getCustomFields() ?? [];
                            $customFields['silvasoft_ordernumber'] = $data['OrderNumber'];

                            $this->orderRepository->upsert([
                                [
                                    'id' => $order->getId(),
                                    'customFields' => $customFields
                                ]
                            ], $event->getContext());
                        }
                    }
                }
                $this->logger->info('Order successfully sent to Silvasoft: ' . $order->getOrderNumber());
            }
        } catch (\Exception $e) {
            $this->logger->error('Silvasoft API request failed: ' . $e->getMessage());
        }
    }

    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransition()->getEntityName() === 'order') {
            $orderId = $event->getTransition()->getEntityId();

            $context = $this->getContext($orderId, $event->getContext());
            $order = $this->getOrder($orderId, $context);

            $newState = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';

            // Check Custom Field
            $customFields = $order->getCustomFields() ?? [];
            $silvasoftOrderNumber = $customFields['silvasoft_ordernumber'] ?? null;

            if (!$silvasoftOrderNumber) {
                $this->logger->debug(
                    'Silvasoft update skipped - No order number for: ' . $order->getOrderNumber()
                );
                return;
            }

            // API-Call
            $this->updateSilvasoftOrder(
                (string) $silvasoftOrderNumber,
                $order->getOrderNumber(),
                $newState
            );
        }
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();
            // Check if it is a new product
            if (!isset($payload['id'])) {
                continue;
            }

            $productId = $payload['id'];
            $product = $this->fetchProductData($productId, $event->getContext());

            if (!$product) {
                $this->logger->error("Product not found: " . $productId);
                continue;
            }

            if ($product->getParentId()) {
                $mainProduct = $this->getProductById($product->getParentId(), $event->getContext());

                $productNumber = $product->getProductNumber();
                $name = $product->getTranslation('name') ? $product->getTranslation('name') : $mainProduct->getTranslation('name');
                $price = $product->getPrice() ? $product->getPrice()->first()->getNet() : $mainProduct->getPrice()->first()->getNet();
                $categoryName = $this->fetchCategoryName($product);
                $mainEan = $mainProduct->getEan() ? $mainProduct->getEan() : '';
                $ean = $product->getEan() ? $product->getEan() : $mainEan;

                $mainUnit = $mainProduct->getUnit() ? $mainProduct->getUnit()->getTranslated()['name'] : '';
                $unit = $product->getUnit() ? $product->getUnit()->getTranslated()['name'] : $mainUnit;
                $mainVat = $mainProduct->getTax() ? $mainProduct->getTax()->getTaxRate() : 21;
                $vat = $product->getTax() ? $product->getTax()->getTaxRate() : $mainVat;

                $mainDescription = $mainProduct->getTranslated()['description'] ? $mainProduct->getTranslated()['description'] : '';
                $description = $product->getTranslated()['description'] ? $product->getTranslated()['description'] : $mainDescription;
            } else {
                $productNumber = $product->getProductNumber();
                $name = $product->getTranslation('name');
                $price = $product->getPrice()->first()->getNet();
                $categoryName = $this->fetchCategoryName($product);
                $ean = $product->getEan() ? $product->getEan() : '';

                $unit = $product->getUnit() ? $product->getUnit()->getTranslated()['name'] : '';
                $vat = $product->getTax() ? $product->getTax()->getTaxRate() : 21;
                $description = $product->getTranslated()['description'] ? $product->getTranslated()['description'] : '';
            }

            $silvasoftPayload = [
                "ArticleNumber" => $productNumber,
                "NewName" => $name,
                "CategoryName" => $categoryName,
                "EAN" => $ean,
                "NewSalePrice" => $price,
                "NewDescription" => $description,
                "NewUnit" => $unit,
                "NewVATPercentage" => $vat,
            ];

            try {
                if ($writeResult->getOperation() === 'insert' && isset($payload['productNumber'])) {
                    $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addproduct/', [
                        'headers' => [
                            'Accept-Encoding' => 'gzip,deflate',
                            'Content-Type' => 'application/json',
                            'ApiKey' => $this->apiKey,
                            'Username' => $this->apiUser
                        ],
                        'json' => $silvasoftPayload
                    ]);
                }
                if ($writeResult->getOperation() === 'update') {
                    $response = $this->httpClient->request('PUT', $this->apiUrl . '/rest/updateproduct/', [
                        'headers' => [
                            'Accept-Encoding' => 'gzip,deflate',
                            'Content-Type' => 'application/json',
                            'ApiKey' => $this->apiKey,
                            'Username' => $this->apiUser
                        ],
                        'json' => $silvasoftPayload
                    ]);
                }

                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Silvasoft API error: ' . $response->getContent(false));
                } else {
                    $this->logger->info('Product successfully sent to Silvasoft: ' . $productNumber);
                }
            } catch (\Exception $e) {
                $this->logger->error('Silvasoft API Error: ' . $e->getMessage());
            }
        }
    }

    public function onCustomerRegister(CustomerRegisterEvent $event): void
    {
        $customer = $event->getCustomer();
        $address = $customer->getDefaultBillingAddress();

        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials are missing.');
            return;
        }

        $payload = [
            "Address_City" => $address->getCity() ? $address->getCity() : '',
            "Address_Street" => $address->getStreet() ? $address->getStreet() : '',
            "Address_PostalCode" => $address->getZipcode() ? $address->getZipcode() : '',
            "Address_CountryCode" => $address->getCountry()->getIso() ? $address->getCountry()->getIso() : '',
            "IsCustomer" => true,
            "CustomerNumber" => $customer->getCustomerNumber() ? $customer->getCustomerNumber() : '',
            "OnExistingRelationEmail" => "ABORT",
            "OnExistingRelationNumber" => "ABORT",
            "OnExistingRelationName" => "ABORT",
            "Relation_Contact" => [
                [
                    "Email" => $customer->getEmail(),
                    "Phone" => $address->getPhoneNumber(),
                    "FirstName" => $customer->getFirstName(),
                    "LastName" => $customer->getLastName()
                ]
            ]
        ];

        $salutation = $customer->getSalutation()->getDisplayName();

        $salutationMap = [
            'Herr' => 'Man',
            'Mr.' => 'Man',
            'Dhr.' => 'Man',
            'De heer' => 'Man',
            'Frau' => 'Woman',
            'Mrs.' => 'Woman',
            'Mevr.' => 'Woman',
            'Mevrouw' => 'Woman',
            'Fräulein' => 'Woman',
            'Miss' => 'Woman',
            'Mej.' => 'Woman',
            'Mejuffrouw' => 'Woman'
        ];

        if (!empty($salutationMap[$salutation])) {
            $payload['Relation_Contact'][0]['Sex'] = $salutationMap[$salutation];
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'ApiKey' => $this->apiKey,
                    'Username' => $this->apiUser,
                ],
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->error("Error when transferring a customer to Silvasoft API: " . $response->getContent(false));
            }
        } catch (\Exception $e) {
            $this->logger->error("API error: " . $e->getMessage());
        }
    }

    private function updateSilvasoftOrder(
        string $silvasoftOrderNumber,
        string $shopwareOrderNumber,
        string $status
    ): void {
        $apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');

        if (!$apiUrl || !$apiKey || !$apiUser) {
            $this->logger->error('Silvasoft API credentials missing');
            return;
        }

        $payload = [
            'OrderNumber' => (int) $silvasoftOrderNumber,
            'OrderStatus' => $status,
            'OrderReference' => $shopwareOrderNumber,
            'OrderNotes' => 'Status updated: ' . $status,
            'HtmlAsPlainText' => true
        ];

        try {
            $response = $this->httpClient->request(
                'PUT',
                $apiUrl . '/rest/updateorder/',
                [
                    'headers' => [
                        'ApiKey' => $apiKey,
                        'Username' => $apiUser,
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $payload
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $this->logger->error(
                    'Silvasoft update failed for ' . $shopwareOrderNumber .
                    ' - ' . $response->getContent(false)
                );
            }

        } catch (\Exception $e) {
            $this->logger->error(
                'Silvasoft update error: ' . $e->getMessage() .
                ' - Payload: ' . json_encode($payload)
            );
        }
    }

    private function getContext(string $orderId, Context $context): Context
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();

        if (!$order instanceof OrderEntity) {
            throw OrderException::orderNotFound($orderId);
        }

        /** @var CashRoundingConfig $itemRounding */
        $itemRounding = $order->getItemRounding();

        $orderContext = new Context(
            $context->getSource(),
            $order->getRuleIds() ?? [],
            $order->getCurrencyId(),
            array_values(array_unique(array_merge([$order->getLanguageId()], $context->getLanguageIdChain()))),
            $context->getVersionId(),
            $order->getCurrencyFactor(),
            true,
            $order?->getTaxStatus(),
            $itemRounding
        );

        $orderContext->addState(...$context->getStates());
        $orderContext->addExtensions($context->getExtensions());

        return $orderContext;
    }

    /**
     * @throws OrderException
     */
    private function getOrder(string $orderId, Context $context): OrderEntity
    {
        $orderCriteria = $this->getOrderCriteria($orderId, $context);

        $order = $this->orderRepository
            ->search($orderCriteria, $context)
            ->first();

        if (!$order instanceof OrderEntity) {
            throw OrderException::orderNotFound($orderId);
        }

        return $order;
    }

    private function getOrderCriteria(string $orderId, Context $context): Criteria
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer.salutation');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('language.locale');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('lineItems.downloads.media');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('addresses.countryState');
        $criteria->addAssociation('tags');

        return $criteria;
    }

    private function fetchProductData(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('categories');
        $criteria->addAssociation('price');

        return $this->productRepository->search($criteria, $context)->first();
    }

    private function fetchCategoryName(ProductEntity $product): string
    {
        $category = $product->getCategories()?->first();
        return $category ? $category->getTranslation('name') : 'Uncategorized';
    }

    private function getProductById(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $productId));
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('unit');

        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();

        return $product;
    }
}
