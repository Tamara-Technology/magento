<?php

namespace Tamara\Checkout\Plugin\Magento\Sales\Block\Adminhtml\Order\View\Tab;

class Info
{
    private $orderRepository;
    protected $tamaraHelper;
    public function __construct(
        \Tamara\Checkout\Model\OrderRepository $orderRepository,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper
    )
    {
        $this->orderRepository = $orderRepository;
        $this->tamaraHelper = $tamaraHelper;
    }

    public function afterGetPaymentHtml(\Magento\Sales\Block\Adminhtml\Order\View\Tab\Info $subject, $result)
    {
        try {
            $order = $subject->getOrder();
            if (!\Tamara\Checkout\Model\Helper\PaymentHelper::isTamaraPayment($order->getPayment()->getMethod())) {
                return $result;
            }
            $additionalInfo = "";
            $tamaraOrder = $this->orderRepository->getTamaraOrderByOrderId($order->getId());
            if (\Tamara\Checkout\Gateway\Config\InstalmentConfig::isInstallmentsPayment($tamaraOrder->getPaymentType())) {
                if (!empty($tamaraOrder->getNumberOfInstallments())) {
                    $additionalInfo .= ("<br />Number of installments: " . $tamaraOrder->getNumberOfInstallments());
                } else {
                    $additionalInfo .= ("<br />Number of installments: NA");
                }
            }
            if (!empty($additionalInfo)) {
                $additionalInfo .= "<br />";
            }
            return ($result . $additionalInfo);
        } catch (\Exception $e) {
            /**
             * @var \Magento\Payment\Model\Method\Logger $logger
             */
            $logger = $this->tamaraHelper->getObject('TamaraCheckoutLogger');
            $logger->debug(["Tamara - Error when add additional payment info" => $e->getMessage()], null, true);
        }
        return $result;
    }
}