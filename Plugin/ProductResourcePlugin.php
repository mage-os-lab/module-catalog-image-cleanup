<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\AbstractModel;
use MageOS\CatalogImageCleanup\Model\ImageCleaner;
use MageOS\CatalogImageCleanup\Model\References;

/**
 * The core already deletes the file of an image removed from the gallery. This completes it:
 * resized copies of every theme/size, product deletion (also from the grid mass action, imports
 * of single products through the repository...) and the gallery rows a deleted product leaves behind.
 */
class ProductResourcePlugin
{
    /** @var array<int, array{value_ids: int[], files: string[]}> */
    private array $snapshots = [];

    public function __construct(
        private readonly ImageCleaner $cleaner,
        private readonly References $references,
        private readonly MetadataPool $metadataPool
    ) {
    }

    public function beforeSave(ProductResource $subject, AbstractModel $product): void
    {
        $this->takeSnapshot($product);
    }

    public function afterSave(ProductResource $subject, $result, AbstractModel $product)
    {
        $snapshot = $this->popSnapshot($product);
        if ($snapshot && $snapshot['files']) {
            $this->cleaner->afterCommit(
                $subject,
                fn () => $this->cleaner->cleanProductImages($snapshot['files'])
            );
        }

        return $result;
    }

    public function beforeDelete(ProductResource $subject, $product): void
    {
        if ($product instanceof AbstractModel) {
            $this->takeSnapshot($product);
        }
    }

    public function afterDelete(ProductResource $subject, $result, $product)
    {
        $snapshot = $product instanceof AbstractModel ? $this->popSnapshot($product) : null;
        if ($snapshot && ($snapshot['files'] || $snapshot['value_ids'])) {
            $this->cleaner->afterCommit($subject, function () use ($snapshot): void {
                $this->references->deleteUnlinkedGalleryRows($snapshot['value_ids']);
                $this->cleaner->cleanProductImages($snapshot['files']);
            });
        }

        return $result;
    }

    private function takeSnapshot(AbstractModel $product): void
    {
        if (!$this->cleaner->isEnabled() || !$product->getId()) {
            return;
        }
        $linkId = (int) $product->getData($this->metadataPool->getMetadata(ProductInterface::class)->getLinkField());
        if ($linkId) {
            $this->snapshots[spl_object_id($product)] = $this->references->getProductImages($linkId);
        }
    }

    private function popSnapshot(AbstractModel $product): ?array
    {
        $key = spl_object_id($product);
        $snapshot = $this->snapshots[$key] ?? null;
        unset($this->snapshots[$key]);

        return $snapshot;
    }
}
