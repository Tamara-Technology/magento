<?php

namespace Tamara\Checkout\Gateway\Request;

use Magento\Framework\App\ProductMetadata;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Tamara\Checkout\Model\Helper\LocaleHelper;
use Tamara\Model\Money;
use Tamara\Model\Order\Discount;

class CommonDataBuilder implements BuilderInterface
{
    public const
        ORDER_ID = 'order_id',
        ORDER_REFERENCE_ID = 'order_reference_id',
        LOCALE = 'locale',
        CURRENCY = 'currency',
        TOTAL_AMOUNT = 'total_amount',
        COUNTRY_CODE = 'country_code',
        PAYMENT_TYPE = 'payment_type',
        PLATFORM = 'platform',
        DESCRIPTION = 'description',
        TAX_AMOUNT = 'tax_amount',
        DISCOUNT_AMOUNT = 'discount_amount',
        SHIPPING_AMOUNT = 'shipping_amount',
        RISK_ASSESSMENT = 'risk_assessment',
        NUMBER_OF_INSTALLMENTS = 'number_of_installments';

    /**
     * @var ProductMetadata
     */
    private $productMetaData;

    private $cartRepository;

    protected $orderHelper;

    /**
     * @param ProductMetadata $productMetaData
     */
    public function __construct(ProductMetadata $productMetaData,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        \Tamara\Checkout\Helper\Order $orderHelper
    )
    {
        $this->productMetaData = $productMetaData;
        $this->cartRepository = $cartRepository;
        $this->orderHelper = $orderHelper;
    }

    public function build(array $buildSubject): array
    {
        if (!isset($buildSubject['order'])
            || !$buildSubject['order'] instanceof OrderInterface
        ) {
            throw new \InvalidArgumentException('Order data object should be provided');
        }

        /** @var OrderInterface $order */
        $order = $buildSubject['order'];
        $currencyCode = $buildSubject['order_currency_code'];
        $phoneVerified = $buildSubject['phone_verified'];
        $magentoDiscountAmount = abs(floatval($order->getDiscountAmount()));
        if ($magentoDiscountAmount < 0.00000001) {
            $discountName = "";
            $magentoDiscountAmount = 0.00;
        } else {
            if (empty($order->getCouponCode())) {
                $discountName = "N/A";
            } else {
                $discountName = $order->getCouponCode();
            }
        }
        $discountAmount = new Discount($discountName, new Money($magentoDiscountAmount, $currencyCode));
        // Precheck exposes only Tamara's single-checkout option. Eligibility is
        // customer-specific and must not be read from the store-wide cache.
        $paymentType = '';
        $numberOfInstallments = null;

        return [
            self::ORDER_ID => $order->getEntityId(),
            self::ORDER_REFERENCE_ID => $order->getIncrementId(),
            self::LOCALE => LocaleHelper::getLocale(),
            self::CURRENCY => $currencyCode,
            self::TOTAL_AMOUNT => new Money($order->getGrandTotal(), $currencyCode),
            self::TAX_AMOUNT => new Money($order->getTaxAmount(), $currencyCode),
            self::SHIPPING_AMOUNT => new Money($order->getShippingAmount(), $currencyCode),
            self::DISCOUNT_AMOUNT => $discountAmount,
            self::COUNTRY_CODE => $order->getBillingAddress()->getCountryId(),
            self::PAYMENT_TYPE => $paymentType,
            self::PLATFORM => 'Magento Version: ' . $this->productMetaData->getVersion() . ', Plugin Version: ' . $this->orderHelper->getPluginVersion(),
            self::DESCRIPTION => 'Description',
            self::RISK_ASSESSMENT => array_merge($this->orderHelper->getRiskAssessmentDataFromOrder($order), ['is_phone_verified' => $phoneVerified]),
            self::NUMBER_OF_INSTALLMENTS => $numberOfInstallments
        ];
    }
}
