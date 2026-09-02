<?php

namespace Tamara\Checkout\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Gateway\Config\PayLaterConfig;
use Tamara\Checkout\Gateway\Config\InstalmentConfig;

class ConfigProvider implements ConfigProviderInterface
{

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $tamaraHelper;

    /**
     * @var $locale \Magento\Framework\Locale\Resolver
     */
    private $locale;

    /**
     * @var PayLaterConfig
     */
    private $payLaterConfig;

    /**
     * @var InstalmentConfig
     */
    private $instalmentConfig;

    /**
     * @var BaseConfig
     */
    private $baseConfig;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    public function __construct(
        \Magento\Framework\Locale\Resolver $locale,
        PayLaterConfig $payLaterConfig,
        InstalmentConfig $instalmentConfig,
        BaseConfig $baseConfig,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->locale = $locale;
        $this->payLaterConfig = $payLaterConfig;
        $this->instalmentConfig = $instalmentConfig;
        $this->baseConfig = $baseConfig;
        $this->tamaraHelper = $tamaraHelper;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Tamara\Exception\RequestDispatcherException
     */
    public function getConfig()
    {
        $storeId = $this->tamaraHelper->getCurrentStore()->getId();
        $storeCurrency = $this->tamaraHelper->getStoreCurrencyCode($storeId);
        $config = [
            'tamara' => []
        ];
        if (!$this->tamaraHelper->isAllowedCurrency($storeCurrency, $storeId)) {
            return [
                'payment' => $config
            ];
        }
        $countryCode = (string) $this->tamaraHelper->getStoreCountryCode($storeId);
        if ($countryCode === '' && isset(\Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency])) {
            $countryCode = \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency];
        }
        $paymentTypes = $this->tamaraHelper->getPaymentTypes($countryCode, $storeCurrency, $storeId);
        $isSingleCheckoutEnabled = true;
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $countryCode = $this->tamaraHelper->getCheckoutCountryCode($quote, $storeId);
                $paymentTypes = $this->tamaraHelper->getPaymentTypesForQuote($quote);
                $isSingleCheckoutEnabled = $this->tamaraHelper->isSingleCheckoutEnabled($storeId);
            }
        } catch (\Exception $exception) {
            $this->tamaraHelper->log(["Tamara checkout config" => $exception->getMessage()]);
        }
        $config = [
            'tamara' => [
                'use_magento_checkout_success' => $this->baseConfig->useMagentoCheckoutSuccessPage(),
                'locale_code' => $this->getLocale(),
                'public_key' => strval($this->tamaraHelper->getMerchantPublicKey()),
                'country_code' => $countryCode,
                'currency_code' => $storeCurrency,
                'language' => substr($this->getLocale(), 0, 2),
                'enable_credit_pre_check' => true,
                'is_single_checkout_enabled' => $isSingleCheckoutEnabled,
                'widget_version' => $this->tamaraHelper->getWidgetVersion(),
                'payment_types' => $paymentTypes
            ]
        ];
        return [
            'payment' => $config
        ];
    }

    /**
     * @return string
     */
    public function getLocale() {
        return $this->locale->getLocale();
    }
}