<?php

namespace Tamara\Checkout\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Tamara\Checkout\Model\Adapter\TamaraAdapter;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;
use Tamara\Checkout\Model\OrderFactory as TamaraOrderFactory;
use Tamara\Model\Order\MerchantUrl;

class CheckoutInformationRepository implements \Tamara\Checkout\Api\CheckoutInformationRepositoryInterface
{

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;
    protected $tamaraHelper;
    private $tamaraOrderRepository;
    private $checkoutInformationFactory;
    protected $baseConfig;
    protected $storeManager;
    protected $orderRepository;
    private $objectManager;
    private $tamaraOrderFactory;

    /**
     * @var \Magento\Payment\Model\Method\Logger
     */
    private $logger;

    public function __construct(
        UrlInterface $urlBuilder,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        OrderRepositoryInterface $orderRepository,
        \Tamara\Checkout\Api\OrderRepositoryInterface $tamaraOrderRepository,
        \Tamara\Checkout\Model\CheckoutInformationFactory $checkoutInformationFactory,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper,
        BaseConfig $baseConfig,
        ObjectManagerInterface $objectManager,
        TamaraOrderFactory $tamaraOrderFactory
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->tamaraOrderRepository = $tamaraOrderRepository;
        $this->checkoutInformationFactory = $checkoutInformationFactory;
        $this->tamaraHelper = $tamaraHelper;
        $this->baseConfig = $baseConfig;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->objectManager = $objectManager;
        $this->tamaraOrderFactory = $tamaraOrderFactory;
        $this->logger = $this->objectManager->get("TamaraCheckoutLogger");
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getTamaraCheckoutInformation($magentoOrderId)
    {
        // Use a static flag to prevent infinite recursion
        static $isCreatingCheckout = [];

        // First check if we're already in the process of creating a checkout session for this order
        if (isset($isCreatingCheckout[$magentoOrderId])) {
            return null;
        }

        try {
            $magentoOrder = $this->orderRepository->get($magentoOrderId);
        } catch (\Exception $e) {
            $this->logger->debug(['Tamara - Error loading Magento order ' . $magentoOrderId => $e->getMessage()], null, true);
            return null;
        }

        // Check if checkout session already exists
        try {
            $tamaraOrder = $this->tamaraOrderRepository->getTamaraOrderByOrderId($magentoOrderId);

            // Check if we have a complete Tamara order with all required data
            if ($tamaraOrder && !empty($tamaraOrder->getRedirectUrl()) && !empty($tamaraOrder->getTamaraOrderId())) {
                // Return existing checkout information
                return $this->buildCheckoutInformationFromTamaraOrder($tamaraOrder, $magentoOrder);
            }
        } catch (NoSuchEntityException $e) {
            // If no existing order, we'll create a new checkout session
        }

        // Set the flag to prevent recursion
        $isCreatingCheckout[$magentoOrderId] = true;

        try {
            return $this->createTamaraCheckoutSession($magentoOrder);
        } catch (\Exception $e) {
            $this->logger->debug(['Tamara - Error creating checkout session' => $e->getMessage()], null, true);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Could not create Tamara checkout session: %1', $e->getMessage())
            );
        } finally {
            // Always unset the flag, even if an exception occurs
            unset($isCreatingCheckout[$magentoOrderId]);
        }
    }
        
    /**
     * Build checkout information object from a Tamara order
     *
     * @param \Tamara\Checkout\Api\OrderInterface $tamaraOrder
     * @param \Magento\Sales\Api\Data\OrderInterface $magentoOrder
     * @return \Tamara\Checkout\Api\Data\CheckoutInformationInterface
     */
    private function buildCheckoutInformationFromTamaraOrder($tamaraOrder, $magentoOrder)
    {
        // Prepare redirect URLs
        $merchantUrl = $this->getMerchantUrl($magentoOrder);

        /**
         * @var \Tamara\Checkout\Model\CheckoutInformation $checkoutInformation
         */
        $checkoutInformation = $this->checkoutInformationFactory->create();
        $checkoutInformation->setMagentoOrderId($magentoOrder->getEntityId());
        $checkoutInformation->setTamaraOrderId($tamaraOrder->getTamaraOrderId());
        $checkoutInformation->setPaymentSuccessRedirectUrl($merchantUrl->getSuccessUrl());
        $checkoutInformation->setPaymentCancelRedirectUrl($merchantUrl->getCancelUrl());
        $checkoutInformation->setPaymentFailureRedirectUrl($merchantUrl->getFailureUrl());
        $checkoutInformation->setRedirectUrl($tamaraOrder->getRedirectUrl());
        
        // Checkout information successfully built
        return $checkoutInformation;
    }

    /**
     * Create a Tamara checkout session for the given Magento order
     *
     * @param \Magento\Sales\Model\Order $magentoOrder
     * @return \Tamara\Checkout\Api\Data\CheckoutInformationInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function createTamaraCheckoutSession($magentoOrder)
    {
        try {
            // Load Magento order
            $storeId = $magentoOrder->getStoreId();
            
            // Create checkout session with Tamara
            try {
                // Prepare checkout data
                $data = $this->prepareCheckoutData($magentoOrder);
                
                // Create Tamara adapter
                /**
                 * @var TamaraAdapter $tamaraAdapter
                 */
                $tamaraAdapter = $this->objectManager->create(
                    TamaraAdapterFactory::class
                )->create($storeId);
                
                // Call Tamara API to create checkout
                $response = $tamaraAdapter->createCheckout($data);
                
