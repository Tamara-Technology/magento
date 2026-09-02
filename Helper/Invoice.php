<?php

namespace Tamara\Checkout\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;

class Invoice extends \Tamara\Checkout\Helper\AbstractData
{
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $magentoOrderRepository;

    public function __construct(
        Context $context,
        \Magento\Framework\Locale\Resolver $locale,
        StoreManagerInterface $storeManager,
        \Magento\Framework\App\CacheInterface $magentoCache,
        \Tamara\Checkout\Gateway\Config\BaseConfig $tamaraConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $magentoOrderRepository,
        \Tamara\Checkout\Model\Adapter\TamaraAdapterFactory $tamaraAdapterFactory
    ) {
        $this->magentoOrderRepository = $magentoOrderRepository;
        parent::__construct($context, $locale, $storeManager, $magentoCache, $tamaraConfig, $tamaraAdapterFactory);
    }

    /**
     * @param $orderId
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generateInvoice($orderId)
    {
        try {
            $order = $this->magentoOrderRepository->get($orderId);
            if ($order->canInvoice()) {

                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

                /**
                 * @var $invoiceService \Magento\Sales\Model\Service\InvoiceService
                 */
                $invoiceService = $objectManager->create(\Magento\Sales\Model\Service\InvoiceService::class);

                /**
                 * @var $transaction \Magento\Framework\DB\Transaction
                 */
                $transaction = $objectManager->create(\Magento\Framework\DB\Transaction::class);

                /**
                 * @var $invoiceSender \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                 */
                $invoiceSender = $objectManager->create(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class);

                $invoice = $invoiceService->prepareInvoice($order);
                $invoice->register();
                $invoice->save();
                $transactionSave = $transaction->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
                $transactionSave->save();
                $invoiceSender->send($invoice);

                //send notification code
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getIncrementId()), false
                )
                    ->setIsCustomerNotified(true)
                    ->save();
            }
        } catch (\Exception $exception) {
            $this->log(["Error when generate invoice for an order" => $exception->getMessage()], true);
        }
    }
}