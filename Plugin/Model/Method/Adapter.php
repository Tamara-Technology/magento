<?php

namespace Tamara\Checkout\Plugin\Model\Method;

class Adapter {
    protected $checkoutSession;
    protected $tamaraHelper;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->tamaraHelper = $tamaraHelper;
    }

    public function afterGetTitle(\Magento\Payment\Model\Method\Adapter $subject,
        $result
    ) {
        $quote = $this->checkoutSession->getQuote();
        $paymentCode = $subject->getCode();
        if ($this->tamaraHelper->isTamaraPayment($paymentCode)) {
            $paymentTypes = $this->tamaraHelper->getPaymentTypesForQuote($quote);
            if (isset($paymentTypes[$paymentCode])) {
                return $paymentTypes[$paymentCode]['title'];
            }
        }
        return $result;
    }

    public function afterIsActive(\Magento\Payment\Model\Method\Adapter $subject, $result, $storeId = null) {
        $paymentCode = $subject->getCode();
        if ($this->tamaraHelper->isTamaraPayment($paymentCode)) {
            return true;
        }
        return $result;
    }
}
