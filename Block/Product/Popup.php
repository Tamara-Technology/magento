<?php

namespace Tamara\Checkout\Block\Product;

use Magento\Framework\View\Element\Template;
use Tamara\Checkout\Gateway\Config\BaseConfig;
use Magento\Customer\Model\Session;
use Tamara\Checkout\Model\EmailWhiteListFactory;

class Popup extends Template
{
    protected $registry;

    protected $customerSession;

    protected $config;

    protected $whitelistFactory;

    protected $helper;

    protected $instalmentConfig;

    protected $payLaterConfig;

    /**
     * @var \Tamara\Checkout\Helper\AbstractData
     */
    protected $tamaraHelper;

    public function __construct(
        Template\Context $context,
        \Magento\Framework\Registry $registry,
        BaseConfig $config,
        Session $customerSession,
        EmailWhiteListFactory $whiteListFactory,
        \Tamara\Checkout\Gateway\Config\InstalmentConfig $instalmentConfig,
        \Tamara\Checkout\Gateway\Config\PayLaterConfig $payLaterConfig,
        \Tamara\Checkout\Helper\AbstractData $tamaraHelper,
        array $data = []
    )
    {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->customerSession = $customerSession;
        $this->config = $config;
        $this->whitelistFactory = $whiteListFactory;
        $this->instalmentConfig = $instalmentConfig;
        $this->payLaterConfig = $payLaterConfig;
        $this->tamaraHelper = $tamaraHelper;
    }

    protected function _toHtml()
    {
        return parent::_toHtml();
    }

    private function isAllowWhitelistEmail(): bool
    {
        $isLogin = $this->customerSession->isLoggedIn();
        if (!$isLogin) {
            return false;
        }
        $customerEmail = $this->customerSession->getCustomer()->getEmail();
        return $this->isWhiteListEmail($customerEmail);
    }

    private function isWhiteListEmail($customerEmail): bool
    {
        $model = $this->whitelistFactory->create();
        $collections = $model->getCollection()->addFieldToFilter('customer_email', $customerEmail)->getFirstItem();

        return $collections->getId() > 0;
    }

    /**
     * @return bool
     */
    public function isArabicLanguage(): bool
    {
        return $this->tamaraHelper->isArabicLanguage();
    }

    /**
     * @return \Magento\Framework\Pricing\Price\PriceInterface
     * @throws \Exception
     */
    public function getCurrentProductPrice() {

        /**
         * @var $currentProduct \Magento\Catalog\Model\Product
         */
        if ($currentProduct = $this->getCurrentProduct()) {
            return floatval($currentProduct->getPriceInfo()->getPrice('final_price')->getAmount()->getValue());
        }
        throw new \Exception(__('Cannot get current product price'));
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreCurrencyCode() {
        return $this->tamaraHelper->getStoreCurrencyCode();
    }

    /**
     * @return mixed
     */
    public function getCurrentProduct() {
        return $this->registry->registry('current_product');
    }

    /**
     * @param float $price
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Tamara\Exception\RequestDispatcherException
     */
    public function getAvailablePaymentMethods(float $price) {
        $enabledMethods = $this->tamaraHelper->getPaymentTypesOfStore();
        return $this->filterUnderOver($enabledMethods, $price);
    }

    /**
     * @param array $methods
     * @param float $price
     * @return array
     */
    public function filterUnderOver(array $methods, float $price) {
        $result = [];
        foreach ($methods as $method) {
            if ($price < $method['min_limit'] || $price > $method['max_limit']) {
                continue;
            }
            $result[$method['name']] = $method;
        }
        return $result;
    }

    /**
     * @param $price
     * @return array|mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Tamara\Exception\RequestDispatcherException
     */
    public function getPaymentMethodForPdpWidget($price) {
        $result = [];
        $availableMethods = $this->getAvailablePaymentMethods($price);
        if (!empty($availableMethods)) {
            $firstElement = [];
            $installmentsMethod = [];
            foreach ($availableMethods as $methodCode => $method) {
                if (\Tamara\Checkout\Gateway\Config\InstalmentConfig::isInstallmentsPayment($methodCode)) {
                    $installmentsMethod = $method;
                }
                if (empty($firstElement)) {
                    $firstElement = $method;
                }
            }
            if (empty($installmentsMethod)) {
                $result = $firstElement;
            } else {
                $result = $installmentsMethod;
            }
        }
        return $result;
    }

    /**
     * @return bool
     */
    public function availableToShow() {
        $currentProduct = $this->getCurrentProduct();
        if (!$this->config->getEnableTamaraPdpWidget($currentProduct->getStoreId())) {
            return false;
        }
        $excludeProductIds = explode("," , strval($this->config->getExcludeProductIds($currentProduct->getStoreId())));
        if (in_array($currentProduct->getEntityId(), $excludeProductIds)) {
            return false;
        }
        $whitelistConfig = $this->config->getIsUseWhitelist($this->tamaraHelper->getCurrentStore()->getId());
        if ($whitelistConfig) {
            if (!$this->isAllowWhitelistEmail()) {
                return false;
            }
        }
        return true;
    }

    public function getPublicKey() {
        return $this->tamaraHelper->getMerchantPublicKey();
    }

    public function isProductionApiEnvironment() {
        return $this->config->isProductionApiEnvironment();
    }

    public function getInlineType() {
        return 2; //product widget
    }

    public function getPageType() {
        return 'product';
    }
}