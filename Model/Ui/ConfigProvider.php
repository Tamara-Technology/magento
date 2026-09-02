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

    public function __construct(
        \Magento\Framework\Locale\Resolver $locale,
        PayLaterConfig $payLaterConfig,
        InstalmentConfig $instalmentConfig,
        BaseConfig $baseConfig,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper
    ) {
        $this->locale = $locale;
        $this->payLaterConfig = $payLaterConfig;
        $this->instalmentConfig = $instalmentConfig;
        $this->baseConfig = $baseConfig;
        $this->tamaraHelper = $tamaraHelper;
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
        $countryCode = \Tamara\Checkout\Gateway\Validator\CountryValidator::CURRENCIES_COUNTRIES_ALLOWED[$storeCurrency];
        $config = [
            'tamara' => [
                'use_magento_checkout_success' => $this->baseConfig->useMagentoCheckoutSuccessPage(),
                'locale_code' => $this->getLocale(),
                'public_key' => strval($this->tamaraHelper->getMerchantPublicKey()),
                'country_code' => $countryCode,
                'currency_code' => $storeCurrency,
                'language' => substr($this->getLocale(), 0, 2),
                'enable_credit_pre_check' => true,
                'is_single_checkout_enabled' => true,
                'widget_version' => $this->tamaraHelper->getWidgetVersion(),
                'single_checkout' => [
                    'title' => $this->tamaraHelper->getSingleCheckoutTitle(),
                    'ksa_country_code' => \Tamara\Checkout\Helper\AbstractData::KSA_COUNTRY_CODE,
                    'default_country_code' => strval($this->tamaraHelper->getCheckoutCountryCode(null, $storeId, $countryCode)),
                    'descriptions' => [
                        'ksa' => $this->tamaraHelper->getSingleCheckoutDescription(
                            \Tamara\Checkout\Helper\AbstractData::KSA_COUNTRY_CODE
                        ),
                        'default' => $this->tamaraHelper->getSingleCheckoutDescription('')
                    ]
                ],
                'payment_types' => []
            ]
        ];
        $config['tamara']['payment_types'] = $this->tamaraHelper->getPaymentTypes($countryCode, $storeCurrency, $storeId);
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