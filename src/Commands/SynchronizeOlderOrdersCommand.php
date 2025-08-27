<?php

/*
// UPDATE order
// SET custom_fields = JSON_REMOVE(custom_fields, '$.silvasoft_ordernumber')
// WHERE custom_fields LIKE '%silvasoft_ordernumber%';
*/


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
        set_error_handler(function($errno, $errstr) {
            return str_contains($errstr, 'User Deprecated') ? true : false;
        }, E_USER_DEPRECATED);

        $io = new SymfonyStyle($input, $output);
        $io->success('Start salesinvoice export Silvasoft');

        $context = Context::createDefaultContext();
        $criteria = new Criteria();

        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('transactions.paymentMethod');

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_OR, [
            new EqualsFilter('stateMachineState.technicalName', 'cancelled'),
            new EqualsFilter('stateMachineState.technicalName', 'open')
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
            $criteria->addFilter(new RangeFilter('orderDateTime', ['gte' => (new \DateTime('2025-01-01'))->format(DATE_ATOM)]));
        }

        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        if ($orders->count() === 0) {
            $io->warning('No orders found.');
            return Command::SUCCESS;
        }

        $io->progressStart($orders->count());

        foreach ($orders as $order) {
            $customFields = $order->getCustomFields() ?? [];

            if (isset($customFields['silvasoft_ordernumber']) && !empty($customFields['silvasoft_ordernumber'])) {
                $this->logger->info('Invoice ' . $order->getOrderNumber() . ' already synced.');
                $io->note('Skipping already synced (sw-customfield): ' . $order->getOrderNumber());
                continue;
            }

            $customer = $order->getOrderCustomer();
            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();

            if (!$billingAddress || !$shippingAddress) {
                $this->logger->error('Missing billing or shipping address for order: ' . $order->getOrderNumber());
                continue;
            }

            $formattedOrderDate = $order->getOrderDateTime()?->format('d-m-Y') ?? date('d-m-Y');
            $salesChannel = $order->getSalesChannel()?->getTranslated()['name'] ?? '';
            $customerComment = $order->getCustomerComment() ?? '';

            // Initial payload using CustomerEmail
            $payload = [
                //"CustomerNumber" => $customer->getCustomerNumber(), // sync based on customernr
                "CustomerEmail" => strtolower($customer->getEmail()),
                "InvoiceNotes" =>
                    (!empty(trim($customerComment)) ? "<h3>Customer Comment: " . nl2br(htmlspecialchars($customerComment)) . "</h3>\n<br>\n" : '') .
                    "<b>Paymentmethod:</b> " . $order->getTransactions()->first()?->getPaymentMethod()?->getName() .
                    "<b>OrderNumber:</b> " . $order->getOrderNumber() . "<br>\n" .
                    "<b>SalesChannel:</b> " . $salesChannel . "<br>\n" .
                    "<b>API Sync:</b> " . (new \DateTime())->format('c') . "<br>\n",
                "InvoiceReference" => $order->getOrderNumber(),
                "InvoiceDate" => $formattedOrderDate,
                "Invoice_Contact" => [
                    [
                        "ContactType" => "Invoice",
                        "Email" => strtolower($customer->getEmail()),
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
                    $taxRate = $price->getCalculatedTaxes()?->first()?->getTaxRate() ?? 21;
                    $unitPriceGross = $price->getUnitPrice();
                    $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));
                    return [
                        "ProductNumber" => $lineItem->getProduct()?->getProductNumber() ?? 'UNK',
                        "Quantity" => $lineItem->getQuantity(),
                        "TaxPc" => $taxRate,
                        "UnitPriceExclTax" => $unitPriceNet,
                        "Description" => $lineItem->getLabel() ?? 'API - No line description retrieved'
                    ];
                }, $order->getLineItems()?->getElements() ?? []))
            ];

             $payload2 = [
                "CustomerNumber" => $customer->getCustomerNumber(), // sync based on customernr
                //"CustomerEmail" => strtolower($customer->getEmail()),
                "InvoiceNotes" =>
                    (!empty(trim($customerComment)) ? "<h3>Customer Comment: " . nl2br(htmlspecialchars($customerComment)) . "</h3>\n<br>\n" : '') .
                    "<b>Paymentmethod:</b> " . $order->getTransactions()->first()?->getPaymentMethod()?->getName() .
                    "<b>OrderNumber:</b> " . $order->getOrderNumber() . "<br>\n" .
                    "<b>SalesChannel:</b> " . $salesChannel . "<br>\n" .
                    "<b>API Sync:</b> " . (new \DateTime())->format('c') . "<br>\n",
                "InvoiceReference" => $order->getOrderNumber(),
                "InvoiceDate" => $formattedOrderDate,
                "Invoice_Contact" => [
                    [
                        "ContactType" => "Invoice",
                        "Email" => strtolower($customer->getEmail()),
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
                    $taxRate = $price->getCalculatedTaxes()?->first()?->getTaxRate() ?? 21;
                    $unitPriceGross = $price->getUnitPrice();
                    $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));
                    return [
                        "ProductNumber" => $lineItem->getProduct()?->getProductNumber() ?? 'UNKNOWN_PRODUCT',
                        "Quantity" => $lineItem->getQuantity(),
                        "TaxPc" => $taxRate,
                        "UnitPriceExclTax" => $unitPriceNet,
                        "Description" => $lineItem->getLabel() ?? 'API - No line description retrieved'
                    ];
                }, $order->getLineItems()?->getElements() ?? []))
            ];

            try {
                // First API call
                $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                    'headers' => [
                        'Accept-Encoding' => 'gzip,deflate',
                        'Content-Type' => 'application/json',
                        'ApiKey' => $this->apiKey,
                        'Username' => $this->apiUser
                    ],
                    'json' => $payload
                ]);
                sleep(2); // Sleep 2 seconds

                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);
                $json = json_decode($content, true);

                // Retry logic if relation not found
                if ($statusCode === 400) {
                    $io->warning("Retrying with Customernumber for order: " . $order->getOrderNumber());
                    $this->logger->warning("Retrying with CustomerNumber for order: " . $order->getOrderNumber());

                    sleep(2); // Sleep 2 seconds

                    $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                        'headers' => [
                            'Accept-Encoding' => 'gzip,deflate',
                            'Content-Type' => 'application/json',
                            'ApiKey' => $this->apiKey,
                            'Username' => $this->apiUser
                        ],
                        'json' => $payload2
                    ]);

                    $statusCode = $response->getStatusCode();
                    $content = $response->getContent(false);
                    $json = json_decode($content, true);
                }

                if ($statusCode !== 200) {
                    $this->logger->error('Silvasoft API error: ' . $content);
                    $io->warning('Silvasoft API error on order ' . $order->getOrderNumber());
                    continue;
                }

                $invoiceNumber = $json['InvoiceNumber'] ?? ($json[0]['InvoiceNumber'] ?? null);

                if (!$invoiceNumber) {
                    $this->logger->error('InvoiceNumber not found in response.', ['response' => $json]);
                    continue;
                }

                $this->orderRepository->upsert([
                    [
                        'id' => $order->getId(),
                        'customFields' => array_merge($customFields, [
                            'silvasoft_ordernumber' => $invoiceNumber
                        ])
                    ]
                ], $context);

                $this->logger->info('Silvasoft invoice number saved', [
                    'order' => $order->getOrderNumber(),
                    'silvasoft_id' => $invoiceNumber
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Silvasoft API exception: ' . $e->getMessage());
                continue;
            }

            usleep(2000000); // Sleep 1.6 seconds
        }

        $io->progressFinish();
        return self::SUCCESS;
    }

    private function logError(string $message, array $context = []): void
    {
        $logEntry = sprintf(
            "[%s] ERROR: %s | Context: %s\n",
            date('Y-m-d H:i:s'),
            $message,
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );
        $date = (new \DateTime())->format('Y-m-d');
        error_log($logEntry, 3, $this->getLogPath('order-sync-' . $date));
    }

    private function getLogPath(string $logType): string
    {
        $logDir = dirname(__DIR__) . '/../../../../var/log/silvasoft/';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
        return $logDir . '/' . $logType . '.log';
    }
}
