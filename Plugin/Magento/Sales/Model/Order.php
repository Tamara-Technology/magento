<?php

namespace Tamara\Checkout\Plugin\Magento\Sales\Model;

class Order
{
    private $tamaraOrderRepository;

    public function __construct(
        \Tamara\Checkout\Api\OrderRepositoryInterface $tamaraOrderRepository
    )
    {
        $this->tamaraOrderRepository = $tamaraOrderRepository;
    }

    public function afterGetPayment(\Magento\Sales\Model\Order $subject, $result)
    {
        if ($result === null || $subject->getEntityId() === null) {
            return $result;
        }
        $resultMethod = $result->getMethod();
        if ($resultMethod != \Tamara\Checkout\Gateway\Config\SingleCheckoutConfig::PAYMENT_TYPE_CODE) {
            return $result;
        }
        try {
            $order = $this->tamaraOrderRepository->getTamaraOrderByOrderId($subject->getEntityId());
        } catch (\Exception $exception) {
            return $result;
        }
        $paymentMethod = $order->getPaymentType();
        if ($paymentMethod != $resultMethod
            && \Tamara\Checkout\Model\Helper\PaymentHelper::isTamaraPayment($paymentMethod)
        ) {
            $result->setMethod($paymentMethod);
        }
        return $result;
    }
}
