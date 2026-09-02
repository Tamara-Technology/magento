<?php

namespace Tamara\Checkout\Ui\DataProvider\Whitelist\Modifier;

use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Magento\Framework\UrlInterface;


class Template implements ModifierInterface
{

    protected $coreRegistry;


    protected $urlBuilder;


    public function __construct(
        \Magento\Framework\Registry $coreRegistry,
        UrlInterface $urlBuilder
    ) {
        $this->coreRegistry = $coreRegistry;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function modifyMeta(array $meta)
    {
        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function modifyData(array $data)
    {
        return $data;
    }
}
