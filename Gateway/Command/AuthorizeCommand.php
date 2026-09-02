<?php

namespace Tamara\Checkout\Gateway\Command;

use Exception;
use Magento\Payment\Gateway\Command;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\ConverterException;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;

class AuthorizeCommand implements CommandInterface
{
    const STATUS_PENDING = 'pending';

    /**
     * @var \Tamara\Checkout\Gateway\Config\BaseConfig 
     */
    protected $config;

    /**
     * @var \Tamara\Checkout\Model\OrderFactory
     */
    private $tamaraOrderFactory;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var \Tamara\Checkout\Model\OrderRepository
     */
    private $tamaraOrderRepository;

    /**
     * @var BuilderInterface
     */
    private $requestBuilder;

    /**
     * @var TransferFactoryInterface
     */
    private $transferFactory;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var HandlerInterface
     */
    private $handler;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var \Tamara\Checkout\Helper\Transaction
     */
    protected $tamaraTransactionHelper;

    /**
     * AuthorizeCommand constructor.
     * @param \Tamara\Checkout\Model\OrderFactory $tamaraOrderFactory
     * @param OrderRepository $orderRepository
     * @param \Tamara\Checkout\Model\OrderRepository $tamaraOrderRepository
     * @param BuilderInterface $requestBuilder
     * @param TransferFactoryInterface $transferFactory
     * @param ClientInterface $client
     * @param HandlerInterface $handler
     * @param Logger $logger
     * @param \Tamara\Checkout\Gateway\Config\BaseConfig $config
     * @param \Tamara\Checkout\Helper\Transaction $tamaraTransactionHelper
     */
    public function __construct(
        \Tamara\Checkout\Model\OrderFactory $tamaraOrderFactory,
        OrderRepository $orderRepository,
        \Tamara\Checkout\Model\OrderRepository $tamaraOrderRepository,
        BuilderInterface $requestBuilder,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        HandlerInterface $handler,
        Logger $logger,
        \Tamara\Checkout\Gateway\Config\BaseConfig $config,
        \Tamara\Checkout\Helper\Transaction $tamaraTransactionHelper
    )
    {
        $this->tamaraOrderFactory = $tamaraOrderFactory;
        $this->orderRepository = $orderRepository;
        $this->tamaraOrderRepository = $tamaraOrderRepository;
        $this->requestBuilder = $requestBuilder;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->handler = $handler;
        $this->logger = $logger;
        $this->config = $config;
        $this->tamaraTransactionHelper = $tamaraTransactionHelper;
    }

    /**
     * @param array $commandSubject
     * @return $this|Command\ResultInterface|null|void
     * @throws ClientException
     * @throws ConverterException
     * @throws Exception
     */
    public function execute(array $commandSubject)
    {
        $this->logger->debug(['Tamara - Start authorize command']);
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $commandSubject['payment']->getPayment();
        $payment->setIsTransactionPending(true);

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        // disable sending confirmation email
        $order->setCanSendNewEmailFlag(false);

        $orderResult = $this->orderRepository->save($order);

        try {
            // We don't create checkout session here anymore, just prepare the order
            // set state for new order
            $order->setState(Order::STATE_NEW)->setStatus($this->config->getCheckoutOrderCreateStatus($orderResult->getStoreId()));

            // Add comment to order history
            $order->addCommentToStatusHistory(__('Tamara - waiting for Tamara checkout session to be created'), false, false);
        } catch (Exception $e) {
            $orderResult->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($orderResult);
            $this->logger->debug(["Tamara - Error in execute AuthorizeCommand" => $e->getMessage()], null, true);
            throw $e;
        }

        // We don't have response anymore since we don't create checkout session here

        $this->logger->debug(['Tamara - End authorize command']);
    }
}
