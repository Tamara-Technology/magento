<?php

namespace Tamara\Checkout\Helper;

use DateTime;
use DateTimeZone;
use Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Data\Collection;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;

class Order extends AbstractData
{
    const DELIVERED_STATUSES = ['complete', 'shipped', 'delivered'];
    const FAILED_STATUSES = ['canceled', 'closed', 'fraud', 'holded', 'payment_review', 'paypal_canceled_reversal', 'paypal_reversed', 'pending', 'pending_payment', 'pending_paypal', 'tamara_expired'];
    protected $magentoOrderCollectionFactory;
    protected $customerRepository;
    protected $timezone;
    private $timezoneOfStores = [];

    public function __construct(Context                     $context, Resolver $locale, StoreManagerInterface $storeManager, CacheInterface $magentoCache, BaseConfig $tamaraConfig, TamaraAdapterFactory $tamaraAdapterFactory,
                                CollectionFactory           $magentoOrderCollectionFactory,
                                CustomerRepositoryInterface $customerRepository,
                                TimezoneInterface           $timezone

    )
    {
        parent::__construct($context, $locale, $storeManager, $magentoCache, $tamaraConfig, $tamaraAdapterFactory);
        $this->magentoOrderCollectionFactory = $magentoOrderCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->timezone = $timezone;
    }

    /**
     * @param CartInterface $quote
     * @return array
     */
    public function getRiskAssessmentDataFromOrder(OrderInterface $order)
    {
        if ($order->getCustomerIsGuest()) {
            return $this->getGuestRiskData($order);
        } else {
            return $this->getCustomerRiskData($order);
        }
    }

    public function getGuestRiskData(OrderInterface $order)
    {
        $rs = [
            'account_creation_date' => null,
            'is_guest_user' => true,
            'is_existing_customer' => null
        ];
        try {
            $shippingAddress = $order->getShippingAddress();
            if (is_null($shippingAddress)) {
                $shippingAddress = $order->getBillingAddress();
            }
            $rs['customer_nationality'] = $shippingAddress->getCountryId();
            $magentoOrderCollection = $this->magentoOrderCollectionFactory->create();
            $salesOrderAddressTable = $magentoOrderCollection->getTable('sales_order_address');
            $magentoOrderCollection->getSelect()
                ->reset(\Zend_Db_Select::COLUMNS)
                ->columns(['grand_total', 'status', 'created_at'])
                ->join(['soa' => $salesOrderAddressTable], 'main_table.entity_id = soa.parent_id',
                    []
                )
                ->where('soa.telephone=?', $shippingAddress->getTelephone())->where('soa.address_type=?', $shippingAddress->getAddressType())
                ->order(['created_at ASC']);
            $magentoOrderCollection->addFieldToFilter(
                'status',
                ['neq' => 'NULL']
            );

            return array_merge($rs, $this->getRiskDataFromConsumerOrders($magentoOrderCollection, $this->getMagentoTimezone($order->getStoreId())));
        } catch (Exception $exception) {
            //pass
        }
        return $rs;
    }

    /**
     * @param $storeId
     * @return DateTimeZone
     */
    public function getMagentoTimezone($storeId)
    {
        if (!array_key_exists($storeId, $this->timezoneOfStores)) {
            $this->timezoneOfStores[$storeId] = new DateTimeZone($this->timezone->getConfigTimezone('stores', $storeId));
        }
        return $this->timezoneOfStores[$storeId];
    }

    public function getCustomerRiskData(OrderInterface $order)
    {
        $rs = [
            'account_creation_date' => null,
            'is_guest_user' => false,
            'is_existing_customer' => null
        ];
        try {
            $timezone = $this->getMagentoTimezone($order->getStoreId());
            $customerDob = strval($order->getCustomerDob());
            if (!empty($customerDob)) {
                $customerDobDtObj = DateTime::createFromFormat('Y-m-d H:i:s', $customerDob, $timezone);
                if ($customerDobDtObj !== false) {
                    $rs['customer_dob'] = $customerDobDtObj->format('d-m-Y');
                    if (!$this->isValidDateTime($rs['customer_dob'])) {
                        unset($rs['customer_dob']);
                    } else {
                        $rs['customer_age'] = $customerDobDtObj->diff(new DateTime('now', $timezone))
                            ->y;
                    }
                }
            }
            $rs['customer_gender'] = ($order->getCustomerGender() == 1) ? 'Male' : 'Female';

            /**
             * @var CustomerInterface $customerObj
             */
            $customerObj = $this->customerRepository->getById($order->getCustomerId());
            $customerAddresses = $customerObj->getAddresses();
            if (!is_null($customerAddresses)) {
                foreach ($customerAddresses as $customerAddress) {
                    if ($customerAddress->isDefaultShipping()) {
                        $rs['customer_nationality'] = $customerAddress->getCountryId();
                    }
                }
            }
            $customerObjCreatedAt = strval($customerObj->getCreatedAt());
            if (!empty($customerObjCreatedAt)) {
                $accountCreationDateDtObj = DateTime::createFromFormat('Y-m-d H:i:s', $customerObjCreatedAt, $timezone);
                if ($accountCreationDateDtObj !== false) {
                    $rs['account_creation_date'] = $accountCreationDateDtObj->format('d-m-Y');
                    if (!$this->isValidDateTime($rs['account_creation_date'])) {
                        unset($rs['account_creation_date']);
                    } else {
                        $orderCreatedAtDtObj = DateTime::createFromFormat('Y-m-d H:i:s', strval($order->getCreatedAt()), $timezone);
                        if ($orderCreatedAtDtObj !== false) {
                            $orderCreatedAtDtStr = $orderCreatedAtDtObj->format('d-m-Y');
                            if (DateTime::createFromFormat('d-m-Y', $orderCreatedAtDtStr) !== false) {
                                if ($rs['account_creation_date'] != $orderCreatedAtDtStr) {
                                    $rs['is_existing_customer'] = true;
                                }
                            }
                        }
                    }
                }
            }
            $magentoOrderCollection = $this->magentoOrderCollectionFactory->create($customerObj->getId());
            $magentoOrderCollection->setOrder('created_at', Collection::SORT_ORDER_ASC);
            return array_merge($rs, $this->getRiskDataFromConsumerOrders($magentoOrderCollection, $timezone));
        } catch (Exception $exception) {
            //pass
        }
        return $rs;
    }

