<?php

namespace Tamara\Checkout\Model\Helper;

use Magento\Sales\Model\Order\Item;
use Tamara\Checkout\Gateway\Config\PayNextMonthConfig;
use Tamara\Checkout\Gateway\Config\PayNowConfig;
use Tamara\Checkout\Gateway\Config\PayLaterConfig;
use Tamara\Checkout\Gateway\Config\InstalmentConfig;
use Tamara\Checkout\Model\CaptureItem;
use Tamara\Model\Money;
use Tamara\Model\Order\OrderItem;
use Tamara\Model\Order\OrderItemCollection;
use Tamara\Model\Payment\Capture;
use Tamara\Model\Payment\Refund;
use Tamara\Model\ShippingInfo;
use Tamara\Request\Order\CancelOrderRequest;
use Tamara\Request\Payment\CaptureRequest;
use Tamara\Checkout\Model\Capture as CaptureCheckout;
use Tamara\Request\Payment\RefundRequest;
use Tamara\Response\Payment\CancelResponse;

class PaymentHelper
{
    public const ALLOWED_PAYMENTS = [
        PayLaterConfig::PAYMENT_TYPE_CODE,
        PayNextMonthConfig::PAYMENT_TYPE_CODE,
        PayNowConfig::PAYMENT_TYPE_CODE,
        InstalmentConfig::PAYMENT_TYPE_CODE_2,
        InstalmentConfig::PAYMENT_TYPE_CODE,
        InstalmentConfig::PAYMENT_TYPE_CODE_4,
        InstalmentConfig::PAYMENT_TYPE_CODE_5,
        InstalmentConfig::PAYMENT_TYPE_CODE_6,
        InstalmentConfig::PAYMENT_TYPE_CODE_7,
        InstalmentConfig::PAYMENT_TYPE_CODE_8,
        InstalmentConfig::PAYMENT_TYPE_CODE_9,
        InstalmentConfig::PAYMENT_TYPE_CODE_10,
        InstalmentConfig::PAYMENT_TYPE_CODE_11,
        InstalmentConfig::PAYMENT_TYPE_CODE_12
    ];

    public static function createCaptureRequestFromArray(array $data): CaptureRequest
    {
        $totalAmount = new Money($data['total_amount'], $data['currency']);
        $shippingAmount = new Money($data['shipping_amount'], $data['currency']);
        $taxAmount = new Money($data['tax_amount'], $data['currency']);
        $discountAmount = new Money($data['discount_amount'], $data['currency']);

        $itemCollection = new OrderItemCollection();
        foreach ($data['items'] as $item) {
            $itemCollection->append(self::createItemFromData($item, $data));
        }

        $shippingData = $data['shipping_info'];

        $companies = [];
        $trackingNumbers = [];

        foreach ($shippingData['items'] as $item) {
            $companies[] = $item['title'] ?? '';
            $trackingNumbers[] = $item['track_number'] ?? '';
        }

        $company = !empty($companies) ? implode(',', $companies) : \Tamara\Checkout\Model\Capture::EMPTY_STRING;
        $trackNumber = !empty($trackingNumbers) ? implode(',', $trackingNumbers) : '';

        $shippingInfo = new ShippingInfo(
            new \DateTimeImmutable('now'),
            $company,
            $trackNumber
        );

        $capture = new Capture(
            $data['tamara_order_id'],
            $totalAmount,
            $shippingAmount,
            $taxAmount,
            $discountAmount,
            $itemCollection,
            $shippingInfo
        );

        return new CaptureRequest($capture);
    }

    public static function createCaptureFromArray(array $data): CaptureCheckout
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var CaptureCheckout $capture */
        $capture = $objectManager->create(CaptureCheckout::class);

        $capture->setOrderId($data['order_id']);
        $capture->setTamaraOrderId($data['tamara_order_id']);
        $capture->setCaptureId($data['capture_id']);
        $capture->setDiscountAmount($data['discount_amount']);
        $capture->setTotalAmount($data['total_amount']);
        $capture->setTaxAmount($data['tax_amount']);
        $capture->setShippingAmount($data['shipping_amount']);
        $capture->setCurrency($data['currency']);
        $capture->setShippingInfo($data['shipping_info']);

