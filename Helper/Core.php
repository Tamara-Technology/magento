<?php

namespace Tamara\Checkout\Helper;

use Magento\Framework\App\Area;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

class Core extends AbstractHelper
{
    const UNKNOWN = "Unknown";

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var array
     */
    protected $isArea = [];

    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager
    ) {
        $this->objectManager = $objectManager ?? \Magento\Framework\App\ObjectManager::getInstance();
        $this->storeManager = $storeManager ?? $this->getObject(StoreManagerInterface::class);

        parent::__construct($context);
    }

    /**
     * @param $path
     * @param array $arguments
     *
     * @return mixed
     */
    public function createObject($path, $arguments = [])
    {
        return $this->objectManager->create($path, $arguments);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    public function getObject($path)
    {
        return $this->objectManager->get($path);
    }

    /**
     * @param $haystack string
     * @param $needle string
     * @return bool
     */
    public function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return substr($haystack, 0, $length) === $needle;
    }

    /**
     * @param $haystack string
     * @param $needle string
     * @return bool
     */
    public function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if (!$length) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }

    /**
     * @return bool
     */
    public function isAdminArea()
    {
        return $this->isArea(Area::AREA_ADMINHTML);
    }

    /**
     * @param string $area
     * @return bool
     */
    public function isArea($area = Area::AREA_FRONTEND)
    {
        if (!isset($this->isArea[$area])) {
            /** @var State $state */
            $state = $this->objectManager->get(\Magento\Framework\App\State::class);

            try {
                $this->isArea[$area] = ($state->getAreaCode() == $area);
            } catch (\Exception $e) {
                $this->isArea[$area] = false;
            }
        }

        return $this->isArea[$area];
    }

    /**
     * Get plugin version in composer.json
     * @param null $moduleName
     * @return string
     */
    public function getPluginVersion($moduleName = null) {
        $composerJsonPath = $this->getModuleDir($moduleName) . DIRECTORY_SEPARATOR . "composer.json";
        if (file_exists($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);
            if ($content) {
                $jsonContent = json_decode($content, true);
                if (!empty($jsonContent['version'])) {
                    return $jsonContent['version'];
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Get PHP SDK version in composer.json
     */
    public function getPHPSDKVersion() {
        $rootPath = $this->getObject(\Magento\Framework\Filesystem\DirectoryList::class)->getRoot();
        $magentoComposerJsonPath = $rootPath . DIRECTORY_SEPARATOR . "composer.json";
        $vendorPath = "";
        if (file_exists($magentoComposerJsonPath)) {
            $content = file_get_contents($magentoComposerJsonPath);
            if ($content) {
                $jsonContent = json_decode($content, true);
                if (!empty($jsonContent['config']['vendor-dir'])) {
                    $vendorPath = rtrim($jsonContent['config']['vendor-dir'], DIRECTORY_SEPARATOR);
                }
            }
        }
        if (empty($vendorPath)) {
            $vendorPath = $rootPath . DIRECTORY_SEPARATOR . "vendor";
        }
        if (is_dir($vendorPath)) {
            $composerJsonPath = $vendorPath . DIRECTORY_SEPARATOR . "tamara-solution" . DIRECTORY_SEPARATOR . "php-sdk" . DIRECTORY_SEPARATOR . "composer.json";
        } else {
            $composerJsonPath = $vendorPath;
        }
        if (file_exists($composerJsonPath)) {
            $content = file_get_contents($composerJsonPath);
            if ($content) {
                $jsonContent = json_decode($content, true);
                if (!empty($jsonContent['version'])) {
                    return $jsonContent['version'];
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * @param null $moduleName
     * @return string|null
     */
    public function getModuleDir($moduleName = null) {
        if (!$moduleName) {
            $moduleName = $this->_getModuleName();
        }

        /**
         * @var ComponentRegistrarInterface $componentRegistrar
         */
        $componentRegistrar = $this->getObject(ComponentRegistrarInterface::class);
        $path = $componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);

        // An empty $type means it's getting the directory of the module itself.
        if (empty($type) && !isset($path)) {
            // Note: do not throw \LogicException, as it would break backwards-compatibility.
            throw new \InvalidArgumentException("Module '$moduleName' is not correctly registered.");
        }

        return $path;
    }

    public function getCurrentScope() {
        if ($this->isAdminArea()) {
            $storeId = $this->_getRequest()->getParam('store');
            if ($storeId) {
                return \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
            } else {
                $websiteId = $this->_getRequest()->getParam('website');
                if ($websiteId) {
                    return \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES;
                } else {
                    return \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
                }
            }
        } else {
            return \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        }
    }

    public function getCurrentScopeId() {
        if ($this->isAdminArea()) {
            $storeId = $this->_getRequest()->getParam('store');
            if ($storeId) {
                return $storeId;
            } else {
                $websiteId = $this->_getRequest()->getParam('website');
                if ($websiteId) {
                    return $websiteId;
                } else {
                    return 0;
                }
            }
        } else {
            return $this->storeManager->getStore()->getId();
        }
    }

    /**
     * @param $str
     * @return bool
     */
    public function isAnUrl($str)
    {
        $regex = "((https?|ftp)\:\/\/)?"; // SCHEME
        $regex .= "([a-z0-9+!*(),;?&=\$_.-]+(\:[a-z0-9+!*(),;?&=\$_.-]+)?@)?"; // User and Pass
        $regex .= "([a-z0-9-.]*)\.([a-z]{2,3})"; // Host or IP
        $regex .= "(\:[0-9]{2,5})?"; // Port
        $regex .= "(\/([a-z0-9+\$_-]\.?)+)*\/?"; // Path
        $regex .= "(\?[a-z+&\$_.-][a-z0-9;:@&%=+\/\$_.-]*)?"; // GET Query
        $regex .= "(#[a-z_.-][a-z0-9+\$_.-]*)?"; // Anchor

        if(preg_match("/^$regex$/i", $str)) // `i` flag for case-insensitive
        {
            return true;
        }
        return false;
    }

    /**
     * @param $datetime
     * @param string $format
     * @param null $timezone
     * @return bool
     */
    public function isValidDateTime($datetime, $format = "d-m-Y", $timezone = null)
    {
        return \DateTime::createFromFormat($format, $datetime, $timezone) !== false;
    }
}