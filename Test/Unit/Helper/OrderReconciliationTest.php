<?php

namespace Tamara\Checkout\Test\Unit\Helper;

use Magento\Framework\Registry;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tamara\Checkout\Api\OrderRepositoryInterface as TamaraOrderRepositoryInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Helper\AbstractData;
use Tamara\Checkout\Helper\OrderAuthorization;
use Tamara\Checkout\Helper\OrderReconciliation;
use Tamara\Checkout\Model\Adapter\TamaraAdapter;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;
use Tamara\Checkout\Model\Order as TamaraOrder;
use Tamara\Client;
use Tamara\Model\Money;
use Tamara\Model\Order\CancelCollection;
use Tamara\Model\Order\CaptureCollection;
use Tamara\Model\Order\CaptureItem;
use Tamara\Model\Order\RefundCollection;
use Tamara\Model\Order\Transactions;
use Tamara\Response\Order\GetOrderResponse;

class OrderReconciliationTest extends TestCase
{
    private $adapterFactory;
    private $authorization;
    private $orderRepository;
    private $tamaraOrderRepository;
    private $orderManagement;
    private $config;
    private $registry;
    private $helper;
    private $client;
    private $service;

    private function createService(array $mockClasses = []): void
    {
        $this->adapterFactory = $this->createStub(TamaraAdapterFactory::class);
        $this->authorization = $this->createDouble(OrderAuthorization::class, $mockClasses);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->tamaraOrderRepository = $this->createDouble(TamaraOrderRepositoryInterface::class, $mockClasses);
        $this->orderManagement = $this->createDouble(OrderManagementInterface::class, $mockClasses);
        $this->config = $this->createDouble(BaseConfig::class, $mockClasses);
        $this->registry = new Registry();
        $this->helper = $this->createStub(AbstractData::class);
        $this->client = $this->createDouble(Client::class, $mockClasses);

        $adapter = $this->createStub(TamaraAdapter::class);
        $adapter->method('getClient')->willReturn($this->client);
        $this->adapterFactory->method('create')->willReturn($adapter);

        $this->service = new OrderReconciliation(
            $this->adapterFactory,
            $this->authorization,
            $this->orderRepository,
            $this->tamaraOrderRepository,
            $this->orderManagement,
            $this->config,
            $this->registry,
            $this->helper
        );
    }

    /**
     * @dataProvider pendingStateProvider
     */
    #[DataProvider('pendingStateProvider')]
    public function testRecognizesCurrentAndLegacyPendingStates($state, $expected)
    {
        $this->createService();
        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn($state);
        $tamaraOrder = $this->createStub(TamaraOrder::class);
        $tamaraOrder->method('getIsAuthorised')->willReturn(0);

        self::assertSame($expected, $this->service->isPendingOrder($order, $tamaraOrder));
    }

    public static function pendingStateProvider()
    {
        return [
            'pending payment' => [Order::STATE_PENDING_PAYMENT, true],
            'legacy new' => [Order::STATE_NEW, true],
            'processing' => [Order::STATE_PROCESSING, false]
        ];
    }

    public function testRepeatedReconciliationSkipsAnAlreadyAuthorisedOrder()
    {
        $this->createService([Client::class]);
        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $tamaraOrder = $this->createStub(TamaraOrder::class);
        $tamaraOrder->method('getIsAuthorised')->willReturn(1);

        $this->client->expects(self::never())->method('getOrder');
        $this->client->expects(self::never())->method('getOrderByReferenceId');

        self::assertNull($this->service->reconcile($order, $tamaraOrder));
    }