        return $capture;
    }

    public static function createCaptureItemFromArray(array $data): CaptureItem
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var CaptureItem $captureItem */
        $captureItem = $objectManager->create(CaptureItem::class);

        $captureItem->setUnitPrice($data['unit_price']);
        $captureItem->setTotalAmount($data['total_amount']);
        $captureItem->setDiscountAmount($data['discount_amount']);
        $captureItem->setTaxAmount($data['tax_amount']);
        $captureItem->setName($data['name']);
        $captureItem->setOrderItemId($data['order_item_id']);
        $captureItem->setSku($data['sku']);
        $captureItem->setQuantity($data['quantity']);
        $captureItem->setType($data['type']);
        $captureItem->setImageUrl($data['image_url']);

        return $captureItem;
    }

    public static function createRefundRequestFromArray(array $data): RefundRequest
    {
        $refundRequest = new RefundRequest($data['tamara_order_id'], []);

        foreach ($data['refunds'] as $captureId => $refund) {
            $orderItemCollection = new OrderItemCollection();
            foreach ($refund['items'] as $item) {
                $orderItemCollection->append(self::createItemFromData($item, $data));
            }

            $refundModel = new Refund(
                $captureId,
                new Money($refund['total_amount'], $data['currency']),
                new Money($refund['shipping_amount'], $data['currency']),
                new Money($refund['tax_amount'], $data['currency']),
                new Money($refund['discount_amount'], $data['currency']),
                $orderItemCollection
            );

            $refundRequest->addRefund($refundModel);
        }

        return $refundRequest;
    }

    public static function createRefundFromData(
        $captureId,
        $refundId,
        $request,
        $data,
        $refundData,
        $totalAmount
    ): \Tamara\Checkout\Model\Refund
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Tamara\Checkout\Model\Refund $refund */
        $refund = $objectManager->create(\Tamara\Checkout\Model\Refund::class);

        $refund->setCaptureId($captureId);
        $refund->setOrderId($data['order_id']);
        $refund->setRefundId($refundId);
        $refund->setTamaraOrderId($data['tamara_order_id']);
        $refund->setRefundedAmount($refundData['refunded_amount']);
        $refund->setTotalAmount($totalAmount);
        $refund->setCurrency($data['currency']);
        $refund->setRequest($request);

        return $refund;
    }

    public static function createCancelRequestFromArray($data): CancelOrderRequest
    {
        $totalAmount = new Money($data['total_amount'], $data['currency']);
        $shippingAmount = new Money($data['shipping_amount'], $data['currency']);
        $taxAmount = new Money($data['tax_amount'], $data['currency']);
        $discountAmount = new Money($data['discount_amount'], $data['currency']);

        $itemCollection = new OrderItemCollection();
        /** @var Item $item */
        foreach ($data['items'] as $item) {
            $orderItem = new OrderItem();
            $orderItem->setReferenceId($item->getItemId());
            $orderItem->setName($item->getName());
            $orderItem->setSku($item->getSku());
            $orderItem->setType($item->getProductType());
            $orderItem->setUnitPrice(new Money($item->getPrice(), $data['currency']));
            $orderItem->setTotalAmount(new Money(self::getItemTotalAmount($item), $data['currency']));
            $orderItem->setTaxAmount(new Money($item->getTaxAmount(), $data['currency']));
            $discountAmountForItem = floatval($item->getDiscountAmount());
            if ($discountAmountForItem > 0.00) {
                $orderItem->setDiscountAmount(new Money($discountAmountForItem, $data['currency']));
            }
            $orderItem->setQuantity($item->getQtyOrdered());
            $orderItem->setImageUrl('');
            $orderItem->setItemUrl('');
            $itemCollection->append($orderItem);
        }

        return new CancelOrderRequest(
            $data['tamara_order_id'],
            $totalAmount,
            $itemCollection,
            $shippingAmount,
            $taxAmount,
            $discountAmount
        );
    }

    public static function createCancelFromResponse(CancelResponse $response): \Tamara\Checkout\Model\Cancel
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Tamara\Checkout\Model\Cancel $cancel */
        $cancel = $objectManager->create(\Tamara\Checkout\Model\Cancel::class);

        $cancel->setTamaraOrderId($response->getOrderId());
        $cancel->setCancelId($response->getCancelId());

        return $cancel;
    }

    private static function createItemFromData($item, $data)
    {
        $orderItem = new OrderItem();
        $orderItem->setReferenceId($item['order_item_id']);
        $orderItem->setName($item['name']);
        $orderItem->setSku($item['sku']);
        $orderItem->setType($item['type']);
        $orderItem->setUnitPrice(new Money($item['unit_price'], $data['currency']));
        $orderItem->setTotalAmount(new Money($item['total_amount'], $data['currency']));
        $orderItem->setTaxAmount(new Money($item['tax_amount'] ?? 0, $data['currency']));
        if (!empty($item['discount_amount'])) {
            $orderItem->setDiscountAmount(new Money(floatval($item['discount_amount']), $data['currency']));
        }
        $orderItem->setQuantity($item['quantity']);
        $orderItem->setImageUrl((string) ($item['image_url'] ?? ''));
        $orderItem->setItemUrl((string) ($item['item_url'] ?? ''));

        return $orderItem;
    }

    private static function getItemTotalAmount($item)
    {
        if ($item->getRowTotal() === null || !$item->getRowTotal()) {
            return 0;
        }

        return $item->getRowTotal()
            - $item->getDiscountAmount()
            + $item->getTaxAmount()
            + $item->getDiscountTaxCompensationAmount();
    }

    public static function isTamaraPayment($method)
    {
        return in_array($method, self::ALLOWED_PAYMENTS, true);
    }

}