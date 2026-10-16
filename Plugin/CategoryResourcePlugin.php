<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Plugin;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Model\AbstractModel;
use MageOS\CatalogImageCleanup\Model\ImageCleaner;
use MageOS\CatalogImageCleanup\Model\References;

/**
 * The core never deletes category image files: not when the image is removed or replaced,
 * not when the category is deleted. Files no category uses anymore are deleted after the save.
 */
class CategoryResourcePlugin
{
    /** @var array<int, string[]> */
    private array $snapshots = [];

    public function __construct(
        private readonly ImageCleaner $cleaner,
        private readonly References $references
    ) {
    }

    public function beforeSave(CategoryResource $subject, AbstractModel $category): void
    {
        if ($this->cleaner->isEnabled() && $category->getId()) {
            $this->snapshots[spl_object_id($category)] = $this->references->getCategoryImages([(int) $category->getId()]);
        }
    }

    public function afterSave(CategoryResource $subject, $result, AbstractModel $category)
    {
        $this->clean($subject, $category);

        return $result;
    }

    public function beforeDelete(CategoryResource $subject, $category): void
    {
        if ($this->cleaner->isEnabled() && $category instanceof AbstractModel && $category->getId()) {
            $ids = $this->references->getCategoryTreeIds((int) $category->getId(), $category->getPath());
            $this->snapshots[spl_object_id($category)] = $this->references->getCategoryImages($ids);
        }
    }

    public function afterDelete(CategoryResource $subject, $result, $category)
    {
        if ($category instanceof AbstractModel) {
            $this->clean($subject, $category);
        }

        return $result;
    }

    private function clean(CategoryResource $subject, AbstractModel $category): void
    {
        $key = spl_object_id($category);
        $paths = $this->snapshots[$key] ?? [];
        unset($this->snapshots[$key]);
        if ($paths) {
            $this->cleaner->afterCommit($subject, fn () => $this->cleaner->cleanCategoryImages($paths));
        }
    }
}
