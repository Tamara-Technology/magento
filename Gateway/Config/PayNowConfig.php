<?php
namespace Tamara\Checkout\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Config\Config as MagentoPaymentConfig;

class PayNowConfig extends MagentoPaymentConfig
{
    const PAY_NOW = 'PAY_NOW';
    const PAYMENT_TYPE_CODE = 'tamara_pay_now',
        TITLE = 'title',
        ACTIVE = 'active';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param null|string $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        $methodCode = self::PAYMENT_TYPE_CODE,
        $pathPattern = MagentoPaymentConfig::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }
}
