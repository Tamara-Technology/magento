<?php

namespace Tamara\Checkout\Controller\Adminhtml\Whitelist;

use Tamara\Checkout\Model\EmailWhiteListFactory;
use Magento\Backend\App\Action;

class ProcessImport extends \Magento\Backend\App\Action
{

    protected $csv;

    protected $authSession;

    protected $storeManager;

    protected $timezone;


    public function __construct(
        Action\Context $context,
        EmailWhiteListFactory $whiteListFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        \Magento\Framework\File\Csv $csv
    ) {
        parent::__construct($context);

        $this->whitelistFactory = $whiteListFactory;
        $this->timezone = $timezone;
        $this->csv = $csv;
    }

    public function execute()
    {
        $model = $this->whitelistFactory->create();
        $resultRedirect = $this->resultRedirectFactory->create();
        $file = $this->getRequest()->getFiles('filecsv');
        if ($file) {
            if (substr($file["name"], -4) !== '.csv') {
                $this->messageManager->addErrorMessage(__('Please choose a CSV file'));

                return $resultRedirect->setPath('*/*/import');
            }

            try {
                $fileName = $file['tmp_name'];
                $data = $this->csv->getData($fileName);
                $updateAt = $createAt = date('Y-m-d H:i:s', $this->timezone->date()->getTimestamp());

                $savedItems = [];
                $i = 0;
                foreach ($data as $row => $email) {
                    $email = trim($email[0]);
                    // we don't process header or duplicated email
                    if (0 === $row || empty($email) || isset($savedItems[$email])) {
                        continue;
                    }

                    $collection = $model->getCollection()->addFieldToFilter('customer_email', $email)->getFirstItem();
                    // already existed
                    if ($collection->getId() > 0) {
                        continue;
                    }

                    try {
                        $model->setCustomerEmail($email)
                            ->setUpdatedAt($updateAt)
                            ->setCreatedAt($createAt)
                            ->save();
                        $savedItems[$email] = 1;
                        $i++;
                    } catch (\Exception $e) {
                        $this->messageManager->addSuccessMessage($e->getMessage());
                    }
                }

                if ($i > 0) {
                    $successMessage = __('Imported total %1 Email(s)', $i);
                    $this->messageManager->addSuccessMessage($successMessage);

                    return $resultRedirect->setPath('*/*/index');
                }

                $this->messageManager->addErrorMessage(__('No email imported'));

                return $resultRedirect->setPath('*/*/import');

            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Please check your import file content again.' . $e->getMessage()));

                return $resultRedirect->setPath('*/*/import');
            }
        }
    }
}
