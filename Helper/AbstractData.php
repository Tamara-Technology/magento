<?php

namespace Tamara\Checkout\Helper;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Model\Helper\PaymentHelper;
use Tamara\Checkout\Gateway\Config\InstalmentConfig;
use Tamara\Checkout\Gateway\Validator\CountryValidator;
use Tamara\Client;
use Tamara\Configuration;
use Tamara\Exception\RequestException;
use Tamara\Model\Money;
use Tamara\Request\Checkout\PreCheckoutEligibilityRequest;

class AbstractData extends \Tamara\Checkout\Helper\Core
{
    const PAYMENT_TYPES_CACHE_IDENTIFIER = 'payment_types_cache';
    const PAYMENT_TYPES_CACHE_LIFE_TIME = 1800; //30 minutes
    const SINGLE_CHECKOUT_CACHE_LIFE_TIME = 86400; //1 day
    const ORDER_PAYMENT_TYPES_CACHE_LIFE_TIME = 300; //5 minutes
    const PRECHECK_TIMEOUT = 2; //seconds
    const COUNTRY_CALLING_CODES = [
        'SA' => '966',
        'AE' => '971',
        'KW' => '965',
        'BH' => '973',
        'QA' => '974',
        'OM' => '968',
    ];

    /**
     * @var \Magento\Framework\Locale\Resolver
     */
    protected $locale;

    /**
     * @var \Magento\Framework\App\CacheInterface
     */
    protected $magentoCache;

    /**
     * @var BaseConfig
     */
    protected $tamaraConfig;

    /**
     * @var \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory
     */
    protected $tamaraAdapterFactory;

    /**
     * @var \Magento\Payment\Model\Method\Logger
     */
    private $tamaraPaymentLogger;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(
        Context $context,
        \Magento\Framework\Locale\Resolver $locale,
        StoreManagerInterface $storeManager,
        \Magento\Framework\App\CacheInterface $magentoCache,
        BaseConfig $tamaraConfig,
        \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory $tamaraAdapterFactory
    ) {
        $this->locale = $locale;
        $this->magentoCache = $magentoCache;
        $this->tamaraConfig = $tamaraConfig;
        $this->tamaraAdapterFactory = $tamaraAdapterFactory;
        parent::__construct($context, \Magento\Framework\App\ObjectManager::getInstance(), $storeManager);
    }

    /**
     * @return bool
     */
    public function isArabicLanguage()
    {
        return $this->startsWith($this->getLocale(), 'ar_');
    }

