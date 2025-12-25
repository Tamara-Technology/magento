<?php
namespace Tamara\Checkout\Model\Method;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Framework\DataObject;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Tamara\Checkout\Api\OrderInterface;
use Tamara\Checkout\Api\OrderRepositoryInterface;
use Tamara\Checkout\Model\Request\CheckoutRequest;
use Tamara\Client;
use Tamara\Configuration;
use Tamara\Tests\Fixtures\OrderDataFactory;

class Checkout extends AbstractMethod {

	/**
	 * @var string
	 */
	protected $_code = 'tamara_checkout';

	const ALLOWED_COUNTRIES = 'SA,AE,KW,BH,QA,OM';

	const ALLOWED_CURRENCIES = 'SAR,AED,KWD,BHD,QAR,OMR';

	/**
	 * @var string
	 */
	protected $_formBlockType = \Magento\Payment\Block\Form::class;

	/**
	 * @var string
	 */
	protected $_infoBlockType = \Tamara\Checkout\Block\Info::class;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_isGateway = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canAuthorize = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canCapture = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canCapturePartial = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canRefund = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canRefundInvoicePartial = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canVoid = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canUseInternal = false;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canFetchTransactionInfo = true;

	/**
	 * Payment Method feature
	 *
	 * @var bool
	 */
	protected $_canReviewPayment = false;

	/**
	 * @var bool
	 */
	protected $_canCancelInvoice = true;

    /**
     * @var Config
     */
	protected $_configModule;

    /**
     * @var OrderRepositoryInterface
     */
	protected $tamaraOrderRepository;

    /**
     * @var OrderInterface
     */
	protected $tamaraOrder;

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $tamaraHelper;


    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param BaseConfig $config ,
     * @param AbstractResource $resource
     * @param AbstractDb $resourceCollection
     * @param array $data
     * @param DirectoryHelper $directory
     * @param OrderRepositoryInterface $tamaraOrderRepository
     * @param OrderInterface $tamaraOrder
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
	public function __construct(
		\Magento\Framework\Model\Context $context,
		\Magento\Framework\Registry $registry,
		\Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
		\Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
		\Magento\Payment\Helper\Data $paymentData,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Payment\Model\Method\Logger $logger,
		BaseConfig $config,
        OrderRepositoryInterface $tamaraOrderRepository,
        OrderInterface $tamaraOrder,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper,
		?AbstractResource $resource = null,
		?AbstractDb $resourceCollection = null,
		array $data = [],
		?DirectoryHelper $directory = null
	) {
		parent::__construct(
			$context,
			$registry,
			$extensionFactory,
			$customAttributeFactory,
			$paymentData,
			$scopeConfig,
			$logger,
			$resource,
			$resourceCollection,
			$data,
			$directory
		);
		$this->_configModule = $config;
		$this->tamaraOrderRepository = $tamaraOrderRepository;
		$this->tamaraOrder = $tamaraOrder;
		$this->tamaraHelper = $tamaraHelper;
	}
    /**
     * To check billing country is allowed for the payment method
     *
     * @param string $country
     * @return bool
     * @deprecated 100.2.0
     */
    public function canUseForCountry($country)
    {
        if ($country != $this->tamaraHelper->getStoreCountryCode()) {
            return false;
        }
        return parent::canUseForCountry($country) && in_array($country, explode(',', self::ALLOWED_COUNTRIES));
    }

	/**
	 * Assign data to info model instance
	 *
	 * @param \Magento\Framework\DataObject|mixed $data
	 * @return $this
	 * @throws \Magento\Framework\Exception\LocalizedException
	 */
	public function assignData(\Magento\Framework\DataObject $data)
	{
		$additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
		if (!is_object($additionalData)) {
			$additionalData = new DataObject($additionalData ?: []);
		}

		/** @var DataObject $info */
		$info = $this->getInfoInstance();
		$info->setAdditionalInformation(
			[
				'checkout_id' => $additionalData->getCheckoutId()
			]
		);

		$this->logger->debug(['assignData', $info->getAdditionalInformation('checkout_id')]);
		return $this;
	}
}
