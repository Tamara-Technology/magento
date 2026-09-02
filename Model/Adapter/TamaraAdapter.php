<?php

namespace Tamara\Checkout\Model\Adapter;

use Magento\Config\Model\ResourceModel\Config;
use Magento\Framework\Exception\IntegrationException;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Tamara\Checkout\Api\CancelRepositoryInterface;
use Tamara\Checkout\Api\CaptureRepositoryInterface;
use Tamara\Checkout\Api\OrderRepositoryInterface;
use Tamara\Checkout\Api\RefundRepositoryInterface;
use Tamara\Checkout\Model\Helper\OrderHelper;
use Tamara\Checkout\Model\Helper\PaymentHelper;
use Tamara\Client;
use Tamara\Configuration;
use Tamara\Exception\RequestDispatcherException;
use Tamara\Exception\RequestException;
use Tamara\Model\Checkout\PaymentType;
use Tamara\Model\Webhook;
use Tamara\Notification\NotificationService;
use Tamara\Request\Checkout\CreateCheckoutRequest;
use Tamara\Request\Webhook\RegisterWebhookRequest;
use Tamara\Request\Webhook\RemoveWebhookRequest;
use Tamara\Response\Checkout\CreateCheckoutResponse;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Tamara\Response\Checkout\GetPaymentTypesResponse;

class TamaraAdapter
{
    const API_REQUEST_TIMEOUT = 30; //in seconds
    const PRE_CHECKOUT_ELIGIBILITY_TIMEOUT = 2; //in seconds
    const DISABLE_TAMARA_IDENTIFIER = "DISABLE_TAMARA";
    const DISABLE_TAMARA_CACHE_LIFE_TIME = 900; //15 minutes

    private const WEBHOOK_URL = 'tamara/payment/webhook';
    const TAMARA_ORDER_EVENT_EXPIRED = 'order_expired';
    const TAMARA_ORDER_EVENT_DECLINED = 'order_declined';
    const ALLOWED_WEBHOOKS = [self::TAMARA_ORDER_EVENT_EXPIRED, self::TAMARA_ORDER_EVENT_DECLINED];
    /**
     * @var Client
     */
    private $client;

    /**
     * @var Client
     */
    private $preCheckoutEligibilityClient;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var NotificationService
     */
    private $notificationService;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CaptureRepositoryInterface
     */
    private $captureRepository;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $mageRepository;

    /**
     * @var RefundRepositoryInterface
     */
    private $refundRepository;

    /**
     * @var CancelRepositoryInterface
     */
    private $cancelRepository;

    /**
     * @var string
     */
    private $checkoutAuthoriseStatus;

    private $orderSender;

    /**
     * @var Config
     */
    private $resourceConfig;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory
     */
    private $orderStatusCollectionFactory;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender
     */
    private $orderCommentSender;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var \Tamara\Checkout\Gateway\Config\BaseConfig
     */
    protected $baseConfig;

    /**
     * @var \Tamara\Checkout\Helper\Invoice
     */
    protected $tamaraInvoiceHelper;

    /**
     * @var \Tamara\Checkout\Helper\Transaction
     */
    protected $tamaraTransactionHelper;

    protected $magentoCache;

    protected $tamaraOrderAuthorizationHelper;

