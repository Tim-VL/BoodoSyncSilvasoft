<?php

namespace BoodoSyncSilvasoft\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Context;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

class MergeGuestAccountService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderCustomerRepository,
        private readonly LoggerInterface $logger
    ) {
        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    public function executeMerge(InputInterface $input = null, OutputInterface $output = null): array
    {
        $updatedCustomers = [];
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('guest', true));
        $customers = $this->customerRepository->search($criteria, $context)->getEntities();

        foreach ($customers as $guestCustomer) {
            $email = $guestCustomer->getEmail();
            $salesChannelId = $guestCustomer->getBoundSalesChannelId();

            $realCustomerCriteria = new Criteria();
            $realCustomerCriteria->addFilter(new EqualsFilter('email', $email));
            $realCustomerCriteria->addFilter(new EqualsFilter('guest', false));
            $realCustomerCriteria->addFilter(new EqualsFilter('boundSalesChannelId', $salesChannelId));
            $realCustomerCriteria->addSorting(new FieldSorting('customerNumber', FieldSorting::ASCENDING));
            $realCustomerCriteria->setLimit(1);

            $realCustomer = $this->customerRepository->search($realCustomerCriteria, $context)->first();

            if (!$realCustomer) {
                $guestCriteria = new Criteria();
                $guestCriteria->addFilter(new EqualsFilter('email', $email));
                $guestCriteria->addFilter(new EqualsFilter('boundSalesChannelId', $salesChannelId));
                $guestCriteria->addSorting(new FieldSorting('customerNumber', FieldSorting::ASCENDING));

                $guestCriteria->setLimit(1);

                $realCustomer = $this->customerRepository->search($guestCriteria, $context)->first();
            }

            if (!$realCustomer || $realCustomer->getId() === $guestCustomer->getId()) {
                continue;
            }

            $orderCriteria = new Criteria();
            $orderCriteria->addFilter(new EqualsFilter('customerId', $guestCustomer->getId()));
            $orderCustomers = $this->orderCustomerRepository->search($orderCriteria, $context)->getEntities();

            $updates = [];
            foreach ($orderCustomers as $orderCustomer) {
                $updates[] = [
                    'id' => $orderCustomer->getId(),
                    'customerId' => $realCustomer->getId(),
                ];
            }

            if (!empty($updates)) {
                $this->orderCustomerRepository->update($updates, $context);
                $updatedCustomers[$realCustomer->getEmail()] = $realCustomer;
                
                if ($output) {
                    $output->writeln("Updated orders from guest {$guestCustomer->getCustomerNumber()} to {$realCustomer->getCustomerNumber()}");
                }

                $this->customerRepository->delete([['id' => $guestCustomer->getId()]], $context);
                if ($output) {
                    $output->writeln("Deleted guest account {$guestCustomer->getCustomerNumber()}");
                }
            }
        }
        return $updatedCustomers;
    }
}
