<?php

namespace Tamara\Checkout\Plugin\Model\Method;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\ObjectManagerInterface;
use Magento\Payment\Model\Method\Logger;
use Tamara\Checkout\Api\EmailWhiteListRepositoryInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Model\Helper\PaymentHelper;
use Tamara\Model\Money;
use Tamara\Model\Order\Consumer;
use Tamara\Model\Order\OrderItemCollection;
use Tamara\Model\Order\OrderItem;
use Tamara\Model\Order\RiskAssessment;
use function GuzzleHttp\Psr7\str;

class Available
{
    private $logger;

    private $magentoOrderCollectionFactory;

    private $timezone;

    private $config;

    private $emailWhiteListRepository;

    private $httpHeader;

    private $tamaraHelper;

    public function __construct(
        Logger $logger,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $magentoOrderCollectionFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        BaseConfig $config,
        EmailWhiteListRepositoryInterface $emailWhiteListRepository,
        Header $httpHeader,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper
    ) {
        $this->logger = $logger;
        $this->magentoOrderCollectionFactory = $magentoOrderCollectionFactory;
        $this->timezone = $timezone;
        $this->config = $config;
        $this->emailWhiteListRepository = $emailWhiteListRepository;
        $this->httpHeader = $httpHeader;
        $this->tamaraHelper = $tamaraHelper;
    }


    public function afterGetAvailableMethods(
        \Magento\Payment\Model\MethodList $subject,
        $availableMethods,
        ?\Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        if (is_null($quote)) {
            return $availableMethods;
        }
        if (!$this->config->isEnableTamaraPayment($quote->getStoreId())) {
            return $this->removeTamaraMethod($availableMethods);
        }
        if ($this->tamaraHelper->isAdminArea()) {
            return $this->removeTamaraMethod($availableMethods);
        }

        if (!$this->tamaraHelper->isAllowedCurrency($quote->getCurrency()->getQuoteCurrencyCode(), $quote->getStoreId())) {
            return $this->removeTamaraMethod($availableMethods);
        }

        //Remove Tamara payment for these products
        $excludeProductIds = explode(",", strval($this->config->getExcludeProductIds($quote->getStoreId())));
        $quoteItems = $quote->getItems();
        if (!is_array($quoteItems)) {
            return $availableMethods;
        }
        foreach ($quoteItems as $item) {

            /**
             * @var \Magento\Quote\Model\Quote\Item $item
             */
            if (in_array($item->getProductId(), $excludeProductIds)) {
                return $this->removeTamaraMethod($availableMethods);
            }
        }

        //If block webview
        $userAgent = $this->httpHeader->getHttpUserAgent();
        if ($this->config->isBlockWebViewEnabled($quote->getStoreId())) {
            if (!$this->isWebView($userAgent) || $this->isRestful()) {
                return $this->removeTamaraMethod($availableMethods);
            }
        }

        //If enable whitelist
        if ($this->config->getIsUseWhitelist($quote->getStoreId())) {
            if ($quote->getCustomerIsGuest()) {
                return $this->removeTamaraMethod($availableMethods);
            }

            $email = $quote->getCustomer()->getEmail();
            if (!$email || !$this->emailWhiteListRepository->isEmailWhitelisted($email)) {
                return $this->removeTamaraMethod($availableMethods);
            }
        }

        $supportedMethods = $this->tamaraHelper->getPaymentTypesForQuote($quote);
        if (empty($supportedMethods)) {
            return $this->removeTamaraMethod($availableMethods);
        }
        return $this->filterUnsupportedMethods($supportedMethods, $availableMethods);
    }

    private function filterUnsupportedMethods($supportedMethods, $availableMethods) {
        foreach ($availableMethods as $key => $method) {
            $methodCode = $method->getCode();
            if (PaymentHelper::isTamaraPayment($methodCode) && !isset($supportedMethods[$methodCode])) {
                unset($availableMethods[$key]);
            }
        }
        return $availableMethods;
    }

