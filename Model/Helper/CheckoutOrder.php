<?php

namespace Tamara\Checkout\Model\Helper;

use Tamara\Model\Order\Order;

class CheckoutOrder extends Order
{
    public function toArray(): array
    {
        $result = parent::toArray();
        if (($result[self::PAYMENT_TYPE] ?? '') === '') {
            unset($result[self::PAYMENT_TYPE], $result[self::INSTALMENTS]);
        }
        return $result;
    }
}
