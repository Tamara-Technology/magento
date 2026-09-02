<?php

namespace Tamara\Checkout\Cron;

use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\App\ResourceConnection;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Helper\OrderAuthorization;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;
use Tamara\Checkout\Model\OrderRepository;
use Tamara\Response\Order\GetOrderResponse;

class OrderStatusSync
{
    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $helper;

    /**
     * @var BaseConfig
     */
    protected $config;

    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var \Tamara\Checkout\Model\ResourceModel\Order\CollectionFactory
     */
    private $tamaraOrderCollectionFactory;

    /**
     * @var TamaraAdapterFactory
     */
    private $tamaraAdapterFactory;

    /**
     * @var OrderRepository
     */
    private $tamaraOrderRepository;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Tamara\Checkout\Helper\Transaction
     */
    private $tamaraTransactionHelper;

    /**
     * @var \Tamara\Checkout\Helper\Invoice
     */
    private $tamaraInvoiceHelper;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $orderSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender
     */
    private $orderCommentSender;

    /**
     * @var OrderAuthorization
     */
    private $orderAuthorizationHelper;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @param Registry $coreRegistry
     * @param TimezoneInterface $timezone
     * @param ResourceConnection $resourceConnection
     * @param \Tamara\Checkout\Helper\AbstractData $helper
     * @param \Tamara\Checkout\Model\ResourceModel\Order\CollectionFactory $tamaraOrderCollectionFactory
     * @param TamaraAdapterFactory $tamaraAdapterFactory
     * @param OrderRepository $tamaraOrderRepository
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Tamara\Checkout\Helper\Transaction $tamaraTransactionHelper
     * @param \Tamara\Checkout\Helper\Invoice $tamaraInvoiceHelper
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender
     * @param OrderAuthorization $orderAuthorizationHelper
     * @param BaseConfig $config
     */
    public function __construct(
        Registry                                                     $coreRegistry,
        TimezoneInterface                                            $timezone,
        ResourceConnection                                           $resourceConnection,
        \Tamara\Checkout\Helper\AbstractData                         $helper,
        \Tamara\Checkout\Model\ResourceModel\Order\CollectionFactory $tamaraOrderCollectionFactory,
        TamaraAdapterFactory                                         $tamaraAdapterFactory,
        OrderRepository                                              $tamaraOrderRepository,
        \Magento\Sales\Api\OrderRepositoryInterface                  $orderRepository,
        \Tamara\Checkout\Helper\Transaction                          $tamaraTransactionHelper,
        \Tamara\Checkout\Helper\Invoice                              $tamaraInvoiceHelper,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender          $orderSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender   $orderCommentSender,
        OrderAuthorization                                           $orderAuthorizationHelper,
        BaseConfig                                                   $config
    )
    {
        $this->coreRegistry = $coreRegistry;
        $this->timezone = $timezone;
        $this->resourceConnection = $resourceConnection;
        $this->helper = $helper;
        $this->tamaraOrderCollectionFactory = $tamaraOrderCollectionFactory;
        $this->tamaraAdapterFactory = $tamaraAdapterFactory;
        $this->tamaraOrderRepository = $tamaraOrderRepository;
        $this->orderRepository = $orderRepository;
        $this->tamaraTransactionHelper = $tamaraTransactionHelper;
        $this->tamaraInvoiceHelper = $tamaraInvoiceHelper;
        $this->orderSender = $orderSender;
        $this->orderCommentSender = $orderCommentSender;
        $this->orderAuthorizationHelper = $orderAuthorizationHelper;
        $this->config = $config;
    }