    private function isWebView(string $userAgent): bool
    {
        $this->logger->debug(['Tamara - User Agent' => $userAgent]);

        if ((strpos($userAgent, 'Mobile/') !== false) && (strpos($userAgent, 'Safari/') === false)) {
            return true;
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return true;
        }

        return false;
    }

    private function isRestful(): bool
    {
        if ($this->isAjaxRequest()) {
            return false;
        }

        $uri = $this->httpHeader->getRequestUri();

        return preg_match('/\/rest\//m', $uri);
    }

    private function isAjaxRequest(): bool
    {
        /** @var ObjectManagerInterface $om */
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var RequestInterface|Http $request */
        $request = $om->get(RequestInterface::class);

        return $request->isXmlHttpRequest();
    }

    private function removeTamaraMethod($availableMethods): array
    {
        foreach ($availableMethods as $key => $method) {
            if (PaymentHelper::isTamaraPayment($method->getCode())) {
                unset($availableMethods[$key]);
            }
        }

        return $availableMethods;
    }

    private function filterUnderOverLimit($availableMethods, $price, $storeId) {
        $storeCurrency = $this->tamaraHelper->getStoreCurrencyCode($storeId);
        $paymentTypes = $this->tamaraHelper->getPaymentTypes(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency],
            $storeCurrency, $storeId);
        foreach ($availableMethods as $key => $method) {
            $methodCode = $method->getCode();
            if (!PaymentHelper::isTamaraPayment($methodCode)) {
                continue;
            }
            if ($price < $paymentTypes[$methodCode]['min_limit'] || $price > $paymentTypes[$methodCode]['max_limit']) {
                unset($availableMethods[$key]);
            }
        }
        return $availableMethods;
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return array|null
     */
    private function getPaymentTypesForCustomer(\Magento\Quote\Api\Data\CartInterface $quote) {
        try {
            $shippingAddress = $this->tamaraHelper->getShippingAddressFromQuote($quote);
            $reject = false;
            if (empty($shippingAddress) || empty($shippingAddress->getTelephone()) || empty($shippingAddress->getCountryId())) {
                $reject = true;
            }
            if ($reject) {
                return null;
            }
            $riskAssessment = new RiskAssessment(
                $this->getRiskAssessmentData($quote)
            );
            return $this->tamaraHelper->getPaymentTypesV2(
                new \Tamara\Model\Money(floatval($quote->getGrandTotal()), $this->getCurrencyCodeFromQuote($quote)),
                $shippingAddress->getCountryId(), $this->getOrderItemCollectionFromQuote($quote),
                $this->getConsumer($quote), $this->getAddressFromQuote($quote), $riskAssessment,
                $this->getAdditionalDataFromQuote($quote),
                $quote->getStoreId()
            );
        } catch (\Exception $exception) {
            return [];
        }
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return OrderItemCollection|null
     */
    public function getOrderItemCollectionFromQuote(\Magento\Quote\Api\Data\CartInterface $quote) {
        try {
            $orderItemCollection = new OrderItemCollection();
            $currencyCode = $this->getCurrencyCodeFromQuote($quote);
            $items = $quote->getItems();
            if (!is_null($items)) {
                foreach ($items as $item) {
                    $orderItem = new OrderItem();
                    $orderItem = $orderItem->setName($item->getName())
                        ->setReferenceId($item->getItemId())
                        ->setSku($item->getSku())
                        ->setType($item->getProductType())
                        ->setQuantity($item->getQty())
                        ->setUnitPrice(
                            new Money(floatval($item->getPrice()), $currencyCode)
                        )->setTotalAmount(
                            new Money(floatval($item->getRowTotalInclTax()), $currencyCode)
                        )->setTaxAmount(
                            new Money(floatval($item->getTaxAmount()), $currencyCode)
                        )->setDiscountAmount(
                            new Money(floatval($item->getDiscountAmount()), $currencyCode)
                        )->setImageUrl('');
                    $orderItemCollection->append($orderItem);
                }
            }
            return $orderItemCollection;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return Consumer
     */
    public function getConsumer(\Magento\Quote\Api\Data\CartInterface $quote) {
        $consumer = new Consumer();
        $shippingAddress = $this->tamaraHelper->getShippingAddressFromQuote($quote);
        $customerDob = '';
        $isFirstOrder = true;
        if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
            $customerObj = $quote->getCustomer();
            $customerDob = strval($customerObj->getDob());
            if (!empty($customerDob)) {
                $customerDob = \DateTime::createFromFormat('Y-m-d', $customerDob, $this->getMagentoTimezone($quote->getStoreId()))
                    ->format('d-m-Y');
            }
            $magentoOrderCollection = $this->magentoOrderCollectionFactory->create($customerObj->getId());
            if ($magentoOrderCollection->getSize() > 0) {
                $isFirstOrder = false;
            }
        }
        $consumer = $consumer->setFirstName(strval($shippingAddress->getFirstname()))
            ->setLastName(strval($shippingAddress->getLastname()))
            ->setPhoneNumber(strval($shippingAddress->getTelephone()))
            ->setEmail(strval($shippingAddress->getEmail()))
            ->setNationalId(strval($shippingAddress->getCountryId()))
            ->setDateOfBirth($customerDob)
            ->setIsFirstOrder($isFirstOrder);
        return $consumer;
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return array
     */
    public function getRiskAssessmentData(\Magento\Quote\Api\Data\CartInterface $quote) {
        try {
            $riskAssessmentData = [];
            $timezone = $this->getMagentoTimezone($quote->getStoreId());
            if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
                $customerObj = $quote->getCustomer();
                $customerDob = strval($customerObj->getDob());
                if (!empty($customerDob)) {
                    $customerAge = \DateTime::createFromFormat('Y-m-d', $customerDob, $timezone)
                        ->diff(new \DateTime('now', $timezone))
                        ->y;
                    $riskAssessmentData['customer_age'] = $customerAge;
                    $riskAssessmentData['customer_dob'] = \DateTime::createFromFormat('Y-m-d', $customerDob, $timezone)->format('d-m-Y');
                }
                $riskAssessmentData['customer_gender'] = ($customerObj->getGender() == 1) ? 'Male' : 'Female';
                $customerAddresses = $customerObj->getAddresses();
                if (!is_null($customerAddresses)) {
                    foreach ($customerAddresses as $customerAddress) {
                        if ($customerAddress->isDefaultShipping()) {
                            $riskAssessmentData['customer_nationality'] = $customerAddress->getCountryId();
                        }
                    }
                }
                $riskAssessmentData['is_existing_customer'] = true;
                $riskAssessmentData['is_guest_user'] = false;

                $customerObjCreatedAt = strval($customerObj->getCreatedAt());
                if (!empty($customerObjCreatedAt)) {
                    $riskAssessmentData['account_creation_date'] = \DateTime::createFromFormat('Y-m-d', explode(" ", $customerObjCreatedAt)[0], $timezone)
                        ->format('d-m-Y');
                }
                $magentoOrderCollection = $this->magentoOrderCollectionFactory->create($customerObj->getId());
                $magentoOrderCollection->setOrder('created_at', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
                $hasCompletedOrder = false;
                $firstOrder = false;
                $totalOrderCount = 0;
                $date3monthsAgo = new \DateTime('now', $timezone);
                $date3monthsAgo->modify('-3 month');
                $orderCountLast3Months = 0;
                $orderAmountLast3Months = 0.0;
                $lastCustomerOrder = null;
                $lastCustomerOrderCreatedAt = "";
                foreach ($magentoOrderCollection as $customerOrder) {
                    $customerOrderCreatedAt = strval($customerOrder->getCreatedAt());
                    if (!$firstOrder) {
                        $riskAssessmentData['date_of_first_transaction'] = \DateTime::createFromFormat('Y-m-d', explode(" ", $customerOrderCreatedAt)[0], $timezone)
                            ->format('d-m-Y');
                        $firstOrder = true;
                    }
                    if ($customerOrder->getStatus() == "complete") {
                        $hasCompletedOrder = true;
                    }
                    $orderCreatedDate = \DateTime::createFromFormat('Y-m-d', explode(" ", $customerOrderCreatedAt)[0], $timezone);
                    if ($orderCreatedDate > $date3monthsAgo) {
                        $orderCountLast3Months++;
                        $orderAmountLast3Months += floatval($customerOrder->getGrandTotal());
                    }
                    $lastCustomerOrder = $customerOrder;
                    $lastCustomerOrderCreatedAt = $customerOrderCreatedAt;
                    $totalOrderCount++;
                }
                $riskAssessmentData['has_delivered_order'] = $hasCompletedOrder;
                $riskAssessmentData['total_order_count'] = $totalOrderCount;
                $riskAssessmentData['order_amount_last3months'] = $orderAmountLast3Months;
                $riskAssessmentData['order_count_last3months'] = $orderCountLast3Months;
                $riskAssessmentData['last_order_date'] = \DateTime::createFromFormat('Y-m-d', explode(" ", $lastCustomerOrderCreatedAt)[0], $timezone)
                    ->format('d-m-Y');
                $riskAssessmentData['last_order_amount'] = $lastCustomerOrder->getGrandTotal();
            } else {
                $shippingAddress = $this->tamaraHelper->getShippingAddressFromQuote($quote);
                $riskAssessmentData['customer_nationality'] = $shippingAddress->getCountryId();
                $riskAssessmentData['is_existing_customer'] = false;
                $riskAssessmentData['is_guest_user'] = true;
            }

            return $riskAssessmentData;
        } catch (\Exception $exception) {
            return [];
        }
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return \Tamara\Model\Order\Address
     */
    public function getAddressFromQuote(\Magento\Quote\Api\Data\CartInterface $quote) {
        $address = new \Tamara\Model\Order\Address();
        $shippingAddress = $this->tamaraHelper->getShippingAddressFromQuote($quote);
        $street = $shippingAddress->getStreet();
        $streetClone = array_values($street);
        $address = $address->setFirstName(strval($shippingAddress->getFirstname()))
            ->setLastName(strval($shippingAddress->getLastname()))
            ->setLine1($streetClone[0] ?? '')
            ->setLine2($streetClone[1] ?? '')
            ->setRegion(strval($shippingAddress->getRegion()))
            ->setPostalCode(strval($shippingAddress->getPostcode()))
            ->setCity(strval($shippingAddress->getCity()))
            ->setCountryCode(strval($shippingAddress->getCountryId()))
            ->setPhoneNumber(strval($shippingAddress->getTelephone()));
        return $address;
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCurrencyCodeFromQuote(\Magento\Quote\Api\Data\CartInterface $quote) {
        return strval($this->tamaraHelper->getStoreCurrencyCode($quote->getStoreId()));
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return array
     */
    public function getAdditionalDataFromQuote(\Magento\Quote\Api\Data\CartInterface $quote) {
        $additionalData = [];
        $shippingAddress = $this->tamaraHelper->getShippingAddressFromQuote($quote);
        if ($shippingAddress->getAddressType() == "shipping") {
            $additionalData['delivery_method'] = $shippingAddress->getShippingDescription();
        }
        return $additionalData;
    }

    /**
     * @param $storeId
     * @return \DateTimeZone
     */
    public function getMagentoTimezone($storeId) {
        return new \DateTimeZone($this->timezone->getConfigTimezone('stores', $storeId));
    }
}
