<?php

namespace Tamara\Checkout\Gateway\Request;

use Magento\Catalog\Model\ProductRepository;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Tamara\Model\Money;
use Tamara\Model\Order\OrderItem;
use Tamara\Model\Order\OrderItemCollection;
use Magento\Catalog\Helper\Image as ImageHelper;

class ItemsDataBuilder implements BuilderInterface
{
    public const
        ITEMS = 'items';

    /**
     * @var ProductRepository
     */
    private $productRepository;

    /**
     * @var ImageHelper
     */
    private $imageHelper;

    /**
     * ItemsDataBuilder constructor.
     * @param $productRepository
     * @param $imageHelper
     */
    public function __construct(
        ProductRepository $productRepository,
        ImageHelper $imageHelper
    ){
        $this->productRepository = $productRepository;
        $this->imageHelper = $imageHelper;
    }


    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['order'])
            || !$buildSubject['order'] instanceof OrderInterface
        ) {
            throw new \InvalidArgumentException('Order data object should be provided');
        }

        /** @var OrderInterface $order */
        $order = $buildSubject['order'];
        $currencyCode = $buildSubject['order_currency_code'];

        $orderItemCollection = new OrderItemCollection();

        foreach ($order->getItems() as $item) {
            if ($item->getRowTotal() > 0) {
                $orderItem = new OrderItem();
                $orderItem->setName($item->getName());
                $orderItem->setQuantity($item->getQtyOrdered());
                $orderItem->setUnitPrice(new Money($item->getPrice(), $currencyCode));
                $orderItem->setType($item->getProductType());
                $orderItem->setSku($item->getSku());
                $orderItem->setTotalAmount(new Money($this->calculatePriceItem($item), $currencyCode));
                $orderItem->setTaxAmount(new Money($item->getTaxAmount(), $currencyCode));
                $discountAmountForItem = floatval($item->getDiscountAmount());
                if ($discountAmountForItem > 0.00) {
                    $orderItem->setDiscountAmount(new Money($discountAmountForItem, $currencyCode));
                }
                $orderItem->setReferenceId($item->getItemId());
                $orderItem->setImageUrl($this->getImageUrlFromProductId($item->getProductId()));
                $itemUrl = $item->getProduct()->setStoreId($item->getStoreId())->getUrlModel()->getUrlInStore($item->getProduct(), ['_escape' => true]);
                $orderItem->setItemUrl($itemUrl);
                $orderItemCollection->append($orderItem);
            }
        }

        return [self::ITEMS => $orderItemCollection];
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     * @return float|int|null
     */
    private function calculatePriceItem($item)
    {
        return $item->getPrice() * $item->getQtyOrdered() + $item->getTaxAmount() - $item->getDiscountAmount();
    }

    private function getImageUrlFromProductId($productId): string
    {
        $product = $this->productRepository->getById($productId);
        return $this->imageHelper->init($product, 'small_image')
            ->setImageFile($product->getImage())->getUrl();
    }
}
