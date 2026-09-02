<?php

namespace Tamara\Checkout\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Model\StoreManagerInterface;
use Tamara\Checkout\Model\Helper\ProductHelper;

class Capture extends \Tamara\Checkout\Helper\AbstractData
{
    /**
     * @var \Magento\Sales\Model\OrderRepository
     */
    protected $magentoOrderRepository;

    /**
     * @var \Tamara\Checkout\Model\CaptureRepository
     */
    protected $captureRepository;

    /**
     * @var \Tamara\Checkout\Api\OrderRepositoryInterface
     */
    protected $tamaraOrderRepository;

    /**
     * @var \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory
     */
    protected $tamaraAdapterFactory;

    /**
     * @var ProductHelper
     */
    protected $productHelper;

    /**
     * @var Invoice
     */
    protected $tamaraInvoiceHelper;

    public function __construct(
        Context $context,
        \Magento\Framework\Locale\Resolver $locale,
        StoreManagerInterface $storeManager,
        \Magento\Framework\App\CacheInterface $magentoCache,
        \Tamara\Checkout\Gateway\Config\BaseConfig $tamaraConfig,
        \Magento\Sales\Model\OrderRepository $magentoOrderRepository,
        \Tamara\Checkout\Api\OrderRepositoryInterface $tamaraOrderRepository,
        \Tamara\Checkout\Model\CaptureRepository $captureRepository,
        \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory $tamaraAdapterFactory,
        ProductHelper $productHelper,
        \Tamara\Checkout\Helper\Invoice $tamaraInvoiceHelper
    ) {
        $this->magentoOrderRepository = $magentoOrderRepository;
        $this->captureRepository = $captureRepository;
        $this->tamaraOrderRepository = $tamaraOrderRepository;
        $this->tamaraAdapterFactory = $tamaraAdapterFactory;
        $this->productHelper = $productHelper;
        $this->tamaraInvoiceHelper = $tamaraInvoiceHelper;
        parent::__construct($context, $locale, $storeManager, $magentoCache, $tamaraConfig, $tamaraAdapterFactory);
    }

    public function captureOrder($orderId): void
    {

        /**
         * @var $order \Magento\Sales\Model\Order
         */
        $order = $this->magentoOrderRepository->get($orderId);

        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }
        if (!$this->isTamaraPayment($payment->getMethod())) {
            return;
        }

        if (!$this->canCapture($order)) {
            $this->log(['Order cannot capture'], true);
            return;
        }

        $this->log(["Capture order " . $orderId]);

        $tamaraOrder = $this->tamaraOrderRepository->getTamaraOrderByOrderId($order->getId());
        $data['order_id'] = $order->getId();
        $data['tamara_order_id'] = $tamaraOrder->getTamaraOrderId();
        $data['total_amount'] = $order->getGrandTotal();
        $data['tax_amount'] = $order->getTaxAmount();
        $data['shipping_amount'] = $order->getShippingAmount();
        $data['discount_amount'] = $order->getDiscountAmount();
        $data['shipping_info'] = $order->getTracksCollection()->toArray() ?? [];
        $data['currency'] = $order->getOrderCurrencyCode();

        $data['items'] = [];
        foreach ($order->getItems() as $orderItem) {
            $totalAmount = $this->getRowTotalItem($orderItem);
            if (empty($totalAmount)) {
                continue;
            }
            $itemTemp = [];
            $itemTemp['order_item_id'] = $orderItem->getItemId();
            $itemTemp['type'] = $orderItem->getProductType();
            $itemTemp['total_amount'] = $totalAmount;
            $itemTemp['tax_amount'] = $orderItem->getTaxAmount();
            $itemTemp['discount_amount'] = $orderItem->getDiscountAmount();
            $itemTemp['unit_price'] = $orderItem->getPrice();
            $itemTemp['name'] = $orderItem->getName();
            $itemTemp['sku'] = $orderItem->getSku();
            $itemTemp['quantity'] = $this->getQty($orderItem);
            $itemTemp['image_url'] = $this->productHelper->getImageFromProductId($orderItem->getProductId());
            $data['items'][] = $itemTemp;
        }

        $tamaraAdapter = $this->tamaraAdapterFactory->create($order->getStoreId());
        $this->log([sprintf('Capture when order status is %s', $order->getStatus())]);
        $tamaraAdapter->capture($data, $order);
    }

    /**
     * Check order that can capture (both partially or fully)
     * @param $order \Magento\Sales\Model\Order
     * @return bool
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function canCapture(\Magento\Sales\Model\Order $order): bool
    {
        $captures = $this->captureRepository->getCaptureByConditions(['order_id' => $order->getId()]);
        if (!count($captures)) {
            return true;
        }
        $capturedAmount = 0;
        foreach ($captures as $row) {
            $capturedAmount += $row['total_amount'];
        }
        if (empty($order->getTotalPaid()) || $capturedAmount < $order->getTotalPaid()) {
            return true;
        }
        return false;
    }

    /**
     * @param OrderItemInterface $item
     * @return float
     */
    private function getRowTotalItem(OrderItemInterface $item): float
    {
        if ($item->getRowTotal() === null || !$item->getRowTotal()) {
            return 0.0;
        }

        return floatval($item->getRowTotal()
            - $item->getDiscountAmount()
            + $item->getTaxAmount()
            + $item->getDiscountTaxCompensationAmount());
    }

    /**
     * @param OrderItemInterface $item
     * @return int
     */
    private function getQty(OrderItemInterface $item): int
    {
        if ($qtyShipped = intval($item->getQtyShipped())) {
            return $qtyShipped;
        }
        if ($qtyInvoiced = intval($item->getQtyInvoiced())) {
            return $qtyInvoiced;
        }
        if ($qtyOrdered = intval($item->getQtyOrdered())) {
            return $qtyOrdered;
        }
    }
}