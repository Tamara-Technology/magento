<?php

namespace Tamara\Checkout\Controller\Adminhtml\Whitelist;

class Edit extends \Magento\Backend\App\Action
{

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $resultForwardFactory;
    protected $_registry;
    protected $modelFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Backend\Model\View\Result\ForwardFactory $resultForwardFactory,
        \Magento\Framework\Registry $registry,
        \Tamara\Checkout\Model\EmailWhiteListFactory $modelFactory

    )
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->_registry = $registry;
        $this->resultForwardFactory = $resultForwardFactory;
        $this->modelFactory = $modelFactory;
    }

    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $model = $this->modelFactory->create();
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                $this->messageManager->addError(__('This column not existed'));
                /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('*/*/');
            }
        }

        $this->_registry->register('whitelish_data', $model);

        $resultPage = $this->resultPageFactory->create();

        $resultPage->addBreadcrumb(
            $id ? __('Edit Whitelist') : __('New Whitelist'),
            $id ? __('Edit Whitelist') : __('New Whitelist')
        )->setActiveMenu('Tamara_Checkout::tamara_whitelist');

        $resultPage->getConfig()->getTitle()->prepend(__('Whitelist'));
        $resultPage->getConfig()->getTitle()->prepend($model->getId() ? $model->getWhitelistId() : __('New Whitelist'));

        return $resultPage;
    }
}
