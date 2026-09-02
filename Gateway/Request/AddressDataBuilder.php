<?php

namespace Tamara\Checkout\Gateway\Request;


use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Tamara\Model\Order\Address;

class AddressDataBuilder implements BuilderInterface
{
    public const
        SHIPPING_ADDRESS = 'shipping_address',
        EMPTY = "",
        BILLING_ADDRESS = 'billing_address';

    private $tamaraAddressRepository;

    public function __construct(
        \Tamara\Checkout\Model\AddressRepository $tamaraAddressRepository
    )
    {
        $this->tamaraAddressRepository = $tamaraAddressRepository;
    }

    public function build(array $buildSubject): array
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $payment */
        $payment = $buildSubject['payment'];
        $order = $payment->getOrder();
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $useBillingAddress = false;
        if ($shippingAddress) {
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
            $shippingAddress = $billingAddress;
        }

        $shipping = new Address();
        $billing = new Address();

        if ($shippingAddress === null || $billingAddress === null) {
            throw new \InvalidArgumentException('The address should be provided');
        }

        $regionBilling = empty($billingAddress->getRegionCode()) ? self::EMPTY : $billingAddress->getRegionCode();
        $regionShipping = empty($shippingAddress->getRegionCode()) ? self::EMPTY : $shippingAddress->getRegionCode();

        $shippingAddressFirstName = empty($shippingAddress->getFirstname()) ? self::EMPTY : $shippingAddress->getFirstname();
        $shipping->setFirstName($shippingAddressFirstName);
        $shippingAddressLastName = empty($shippingAddress->getLastname()) ? self::EMPTY : $shippingAddress->getLastname();
        $shipping->setLastName($shippingAddressLastName);
        $shippingAddressLine1 = empty($shippingAddress->getStreetLine1()) ? self::EMPTY : $shippingAddress->getStreetLine1();
        $shipping->setLine1($shippingAddressLine1);
        $shipping->setLine2($shippingAddress->getStreetLine2() ?? '');
        $shipping->setRegion($regionShipping);
        $shippingAddressCity = empty($shippingAddress->getCity()) ? self::EMPTY : $shippingAddress->getCity();
        $shipping->setCity($shippingAddressCity);
        $shippingAddressPhoneNumber = empty($shippingAddress->getTelephone()) ? self::EMPTY : $shippingAddress->getTelephone();
        $shipping->setPhoneNumber($shippingAddressPhoneNumber);
        $shippingAddressCountryCode = empty($shippingAddress->getCountryId()) ? self::EMPTY : $shippingAddress->getCountryId();
        $shipping->setCountryCode($shippingAddressCountryCode);
        $shipping->setPostalCode($shippingAddress->getPostcode());

        $billing->setLastName($billingAddress->getLastname());
        $billingAddressLine1 = empty($billingAddress->getStreetLine1()) ? self::EMPTY : $billingAddress->getStreetLine1();
        $billing->setLine1($billingAddressLine1);
        $billing->setFirstName(strval($billingAddress->getFirstname()));
        $billing->setLine2($billingAddress->getStreetLine2() ?? '');
        $billing->setRegion($regionBilling);
        $billingAddressCity = empty($billingAddress->getCity()) ? self::EMPTY : $billingAddress->getCity();
        $billing->setCity($billingAddressCity);
        $billing->setPhoneNumber($billingAddress->getTelephone());
        $billing->setCountryCode($billingAddress->getCountryId());
        $billing->setPostalCode($billingAddress->getPostcode());

        return [
            self::BILLING_ADDRESS => $billing,
            self::SHIPPING_ADDRESS => $shipping
        ];
    }
}
