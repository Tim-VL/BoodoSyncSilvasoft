<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

#[AsCommand('boodo:synchronize:customer', 'Exports all existing customer to Silvasoft from set date')]
class SynchronizeCustomerWithSilvasoftCommand extends Command
{
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $customerRepository,
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
                'Filter Customers from this date (format: YYYY-MM-DD)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Start customers export to Silvasoft 2020-01-01 OR with with Date filter');

        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultShippingAddress.country');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('salutation');

        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [new EqualsFilter('lastLogin', null)]
            )
        );

        $dateString = $input->getOption('date');

        if ($dateString) {
            try {
                $fromDate = new \DateTime($dateString);
                $io->info(sprintf('Filtering customers from date: %s', $fromDate->format('Y-m-d')));
                $criteria->addFilter(new RangeFilter('createdAt', ['gte' => $fromDate->format(DATE_ATOM)]));
            } catch (\Exception $e) {
                $io->error(sprintf('Invalid date format: %s. Please use YYYY-MM-DD format.', $dateString));
                return Command::FAILURE;
            }
        } else {
            $criteria->addFilter(new RangeFilter('createdAt', ['gte' => (new \DateTime('2020-01-01'))->format(DATE_ATOM)]));
        }

        /** @var CustomerCollection $customers */
        $customers = $this->customerRepository->search($criteria, $context)->getEntities();

        if ($customers->count() === 0) {
            $io->warning('No customers found.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Found %d customers to sync', $customers->count()));
        $io->progressStart($customers->count());

        foreach ($customers as $customer) {
            $this->sendCustomerToSilvasoft($customer, $io);
            $io->progressAdvance();
            usleep(1400000); // Sleep 1.4 seconds
        }

        $io->progressFinish();
        $io->success(sprintf('Customer export complete — %d customers processed.', $customers->count()));

        return Command::SUCCESS;
    }

    private function sendCustomerToSilvasoft(CustomerEntity $customer, SymfonyStyle $io): void
    {
        /** @var CustomerAddressEntity|null $address */
        $address = $customer->getDefaultBillingAddress()
            ?? $customer->getDefaultShippingAddress()
            ?? ($customer->getAddresses() ? $customer->getAddresses()->first() : null);

        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials are missing.');
            $io->error('Missing Silvasoft API credentials.');
            return;
        }
        usleep(1400000); // Sleep 1.4 seconds
        $payload = [
            "OnExistingRelationEmail" => "ABORT",
            "OnExistingRelationNumber" => "ABORT",
            "IsCustomer" => true,
            // "CustomerNumber" => (int) $customer->getCustomerNumber() ?: '',
            "CustomerNumber" => $customer->getCustomerNumber(),
            "Relation_Contact" => [
                [
                    //"Email" => $customer->getEmail(),
                    "Email"=> strtolower($customer->getEmail()),
                    "Phone" => $address?->getPhoneNumber() ?? '',
                    // "FirstName" => $customer->getFirstName(),
                    // "LastName" => $customer->getLastName()
                    "FirstName" => ucfirst(strtolower($customer->getFirstName())),
                    "LastName"  => ucfirst(strtolower($customer->getLastName()))
                ]
            ]
        ];

        if ($address) {
            $payload += [
                //"Address_City" => $address->getCity() ?? '',
                //"Address_Street" => $address->getStreet() ?? '',
                "Address_City"   => $address->getCity() ? ucwords(strtolower($address->getCity())) : '',
                "Address_Street" => $address->getStreet() ? ucwords(strtolower($address->getStreet())) : '',
                "Address_PostalCode" => $address->getZipcode() ?? '',
                "Address_CountryCode" => $address->getCountry()?->getIso() ?? '',
            ];
        }

        // $salutation = $customer->getSalutation()?->getDisplayName();
        $salutation = $customer->getSalutation()?->getDisplayName() ?? '';  //fix if no Salutation in SW
        $salutationMap = [
            'Herr' => 'Man', 'Mr.' => 'Man', 'Dhr.' => 'Man', 'De heer' => 'Man',
            'Frau' => 'Woman', 'Mrs.' => 'Woman', 'Mevr.' => 'Woman', 'Mevrouw' => 'Woman',
            'Fräulein' => 'Woman', 'Miss' => 'Woman', 'Mej.' => 'Woman', 'Mejuffrouw' => 'Woman',
        ];

        if (!empty($salutationMap[$salutation])) {
            $payload['Relation_Contact'][0]['Sex'] = $salutationMap[$salutation];
        }

        $io->text(sprintf(' - Sending customer %s <%s>', $customer->getCustomerNumber(), $customer->getEmail()));

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'ApiKey' => $this->apiKey,
                    'Username' => $this->apiUser,
                ],
                'json' => $payload,
               
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $io->success(sprintf('✓ Synced %s <%s>', $customer->getCustomerNumber(), $customer->getEmail()));
            } else {
                $errorMessage = $response->getContent(false);

                $logContext = [
                    'customerNumber' => $customer->getCustomerNumber(),
                    'email' => $customer->getEmail(),
                    'statusCode' => $statusCode,
                    'payload' => $payload,
                    'response' => $errorMessage
                ];

                $this->writeLog('silvasoft_sync_error', "API error syncing customer", $logContext);

                if (str_contains(strtolower($errorMessage), 'already exists') || str_contains($errorMessage, 'Relation already exists')) {
                    $io->note(sprintf('Customer %s already exists in Silvasoft.', $customer->getCustomerNumber()));
                } else {
                    $io->warning(sprintf('API error (%d) for customer %s: %s', $statusCode, $customer->getCustomerNumber(), $errorMessage));
                }
            }
        } catch (\Exception $e) {
            $logContext = [
                'customerNumber' => $customer->getCustomerNumber(),
                'email' => $customer->getEmail(),
                'exception' => $e->getMessage(),
                'payload' => $payload
            ];

            $this->writeLog('silvasoft_sync_error', "Exception while syncing customer", $logContext);

            $this->logger->error("API exception: " . $e->getMessage());
            $io->error(sprintf('✗ Exception while syncing %s: %s', $customer->getCustomerNumber(), $e->getMessage()));
        }
    }

    private function getLogPath(string $logType): string
    {
        $logDir = dirname(__DIR__, 2) . '/var/log';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $date = date('Y-m-d');
        return sprintf('%s/%s_%s.log', $logDir, $logType, $date);
    }

    private function writeLog(string $logType, string $message, array $context = []): void
    {
        $logPath = $this->getLogPath($logType);
        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');

        $logEntry = "[$timestamp] $message";

        if (!empty($context)) {
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $logEntry .= PHP_EOL . $json;
        }

        $logEntry .= PHP_EOL . str_repeat('-', 80) . PHP_EOL;

        file_put_contents($logPath, $logEntry, FILE_APPEND);
    }
}