                // Save information to DB
                $tamaraOrder = $this->tamaraOrderFactory->create();
                $tamaraOrder->setData([
                    'order_id' => $magentoOrder->getEntityId(),
                    'tamara_order_id' => $response['order_id'],
                    'redirect_url' => $response['checkout_url'],
                    'payment_type' => $magentoOrder->getPayment()->getMethod()
                ]);
                
                $this->tamaraOrderRepository->save($tamaraOrder);
                try {
                    // Add comment to order history
                    $magentoOrder->addCommentToStatusHistory(
                        __('Tamara - checkout session was created, order id: ' . $response['order_id']),
                        false,
                        false
                    );
                    $this->orderRepository->save($magentoOrder);
                } catch (\Exception $e) {
                    $this->logger->debug(['Tamara - Error saving Magento order history: ' . $e->getMessage()], null, true);
                }
                return $this->buildCheckoutInformationFromTamaraOrder($tamaraOrder, $magentoOrder);
            } catch (\Exception $innerException) {
                $this->logger->debug(['Tamara - Error in API call or saving Tamara order' => $innerException->getMessage()], null, true);
                throw $innerException; // Re-throw to be caught by the outer try-catch
            }
        } catch (\Exception $e) {
            $this->logger->debug(['Tamara - Error when creating checkout session' => $e->getMessage()], null, true);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Could not create Tamara checkout session: %1', $e->getMessage())
            );
        }
    }
    
    /**
     * Prepare checkout data from Magento order
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $magentoOrder
     * @return array
     */
    private function prepareCheckoutData($magentoOrder)
    {
        $storeId = $magentoOrder->getStoreId();

        // Get the request builder components
        /**
         * @var \Tamara\Checkout\Gateway\Request\CommonDataBuilder $commonDataBuilder
         */
        $commonDataBuilder = $this->objectManager->create(
            \Tamara\Checkout\Gateway\Request\CommonDataBuilder::class
        );

        /**
         * @var \Tamara\Checkout\Gateway\Request\AddressDataBuilder $addressDataBuilder
         */
        $addressDataBuilder = $this->objectManager->create(
            \Tamara\Checkout\Gateway\Request\AddressDataBuilder::class
        );

        /**
         * @var \Tamara\Checkout\Gateway\Request\ConsumerDataBuilder $consumerDataBuilder
         */
        $consumerDataBuilder = $this->objectManager->create(
            \Tamara\Checkout\Gateway\Request\ConsumerDataBuilder::class
        );

        /**
         * @var \Tamara\Checkout\Gateway\Request\ItemsDataBuilder $itemsDataBuilder
         */
        $itemsDataBuilder = $this->objectManager->create(
            \Tamara\Checkout\Gateway\Request\ItemsDataBuilder::class
        );
        
        $paymentDataObjectMock = $this->createPaymentDataObjectMock($magentoOrder);
        
        // Prepare data
        $commonData = $commonDataBuilder->build([
            'order' => $magentoOrder,
            'order_result_id' => $magentoOrder->getEntityId(),
            'order_currency_code' => $magentoOrder->getOrderCurrencyCode(),
            'phone_verified' => $this->baseConfig->isPhoneVerified($storeId)
        ]);
        
        $addressData = $addressDataBuilder->build([
            'payment' => $paymentDataObjectMock
        ]);
        
        $consumerData = $consumerDataBuilder->build([
            'payment' => $paymentDataObjectMock
        ]);
        
        $itemsData = $itemsDataBuilder->build([
            'order' => $magentoOrder,
            'order_currency_code' => $magentoOrder->getOrderCurrencyCode()
        ]);
        
        // Prepare merchant URLs
        $merchantUrlData = [
            \Tamara\Checkout\Gateway\Request\MerchantUrlDataBuilder::MERCHANT_URL => $this->getMerchantUrl($magentoOrder)
        ];
        
        // Combine all data
        $data = array_merge(
            $commonData,
            $addressData,
            $consumerData,
            $itemsData,
            $merchantUrlData
        );
        
        return $data;
    }

    /**
     * @param $magentoOrder
     * @return MerchantUrl
     * @throws NoSuchEntityException
     */
    public function getMerchantUrl($magentoOrder) {
        $storeId = $magentoOrder->getStoreId();

        // Build URLs
        $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        if (!$this->baseConfig->getTamaraCore()->isAnUrl($baseUrl)) {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        }
        $paymentController = $baseUrl . 'tamara/payment/' . $magentoOrder->getEntityId() . '/';
        $successUrl = $paymentController . 'success';
        $cancelUrl = $paymentController . 'cancel';
        $failureUrl = $paymentController . 'failure';
        $notificationUrl = $baseUrl . 'tamara/payment/notification';
        $merchantUrl = new MerchantUrl();
        $merchantUrl->setSuccessUrl($successUrl);
        $merchantUrl->setFailureUrl($failureUrl);
        $merchantUrl->setCancelUrl($cancelUrl);
        $merchantUrl->setNotificationUrl($notificationUrl);
        return $merchantUrl;
    }
    
    /**
     * Create a simple payment data object for builders that require it
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $magentoOrder
     * @return \Magento\Payment\Gateway\Data\PaymentDataObjectInterface
     */
    private function createPaymentDataObjectMock($magentoOrder)
    {
        // Use concrete class instead of anonymous class for better compatibility with Magento 2.2.x
        return new \Tamara\Checkout\Model\Payment\PaymentDataObject($magentoOrder);
    }
}
