<?php

declare(strict_types=1);

namespace Tamara\Checkout\Model\Helper;

class StoreHelper
{
    public static function getBaseUrl($storeId = null)
    {
        $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        /**
         * @var $storeManager \Magento\Store\Model\StoreManagerInterface
         */
        $store = $storeManager->getStore($storeId);
        return $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
    }
}