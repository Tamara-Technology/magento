<?php

namespace Tamara\Checkout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderRepositoryInterface as MagentoOrderRepository;
use Tamara\Checkout\Api\OrderRepositoryInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;
use Tamara\Checkout\Model\Helper\ProductHelper;

class OrderSaveAfter extends AbstractObserver
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var BaseConfig
     */
    protected $config;

    /**
     * @var \Tamara\Checkout\Helper\Capture
     */
    protected $captureHelper;

    public function __construct(
        Logger $logger,
        OrderRepositoryInterface $orderRepository,
        BaseConfig $config,
        \Tamara\Checkout\Helper\Capture $captureHelper
    ) {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->config = $config;
        $this->captureHelper = $captureHelper;
    }


    public function execute(Observer $observer)
    {
        $this->logger->debug(['Start to order save after event']);

        if (!$this->config->getTriggerActions()) {
            $this->logger->debug(['Turned off the trigger actions']);
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        $this->captureOrderWhenChangeStatus($order);

        $this->logger->debug(['End to order save after event']);
    }


    /**
     * @param Order $order
     * @throws \Magento\Framework\Exception\IntegrationException
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    protected function captureOrderWhenChangeStatus(Order $order): void
    {
        if (empty($this->config->getOrderStatusShouldBeCaptured())) {
            $this->logger->debug(['Capture when order status change is not set, skip capture'], null,
                $this->config->enabledDebug());
            return;
        }

        if ($order->getStatus() != $this->config->getOrderStatusShouldBeCaptured()) {
            return;
        }

        $this->captureHelper->captureOrder($order->getEntityId());
    }
}