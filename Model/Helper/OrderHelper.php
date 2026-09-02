<?php

namespace Tamara\Checkout\Model\Helper;

use Tamara\Checkout\Gateway\Request\AddressDataBuilder;
use Tamara\Checkout\Gateway\Request\CommonDataBuilder;
use Tamara\Checkout\Gateway\Request\ConsumerDataBuilder;
use Tamara\Checkout\Gateway\Request\ItemsDataBuilder;
use Tamara\Checkout\Gateway\Request\MerchantUrlDataBuilder;
use Tamara\Model\Order\Order;

class OrderHelper
{
    public static function createTamaraOrderFromArray(array $data): Order
    {
        $order = new Order();

        $order->setOrderReferenceId($data[CommonDataBuilder::ORDER_REFERENCE_ID]);
        $order->setLocale($data[CommonDataBuilder::LOCALE]);
        $order->setCurrency($data[CommonDataBuilder::CURRENCY]);
        $order->setTotalAmount($data[CommonDataBuilder::TOTAL_AMOUNT]);
        $order->setTaxAmount($data[CommonDataBuilder::TAX_AMOUNT]);
        $order->setShippingAmount($data[CommonDataBuilder::SHIPPING_AMOUNT]);
        $order->setDiscount($data[CommonDataBuilder::DISCOUNT_AMOUNT]);
        $order->setCountryCode($data[CommonDataBuilder::COUNTRY_CODE]);
        $order->setPaymentType($data[CommonDataBuilder::PAYMENT_TYPE]);
        $order->setPlatform($data[CommonDataBuilder::PLATFORM]);
        $order->setDescription($data[CommonDataBuilder::DESCRIPTION]);
        $order->setShippingAddress($data[AddressDataBuilder::SHIPPING_ADDRESS]);
        $order->setBillingAddress($data[AddressDataBuilder::BILLING_ADDRESS]);
        $order->setMerchantUrl($data[MerchantUrlDataBuilder::MERCHANT_URL]);
        $order->setConsumer($data[ConsumerDataBuilder::CONSUMER]);
        $order->setItems($data[ItemsDataBuilder::ITEMS]);
        $order->setRiskAssessment(new \Tamara\Model\Order\RiskAssessment($data[CommonDataBuilder::RISK_ASSESSMENT]));
        $order->setInstalments($data[\Tamara\Checkout\Gateway\Request\CommonDataBuilder::NUMBER_OF_INSTALLMENTS]);

        return $order;
    }
}