    /**
     * Execute cron job
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->config->isOrderStatusSyncEnabled()) {
            return;
        }

        $this->helper->log(["Run order status sync from cron"]);
        $beforeTime = $this->config->getOrderStatusSyncTime();
        $this->syncOrderStatus($beforeTime);
        $this->helper->log(["Done"]);
    }

    /**
     * Sync order status with Tamara API
     *
     * @param string $beforeTime Time interval string (e.g., "-40 minutes")
     * @param int|null $storeId Store ID
     * @return void
     */
    public function syncOrderStatus($beforeTime, $storeId = null)
    {
        // Use safe timezone conversion
        $beforeTimeUtc = $this->convertTimeToUtc($beforeTime, $storeId);

        $this->helper->log(["Processing orders created before: " . $beforeTimeUtc . " UTC"]);

        $tamaraOrderCollection = $this->tamaraOrderCollectionFactory->create();
        $salesOrderTable = $this->resourceConnection->getTableName('sales_order');
        $tamaraOrderCollection->addFieldToFilter('main_table.created_at', ['lt' => $beforeTimeUtc]);
        $tamaraOrderCollection->addFieldToFilter('main_table.is_authorised', 0);
        $tamaraOrderCollection->addFieldToFilter('main_table.canceled_from_console', ['eq' => false]);
        $tamaraOrderCollection->addFieldToSelect(['order_id', 'tamara_order_id']);
        $tamaraOrderCollection->getSelect()->join(['so' => $salesOrderTable], "main_table.order_id = so.entity_id", ['so.store_id'])
            ->where('so.state = ?', 'new');
        if ($storeId) {
            $tamaraOrderCollection->getSelect()->where('so.store_id = ?', $storeId);
        }
        $tamaraOrderCollection->getSelect()->limit(30);

        $totalOrdersProcessed = 0;
        $totalOrdersAuthorized = 0;
        $totalOrdersCancelled = 0;

        foreach ($tamaraOrderCollection as $tamaraOrder) {
            try {
                $orderId = $tamaraOrder->getOrderId();
                if (!$storeId) {
                    $storeId = $tamaraOrder->getStoreId();
                }
                $tamaraOrderId = $tamaraOrder->getTamaraOrderId();

                if (empty($tamaraOrderId)) {
                    $this->helper->log(["Order " . $orderId . " has no Tamara order ID, skipping"], true);
                    continue;
                }

                $adapter = $this->tamaraAdapterFactory->create($storeId);
                $client = $adapter->getClient();

                // Print current time before call Tamara Get Order API
                $this->helper->log(["Current time before call Tamara Get Order API: " . date('Y-m-d H:i:s')]);

                // Get order status from Tamara API
                $remoteOrder = $client->getOrder(new \Tamara\Request\Order\GetOrderRequest($tamaraOrderId));

                if (!$remoteOrder->isSuccess()) {
                    $this->helper->log(['Failed to get order status for order ' . $orderId,
                    'Message: ' => $remoteOrder->getMessage(),
                    'Status code: ' => $remoteOrder->getStatusCode()], true);

                    // Set canceled_from_console in Tamara order to true if the API returns 403 to skip it from next time
                    if ($remoteOrder->getStatusCode() == 403) {
                        $tamaraOrder->setCanceledFromConsole(true)  
                            ->save();
                    }
                    $this->helper->log(["Updated order " . $orderId . " to canceled_from_console = true to prevent further processing"], true);
                    continue;
                }

                $orderStatus = $remoteOrder->getStatus();
                $this->helper->log(["Order " . $orderId . " has Tamara status: " . $orderStatus]);

                $order = $this->orderRepository->get($orderId);

                if ($orderStatus === 'expired' || $orderStatus == 'declined') {
                    // Cancel the order if status is expired
                    $this->cancelOrder($order, $tamaraOrder, $orderStatus);
                    $totalOrdersCancelled++;
                } elseif ($orderStatus === 'approved') {
                    // Authorize the order if status is approved
                    $this->authorizeOrder($order, $tamaraOrder, $storeId, $remoteOrder);
                    $totalOrdersAuthorized++;
                }

                $totalOrdersProcessed++;

            } catch (\Exception $exception) {
                $this->helper->log(["Error processing order " . $tamaraOrder->getOrderId() => $exception->getMessage()], true);
            }
        }

        $this->helper->log([
            'Total orders processed: ' . $totalOrdersProcessed,
            'Total orders authorized: ' . $totalOrdersAuthorized,
            'Total orders cancelled: ' . $totalOrdersCancelled
        ]);
    }

    /**
     * Convert time interval to UTC format for database comparison
     * This method ensures timezone consistency between server and database
     *
     * @param string $timeInterval Time interval string (e.g., "-40 minutes")
     * @param int|null $storeId Store ID for timezone context
     * @return string UTC formatted datetime string
     */
    private function convertTimeToUtc($timeInterval, $storeId = null)
    {
        try {
            // Get current time in the appropriate timezone
            // If storeId is provided, use store timezone, otherwise use default
            if ($storeId) {
                $currentDateTime = $this->timezone->date(null, $storeId);
            } else {
                $currentDateTime = $this->timezone->date();
            }

            // Apply the time interval (e.g., "-40 minutes")
            $targetDateTime = clone $currentDateTime;
            $targetDateTime->modify($timeInterval);

            // Convert to UTC for database comparison (Magento stores dates in UTC)
            $targetDateTime->setTimezone(new \DateTimeZone('UTC'));

            return $targetDateTime->format('Y-m-d H:i:s');

        } catch (\Exception $e) {
            // Fallback to the old method if timezone conversion fails
            $this->helper->log(["Warning: Timezone conversion failed, using fallback method" => $e->getMessage()], true);
            return gmdate('Y-m-d H:i:s', strtotime($timeInterval));
        }
    }

    /**
     * Cancel an order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Tamara\Checkout\Model\Order $tamaraOrder
     * @return void
     */
    protected function cancelOrder($order, $tamaraOrder, $remoteOrderStatus = "expired")
    {
        try {
            $orderManagement = \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Sales\Api\OrderManagementInterface::class);
            $orderManagement->cancel($order->getId());

            // Mark the Tamara order as canceled from console
            $tamaraOrder->setCanceledFromConsole(true);
            $this->tamaraOrderRepository->save($tamaraOrder);

            if ($remoteOrderStatus ==  "declined") {
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)->setStatus($this->config->getCheckoutFailureStatus($order->getStoreId()));
            } else {
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)->setStatus($this->config->getCheckoutExpireStatus($order->getStoreId()));
            }
            $order->addCommentToStatusHistory(__('Tamara - Order was automatically cancelled because it '. $remoteOrderStatus . ' in Tamara.'), false, false);
            $this->orderRepository->save($order);

            $this->helper->log(["Cancelled ".  $remoteOrderStatus . " order " . $order->getId()]);
        } catch (\Exception $exception) {
            $this->helper->log(["Cannot cancel order " . $order->getId() => $exception->getMessage()], true);
        }
    }

    /**
     * Authorize an order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Tamara\Checkout\Model\Order $tamaraOrder
     * @param int $storeId
     * @param GetOrderResponse $remoteOrder
     * @return void
     */
    protected function authorizeOrder($order, $tamaraOrder, $storeId, $remoteOrder)
    {
        try {
            // Use the common helper to authorize the order
            $result = $this->orderAuthorizationHelper->authorizeOrder($order, $tamaraOrder, $storeId, $remoteOrder);

            if ($result) {
                $this->helper->log(["Successfully authorized order " . $order->getId()]);
            } else {
                $this->helper->log(["Failed to authorize order " . $order->getId()]);
            }
        } catch (\Exception $exception) {
            $this->helper->log(["Cannot authorize order " . $order->getId() => $exception->getMessage()], true);
        }
    }
}
