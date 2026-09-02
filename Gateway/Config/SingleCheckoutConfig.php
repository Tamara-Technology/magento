<?php

namespace Tamara\Checkout\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Config\Config as MagentoPaymentConfig;

class SingleCheckoutConfig extends MagentoPaymentConfig
{
    const PAYMENT_TYPE_CODE = 'tamara_single_checkout',
        TITLE = 'title',
        ACTIVE = 'active';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $serializer,
        $methodCode = self::PAYMENT_TYPE_CODE,
        $pathPattern = MagentoPaymentConfig::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }
}