    /**
     * @return string|null
     */
    public function getLocale()
    {
        return $this->locale->getLocale();
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreCurrencyCode($storeId = null)
    {
        return $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
    }

    /**
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCurrentStore()
    {
        return $this->storeManager->getStore();
    }

    public function log(array $data, $forceLog = false)
    {
        if (!empty($data)) {
            reset($data);
            $firstKey = key($data);
            if (is_string($firstKey)) {
                if (!$this->startsWith(strtolower($firstKey), "tamara")) {

                    //Add Tamara to first key
                    $firstValue = $data[$firstKey];
                    $newKey = "Tamara - " . $firstKey;
                    unset($data[$firstKey]);
                    $newArray = [$newKey => $firstValue] + $data;
                    $data = $newArray;
                    $firstKey = $newKey;
                }
                if ($this->getOutput()) {
                    $this->output->writeln($firstKey);
                }
            } else {
                if (!empty($data[0]) && is_string($data[0])) {
                    if (!$this->startsWith(strtolower($data[0]), "tamara")) {
                        $data[0] = "Tamara - " . $data[0];
                    }
                    if ($this->getOutput()) {
                        $this->output->writeln($data[0]);
                    }
                }
            }
            if ($forceLog) {
                $this->getLogger()->debug($data, null, true);
            } else {
                $this->getLogger()->debug($data, null, $this->tamaraConfig->enabledDebug());
            }
        }
    }

    /**
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @return \Magento\Payment\Model\Method\Logger
     */
    public function getLogger()
    {
        if (!$this->tamaraPaymentLogger) {
            try {
                $this->tamaraPaymentLogger = $this->getObject('TamaraCheckoutLogger');
            } catch (\Exception $exception) {
                $this->tamaraPaymentLogger = $this->createObject('TamaraCheckoutLogger');
            }
        }
        return $this->tamaraPaymentLogger;
    }

    public function isTamaraPayment($method)
    {
        return PaymentHelper::isTamaraPayment($method);
    }

    /**
     * @param string $countryCode
     * @param string $currencyCode
     * @param int $storeId
     * @return array|mixed
     */
    public function getPaymentTypes($countryCode = 'SA', $currencyCode = '',  $storeId = 0) {
        return $this->buildSingleCheckoutPaymentType($countryCode, $currencyCode);
    }

    /**
     * @param \Tamara\Model\Money $totalAmount
     * @param string $countryCode
     * @param null $items
     * @param null $consumer
     * @param null $shippingAddress
     * @param null $riskAssessment
     * @param array $additionalData
     * @param int $storeId
     * @return array
     */
    public function getPaymentTypesV2(\Tamara\Model\Money $totalAmount, string $countryCode, $items = null,
        $consumer = null, $shippingAddress = null, $riskAssessment = null, $additionalData = [], $storeId = 0) {
        $adapter = $this->tamaraAdapterFactory->create($storeId);
        if ($adapter->getDisableTamara()) {
            return [];
        }
        try {
            $request = new \Tamara\Request\Checkout\GetPaymentTypesV2Request(
                $totalAmount, $countryCode, $items, $consumer, $shippingAddress, $riskAssessment, $additionalData
            );
            $response = $adapter->getClient()->getPaymentTypesV2($request);
            return $adapter->parsePaymentTypesResponse($response);
        } catch (RequestException $requestException) {
            $adapter->setDisableTamara(true);
            $this->getLogger()->debug(["Tamara" => $requestException->getMessage()]);
        } catch (\Exception $exception) {
            $this->getLogger()->debug(["Tamara" => $exception->getMessage()]);
        }
        return [];
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return \Magento\Quote\Api\Data\AddressInterface|\Magento\Quote\Model\Quote\Address
     */
    public function getShippingAddressFromQuote(\Magento\Quote\Api\Data\CartInterface $quote) {
        $shippingAddress = $quote->getShippingAddress();
        $useBillingAddress = false;
        if ($shippingAddress && $shippingAddress->getId()) {
            $shippingMethod = strval($shippingAddress->getShippingMethod());

            /**
             * @var \Tamara\Checkout\Model\AddressRepository $tamaraAddressRepositoryObj
             */
            $tamaraAddressRepositoryObj = $this->createObject(\Tamara\Checkout\Model\AddressRepository::class);
            foreach ($tamaraAddressRepositoryObj->getClickAndCollectMethods() as $method) {
                if ($this->startsWith($shippingMethod, $method)) {
                    $useBillingAddress = true;
                    break;
                }
            }
        } else {
            $useBillingAddress = true;
        }
        if ($useBillingAddress) {
            $shippingAddress = $quote->getBillingAddress();
        }
        return $shippingAddress;
    }

    /**
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     */
    public function getCheckoutCountryCode($quote, $storeId = null) {
        $storeId = $storeId ?? ($quote ? $quote->getStoreId() : null);
        $shippingAddress = $quote ? $this->getShippingAddressFromQuote($quote) : null;
        if ($shippingAddress && !empty($shippingAddress->getCountryId())) {
            return (string) $shippingAddress->getCountryId();
        }
        $billingAddress = $quote ? $quote->getBillingAddress() : null;
        if ($billingAddress && !empty($billingAddress->getCountryId())) {
            return (string) $billingAddress->getCountryId();
        }
        $storeCountry = (string) $this->getStoreCountryCode($storeId);
        if ($storeCountry !== '') {
            return $storeCountry;
        }
        $storeCurrency = $this->getStoreCurrencyCode($storeId);
        return CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency] ?? '';
    }

    public function getPaymentTypesForQuote($quote) {
        $storeId = $quote->getStoreId();
        $storeCurrency = $this->getStoreCurrencyCode($storeId);
        $countryCode = $this->getCheckoutCountryCode($quote, $storeId);
        if (empty($countryCode)) {
            return [];
        }
        $shippingAddress = $this->getShippingAddressFromQuote($quote);
        $phoneNumber = '';
        $email = (string) $quote->getCustomerEmail();
        if ($shippingAddress !== null) {
            if (!empty($shippingAddress->getTelephone())) {
                $phoneNumber = (string) $shippingAddress->getTelephone();
            }
            if (empty($email) && !empty($shippingAddress->getEmail())) {
                $email = (string) $shippingAddress->getEmail();
            }
        }
        return $this->getPaymentTypesByOrderInfo(
            $countryCode,
            $storeCurrency,
            floatval($quote->getGrandTotal()),
            $phoneNumber,
            true,
            $storeId,
            $email
        );
    }

    public function getPaymentTypesByOrderInfo($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip = true, $storeId = 0, $email = '') {
        // With debug on, skip the payment-types cache so each checkout load hits Precheck.
        $useCache = !$this->tamaraConfig->enabledDebug($storeId);
        $cacheKey = $countryCode . $currencyCode . strval($orderValue) . $phoneNumber . $email . strval(intval($isVip)) . strval($storeId);
        if ($useCache && ($val = $this->magentoCache->load($cacheKey)) !== false) {
            if (empty($val)) {
                return [];
            }
            return json_decode($val, true);
        }
        $paymentTypes = $this->checkPaymentOptionsAvailability($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip, $storeId, $email)['payment_types'];
        if ($useCache) {
            $this->magentoCache->save(json_encode($paymentTypes), $cacheKey, [], self::ORDER_PAYMENT_TYPES_CACHE_LIFE_TIME);
        }
        return $paymentTypes;
    }

    public function checkPaymentOptionsAvailability($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip = true, $storeId = 0, $email = '') {
        $result = [
            'has_available_payment_options' => false,
            'single_checkout_enabled' => false,
            'payment_types' => []
        ];
        if (!$this->tamaraConfig->isEnableTamaraPayment($storeId)) {
            return $result;
        }
        if (!isset(CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$currencyCode])
            || CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$currencyCode] != $countryCode
        ) {
            return $result;
        }
        $adapter = $this->tamaraAdapterFactory->create($storeId);
        if ($adapter->getDisableTamara()) {
            return $result;
        }
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber, $countryCode);
        $request = new PreCheckoutEligibilityRequest(
            new Money($orderValue, $currencyCode),
            $phoneNumber,
            $email !== '' ? $email : null
        );
        try {
            $response = $this->createPrecheckClient($storeId)->preCheckoutEligibility($request);
            if ($response->isSuccess() && $response->isEligible()) {
                $result = $this->eligibleSingleCheckoutResult($countryCode, $currencyCode);
                $this->getLogger()->debug(["Tamara Precheck result" => $result]);
            }
        } catch (RequestException $requestException) {
            if ($this->isHttpTimeout($requestException)) {
                $this->getLogger()->debug(["Tamara Precheck timeout, treating as eligible" => $requestException->getMessage()]);
                $result = $this->eligibleSingleCheckoutResult($countryCode, $currencyCode);
            } else {
                $adapter->setDisableTamara(true);
                $this->getLogger()->debug(["Tamara" => $requestException->getMessage()]);
            }
        } catch (\Exception $exception) {
            if ($this->isHttpTimeout($exception)) {
                $this->getLogger()->debug(["Tamara Precheck timeout, treating as eligible" => $exception->getMessage()]);
                $result = $this->eligibleSingleCheckoutResult($countryCode, $currencyCode);
            } else {
                $this->getLogger()->debug(["Tamara" => $exception->getMessage()]);
            }
        }
        return $result;
    }

    public function buildSingleCheckoutPaymentType($countryCode, $currencyCode) {
        $copy = $this->getSingleCheckoutCopy($countryCode);
        $methodCode = InstalmentConfig::PAYMENT_TYPE_CODE;
        return [
            $methodCode => [
                'name' => $methodCode,
                'currency' => $currencyCode,
                'description' => $copy['description'],
                'description_ar' => $copy['description_ar'],
                'min_limit' => 1,
                'max_limit' => 999999999,
                'title' => $copy['title'],
                'is_installment' => true,
                'is_none_validated_method' => false,
                'number_of_instalments' => 3,
                'country_code' => $countryCode,
            ]
        ];
    }

    public function getSingleCheckoutCopy($countryCode) {
        $isKsa = strtoupper((string) $countryCode) === 'SA';
        if ($this->isArabicLanguage()) {
            return [
                'title' => 'تمارا',
                'description' => $isKsa ? 'دفعات شهرية. متوافقة مع الشريعة' : 'دفعات شهرية',
                'description_ar' => $isKsa ? 'دفعات شهرية. متوافقة مع الشريعة' : 'دفعات شهرية',
            ];
        }
        return [
            'title' => 'Tamara',
            'description' => $isKsa ? 'Monthly Payments. Sharia Compliant.' : 'Monthly Payments.',
            'description_ar' => $isKsa ? 'دفعات شهرية. متوافقة مع الشريعة' : 'دفعات شهرية',
        ];
    }

    public function normalizePhoneNumber($phoneNumber, $countryCode): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $phoneNumber);
        if ($phone === '' || $phone === null) {
            return null;
        }
        if (strpos($phone, '00') === 0) {
            $phone = substr($phone, 2);
        }
        $callingCode = preg_replace('/\D+/', '', self::COUNTRY_CALLING_CODES[strtoupper((string) $countryCode)] ?? '');
        if ($callingCode !== '' && strpos($phone, $callingCode) === 0) {
            $nationalNumber = substr($phone, strlen($callingCode));
            if (strpos($nationalNumber, '0') === 0) {
                $nationalNumber = substr($nationalNumber, 1);
            }
            return $nationalNumber !== '' ? $callingCode . $nationalNumber : null;
        }
        if (strpos($phone, '0') === 0) {
            $phone = substr($phone, 1);
        }
        if ($phone === '') {
            return null;
        }
        if ($callingCode !== '') {
            $phone = $callingCode . $phone;
        }
        return $phone;
    }

    public function formatCheckoutPhoneNumber($phoneNumber, $countryCode): ?string
    {
        $phone = $this->normalizePhoneNumber($phoneNumber, $countryCode);
        return $phone !== null ? '+' . $phone : null;
    }

    private function createPrecheckClient($storeId): Client
    {
        $config = Configuration::create(
            (string) $this->tamaraConfig->getApiUrl($storeId),
            (string) $this->tamaraConfig->getMerchantToken($storeId),
            self::PRECHECK_TIMEOUT
        );
        return Client::create($config);
    }

    private function eligibleSingleCheckoutResult($countryCode, $currencyCode): array
    {
        return [
            'has_available_payment_options' => true,
            'single_checkout_enabled' => true,
            'payment_types' => $this->buildSingleCheckoutPaymentType($countryCode, $currencyCode),
        ];
    }

    private function isHttpTimeout(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return strpos($message, 'timed out') !== false
            || strpos($message, 'timeout') !== false
            || $exception->getCode() === 28;
    }

    /**
     * @param CheckPaymentOptionsAvailabilityResponse $response
     * @param $currencyCode
     * @param $storeId
     * @return array
     */
    public function parsePaymentOptionsAvailabilityResponse($response, $currencyCode, $storeId) {
        $result = [
            'has_available_payment_options' => false,
            'single_checkout_enabled' => false,
            'payment_types' => []
        ];
        if ($response->isSuccess()) {
            $result['has_available_payment_options'] = $response->hasAvailablePaymentOptions();
            $result['single_checkout_enabled'] = $response->isSingleCheckoutEnabled();
            $paymentTypes = [];
            foreach ($response->getAvailablePaymentLabels() as $paymentType) {
                $typeName = "";
                if ($paymentType['payment_type'] == \Tamara\Checkout\Gateway\Config\PayLaterConfig::PAY_BY_LATER) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayLaterConfig::PAYMENT_TYPE_CODE;
                }
                if ($paymentType['payment_type'] == \Tamara\Checkout\Gateway\Config\PayNextMonthConfig::PAY_NEXT_MONTH) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayNextMonthConfig::PAYMENT_TYPE_CODE;
                }
                if ($paymentType['payment_type'] == \Tamara\Checkout\Gateway\Config\InstalmentConfig::PAY_BY_INSTALMENTS || !empty($paymentType['instalment'])) {
                    $typeName = \Tamara\Checkout\Gateway\Config\InstalmentConfig::getInstallmentPaymentCode($paymentType['instalment']);
                }
                if (empty($typeName)) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayNowConfig::PAYMENT_TYPE_CODE;
                }
                $title = $paymentType['description_ar'];
                if (!$this->isArabicLanguage()) {
                    $title = $paymentType['description_en'];
                }
                $paymentTypes[$typeName] = [
                    'name' => $typeName,
                    'currency' => $currencyCode,
                    'description' => $paymentType['description_en'],
                    'description_ar' => $paymentType['description_ar'],
                    'min_limit' => 1,
                    'max_limit' => 999999999,
                    'title' => $title,
                    'is_installment' => ($paymentType['payment_type'] == \Tamara\Checkout\Gateway\Config\InstalmentConfig::PAY_BY_INSTALMENTS),
                    'is_none_validated_method' => false
                ];
                if (empty($paymentType['instalment'])) {
                    $paymentTypes[$typeName]['number_of_instalments'] = 3;
                } else {
                    $paymentTypes[$typeName]['number_of_instalments'] = $paymentType['instalment'];
                }
            }
            $result['payment_types'] = $paymentTypes;
        }

        return $result;
    }

    /**
     * @param $currency
     * @param $storeId
     * @return bool
     */
    public function isAllowedCurrency($currency, $storeId) {
        if (!in_array($currency, explode(',', \Tamara\Checkout\Model\Method\Checkout::ALLOWED_CURRENCIES))) {
            return false;
        }
        return $this->isAllowedCountry(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$currency], $storeId);
    }

    /**
     * @param $country
     * @param $storeId
     * @return bool
     */
    public function isAllowedCountry($country, $storeId) {
        if (!in_array($country, explode(',', \Tamara\Checkout\Model\Method\Checkout::ALLOWED_COUNTRIES))) {
            return false;
        }
        if ((int)$this->getTamaraConfig()->getValue('allowspecific', $storeId) === 1) {
            $availableCountries = explode(
                ',',
                strval($this->getTamaraConfig()->getValue('specificcountry', $storeId))
            );

            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param $storeId
     * @return array|mixed
     * @throws \Tamara\Exception\RequestDispatcherException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getPaymentTypesOfStore($storeId = null) {
        if (is_null($storeId)) {
            $storeId = $this->getCurrentStore()->getId();
        }

        $storeCurrencyCode = $this->getStoreCurrencyCode($storeId);
        if (!$this->isAllowedCurrency($storeCurrencyCode, $storeId)) {
            return [];
        }
        $paymentTypes = $this->getPaymentTypes(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrencyCode], $storeCurrencyCode, $storeId);
        foreach ($paymentTypes as $methodCode => $paymentType) {
            if (!$this->isPaymentMethodEnabled($methodCode, $storeId)) {
                unset($paymentTypes[$methodCode]);
            }
        }
        return $paymentTypes;
    }

    /**
     * @param array $paymentTypes
     * @param $countryCode
     * @param $currencyCode
     * @param int $storeId
     * @param int $lifeTime
     */
    private function cachePaymentTypes(array $paymentTypes, $countryCode, $currencyCode, $storeId, $lifeTime = self::PAYMENT_TYPES_CACHE_LIFE_TIME) {
        $paymentTypesAsStr = json_encode($paymentTypes);
        $this->magentoCache->save($paymentTypesAsStr, $this->getPaymentTypesCacheIdentifier($countryCode, $currencyCode, $storeId), [],
            $lifeTime);
    }

    /**
     * @param $countryCode
     * @param $currencyCode
     * @param $storeId
     * @return array|mixed
     */
    private function getPaymentTypesCached($countryCode, $currencyCode, $storeId) {
        $cachedStr = $this->magentoCache->load($this->getPaymentTypesCacheIdentifier($countryCode, $currencyCode, $storeId));
        if ($cachedStr === false) {
            return $cachedStr;
        }
        if (empty($cachedStr)) {
            return [];
        }
        return json_decode($cachedStr, true);
    }

    /**
     * @param $countryCode
     * @param $currencyCode
     * @param $storeId
     * @return string
     */
    protected function getPaymentTypesCacheIdentifier($countryCode, $currencyCode, $storeId) {
        return self::PAYMENT_TYPES_CACHE_IDENTIFIER . $countryCode. $currencyCode . $storeId;
    }

    /**
     * @param null $storeId
     * @return mixed
     */
    public function getStoreCountryCode($storeId = null) {
        return $this->scopeConfig->getValue("general/country/default", ScopeInterface::SCOPE_STORES, $storeId);
    }

    /**
     * @param $paymentMethodCode
     * @param $storeId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function isPaymentMethodEnabled($paymentMethodCode, $storeId = null) {
        if ($storeId === null) {
            $storeId = $this->getCurrentStore()->getId();
        }
        return $this->tamaraConfig->isEnableTamaraPayment($storeId);
    }

    public function getTamaraConfig() {
        return $this->tamaraConfig;
    }

    public function isSingleCheckoutEnabled($storeId = null) {
        if ($storeId === null) {
            $scope = $this->getCurrentScope();
            $storeId = $this->getCurrentScopeId();
        } else {
            $scope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        }
        return boolval($this->getSingleCheckoutCached($scope, $storeId));
    }

    private function getSingleCheckoutCached($scope, $scopeId) {
        return $this->magentoCache->load($this->getSingleCheckoutCacheIdentifier($scope, $scopeId));
    }

    private function getSingleCheckoutCacheIdentifier($scope, $scopeId) {
        return 'single_checkout_enabled' . $scope . $scopeId;
    }

    public function setSingleCheckoutEnabled($singleCheckoutValueCached, $scope, $storeId) {
        //set cache
        $this->magentoCache->save(intval($singleCheckoutValueCached),
            $this->getSingleCheckoutCacheIdentifier($scope, $storeId), [], self::SINGLE_CHECKOUT_CACHE_LIFE_TIME);
    }


    /**
     * @return string
     */
    public function getWidgetVersion() {
        return 'v2';
    }

    public function getMerchantPublicKey($storeId = null) {
        if ($storeId === null) {
            $storeId = $this->getCurrentStore()->getId();
        }
        return $this->getTamaraConfig()->getPublicKey($storeId);
    }
}