    public function __construct(
        $apiUrl,
        $merchantToken,
        $notificationToken,
        $checkoutAuthoriseStatus,
        $orderRepository,
        $captureRepository,
        $mageRepository,
        $refundRepository,
        $cancelRepository,
        $logger,
        OrderSender $orderSender,
        Config $resourceConfig,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollectionFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Tamara\Checkout\Gateway\Config\BaseConfig $baseConfig,
        \Tamara\Checkout\Helper\Invoice $tamaraInvoiceHelper,
        \Tamara\Checkout\Helper\Transaction $tamaraTransactionHelper,
        \Magento\Framework\App\CacheInterface $magentoCache,
        \Tamara\Checkout\Helper\OrderAuthorization $tamaraOrderAuthorizationHelper
    )
    {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->notificationService = NotificationService::create($notificationToken);
        $config = Configuration::create($apiUrl, $merchantToken, self::API_REQUEST_TIMEOUT);
        $this->client = Client::create($config);
        $eligibilityConfig = Configuration::create(
            $apiUrl,
            $merchantToken,
            null,
            null,
            new PreCheckoutEligibilityTransport(self::PRE_CHECKOUT_ELIGIBILITY_TIMEOUT)
        );
        $this->preCheckoutEligibilityClient = Client::create($eligibilityConfig);
        $this->captureRepository = $captureRepository;
        $this->mageRepository = $mageRepository;
        $this->refundRepository = $refundRepository;
        $this->cancelRepository = $cancelRepository;
        $this->checkoutAuthoriseStatus = $checkoutAuthoriseStatus;
        $this->orderSender = $orderSender;
        $this->resourceConfig = $resourceConfig;
        $this->orderStatusCollectionFactory = $orderStatusCollectionFactory;
        $this->orderCommentSender = $orderCommentSender;
        $this->orderManagement = $orderManagement;
        $this->baseConfig = $baseConfig;
        $this->tamaraInvoiceHelper = $tamaraInvoiceHelper;
        $this->tamaraTransactionHelper = $tamaraTransactionHelper;
        $this->magentoCache = $magentoCache;
        $this->tamaraOrderAuthorizationHelper = $tamaraOrderAuthorizationHelper;
    }

    /**
     * @param string $countryCode
     * @param string $currencyCode
     * @return array|null
     */
    public function getPaymentTypes(string $countryCode, $currencyCode = '')
    {
        return [];
    }

