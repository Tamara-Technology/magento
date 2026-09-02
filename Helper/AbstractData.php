<?php

namespace Tamara\Checkout\Helper;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Gateway\Config\SingleCheckoutConfig;
use Tamara\Checkout\Model\Helper\PaymentHelper;
use Tamara\Exception\RequestException;
use Tamara\Model\Money;
use Tamara\Request\Checkout\PreCheckoutEligibilityRequest;
use Tamara\Response\Checkout\CheckPaymentOptionsAvailabilityResponse;

class AbstractData extends \Tamara\Checkout\Helper\Core
{
    const PAYMENT_TYPES_CACHE_IDENTIFIER = 'payment_types_cache';
    const PAYMENT_TYPES_CACHE_LIFE_TIME = 1800; //30 minutes
    const SINGLE_CHECKOUT_CACHE_LIFE_TIME = 86400; //1 day
    const ORDER_PAYMENT_TYPES_CACHE_LIFE_TIME = 300; //5 minutes
    const PRE_CHECKOUT_ELIGIBILITY_CACHE_LIFE_TIME = 60; //1 minute

    const KSA_COUNTRY_CODE = 'SA';
    const SINGLE_CHECKOUT_TITLE_EN = 'Tamara';
    const SINGLE_CHECKOUT_TITLE_AR = 'تمارا';
    const SINGLE_CHECKOUT_DESCRIPTION_KSA_EN = 'Monthly Payments. Sharia Compliant.';
    const SINGLE_CHECKOUT_DESCRIPTION_KSA_AR = 'دفعات شهرية. متوافقة مع الشريعة';
    const SINGLE_CHECKOUT_DESCRIPTION_EN = 'Monthly Payments.';
    const SINGLE_CHECKOUT_DESCRIPTION_AR = 'دفعات شهرية';

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
        return [];
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
    public function getPaymentTypesForQuote($quote) {
        $storeId = $quote->getStoreId();
        if (!$this->tamaraConfig->isEnableTamaraPayment($storeId)) {
            return [];
        }
        $storeCurrency = strval($quote->getCurrency()->getQuoteCurrencyCode());
        $shippingAddress = $this->getShippingAddressFromQuote($quote);
        $countryCode = '';
        $checkoutCountryCode = '';
        $phoneNumber = null;
        $email = null;
        if (isset(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency])) {
            $countryCode = \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency];
        }
        if ($shippingAddress !== null) {
            if (!empty($shippingAddress->getCountryId())) {
                $countryCode = $shippingAddress->getCountryId();
                $checkoutCountryCode = $countryCode;
            }
            if (!empty($shippingAddress->getTelephone())) {
                $phoneNumber = strval($shippingAddress->getTelephone());
            }
            if (!empty($shippingAddress->getEmail())) {
                $email = strval($shippingAddress->getEmail());
            }
        }
        if (empty($email) && !empty($quote->getCustomerEmail())) {
            $email = strval($quote->getCustomerEmail());
        }
        if (empty($email) && $quote->getCustomer() && !empty($quote->getCustomer()->getEmail())) {
            $email = strval($quote->getCustomer()->getEmail());
        }
        if (empty($countryCode)
            || !isset(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency])
            || \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency] != $countryCode
            || !$this->isAllowedCurrency($storeCurrency, $storeId)
        ) {
            return [];
        }

        if (empty($checkoutCountryCode)) {
            $checkoutCountryCode = $this->getCheckoutCountryCode(null, $storeId, $countryCode);
        }

        $amount = floatval($quote->getGrandTotal());
        $ignoreCache = $this->tamaraConfig->enabledDebug($storeId);
        $cacheKey = $this->getPreCheckoutEligibilityCacheIdentifier(
            $amount,
            $storeCurrency,
            $phoneNumber,
            $email,
            $storeId
        );
        if (!$ignoreCache) {
            $cached = $this->magentoCache->load($cacheKey);
            if ($cached !== false) {
                return $cached === '1' ? $this->getSingleCheckoutPaymentType($checkoutCountryCode) : [];
            }
        }

        // Eligibility failures intentionally fail open so checkout latency does not hide Tamara.
        $isEligible = true;
        $hasDefinitiveResponse = false;
        try {
            $request = new PreCheckoutEligibilityRequest(
                new Money($amount, $storeCurrency),
                $phoneNumber,
                $email
            );
            $response = $this->tamaraAdapterFactory->create($storeId)
                ->getPreCheckoutEligibilityClient()
                ->preCheckoutEligibility($request);
            if ($response && $response->isSuccess()) {
                $content = json_decode($response->getContent(), true);
                if (is_array($content) && array_key_exists('is_eligible', $content)
                    && is_bool($content['is_eligible'])
                ) {
                    $isEligible = $response->isEligible();
                    $hasDefinitiveResponse = true;
                }
            }
        } catch (\Throwable $exception) {
            $this->getLogger()->debug(["Tamara - Pre-checkout eligibility" => $exception->getMessage()]);
        }

        if ($hasDefinitiveResponse && !$ignoreCache) {
            $this->magentoCache->save(
                $isEligible ? '1' : '0',
                $cacheKey,
                [],
                self::PRE_CHECKOUT_ELIGIBILITY_CACHE_LIFE_TIME
            );
        }

        return $isEligible ? $this->getSingleCheckoutPaymentType($checkoutCountryCode) : [];
    }

    /**
     * The country the shopper checks out with: taken from the checkout form when it is filled in,
     * otherwise from the store configuration. A store country Tamara does not operate in (for instance
     * a SAR store still left on the default US country) is ignored in favour of the currency country.
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @param int|null $storeId
     * @param string $fallbackCountryCode
     * @return string
     */
    public function getCheckoutCountryCode($quote = null, $storeId = null, $fallbackCountryCode = '') {
        if ($quote !== null) {
            $shippingAddress = $this->getShippingAddressFromQuote($quote);
            if ($shippingAddress !== null && !empty($shippingAddress->getCountryId())) {
                return strval($shippingAddress->getCountryId());
            }
            if ($storeId === null) {
                $storeId = $quote->getStoreId();
            }
        }

        $storeCountryCode = strval($this->getStoreCountryCode($storeId));
        $supportedCountries = \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED;
        if ($storeCountryCode !== ''
            && ($fallbackCountryCode === '' || in_array($storeCountryCode, $supportedCountries, true))
        ) {
            return $storeCountryCode;
        }

        return strval($fallbackCountryCode);
    }

    /**
     * @param string $countryCode
     * @return array
     */
    private function getSingleCheckoutPaymentType($countryCode) {
        return [
            SingleCheckoutConfig::PAYMENT_TYPE_CODE => [
                'title' => $this->getSingleCheckoutTitle(),
                'description' => $this->getSingleCheckoutDescription($countryCode)
            ]
        ];
    }

    /**
     * @return string
     */
    public function getSingleCheckoutTitle() {
        return $this->isArabicLanguage() ? self::SINGLE_CHECKOUT_TITLE_AR : self::SINGLE_CHECKOUT_TITLE_EN;
    }

    /**
     * Sharia compliance is only claimed for KSA.
     *
     * @param string $countryCode
     * @return string
     */
    public function getSingleCheckoutDescription($countryCode) {
        $isKsa = strtoupper(strval($countryCode)) === self::KSA_COUNTRY_CODE;
        if ($this->isArabicLanguage()) {
            return $isKsa ? self::SINGLE_CHECKOUT_DESCRIPTION_KSA_AR : self::SINGLE_CHECKOUT_DESCRIPTION_AR;
        }

        return $isKsa ? self::SINGLE_CHECKOUT_DESCRIPTION_KSA_EN : self::SINGLE_CHECKOUT_DESCRIPTION_EN;
    }

    private function getPreCheckoutEligibilityCacheIdentifier(
        $amount,
        $currency,
        $phone,
        $email,
        $storeId
    ) {
        return 'tamara_pre_checkout_eligibility_' . hash(
            'sha256',
            implode('|', [strval($amount), $currency, strval($phone), strval($email), strval($storeId)])
        );
    }

    public function getPaymentTypesByOrderInfo($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip = true, $storeId = 0) {
        $cacheKey = $countryCode . $currencyCode . strval($orderValue) . $phoneNumber . strval(intval($isVip)) . strval($storeId);
        if (($val = $this->magentoCache->load($cacheKey)) !== false) {
            if (empty($val)) {
                return [];
            }
            return json_decode($val, true);
        }
        $paymentTypes = $this->checkPaymentOptionsAvailability($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip, $storeId)['payment_types'];
        $this->magentoCache->save(json_encode($paymentTypes), $cacheKey, [], self::ORDER_PAYMENT_TYPES_CACHE_LIFE_TIME);
        return $paymentTypes;
    }

    public function checkPaymentOptionsAvailability($countryCode, $currencyCode, $orderValue, $phoneNumber, $isVip = true, $storeId = 0) {
        $result = [
            'has_available_payment_options' => false,
            'single_checkout_enabled' => false,
            'payment_types' => []
        ];
        if (!isset(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$currencyCode])
        || \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$currencyCode] != $countryCode
        ) {
            return $result;
        }
        $adapter = $this->tamaraAdapterFactory->create($storeId);
        if ($adapter->getDisableTamara()) {
            return $result;
        }
        try {
            $paymentOptionsAvailability = new \Tamara\Model\Checkout\PaymentOptionsAvailability(
                $countryCode,
                new \Tamara\Model\Money($orderValue, $currencyCode),
                $phoneNumber,
                $isVip
            );
            $request = new \Tamara\Request\Checkout\CheckPaymentOptionsAvailabilityRequest($paymentOptionsAvailability);
            $response = $adapter->getClient()->checkPaymentOptionsAvailability($request);
            $result = $this->parsePaymentOptionsAvailabilityResponse($response, $currencyCode, $storeId);
        } catch (RequestException $requestException) {
            $adapter->setDisableTamara(true);
        } catch (\Exception $exception) {
            $this->getLogger()->debug(["Tamara" => $exception->getMessage()]);
        }
        if ($this->isSingleCheckoutEnabled($storeId) != $result['single_checkout_enabled']) {
            $this->setSingleCheckoutEnabled($result['single_checkout_enabled'], \Magento\Store\Model\ScopeInterface::SCOPE_STORES , $storeId);
        }
        return $result;
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
                if (!empty($typeName)) {
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