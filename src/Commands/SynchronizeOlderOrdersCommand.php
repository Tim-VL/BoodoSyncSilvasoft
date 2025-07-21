<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function configure(): void
    {
        $this
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_REQUIRED,
                'Filter orders from this date (format: YYYY-MM-DD)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Start salesinvoice export Silvasoft');

        $context = Context::createDefaultContext();
        $criteria = new Criteria();

        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('lineItems.product');

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('stateMachineState.technicalName', 'cancelled')
        ]));
        
        $dateString = $input->getOption('date');
        
        if ($dateString) {
            try {
                $fromDate = new \DateTime($dateString);
                $io->info(sprintf('Filtering orders from date: %s', $fromDate->format('Y-m-d')));
                $criteria->addFilter(new RangeFilter('orderDateTime', ['gte' => $fromDate->format(DATE_ATOM)]));
            } catch (\Exception $e) {
                $io->error(sprintf('Invalid date format: %s. Please use YYYY-MM-DD format.', $dateString));
                return Command::FAILURE;
            }
        } else {
            // Use original hardcoded filter when no date is provided
            $criteria->addFilter(new RangeFilter('orderDateTime', ['gte' => (new \DateTime('2025-01-01'))->format(DATE_ATOM)]));
        }

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
            if (isset($customFields['silvasoft_invoicenumber']) && !empty($customFields['silvasoft_invoicenumber'])) {
                $this->logger->info(
                    'Invoice ' . $order->getOrderNumber() . ' already synced with Silvasoft ID: ' . $customFields['silvasoft_invoicenumber']
                );
                $io->note('Skipping already synced invoice: ' . $order->getOrderNumber());
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
                "InvoiceNotes" => $order->getOrderNumber(),
                "InvoiceReference" => $order->getOrderNumber(),
                "InvoiceDate" => $formattedOrderDate,
                "TemplateName_Invoice" => "Standaard template",
                "TemplateName_Email" => "Standaard template",
                "PackingSlipNotes" => "Extra note to be added to the packing-slip",

                "Invoice_Contact" => [
                    [
                        "ContactType" => "Invoice",
                        "Email" => $customer->getEmail(),
                        "FirstName" => $customer->getFirstName(),
                        "LastName" => $customer->getLastName(),
                        "DefaultContact" => true
                    ],
                    [
                        "ContactType" => "PackingSlip",
                        "Email" => $customer->getEmail(),
                        "FirstName" => $customer->getFirstName(),
                        "LastName" => $customer->getLastName(),
                        "DefaultContact" => true
                    ]
                ], 

              "Invoice_Address" => [
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
                ],

                "Invoice_InvoiceLine" => array_values(array_map(function ($lineItem) {

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
                        "Description" => $lineItem->getDescription() // Invoice line description
                    ];
                }, $order->getLineItems()?->getElements() ?? [])),
                
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
                    $io->warning('Silvasoft API error when sending the order with the order number: ' . $order->getOrderNumber());
                } else {
                    $responseData = json_decode($response->getContent(), true);
                    if (isset($responseData['InvoiceNumber'])) {
                        $customFields = $order->getCustomFields() ?? [];

                        if (!isset($customFields['silvasoft_invoicenumber'])) {
                            $customFields['silvasoft_invoicenumber'] = $responseData['InvoiceNumber'];

                            // Update durchführen
                            $this->orderRepository->upsert([
                                [
                                    'id' => $order->getId(),
                                    'customFields' => $customFields
                                ]
                            ], $context);

                            $this->logger->info('Silvasoft invoice number saved for invoice: ' . $order->getOrderNumber());
                        }
                    }
                    $this->logger->info('Invoice successfully sent to Silvasoft: ' . $order->getOrderNumber());
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
