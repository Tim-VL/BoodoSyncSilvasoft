<?php
// OrderSubscriber for customer and order sync with Silvasoft in Shopware

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\CheckoutOrderPlacedEvent; // FIX: Correct event type
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use BoodoSyncSilvasoft\Service\OrderSyncService;

class OrderSubscriber implements EventSubscriberInterface
{
    // FIX: Added all required dependencies via constructor
    public function __construct(
        private readonly OrderSyncService $orderSyncService,
        private readonly EntityRepository $orderRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced', // FIX: Correct event subscribed
        ];
    }

    /**
     * Handles order placed event: syncs customer (if needed) and sends invoice to Silvasoft.
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();
        $context = $event->getContext();

        // Load full order with needed associations
        $criteria = new Criteria([$order->getId()]);
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('orderCustomer.customer');

        $orderFromDb = $this->orderRepository->search($criteria, $context)->first();

        if (!$orderFromDb) {
            $this->logger->error('Order not found in repository', ['orderId' => $order->getId()]); // FIX: Replaced logCustom
            return;
        }

        $billingAddress = $orderFromDb->getBillingAddress();
        $shippingAddress = $orderFromDb->getDeliveries()?->first()?->getShippingOrderAddress(); // FIX: Use full loaded order

        if (!$billingAddress || !$shippingAddress) {
            $this->logger->error('Missing billing or shipping address', ['orderId' => $order->getId()]);
            return;
        }

        $email = strtolower($customer->getEmail());
        $customerNumber = $orderFromDb->getOrderCustomer()?->getCustomer()?->getCustomerNumber() ?? 'GUEST-' . $order->getOrderNumber();

        // Step 1: Check if customer exists in Silvasoft
        $checkCustomerPayload = ["Email" => $email];

        try {
            $checkResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/checkrelation/', [
                'headers' => $this->getApiHeaders(),
                'json' => $checkCustomerPayload
            ]);

            $checkData = $this->decodeSilvasoftResponse($checkResponse->getContent(false)); // FIX: Correct variable usage

            if (isset($checkData['RelationFound']) && $checkData['RelationFound'] === false) {
                // Customer not found - add customer

                $customerPayload = [
                    "Address City" => $billingAddress->getCity(),
                    "Address Street" => $billingAddress->getStreet(),
                    "Address PostalCode" => $billingAddress->getZipcode(),
                    "Address CountryCode" => $billingAddress->getCountry()?->getIso() ?? '',
                    "Is Customer" => true,
                    "Customer Number" => $customerNumber,
                    "On Existing Relation Email" => "ABORT",
                    "On Existing Relation Number" => "ABORT",
                    "Relation Contact" => [[
                        "Email" => $email,
                        "Phone" => $billingAddress->getPhoneNumber() ?? '',
                        "First Name" => $customer->getFirstName(),
                        "Last Name" => $customer->getLastName()
                    ]]
                ];

                $addCustomerResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                    'headers' => $this->getApiHeaders(),
                    'json' => $customerPayload
                ]);

                $this->decodeSilvasoftResponse($addCustomerResponse->getContent(false));
                $this->logger->info('Customer added to Silvasoft during order sync.', ['customerId' => $customer->getId()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error checking or adding customer to Silvasoft', [
                'error' => $e->getMessage(),
                'payload' => $checkCustomerPayload
            ]);
        }

        // Step 2: Send order as invoice to Silvasoft
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
            "Invoice Contact" => [[
                "ContactType" => "Invoice",
                "Email" => $email,
                "FirstName" => ucwords(strtolower($customer->getFirstName())),
                "LastName" => ucwords(strtolower($customer->getLastName())),
                "DefaultContact" => true
            ]],
            "Invoice InvoiceLine" => array_values(array_map(function ($lineItem) {
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
            "Invoice Address" => [
                [
                    "Address Street" => ucwords(strtolower($billingAddress->getStreet() ?? '')),
                    "Address City" => ucwords(strtolower($billingAddress->getCity() ?? '')),
                    "Address PostalCode" => $billingAddress->getZipcode() ?? '',
                    "Address CountryCode" => strtoupper($billingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address Type" => "InvoiceAddress"
                ],
                [
                    "Address Street" => ucwords(strtolower($shippingAddress->getStreet() ?? '')),
                    "Address City" => ucwords(strtolower($shippingAddress->getCity() ?? '')),
                    "Address PostalCode" => $shippingAddress->getZipcode() ?? '',
                    "Address CountryCode" => strtoupper($shippingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address Type" => "ShippingAddress"
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                'headers' => $this->getApiHeaders(),
                'json' => $invoicePayload
            ]);

            $this->decodeSilvasoftResponse($response->getContent(false));
            $this->logger->info('Order successfully synced to Silvasoft.', ['orderId' => $order->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('Order sync to Silvasoft failed', [
                'error' => $e->getMessage(),
                'payload' => $invoicePayload
            ]);
        }
    }

    // Make sure this helper exists
    private function getApiHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
            // Add auth header if needed
        ];
    }

    // Placeholder for Silvasoft response decoder
    private function decodeSilvasoftResponse(string $responseContent): array
    {
        return json_decode($responseContent, true) ?? [];
    }
}
