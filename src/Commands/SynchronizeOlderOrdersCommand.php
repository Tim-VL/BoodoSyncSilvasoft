<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;

#[AsCommand('boodo:synchronize:orders', 'Sends all orders to Silvasoft from set date')]
class SynchronizeOlderOrdersCommand extends Command
{

    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Start orders export Silvasoft');

        $context = Context::createDefaultContext();
        $criteria = new Criteria();

        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('lineItems.product');

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('stateMachineState.technicalName', 'cancelled')
        ]));
        $criteria->addFilter(new RangeFilter('orderDateTime', ['gte' => (new \DateTime('2025-01-01'))->format(DATE_ATOM)]));

        /**
         * @var OrderCollection
         */
        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        if ($orders->count() === 0) {
            $io->warning('No orders found.');
            return Command::SUCCESS;
        }

        $io->progressStart($orders->count());

        /** @var OrderEntity $order */
        foreach ($orders as $order) {

            $customFields = $order->getCustomFields() ?? [];
            if (isset($customFields['silvasoft_ordernumber']) && !empty($customFields['silvasoft_ordernumber'])) {
                $this->logger->info(
                    'Order ' . $order->getOrderNumber() . ' already synced with Silvasoft ID: ' . $customFields['silvasoft_ordernumber']
                );
                $io->note('Skipping already synced order: ' . $order->getOrderNumber());
                continue;
            }

            $customer = $order->getOrderCustomer();
            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getDeliveries() ? $order->getDeliveries()->first()->getShippingOrderAddress() : null;

            if (!$billingAddress || !$shippingAddress) {
                $this->logger->error('Billing or Shipping address is missing for order ID: ' . $order->getOrderNumber());
                return Command::FAILURE;
            }

            $orderStatus = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';
            $orderDate = $order->getOrderDateTime();
            $formattedOrderDate = $orderDate ? $orderDate->format('d-m-Y') : date('d-m-Y');

            if (!$this->apiKey || !$this->apiUser) {
                $this->logger->error('Silvasoft API credentials not set.');
                return Command::FAILURE;
            }

            $payload = [
                "CustomerNumber" => $customer->getCustomerNumber(),
                "OrderNotes" => "Order from Shopware",
                "OrderReference" => $order->getOrderNumber(),
                "OrderStatus" => $orderStatus,
                "OrderDate" => $formattedOrderDate,
                "TemplateName_Email_PackingSlip" => "Standaard template",
                "TemplateName_Email" => "Standaard template",
                "TemplateName_Order" => "Standaard template",
                "TemplateName_PackingSlip" => "Standaard template",
                "Order_Contact" => [
                    [
                        "ContactType" => "Invoice",
                        "Email" => $customer->getEmail(),
                        "FirstName" => $customer->getFirstName(),
                        "LastName" => $customer->getLastName(),
                        "DefaultContact" => true
                    ]
                ],
                "Order_Orderline" => array_values(array_map(function ($lineItem) {


                    $price = $lineItem->getPrice();
                    $unitPriceGross = $price->getUnitPrice();
                    $taxRules = $price->getCalculatedTaxes();
                    $taxPercentage = $taxRules ? $taxRules->first()?->getTaxRate() : 21;

                    $taxRate = $taxRules->first() ? $taxRules->first()->getTaxRate() : 21;
                    $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));

                    return [
                        "ProductNumber" => $lineItem->getProduct() ? $lineItem->getProduct()->getProductNumber() : '',
                        "Quantity" => $lineItem->getQuantity(),
                        "TaxPc" => $taxPercentage,
                        "UnitPriceExclTax" => $unitPriceNet,
                        "Description" => $lineItem->getDescription()
                    ];
                }, $order->getLineItems()?->getElements() ?? [])),
                'Order_Address' => [
                    [
                        'Address_Street' => $billingAddress->getStreet(),
                        'Address_City' => $billingAddress->getCity(),
                        'Address_PostalCode' => $billingAddress->getZipcode(),
                        'Address_CountryCode' => $billingAddress->getCountry()->getIso(),
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
                $response = $this->httpClient->request('POST', (string) $this->apiUrl . '/rest/addorder/', [
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
                    $io->warning('Silvasoft API error when sending the order with the order number: ' . $order->getOrderNumber());
                } else {
                    $responseData = json_decode($response->getContent(), true);
                    if (isset($responseData['OrderNumber'])) {
                        $customFields = $order->getCustomFields() ?? [];

                        if (!isset($customFields['silvasoft_ordernumber'])) {
                            $customFields['silvasoft_ordernumber'] = $responseData['OrderNumber'];

                            // Update durchführen
                            $this->orderRepository->upsert([
                                [
                                    'id' => $order->getId(),
                                    'customFields' => $customFields
                                ]
                            ], $context);

                            $this->logger->info('Silvasoft order number saved for order: ' . $order->getOrderNumber());
                        }
                    }
                    $this->logger->info('Order successfully sent to Silvasoft: ' . $order->getOrderNumber());
                }
            } catch (\Exception $e) {
                $this->logger->error('Silvasoft API request failed: ' . $e->getMessage());
                $this->logger->error('Silvasoft API error PAYLOAD: ' . json_encode($payload, JSON_PRETTY_PRINT));
            }

            sleep(2);
        }
        $io->progressFinish();
        return self::SUCCESS;
    }
}