    public function testFallsBackToReferenceAndPersistsRefreshedMetadata()
    {
        $this->createService([Client::class]);
        list($order, $tamaraOrder, $payment) = $this->createPendingOrder(false, true);
        $failedResponse = $this->createStub(GetOrderResponse::class);
        $failedResponse->method('isSuccess')->willReturn(false);
        $failedResponse->method('getStatusCode')->willReturn(404);
        $remoteOrder = $this->createRemoteOrder('new');

        $this->client->expects(self::exactly(2))
            ->method('getOrder')
            ->willReturn($failedResponse);
        $this->client->expects(self::exactly(2))
            ->method('getOrderByReferenceId')
            ->willReturn($remoteOrder);

        $metadata = [];
        $payment->expects(self::exactly(7))
            ->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value) use (&$metadata, $payment) {
                $metadata[$key] = $value;
                return $payment;
            });

        self::assertSame('new', $this->service->reconcile($order, $tamaraOrder));
        self::assertSame('tamara-123', $metadata['tamara_order_id']);
        self::assertSame('PAY_BY_LATER', $metadata['payment_type']);
        self::assertSame('new', $metadata['tamara_order_status']);
        self::assertSame([], $metadata['capture_ids']);
        self::assertSame([], $metadata['cancel_ids']);
        self::assertSame([], $metadata['refund_ids']);
    }

    public function testFailedLookupDefersRetryWithoutSkipping()
    {
        $this->createService([TamaraOrderRepositoryInterface::class]);
        list($order, $tamaraOrder) = $this->createPendingOrder();
        $failedResponse = $this->createStub(GetOrderResponse::class);
        $failedResponse->method('isSuccess')->willReturn(false);
        $failedResponse->method('getStatusCode')->willReturn(503);

        $this->client->method('getOrder')->willReturn($failedResponse);
        $this->client->method('getOrderByReferenceId')->willReturn($failedResponse);
        $this->tamaraOrderRepository->expects(self::once())->method('save')->with($tamaraOrder);

        self::assertNull($this->service->reconcile($order, $tamaraOrder));
        self::assertFalse((bool) $tamaraOrder->getCanceledFromConsole());
        self::assertNotEmpty($tamaraOrder->getData('updated_at'));
    }

    public function testApprovedOrderCallsTamaraAuthorisation()
    {
        $this->createService([OrderAuthorization::class]);
        list($order, $tamaraOrder) = $this->createPendingOrder();
        $remoteOrder = $this->createRemoteOrder('approved');
        $this->client->method('getOrder')->willReturn($remoteOrder);

        $this->authorization->expects(self::once())
            ->method('authorizeOrder')
            ->with($order, $tamaraOrder, 2, $remoteOrder);

        self::assertSame('approved', $this->service->reconcile($order, $tamaraOrder));
    }

    public function testAlreadyAuthorisedOrderOnlyAppliesLocalAuthorisation()
    {
        $this->createService([OrderAuthorization::class]);
        list($order, $tamaraOrder) = $this->createPendingOrder();
        $remoteOrder = $this->createRemoteOrder('authorised');
        $this->client->method('getOrder')->willReturn($remoteOrder);

        $this->authorization->expects(self::never())->method('authorizeOrder');
        $this->authorization->expects(self::once())
            ->method('syncAuthorizedOrder')
            ->with($order, $tamaraOrder, 2, $remoteOrder);

        self::assertSame('authorised', $this->service->reconcile($order, $tamaraOrder));
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    #[DataProvider('terminalStatusProvider')]
    public function testTerminalOrderUsesConfiguredStatus($remoteStatus, $configMethod, $magentoStatus)
    {
        $this->createService([OrderManagementInterface::class, BaseConfig::class]);
        list($order, $tamaraOrder) = $this->createPendingOrder(true);
        $remoteOrder = $this->createRemoteOrder($remoteStatus);
        $this->client->method('getOrder')->willReturn($remoteOrder);
        $this->config->expects(self::once())->method($configMethod)->with(2)->willReturn($magentoStatus);

        $this->orderManagement->expects(self::once())->method('cancel')->with(10);
        $order->expects(self::once())->method('setStatus')->with($magentoStatus)->willReturnSelf();
        $order->expects(self::once())->method('addCommentToStatusHistory')->willReturnSelf();

        self::assertSame($remoteStatus, $this->service->reconcile($order, $tamaraOrder));
        self::assertTrue((bool) $tamaraOrder->getCanceledFromConsole());
    }

    public static function terminalStatusProvider()
    {
        return [
            'expired' => ['expired', 'getCheckoutExpireStatus', 'tamara_expired'],
            'declined' => ['declined', 'getCheckoutFailureStatus', 'tamara_declined'],
            'canceled' => ['canceled', 'getCheckoutCancelStatus', 'tamara_cancelled'],
            'refunded' => ['refunded', 'getCheckoutCancelStatus', 'tamara_cancelled'],
        ];
    }

    /**
     * @dataProvider capturedAmountProvider
     */
    #[DataProvider('capturedAmountProvider')]
    public function testCapturedOrderAddsCaptureIdsAndCaptureNote($capturedValue, $captureType)
    {
        $this->createService([OrderAuthorization::class, BaseConfig::class]);
        list($order, $tamaraOrder) = $this->createPendingOrder(true);
        $remoteOrder = $this->createRemoteOrder('fully_captured', ['capture-1', 'capture-2']);
        $this->client->method('getOrder')->willReturn($remoteOrder);
        $this->config->expects(self::once())->method('getCheckoutAuthoriseStatus')->with(2)->willReturn('tamara_authorised');

        $capturedAmount = $this->createStub(Money::class);
        $capturedAmount->method('getAmount')->willReturn($capturedValue);
        $totalAmount = $this->createStub(Money::class);
        $totalAmount->method('getAmount')->willReturn(100.0);
        $remoteOrder->method('getCapturedAmount')->willReturn($capturedAmount);
        $remoteOrder->method('getTotalAmount')->willReturn($totalAmount);

        if ($captureType === 'fully') {
            $this->authorization->expects(self::once())
                ->method('syncAuthorizedOrder')
                ->willReturn(true);
            $this->authorization->expects(self::never())->method('syncPartiallyCapturedOrder');
        } else {
            $this->authorization->expects(self::never())->method('syncAuthorizedOrder');
            $this->authorization->expects(self::once())
                ->method('syncPartiallyCapturedOrder')
                ->with($order, $tamaraOrder, 2, $remoteOrder, $capturedValue)
                ->willReturn(true);
        }
        $order->expects(self::once())->method('setStatus')->with('tamara_authorised')->willReturnSelf();
        $order->expects(self::once())
            ->method('addCommentToStatusHistory')
            ->with(
                self::callback(function ($comment) use ($captureType) {
                    return strpos((string) $comment, $captureType . ' captured') !== false
                        && strpos((string) $comment, 'capture-1, capture-2') !== false;
                }),
                'tamara_authorised',
                false
            )
            ->willReturnSelf();

        self::assertSame('fully_captured', $this->service->reconcile($order, $tamaraOrder));
    }

    public static function capturedAmountProvider()
    {
        return [
            'full capture' => [100.0, 'fully'],
            'partial capture' => [40.0, 'partially']
        ];
    }

    public function testCapturedOrderRemainsPendingWhenLocalAuthorisationFails()
    {
        $this->createService();
        list($order, $tamaraOrder) = $this->createPendingOrder(true);
        $remoteOrder = $this->createRemoteOrder('fully_captured', ['capture-1']);
        $this->client->method('getOrder')->willReturn($remoteOrder);
        $capturedAmount = $this->createStub(Money::class);
        $capturedAmount->method('getAmount')->willReturn(100.0);
        $totalAmount = $this->createStub(Money::class);
        $totalAmount->method('getAmount')->willReturn(100.0);
        $remoteOrder->method('getCapturedAmount')->willReturn($capturedAmount);
        $remoteOrder->method('getTotalAmount')->willReturn($totalAmount);

        $this->authorization->method('syncAuthorizedOrder')->willReturn(false);
        $order->expects(self::never())->method('setStatus');
        $order->expects(self::never())->method('addCommentToStatusHistory');

        self::assertSame('fully_captured', $this->service->reconcile($order, $tamaraOrder));
    }

    private function createDouble($class, array $mockClasses)
    {
        return in_array($class, $mockClasses, true) ? $this->createMock($class) : $this->createStub($class);
    }

    private function createPendingOrder($mockOrder = false, $mockPayment = false)
    {
        $payment = $mockPayment ? $this->createMock(Payment::class) : $this->createStub(Payment::class);
        $order = $mockOrder ? $this->createMock(Order::class) : $this->createStub(Order::class);
        $order->method('getId')->willReturn(10);
        $order->method('getStoreId')->willReturn(2);
        $order->method('getIncrementId')->willReturn('100000010');
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $order->method('getPayment')->willReturn($payment);
        $order->method('setState')->willReturnSelf();

        $tamaraOrder = $this->createPartialMock(
            TamaraOrder::class,
            ['getTamaraOrderId']
        );
        $tamaraOrder->setIsAuthorised(0);
        $tamaraOrder->expects(self::atLeastOnce())->method('getTamaraOrderId')->willReturn('tamara-123');

        return [$order, $tamaraOrder, $payment];
    }

    private function createRemoteOrder($status, array $captureIds = [])
    {
        $transactions = $this->createStub(Transactions::class);
        $captureCollection = $this->createStub(CaptureCollection::class);
        $captureItems = [];
        foreach ($captureIds as $captureId) {
            $captureItem = $this->createStub(CaptureItem::class);
            $captureItem->method('getCaptureId')->willReturn($captureId);
            $captureItems[] = $captureItem;
        }
        $captureCollection->method('getIterator')->willReturn(new \ArrayIterator($captureItems));
        $transactions->method('getCaptures')->willReturn($captureCollection);
        $transactions->method('getCancels')->willReturn(CancelCollection::create([]));
        $transactions->method('getRefunds')->willReturn(RefundCollection::create([]));

        $remoteOrder = $this->createStub(GetOrderResponse::class);
        $remoteOrder->method('isSuccess')->willReturn(true);
        $remoteOrder->method('getStatus')->willReturn($status);
        $remoteOrder->method('getOrderId')->willReturn('tamara-123');
        $remoteOrder->method('getPaymentType')->willReturn('PAY_BY_LATER');
        $remoteOrder->method('getInstalments')->willReturn(null);
        $remoteOrder->method('getTransactions')->willReturn($transactions);
        return $remoteOrder;
    }
}
