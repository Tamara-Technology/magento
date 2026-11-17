<?php

namespace Tamara\Checkout\Setup;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\App\ResourceConnection;

class UpgradeData implements UpgradeDataInterface
{

    /**
     * @var BlockFactory
     */
    private $blockFactory;

    /**
     * @var BlockRepositoryInterface
     */
    private $blockRepository;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    private $configWriter;

    /**
     * Status Factory
     *
     * @var StatusFactory
     */
    private $statusFactory;

    /**
     * Status Resource Factory
     *
     * @var StatusResourceFactory
     */
    private $statusResourceFactory;

    public function __construct(BlockFactory $blockFactory, BlockRepositoryInterface $blockRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory
    )
    {
        $this->blockFactory = $blockFactory;
        $this->blockRepository = $blockRepository;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
    }

    /**
     * Upgrades data for a module
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.0.5', '<')) {
            $this->createCmsBlock();
        }

        if (version_compare($context->getVersion(), '1.0.6', '<')) {
            $this->updateCmsBlock();
        }

        if (version_compare($context->getVersion(), '1.1.0', '<')) {
            $this->removeCmsBlock();
        }

        if (version_compare($context->getVersion(), '1.1.1', '<')) {
            $this->updateApiUrlConfig();
        }

        if (version_compare($context->getVersion(), '1.1.2', '<')) {
            $this->saveApiUrlFromApiEnvironment();
        }

        if (version_compare($context->getVersion(), '1.1.4', '<')) {
            $this->updateConfigForCreditPreCheck($setup);
            $this->convertClosedStatusToCanceledStatusForFailureOrder($setup);
        }

        if (version_compare($context->getVersion(), '1.1.5', '<')) {
            $this->addExpiredStatus();
        }

        if (version_compare($context->getVersion(), '1.1.7', '<')) {
            $this->updateEnableTamaraPaymentConfig($setup);
        }

        $setup->endSetup();
    }

    private function createCmsBlock()
    {
        $content = '<div class="modal">
                    <div class="modal-overlay modal-toggle">&nbsp;</div>
                    <div class="modal-wrapper modal-transition">
                    <div class="modal-header"><button class="modal-close modal-toggle"></button>
                    <div class="modal-heading"><img class="title" src="{{view url=\'Tamara_Checkout::images/logo.svg\'}}" alt="Tamara - buy now pay later">
                    <p style="padding: 8px 0">{{trans "Buy now pay later in 30 days"}}</p>
                    </div>
                    </div>
                    <div class="modal-body">
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/icon1.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "No fees"}}</p>
                    <p class="sub-description">{{trans "Zero interest and no hidden fees."}}</p>
                    </div>
                    </div>
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/icon2.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "No credit card? No problem!"}}</p>
                    <p class="sub-description">{{trans "Use any debit card or bank transfer to repay."}}</p>
                    </div>
                    </div>
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/icon3.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "Quick and easy"}}</p>
                    <p class="sub-description">{{trans "Simple use your phone number and complete your checkout."}}</p>
                    </div>
                    </div>
                    <div style="text-align: center">{{trans "Sounds good? Just select tamara at checkout."}}</div>
                    </div>
                    </div>
                    </div>';
        $cmsBlockData = [
            'title' => 'Tamara Checkout Info',
            'identifier' => 'tamara_cms_block_info',
            'content' => $content,
            'is_active' => 1,
            'stores' => [0],
            'sort_order' => 0
        ];

        $cmsBlock = $this->blockFactory->create()->setData($cmsBlockData);
        $this->blockRepository->save($cmsBlock);
    }

    private function updateCmsBlock()
    {
        $cmsBlock = $this->blockRepository->getById('tamara_cms_block_info');
        $content = '<div class="modal">
                    <div class="modal-overlay modal-toggle">&nbsp;</div>
                    <div class="modal-wrapper modal-transition">
                    <div class="modal-header"><button class="modal-close modal-toggle"></button>
                    <div class="modal-heading"><img class="title" src="{{view url=\'Tamara_Checkout::images/\'}}/{{trans \'logo.svg\'}}" alt="Tamara - buy now pay later">
                    <p class="sub-title-head">{{trans "Receive the good before you pay for it"}}</p>
                    <p style="padding: 8px 0">{{trans "Pay within 30 days after shipping."}}</p>
                    </div>
                    </div>
                    <div class="modal-body">
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/zero-percent.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "Zero Interest"}}</p>
                    <p class="sub-description">{{trans "No hidden fees."}}</p>
                    </div>
                    </div>
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/debit-card.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "No credit card needed"}}</p>
                    <p class="sub-description">{{trans "Use any debit card or bank transfer or even Apple pay to repay."}}</p>
                    </div>
                    </div>
                    <div class="modal-content one-block">
                    <div class="left-content"><img src="{{view url=\'Tamara_Checkout::images/mobile.svg\'}}" alt=""></div>
                    <div class="right-content">
                    <p class="sub-title">{{trans "Quick and easy"}}</p>
                    <p class="sub-description">{{trans "Simply, use your phone number once you complete your checkout."}}</p>
                    </div>
                    </div>
                    <div style="text-align: center">{{trans "Sounds good? Just select Tamara at checkout."}}</div>
                    <div style="text-align: center">{{trans "For more information about"}} <a target="_blank" href="https://tamara.co"> {{trans "Tamara"}} </a></div>
                    </div>
                    </div>
                    </div>
        ';

        $cmsBlock['content'] = $content;
        $this->blockRepository->save($cmsBlock);
    }

    private function removeCmsBlock()
    {
        $cmsBlock = $this->blockRepository->getById('tamara_cms_block_info');
        $this->blockRepository->deleteById($cmsBlock->getId());
    }

    private function updateApiUrlConfig() {
        if ($this->scopeConfig->getValue("payment/tamara_checkout/api_url" ,\Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES)
            == \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_URL) {
            $this->configWriter->save('payment/tamara_checkout/api_environment', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_ENVIRONMENT);
        } else {
            $this->configWriter->save('payment/tamara_checkout/api_environment', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::SANDBOX_API_ENVIRONMENT);
        }
    }

    private function saveApiUrlFromApiEnvironment() {
        if ($this->scopeConfig->getValue("payment/tamara_checkout/api_environment" ,\Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES)
            == \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_ENVIRONMENT) {
            $this->configWriter->save('payment/tamara_checkout/api_url', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::PRODUCTION_API_URL);
        } else {
            $this->configWriter->save('payment/tamara_checkout/api_url', \Tamara\Checkout\Api\Data\CheckoutInformationInterface::SANDBOX_API_URL);
        }
    }

        
    /**
     * @param  ModuleDataSetupInterface $setup
     * @return void
     */
    private function updateConfigForCreditPreCheck($setup) {
        $table = $setup->getTable('core_config_data');
        $query = "UPDATE `{$table}` SET `path` = 'payment/tamara_checkout/enable_credit_pre_check' WHERE `path` = 'payment/tamara_checkout/enable_post_credit_check';";
        $setup->getConnection()->query($query);
    }
 
