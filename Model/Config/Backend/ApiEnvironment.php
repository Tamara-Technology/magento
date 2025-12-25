<?php


namespace Tamara\Checkout\Model\Config\Backend;

class ApiEnvironment extends \Magento\Framework\App\Config\Value
{
    protected $resourceConfig;
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\App\Config\ConfigResource\ConfigInterface $resourceConfig,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->resourceConfig = $resourceConfig;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return \Magento\Framework\App\Config\Value
     * @throws \Exception
     */
    public function beforeSave()
    {
        if ($this->getValue() == \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_ENVIRONMENT) {
            $this->resourceConfig->saveConfig('payment/tamara_checkout/api_url', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_URL, $this->getScope(), $this->getScopeId());
        } else {
            $this->resourceConfig->saveConfig('payment/tamara_checkout/api_url', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::SANDBOX_API_URL, $this->getScope(), $this->getScopeId());
        }
        return parent::beforeSave();
    }
}