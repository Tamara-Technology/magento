<?php

declare(strict_types=1);

namespace Tamara\Checkout\Block\Adminhtml\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Tamara\Checkout\Gateway\Config\BaseConfig;

class WebhookId extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * @var BaseConfig
     */
    private $config;

    /**
     * @param Context $context
     * @param BaseConfig $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        BaseConfig $config,
        array $data = []
    ) {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    protected function _renderValue(AbstractElement $element)
    {
        $scopeId = $this->config->getTamaraCore()->getCurrentScopeId();
        $scope = $this->config->getTamaraCore()->getCurrentScope();
        $webhookId = $this->config->getScopeConfig()->getValue('payment/tamara_checkout/webhook_id', $scope, $scopeId);
        $webhookId = !empty($webhookId)
                     ? $webhookId
                     : __('You should enable webhook function to get webhook id.');

        return '<td class="value">' . $webhookId . '</td>';
    }

    protected function _renderInheritCheckbox(AbstractElement $element)
    {
        return '<td class="use-default"></td>';
    }
}