    /**
     * Get risk data from consumer orders
     * @param \Magento\Sales\Model\ResourceModel\Order\Collection $magentoOrderCollection
     */
    protected function getRiskDataFromConsumerOrders(\Magento\Sales\Model\ResourceModel\Order\Collection $magentoOrderCollection, DateTimeZone $timezone)
    {
        $rs = [
            'has_delivered_order' => null,
            'total_order_count' => null,
            'date_of_first_transaction' => null,
            'order_amount_last3months' => null,
            'order_count_last3months' => null
        ];
        try {
            $hasDeliveredOrder = false;
            $firstOrder = false;
            $totalOrderCount = 0;
            $date3monthsAgo = new DateTime('now', $timezone);
            $date3monthsAgo->modify('-3 month');
            $orderCountLast3Months = 0;
            $orderAmountLast3Months = 0.0;
            $lastConsumerOrder = null;
            $lastConsumerOrderCreatedAt = null;
            foreach ($magentoOrderCollection as $consumerOrder) {
                $consumerOrderCreatedAt = strval($consumerOrder->getCreatedAt());
                $consumerOrderCreatedAtDtObj = DateTime::createFromFormat('Y-m-d H:i:s', $consumerOrderCreatedAt, $timezone);
                if (!$firstOrder) {
                    if ($consumerOrderCreatedAtDtObj !== false) {
                        $rs['date_of_first_transaction'] = $consumerOrderCreatedAtDtObj
                            ->format('d-m-Y');
                        if (!$this->isValidDateTime($rs['date_of_first_transaction'])) {
                            unset($rs['date_of_first_transaction']);
                        }
                    }
                    $firstOrder = true;
                }
                if (empty($consumerOrder->getStatus()) || in_array($consumerOrder->getStatus(), self::FAILED_STATUSES)) {
                    $isSuccessfulOrder = false;
                } else {
                    $isSuccessfulOrder = true;
                    if (in_array($consumerOrder->getStatus(), self::DELIVERED_STATUSES)) {
                        $hasDeliveredOrder = true;
                    }
                }
                if ($isSuccessfulOrder) {
                    $totalOrderCount++;
                    if ($consumerOrderCreatedAtDtObj !== false && $consumerOrderCreatedAtDtObj > $date3monthsAgo) {
                        $orderCountLast3Months++;
                        $orderAmountLast3Months += floatval($consumerOrder->getGrandTotal());
                    }
                    $lastConsumerOrder = $consumerOrder;
                    $lastConsumerOrderCreatedAt = $consumerOrderCreatedAt;
                }
            }
            $rs['has_delivered_order'] = $hasDeliveredOrder;
            $rs['total_order_count'] = $totalOrderCount;
            $rs['order_amount_last3months'] = $orderAmountLast3Months;
            $rs['order_count_last3months'] = $orderCountLast3Months;
            if ($lastConsumerOrderCreatedAt) {
                $lastConsumerOrderCreatedAtDtObj = DateTime::createFromFormat('Y-m-d H:i:s', $lastConsumerOrderCreatedAt, $timezone);
                if ($lastConsumerOrderCreatedAtDtObj !== false) {
                    $rs['last_order_date'] = $lastConsumerOrderCreatedAtDtObj->format('d-m-Y');
                    if (!$this->isValidDateTime($rs['last_order_date'])) {
                        unset($rs['last_order_date']);
                    }
                }
            }
            if ($lastConsumerOrder) {
                $rs['last_order_amount'] = floatval($lastConsumerOrder->getGrandTotal());
            }
        } catch (Exception $exception) {
            //pass
        }
        return $rs;
    }
}