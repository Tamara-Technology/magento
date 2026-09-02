<?php

namespace Tamara\Checkout\Model;

class ScanOrder
{

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $helper;

    /**
     * @var \Tamara\Checkout\Gateway\Config\BaseConfig
     */
    protected $config;

    /**
     * @var Adapter\TamaraAdapterFactory
     */
    protected $tamaraAdapterFactory;
    /**
     * @var OrderRepository
     */
    protected $tamaraOrderRepository;
    /**
     * @var \Tamara\Checkout\Helper\Refund
     */
    protected $tamaraRefundHelper;
    /**
     * @var \Tamara\Checkout\Helper\Capture
     */
    protected $tamaraCaptureHelper;
    /**
     * @var \Tamara\Checkout\Helper\Cancel
     */
    protected $tamaraCancelHelper;
    /**
     * @var ResourceModel\Order\CollectionFactory
     */
    private $tamaraOrderCollectionFactory;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    private $magentoOrderCollectionFactory;
    /**
     * @var \Magento\Sales\Model\OrderRepository
     */
    private $magentoOrderRepository;

    private $totalOrderProcessed = 0;

    private $scanFromConsole = false;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $magentoOrderCollectionFactory,
        \Magento\Sales\Model\OrderRepository $magentoOrderRepository,
        \Tamara\Checkout\Helper\AbstractData $helper,
        \Tamara\Checkout\Model\ResourceModel\Order\CollectionFactory $tamaraOrderCollectionFactory,
        \Tamara\Checkout\Gateway\Config\BaseConfig $config,
        \Tamara\Checkout\Model\OrderRepository $tamaraOrderRepository,
        \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory $adapter,
        \Tamara\Checkout\Helper\Refund $tamaraRefundHelper,
        \Tamara\Checkout\Helper\Capture $tamaraCaptureHelper,
        \Tamara\Checkout\Helper\Cancel $tamaraCancelHelper
    ) {
        $this->magentoOrderCollectionFactory = $magentoOrderCollectionFactory;
        $this->magentoOrderRepository = $magentoOrderRepository;
        $this->helper = $helper;
        $this->tamaraOrderCollectionFactory = $tamaraOrderCollectionFactory;
        $this->config = $config;
        $this->tamaraOrderRepository = $tamaraOrderRepository;
        $this->tamaraAdapterFactory = $adapter;
        $this->tamaraRefundHelper = $tamaraRefundHelper;
        $this->tamaraCaptureHelper = $tamaraCaptureHelper;
        $this->tamaraCancelHelper = $tamaraCancelHelper;
    }

    /**
     * @param string $startTime
     * @param string $endTime
     * @throws \Exception
     */
    public function scan($startTime = '-10 days', $endTime = 'now')
    {
        $this->log(["Start scan orders"]);

        try {
            $startTime = gmdate('Y-m-d H:i:s', strtotime($startTime));
            $endTime = gmdate('Y-m-d H:i:s', strtotime($endTime));
        } catch (\Exception $exception) {
            $this->log(["Error when scan order, time format is not support" => $exception->getMessage()], true);
            throw $exception;
        }
        $tamaraOrderCollection = $this->tamaraOrderCollectionFactory->create();
        $tamaraOrderCollection->addFieldToFilter('created_at', ['gteq' => $startTime, 'lteq' => $endTime]);
        $tamaraOrderCollection->addFieldToFilter('is_authorised', 1);
        $orderIds = [];
        foreach ($tamaraOrderCollection as $tamaraOrder) {
            $orderIds[] = $tamaraOrder->getOrderId();
        }

        if (count($orderIds)) {
            //scan capture
            $magentoOrderCollection = $this->getMagentoOrderCollection($orderIds, $this->config->getOrderStatusShouldBeCaptured());
            $orderIdsFiltered = $this->getOrderIdsFromCollection($magentoOrderCollection);
            $this->doAction($orderIdsFiltered, 'capture');

            //scan refund
            if (!empty($refundedStatus = $this->config->getOrderStatusShouldBeRefunded())) {
                $orderIds = array_diff($orderIds, $orderIdsFiltered);
                $magentoOrderCollection = $this->getMagentoOrderCollection($orderIds, $refundedStatus);
                $orderIdsFiltered = $this->getOrderIdsFromCollection($magentoOrderCollection);
                $this->doAction($orderIdsFiltered, 'refund');
            }

            //scan cancel
            $orderIds = array_diff($orderIds, $orderIdsFiltered);
            $magentoOrderCollection = $this->getMagentoOrderCollection($orderIds, $this->config->getCheckoutCancelStatus());
            $orderIdsFiltered = $this->getOrderIdsFromCollection($magentoOrderCollection);
            $this->doAction($orderIdsFiltered, 'cancel');
        }

        $this->log(["Total order processed: " . $this->totalOrderProcessed]);
        $this->log(["End scan orders"]);
    }

    private function getOrderIdsFromCollection($magentoOrderCollection) {
        $result = [];
        foreach ($magentoOrderCollection as $order) {
            $result[] = $order->getEntityId();
        }
        return $result;
    }

    private function doAction(array $orderIds, $action) {
        if (count($orderIds)) {
            foreach ($orderIds as $orderId) {
                $this->execute($action, $orderId);
            }
        }
    }

    private function getMagentoOrderCollection($orderIdsToFilter, $statusToFilter) {
        $magentoOrderCollection = $this->magentoOrderCollectionFactory->create();
        $magentoOrderCollection->addFieldToFilter('entity_id', ['in' => $orderIdsToFilter]);
        $magentoOrderCollection->addFieldToFilter('status', $statusToFilter);
        $magentoOrderCollection->addFieldToSelect(['entity_id']);
        $magentoOrderCollection->addFieldToSelect(['status']);
        return $magentoOrderCollection;
    }

    /**
     * @param array $data
     */
    protected function log(array $data, $forceLog = false)
    {
        $this->helper->log($data, $forceLog);
    }

    /**
     * @param $action
     * @param $orderId
     */
    private function execute($action, $orderId)
    {
        $method = $action . "Order";
        if (method_exists($this, $method)) {
            try {
                $this->$method($orderId);
            } catch (\Exception $exception) {
                $this->log(["Error when scan order" => $exception->getMessage()], true);
            }
        }
    }

    /**
     * @param $orderId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function refundOrder($orderId)
    {
        $tamaraOrder = $this->tamaraOrderRepository->getTamaraOrderByOrderId($orderId);
        if ($this->isScanFromConsole()) {
            $this->tamaraRefundHelper->refundOrder($orderId);
            $tamaraOrder->setRefundedFromConsole(true)->save();
        } else {
            $this->tamaraRefundHelper->refundOrder($orderId);
        }
        $this->log(["Processed refund order id: " . $orderId]);
        $this->totalOrderProcessed++;
    }

    /**
     * @param $orderId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function captureOrder($orderId)
    {
        $tamaraOrder = $this->tamaraOrderRepository->getTamaraOrderByOrderId($orderId);
        if ($this->isScanFromConsole()) {
            if (!$tamaraOrder->getCapturedFromConsole()) {
                $this->tamaraCaptureHelper->captureOrder($orderId);
                $tamaraOrder->setCapturedFromConsole(true)->save();
            }
        } else {
            $this->tamaraCaptureHelper->captureOrder($orderId);
        }
        $this->log(["Processed capture order id: " . $orderId]);
        $this->totalOrderProcessed++;
    }

    /**
     * @param $orderId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function cancelOrder($orderId)
    {
        $tamaraOrder = $this->tamaraOrderRepository->getTamaraOrderByOrderId($orderId);
        if ($this->isScanFromConsole()) {
            $this->tamaraCancelHelper->cancelOrder($orderId);
            $tamaraOrder->setCanceledFromConsole(true)->save();
        } else {
            $this->tamaraCancelHelper->cancelOrder($orderId);
        }
        $this->log(["Processed cancel order id: " . $orderId]);
        $this->totalOrderProcessed++;
    }

    /**
     * @param bool $value
     */
    public function setScanFromConsole(bool $value)
    {
        $this->scanFromConsole = $value;
    }

    /**
     * @return bool
     */
    public function isScanFromConsole() {
        return $this->scanFromConsole !== false;
    }
}