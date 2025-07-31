<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Symfony\Component\Console\Input\InputOption;


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
        $io->success('Start customers export to Silvasoft - with date filter');
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultShippingAddress.country');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('salutation');
      
        /* Add NEVER LOGIN */ 
      //  $criteria->addFilter(
      //      new NotFilter(
     //           NotFilter::CONNECTION_AND,
     //           [new EqualsFilter('lastLogin', null)]
     //       )
     //   );
        /* end login*/

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
            // Use original hardcoded filter when no date is provided
            $criteria->addFilter(new RangeFilter('createdAt', ['gte' => (new \DateTime('2025-01-01'))->format(DATE_ATOM)]));
        }

        
        /** @var CustomerCollection $customers */
        $customers = $this->customerRepository->search($criteria, $context)->getEntities();

        if ($customers->count() === 0) {
            $io->warning('No customers found.');
            return Command::SUCCESS;
        }

        $io->progressStart($customers->count());

        foreach ($customers as $customer) {
            /** @var CustomerEntity $customer */
            $this->sendCustomerToSilvasoft($customer, $io);
            $io->progressAdvance();

            usleep(1500000); // Sleep 1.5 seconds
        }

        $io->progressFinish();
        $io->success('All customers successfully exported!');

        return self::SUCCESS;
    }

    private function sendCustomerToSilvasoft(CustomerEntity $customer, SymfonyStyle $io): void
    {
        /** @var CustomerAddressEntity $address */
        $address = $customer->getDefaultBillingAddress() ? $customer->getDefaultBillingAddress() : $customer->getDefaultShippingAddress();
        if (!$address) {
            $address = $customer->getAddresses() ? $customer->getAddresses()->first() : null;
        }
        if (!$this->apiKey || !$this->apiUser) {
            $this->logger->error('Silvasoft API credentials are missing.');
            return;
        }

        $payload = [
            "IsCustomer" => true,
            "CustomerNumber" => (int) $customer->getCustomerNumber() ? $customer->getCustomerNumber() : '',
            "OnExistingRelationEmail" => "ABORT",
            "OnExistingRelationNumber" => "ABORT",
            "OnExistingRelationName" => "ABORT",
            "Relation_Contact" => [
                [
                    "Email" => $customer->getEmail(),
                    "Phone" => $address ? $address->getPhoneNumber() : '',
                    "FirstName" => $customer->getFirstName(),
                    "LastName" => $customer->getLastName()
                ]
            ]
        ];

        if ($address) {
            $payload["Address_City"] = $address->getCity() ?: '';
            $payload["Address_Street"] = $address->getStreet() ?: '';
            $payload["Address_PostalCode"] = $address->getZipcode() ?: '';
            $payload["Address_CountryCode"] = $address->getCountry() ? $address->getCountry()->getIso() : '';
        }

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
            usleep(1500000); // Sleep 1.5 seconds
            
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->error("Error when transferring a customer to Silvasoft API: " . $response->getContent(false));
                $this->logger->error('Silvasoft API error when sending the customer with the customer number: ' . $customer->getCustomerNumber());
                $io->warning('Silvasoft API error when sending the product with the customer number: ' . $customer->getCustomerNumber());
                
            }
        } catch (\Exception $e) {
            $this->logger->error("API error: " . $e->getMessage());

        }
    }
}
