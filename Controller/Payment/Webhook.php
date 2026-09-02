<?php

declare(strict_types=1);

namespace Tamara\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Tamara\Checkout\Model\Adapter\TamaraAdapterFactory;

class Webhook extends Action
{

    protected $_pageFactory;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var TamaraAdapterFactory
     */
    protected $tamaraAdapterFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        TamaraAdapterFactory $tamaraAdapterFactory
    )
    {
        $this->_pageFactory = $pageFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->tamaraAdapterFactory = $tamaraAdapterFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $storeId = $this->_request->getParam('storeId', null);
        $tamaraAdapter = $this->tamaraAdapterFactory->create($storeId);
        $success = $tamaraAdapter->webhook();

        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();
        $response = ['success' => $success];

        $resultJson->setData($response);
        return $resultJson;
    }
}