    /**
     *
     * @param  ModuleDataSetupInterface $setup
     * @return void
     */
    private function convertClosedStatusToCanceledStatusForFailureOrder(ModuleDataSetupInterface $setup) {
        $table = $setup->getTable('core_config_data');
        $value = \Magento\Sales\Model\Order::STATE_CANCELED;
        $query = "UPDATE `{$table}` SET `value` = '{$value}' WHERE `path` = 'payment/tamara_checkout/checkout_order_statuses/checkout_failure_status';";
        $setup->getConnection()->query($query);
    }

    private function addExpiredStatus() {
        /** @var StatusResource $statusResource */
        $statusResource = $this->statusResourceFactory->create();

        /** @var Status $status */
        $status = $this->statusFactory->create();
        $status->setData([
            'status' => \Tamara\Checkout\Model\Config\Source\Order\State\Cancelled\Status::STATUS_EXPIRED,
            'label' => \Tamara\Checkout\Model\Config\Source\Order\State\Cancelled\Status::STATUS_EXPIRED_LABEL,
        ]);
        try {
            $statusResource->save($status);
        } catch (AlreadyExistsException $exception) {
            return;
        }
        $status->assignState(\Magento\Sales\Model\Order::STATE_CANCELED, false, true);
    }

    private function updateEnableTamaraPaymentConfig(ModuleDataSetupInterface $setup) {
        $table = $setup->getTable('core_config_data');
        $selectQuery = "SELECT * FROM `{$table}` WHERE `path` like '%tamara%active%'  AND `value` = '1'";
        $rs = $setup->getConnection()->fetchAll($selectQuery);
        if (!empty($rs)) {
            $data = [];
            foreach ($rs as $row) {
                $data[] = [
                    'config_id' => NULL,
                    'scope' => $row['scope'],
                    'scope_id' => $row['scope_id'],
                    'path' => 'payment/tamara_checkout/enable_payment',
                    'value' => '1',
                    'updated_at' => gmdate('Y-m-d h:i:s \G\M\T', time())
                ];
            }
            $setup->getConnection()->insertOnDuplicate($table, $data);
        }
    }
}