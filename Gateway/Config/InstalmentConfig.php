<?php
namespace Tamara\Checkout\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Config\Config as MagentoPaymentConfig;

class InstalmentConfig extends MagentoPaymentConfig
{
    const PAY_BY_INSTALMENTS = 'PAY_BY_INSTALMENTS';
    const PAYMENT_TYPE_CODE = 'tamara_pay_by_instalments',
          TITLE = 'title',
          ACTIVE = 'active';
    const PAYMENT_TYPE_CODE_2 = 'tamara_pay_by_instalments_2';
    const PAYMENT_TYPE_CODE_4 = 'tamara_pay_by_instalments_4';
    const PAYMENT_TYPE_CODE_5 = 'tamara_pay_by_instalments_5';
    const PAYMENT_TYPE_CODE_6 = 'tamara_pay_by_instalments_6';
    const PAYMENT_TYPE_CODE_7 = 'tamara_pay_by_instalments_7';
    const PAYMENT_TYPE_CODE_8 = 'tamara_pay_by_instalments_8';
    const PAYMENT_TYPE_CODE_9 = 'tamara_pay_by_instalments_9';
    const PAYMENT_TYPE_CODE_10 = 'tamara_pay_by_instalments_10';
    const PAYMENT_TYPE_CODE_11 = 'tamara_pay_by_instalments_11';
    const PAYMENT_TYPE_CODE_12 = 'tamara_pay_by_instalments_12';

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $tamaraHelper;

    protected $numberOfInstallments;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param \Tamara\Checkout\Helper\AbstractData $tamaraHelper
     * @param string $pathPattern
     * @param int $numberOfInstallments
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper,
        $pathPattern = MagentoPaymentConfig::DEFAULT_PATH_PATTERN,
        $numberOfInstallments = 3
    ) {
        parent::__construct($scopeConfig, $this->getInstallmentPaymentCode($numberOfInstallments), $pathPattern);
        $this->tamaraHelper = $tamaraHelper;
        $this->numberOfInstallments = $numberOfInstallments;
    }

    /**
     * @param int $numberOfInstallments
     * @return string
     */
    public static function getInstallmentPaymentCode($numberOfInstallments = 3) {
        $numberOfInstallments = intval($numberOfInstallments);
        if ($numberOfInstallments == 0) {
            $numberOfInstallments = 3;
        }
        if ($numberOfInstallments == 3 || $numberOfInstallments > 12) {
            return self::PAYMENT_TYPE_CODE;
        }
        return self::PAYMENT_TYPE_CODE . "_" . $numberOfInstallments;
    }

    /**
     * @param string $paymentMethodCode
     * @return int
     */
    public static function getInstallmentsNumberByPaymentCode($paymentMethodCode = self::PAYMENT_TYPE_CODE) {
        if ($paymentMethodCode == self::PAYMENT_TYPE_CODE) {
            return 3;
        }
        return intval(substr($paymentMethodCode, -1));
    }

    /**
     * @param $paymentMethodCode
     * @return bool
     */
    public static function isInstallmentsPayment($paymentMethodCode) {
        if (empty($paymentMethodCode)) {
            return false;
        }
        $installmentPattern = "/^(tamara_pay_by_instalments)([_][0-9]+)?$/";
        return boolval(preg_match($installmentPattern, $paymentMethodCode));
    }
}