    /**
     * @param $response GetPaymentTypesResponse
     * @return array
     */
    public function parsePaymentTypesResponse($response) {
        $paymentTypes = [];
        if ($response->isSuccess()) {

            /** @var PaymentType $paymentType */
            foreach ($response->getPaymentTypes() as $paymentType) {
                $paymentTypeClone = $paymentType;
                $typeName = "";
                if ($paymentTypeClone->getName() == \Tamara\Checkout\Gateway\Config\PayLaterConfig::PAY_BY_LATER) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayLaterConfig::PAYMENT_TYPE_CODE;
                }
                if ($paymentTypeClone->getName() == \Tamara\Checkout\Gateway\Config\PayNextMonthConfig::PAY_NEXT_MONTH) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayNextMonthConfig::PAYMENT_TYPE_CODE;
                }
                if ($paymentTypeClone->getName() == \Tamara\Checkout\Gateway\Config\PayNowConfig::PAY_NOW) {
                    $typeName = \Tamara\Checkout\Gateway\Config\PayNowConfig::PAYMENT_TYPE_CODE;
                }
                if (!empty($typeName)) {
                    $paymentTypes[$typeName] = [
                        'name' => $typeName,
                        'min_limit' => $paymentTypeClone->getMinLimit()->getAmount(),
                        'max_limit' => $paymentTypeClone->getMaxLimit()->getAmount(),
                        'currency' => $paymentTypeClone->getMinLimit()->getCurrency(),
                        'description' => $paymentTypeClone->getDescription()
                    ];
                    continue;
                }
                if ($paymentTypeClone->getName() == \Tamara\Checkout\Gateway\Config\InstalmentConfig::PAY_BY_INSTALMENTS) {
                    $description = $paymentTypeClone->getDescription();
                    if (count($installments = $paymentTypeClone->getSupportedInstalments())) {
                        foreach ($installments as $installment) {

                            /**
                             * @var \Tamara\Model\Checkout\Instalment $installment
                             */
                            $installmentMethodCode = \Tamara\Checkout\Gateway\Config\InstalmentConfig::getInstallmentPaymentCode($installment->getInstalments());
                            $installmentData = [
                                'name' => $installmentMethodCode,
                                'min_limit' => $installment->getMinLimit()->getAmount(),
                                'max_limit' => $installment->getMaxLimit()->getAmount(),
                                'currency' => $installment->getMinLimit()->getCurrency(),
                                'number_of_instalments' => $installment->getInstalments(),
                                'description' => $description
                            ];
                            $paymentTypes[$installmentMethodCode] = $installmentData;
                        }
                    }
                }
            }
        }
        return $paymentTypes;
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws RequestDispatcherException
     * @throws IntegrationException
     */
    public function createCheckout(array $data): array
    {
        $this->logger->debug(['Tamara - Start to create checkout session on Tamara']);

        try  {
            $orderRequest = OrderHelper::createTamaraOrderFromArray($data);
            $result = $this->client->createCheckout(new CreateCheckoutRequest($orderRequest));
        } catch (\Exception $e) {
            $this->logger->debug(["Tamara - Error when prepare and create checkout session" => $e->getMessage()], null, true);
            throw $e;
        }

        if (!$result->isSuccess()) {
            $message = $this->getErrorMessageFromResponse($result);
            $this->logger->debug(["Tamara - Failed response when create a checkout session" => $result->getContent()], null, true);
            throw new IntegrationException(__($message));
        }

        $checkoutResponse = $result->getCheckoutResponse();

        if ($checkoutResponse === null) {
            $this->logger->debug(['Tamara - CheckoutResponse was null, please check again'], null, true);
            throw new IntegrationException(__('The response is error, please ask administrator to help'));
        }

        $this->logger->debug(["End to create checkout session on Tamara"]);
        return $checkoutResponse->toArray();
    }

    public function notification(): bool
    {
        $this->logger->debug(['Tamara - Start to notification']);
        try {
            $authoriseMessage = $this->notificationService->processAuthoriseNotification();
        } catch (\Exception $exception) {
            $this->logger->debug(["Tamara - Error when process notification message" => $exception->getMessage()], null, true);

            return false;
        }

        try {
            $order = $this->orderRepository->getTamaraOrderByTamaraOrderId($authoriseMessage->getOrderId());
            if ($order->getIsAuthorised()) {
                return true;
            }

            $remoteOrder = $this->client->getOrder(new \Tamara\Request\Order\GetOrderRequest($authoriseMessage->getOrderId()));
            if ($remoteOrder->isSuccess()) {
                $remoteOrderStatus = $remoteOrder->getStatus();
                if ($remoteOrderStatus == "expired" || $remoteOrderStatus == "declined") {
                    throw new \Exception("Order status not accepted for authorization, order status: " . $remoteOrderStatus);
                }
                if ($remoteOrder->getStatus() == "new" || $remoteOrder->getStatus() == "approved") {
                    $mageOrder = $this->mageRepository->get($order->getOrderId());
                    $this->tamaraOrderAuthorizationHelper->authorizeOrder($mageOrder, $order, $mageOrder->getStoreId(), $remoteOrder);
                }
            } else {
                throw new \Exception(strval($remoteOrder->getContent()));
            }
        } catch (\Exception $exception) {
            $this->logger->debug(["Tamara - Error when process notification" => $exception->getMessage()], null, true);
            return false;
        }

        $this->logger->debug(['Tamara - End notification']);

        return true;
    }

    public function capture(array $data, Order $order): void
    {
        $this->logger->debug(['Tamara - Start to capture']);

        try {
            $captureRequest = PaymentHelper::createCaptureRequestFromArray($data);
            $response = $this->client->capture($captureRequest);

            if ($response->isSuccess()) {
                $captureId = $response->getCaptureId();
                if (!empty($captureId)) {
                    $data['capture_id'] = $captureId;
                    $capture = PaymentHelper::createCaptureFromArray($data);
                    $this->captureRepository->saveCapture($capture);

                    $captureItems = [];
                    foreach ($data['items'] as $itemData) {
                        $captureItem = PaymentHelper::createCaptureItemFromArray($itemData);
                        $captureItem->setOrderId($data['order_id']);
                        $captureItem->setCaptureId($captureId);
                        $captureItems[] = $captureItem->toArray();
                    }

                    $rows = $this->captureRepository->saveCaptureItems($captureItems);

                    if (!$rows) {
                        $this->logger->debug(['Tamara - Cannot save capture items' => $captureItems], null, true);
                        throw new IntegrationException(__('Cannot save capture items, please check log'));
                    }

                    $capturedAmount = $order->getOrderCurrency()->formatTxt(
                        $data['total_amount']
                    );
                    $captureComment = __('Tamara - order was captured. The captured amount is %1. Capture id is %2', $capturedAmount, $response->getCaptureId());
                    $order->addStatusHistoryComment($captureComment, false);
                    $this->mageRepository->save($order);

                    if ($this->baseConfig->getAutoGenerateInvoice($order->getStoreId()) == \Tamara\Checkout\Model\Config\Source\AutomaticallyInvoice::GENERATE_AFTER_CAPTURE) {
                        $this->logger->debug(["Tamara - Automatically generate invoice after capture payment"]);
                        $this->tamaraInvoiceHelper->generateInvoice($order->getId());
                    }
                }
            } else {
                if ($response->getStatusCode() !== 409) {
                    $errorLogs = $response->getErrors() ?? [$response->getMessage()];
                    $this->logger->debug(["Tamara- Failed response when capture an order" => $errorLogs]);
                    throw new IntegrationException(__('Could not capture in tamara, please check log'));
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(["Tamara - Error when capture an order" => $e->getMessage()], null, true);
            throw new IntegrationException(__($e->getMessage()));
        }

        $this->logger->debug(['Tamara - End capture']);
    }

    public function refund(array $data): void
    {
        $this->logger->debug(['Tamara - Start to refund']);

        try {
            $refundRequest = PaymentHelper::createRefundRequestFromArray($data);
            $response = $this->client->refund($refundRequest);

            if ($response->isSuccess()) {
                $refunds = $response->getRefunds();
                $refundIds = [];
                foreach ($refunds as $refund) {
                    $refundIds[$refund->getCaptureId()] = $refund->getRefundId();
                }

                foreach ($data['refunds'] as $captureId => $refund) {
                    $capture = $this->captureRepository->getCaptureById($captureId);
                    $totalRefundedAmount = $capture->getRefundedAmount() + $refund['refunded_amount'];
                    $capture->setRefundedAmount($totalRefundedAmount);
                    $this->captureRepository->saveCapture($capture);

                    $refundModel = PaymentHelper::createRefundFromData(
                        $captureId,
                        $refundIds[$captureId],
                        $refundRequest->toArray(),
                        $data,
                        $refund,
                        $capture['total_amount']
                    );

                    $this->refundRepository->save($refundModel);
                }

                $magentoOrder = $this->mageRepository->get($data['order_id']);
                $creditMemoRefundedAmount = $magentoOrder->getOrderCurrency()->formatTxt(
                    $data['refund_grand_total']
                );
                $refundTransactionId = $magentoOrder->getIncrementId() . '-refund-' . gmdate('Ymd-his', time());
                $refundComment = __('Tamara - order was refunded. The refunded amount is %1.', $creditMemoRefundedAmount);
                $this->tamaraTransactionHelper->createTransaction($magentoOrder, \Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND, $refundComment, $refundTransactionId);
                if (in_array(\Tamara\Checkout\Model\Config\Source\EmailTo\Options::SEND_EMAIL_WHEN_REFUND_ORDER, $this->baseConfig->getSendEmailWhen($magentoOrder->getStoreId()))) {
                    $magentoOrder->setStatus($this->baseConfig->getOrderStatusShouldBeRefunded($magentoOrder->getStoreId()));
                    try {
                        $this->orderCommentSender->send($magentoOrder, true, $refundComment);
                    } catch (\Exception $exception) {
                        $this->logger->debug(["Tamara - Error when sending authorise notification" => $exception->getMessage()], null, true);
                    }
                    $magentoOrder->addStatusHistoryComment(
                        __('Notified customer about order #%1 was refunded.', $magentoOrder->getIncrementId()),
                        $this->baseConfig->getOrderStatusShouldBeRefunded($magentoOrder->getStoreId())
                    )->setIsCustomerNotified(true)->save();
                }
            } else {
                if ($response->getStatusCode() !== 409) {
                    $this->logger->debug(["Tamara - Failed response when refund an order" => $response->getContent()]);
                    throw new IntegrationException(__($response->getMessage()));
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(["Tamara - Error when refund an order" => $e->getMessage()], null, true);
            throw new IntegrationException(__("Cannot refund from Tamara, error: " . $e->getMessage()));
        }

        $this->logger->debug(['Tamara - End to refund']);
    }

    public function cancel(array $data): void
    {
        $this->logger->debug(['Tamara - Start to cancel order' . $data['order_id']]);

        try {
            $cancelRequest = PaymentHelper::createCancelRequestFromArray($data);
            $response = $this->client->cancelOrder($cancelRequest);

            if ($response->isSuccess()) {
                $cancel = PaymentHelper::createCancelFromResponse($response);
                $cancel->setOrderId($data['order_id']);
                $cancel->setRequest($cancelRequest->toArray());
                $this->cancelRepository->save($cancel);
                $mageOrder = $this->mageRepository->get($data['order_id']);
                $canceledAmount = $mageOrder->getOrderCurrency()->formatTxt(
                    $data['total_amount']
                );
                $comment = __('Tamara - order was canceled, canceled amount is ' . $canceledAmount);
                $mageOrder->addStatusHistoryComment(__($comment), false);
                $this->mageRepository->save($mageOrder);
                if (in_array(\Tamara\Checkout\Model\Config\Source\EmailTo\Options::SEND_EMAIL_WHEN_CANCEL_ORDER, $this->baseConfig->getSendEmailWhen($mageOrder->getStoreId()))) {
                    if (!empty($data['is_authorised'])) {
                        try {
                            $this->orderCommentSender->send($mageOrder, true, $comment);
                        } catch (\Exception $exception) {
                            $this->logger->debug(["Tamara - Error when sending authorise notification" => $exception->getMessage()], null, true);
                        }
                        $mageOrder->addStatusHistoryComment(
                            __('Notified customer about order #%1 was canceled.', $mageOrder->getIncrementId()),
                            $this->baseConfig->getCheckoutCancelStatus($mageOrder->getStoreId())
                        )->setIsCustomerNotified(true)->save();
                    }
                }
            } else {
                if ($response->getStatusCode() !== 409) {
                    $errorLogs = [$response->getContent()];
                    $this->logger->debug(["Tamara - Failed response when cancel an order" => $errorLogs]);
                    throw new IntegrationException(__($response->getMessage()));
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug(["Tamara - Failed when cancel an order" => $e->getMessage()], null, true);
            throw new IntegrationException(__($e->getMessage()));
        }

        $this->logger->debug(['Tamara - End to cancel']);
    }

    public function registerWebhook(): void
    {
        $this->logger->debug(['Tamara - Start to register webhook']);

        try {
            $scopeId = $this->baseConfig->getTamaraCore()->getCurrentScopeId();
            $scope = $this->baseConfig->getTamaraCore()->getCurrentScope();
            $baseUrl = $this->baseConfig->getScopeConfig()->getValue('web/secure/base_url', $scope, $scopeId);
            if (empty($baseUrl)) {
                $baseUrl = $this->baseConfig->getScopeConfig()->getValue('web/unsecure/base_url', $scope, $scopeId);
            }

            $param = "";
            if ($scope == \Magento\Store\Model\ScopeInterface::SCOPE_STORES) {
                $param = ("?storeId=" . $scopeId);
            }
            $webhookUrl = $baseUrl . self::WEBHOOK_URL . $param;

            $request = new RegisterWebhookRequest(
                $webhookUrl,
                self::ALLOWED_WEBHOOKS
            );

            $response = $this->client->registerWebhook($request);

            if (!$response->isSuccess()) {
                $webhookId = $this->getAlreadyRegisteredWebhookId($response);
                if ($webhookId === null) {
                    $this->logger->debug(["Tamara - Failed response when register a webhook" => $response->getContent()]);
                    throw new IntegrationException(__($response->getMessage()));
                }
                $this->logger->debug(
                    ["Tamara - Webhook was already registered, reuse the existing webhook id" => $webhookId]
                );
            } else {
                $webhookId = $response->getWebhookId();
            }

            $this->resourceConfig->saveConfig(
                'payment/tamara_checkout/webhook_id',
                $webhookId,
                $scope,
                $scopeId
            );
        } catch (\Exception $exception) {
            $this->logger->debug(["Tamara - Error when register webhook" => $exception->getMessage()], null, true);

            throw $exception;
        }

        $this->logger->debug(['Tamara - End of register webhook']);
    }

    /**
     * Tamara rejects a duplicate registration and returns the webhook already held for the merchant,
     * so the id can be taken from the error instead of failing the config save.
     *
     * @param \Tamara\Response\Webhook\RegisterWebhookResponse $response
     *
     * @return string|null
     */
    private function getAlreadyRegisteredWebhookId($response)
    {
        if (!$this->isAlreadyRegisteredError($response)) {
            return null;
        }

        return $this->findWebhookIdInPayload(json_decode($response->getContent(), true));
    }

    /**
     * @param \Tamara\Response\Webhook\RegisterWebhookResponse $response
     *
     * @return bool
     */
    private function isAlreadyRegisteredError($response): bool
    {
        if ($response->getStatusCode() === 409) {
            return true;
        }

        $messages = [$response->getMessage()];
        foreach ($response->getErrors() as $error) {
            if (isset($error['error_code'])) {
                $messages[] = $error['error_code'];
            }
            if (isset($error['message'])) {
                $messages[] = $error['message'];
            }
        }

        foreach ($messages as $message) {
            if (is_string($message) && stripos($message, 'already') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $payload
     *
     * @return string|null
     */
    private function findWebhookIdInPayload($payload)
    {
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload[Webhook::WEBHOOK_ID])
            && is_string($payload[Webhook::WEBHOOK_ID])
            && $payload[Webhook::WEBHOOK_ID] !== ''
        ) {
            return $payload[Webhook::WEBHOOK_ID];
        }

        foreach ($payload as $value) {
            $webhookId = $this->findWebhookIdInPayload($value);
            if ($webhookId !== null) {
                return $webhookId;
            }
        }

        return null;
    }

    public function deleteWebhook($webhookId): void
    {
        $this->logger->debug(['Tamara - Start to delete webhook']);
        $scope = $this->baseConfig->getTamaraCore()->getCurrentScope();
        $scopeId = $this->baseConfig->getTamaraCore()->getCurrentScopeId();

        $this->resourceConfig->deleteConfig(
            'payment/tamara_checkout/webhook_id',
            $scope,
            $scopeId
        );

        try {
            $request = new RemoveWebhookRequest($webhookId);

            $response = $this->client->removeWebhook($request);

            if (!$response->isSuccess()) {
                $this->logger->debug(["Tamara - Failed response when delete a webhook" => $response->getContent()]);
                throw new IntegrationException(__($response->getMessage()));
            }
        } catch (RequestException $exception) {
            $this->logger->debug(["Tamara - Error when delete webhook" => $exception->getMessage()], null, true);
            return;
        }

        $this->logger->debug(['Tamara - End of delete webhook']);
    }

    public function webhook(): bool
    {
        $this->logger->debug(['Tamara - Start to webhook']);

        try {
            $webhookMessage = $this->notificationService->processWebhook();
            $eventType = $webhookMessage->getEventType();

            if (!in_array($eventType, self::ALLOWED_WEBHOOKS)) {
                $this->logger->debug([
                    'Event type: ' => $eventType,
                    'Webhook tamara order id: ' => $webhookMessage->getOrderId(),
                    'Webhook reference order id: ' => $webhookMessage->getOrderReferenceId(),
                ]);

                return false;
            }

            $tamaraOrderId = $webhookMessage->getOrderId();
            $order = $this->orderRepository->getTamaraOrderByTamaraOrderId($tamaraOrderId);
            if ($order->getIsAuthorised()) {
                return false;
            }

            /** @var \Magento\Sales\Model\Order $mageOrder */
            $mageOrder = $this->mageRepository->get($order->getOrderId());

            if ($mageOrder->getState() == Order::STATE_CANCELED || $mageOrder->getState() == Order::STATE_CLOSED) {
                $this->logger->debug([
                    __("Tamara - Magento order was canceled or closed, skip cancel by webhook")
                ]);
                return true;
            }
            $this->orderManagement->cancel($order->getOrderId());

            if ($eventType ==  self::TAMARA_ORDER_EVENT_DECLINED) {
                $mageOrder->setState(Order::STATE_CANCELED)->setStatus($this->baseConfig->getCheckoutFailureStatus($mageOrder->getStoreId()));
            } else {
                $mageOrder->setState(Order::STATE_CANCELED)->setStatus($this->baseConfig->getCheckoutExpireStatus($mageOrder->getStoreId()));
            }
            $comment = sprintf('Tamara - order was %s by webhook', $eventType);
            $mageOrder->addStatusHistoryComment(__($comment), false);
            $mageOrder->getResource()->save($mageOrder);

        } catch (\Exception $exception) {
            $this->logger->debug(["Tamara - error when process webhook" => $exception->getMessage()], null, true);
            return false;
        }

        $this->logger->debug(['Tamara - End Webhook']);
        return true;
    }

    /**
     * @param CreateCheckoutResponse $errorResponse
     *
     * @return string
     */
    private function getErrorMessageFromResponse($errorResponse): string
    {
        $message = $errorResponse->getMessage();

        if ($errorResponse->getErrors() === null) {
            return $message;
        }

        foreach ($errorResponse->getErrors() as $error) {
            $message = isset($error['error_code']) ? sprintf('%s, %s', $message, $error['error_code']) : $message;
        }

        return $message;
    }

    /**
     * @return Client
     */
    public function getClient() {
        return $this->client;
    }

    /**
     * @return Client
     */
    public function getPreCheckoutEligibilityClient() {
        return $this->preCheckoutEligibilityClient;
    }

    /**
     * @param $magentoOrderIncrementId
     * @return \Tamara\Response\Order\GetOrderByReferenceIdResponse
     * @throws RequestDispatcherException
     */
    public function getTamaraOrderFromRemote($magentoOrderIncrementId) {
        return $this->getClient()->getOrderByReferenceId(new \Tamara\Request\Order\GetOrderByReferenceIdRequest($magentoOrderIncrementId));
    }

    /**
     * @return bool
     */
    public function getDisableTamara() {
        return boolval($this->magentoCache->load(self::DISABLE_TAMARA_IDENTIFIER));
    }

    /**
     * @param bool $val
     */
    public function setDisableTamara($val) {
        $this->magentoCache->save(strval(intval($val)), self::DISABLE_TAMARA_IDENTIFIER, [], self::DISABLE_TAMARA_CACHE_LIFE_TIME);
    }

    public function updatePaymentMethodToDbDirectly($magentoOrderId, $paymentMethod) {
        $updateSalesOrderPaymentQuery = "UPDATE `". $this->resourceConfig->getTable("sales_order_payment") ."` SET `method` = '". $paymentMethod ."' WHERE `parent_id` = '". $magentoOrderId . "'";
        $updateSalesOrderGridQuery = "UPDATE `". $this->resourceConfig->getTable("sales_order_grid") ."` SET `payment_method` = '". $paymentMethod ."' WHERE `entity_id` = '". $magentoOrderId. "'";
        $connection  = $this->resourceConfig->getConnection();
        $connection->query($updateSalesOrderPaymentQuery);
        $connection->query($updateSalesOrderGridQuery);
    }
}
