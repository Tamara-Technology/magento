<?php

namespace Tamara\Checkout\Model\Helper;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\ProductRepository;

class ProductHelper
{
    private $productRepository;
    private $imageHelper;

    public function __construct(
        ProductRepository $productRepository,
        ImageHelper $imageHelper
    ) {
        $this->productRepository = $productRepository;
        $this->imageHelper = $imageHelper;
    }

    public function getImageFromProductId($productId): string
    {
        try {
            if (!$productId) {
                return '';
            }
            $product = $this->productRepository->getById($productId);
            return (string) $this->imageHelper->init($product, 'small_image')
                ->setImageFile($product->getImage())->getUrl();
        } catch (\Exception $exception) {
            return '';
        }
    }

    public function getUrlFromProductId($productId, $storeId = null): string
    {
        try {
            if (!$productId) {
                return '';
            }
            $product = $this->productRepository->getById($productId, false, $storeId);

            return $this->getUrlFromProduct($product, $storeId);
        } catch (\Exception $exception) {
            return '';
        }
    }

    public function getUrlFromProduct($product, $storeId = null): string
    {
        if (!$product) {
            return '';
        }
        if ($storeId !== null) {
            $product->setStoreId($storeId);
        }
        $url = $product->getUrlModel()->getUrlInStore($product, ['_escape' => true]);

        return is_string($url) ? $url : '';
    }
}