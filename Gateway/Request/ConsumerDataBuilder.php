<?php

namespace Tamara\Checkout\Gateway\Request;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Model\Method\Logger;
use Tamara\Model\Order\Consumer;

class ConsumerDataBuilder implements BuilderInterface
{
    public const
        CONSUMER = 'consumer';

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var Logger
     */
    private $logger;

    private $tamaraAddressRepository;

    /**
     * ConsumerDataBuilder constructor.
     * @param CustomerRepositoryInterface $customerRepository
     * @param AddressRepositoryInterface $addressRepository
     * @param Logger $logger
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        Logger $logger,
        \Tamara\Checkout\Model\AddressRepository $tamaraAddressRepository
    )
    {
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->logger = $logger;
        $this->tamaraAddressRepository = $tamaraAddressRepository;
    }

    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $payment */
        $payment = $buildSubject['payment'];
        $order = $payment->getOrder();

        $consumer = new Consumer();

        try {
            /** @var AddressAdapterInterface $address */
            $address = $order->getShippingAddress();
            $billingAddress = $order->getBillingAddress();
            $useBillingAddress = false;
            if ($address) {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

                /**
                 * @var \Magento\Sales\Model\Order $magentoOrder
                 */
                $magentoOrder = $objectManager->create('Magento\Sales\Model\Order')->load($order->getId());
                $shippingMethod = strval($magentoOrder->getShippingMethod());

                //use click and collect
                foreach ($this->tamaraAddressRepository->getClickAndCollectMethods() as $method) {
                    if (strpos($shippingMethod, $method) === 0) {
                        $useBillingAddress = true;
                        break;
                    }
                }
            } else {
                $useBillingAddress = true;
            }
            if ($useBillingAddress) {
                $address = $billingAddress;
            }

            $consumer->setFirstName(strval($address->getFirstname()));
            $consumer->setLastName(strval($address->getLastname()));
            $consumer->setEmail(strval($address->getEmail()));
            $consumer->setPhoneNumber($address->getTelephone());
            $consumer->setIsFirstOrder($this->isFirstOrder($order->getCustomerId()));

        } catch (\Exception $e) {
            $this->logger->debug(["Tamara - Error when building common data" => $e->getMessage()], null, true);
        }

        return [self::CONSUMER => $consumer];
    }

    private function isFirstOrder($customerId): bool
    {
        if ($customerId === null) {
            return true;
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->create('Magento\Sales\Model\Order')
            ->getCollection()
            ->addAttributeToFilter('customer_id', $customerId)->getFirstItem();

        return empty($order);
    }
}
