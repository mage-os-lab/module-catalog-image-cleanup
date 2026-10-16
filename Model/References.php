<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as CategoryImageBackend;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Image files referenced by products and categories in the database.
 */
class References
{
    private const BATCH_SIZE = 10000;

    private ?array $productImageAttributeIds = null;
    private ?array $categoryImageAttributeIds = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Every product image path (gallery and image/small_image/thumbnail/swatch values).
     *
     * @param bool $linkedOnly skip gallery rows no product is linked to anymore
     * @return \Generator<string>
     */
    public function getProductImagePaths(bool $linkedOnly = false): \Generator
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(['g' => $this->table(Gallery::GALLERY_TABLE)], ['value']);
        if ($linkedOnly) {
            $select->join(
                ['e' => $this->table(Gallery::GALLERY_VALUE_TO_ENTITY_TABLE)],
                'e.value_id = g.value_id',
                []
            );
        }
        yield from $this->streamPaths($select, [MediaPath::class, 'fromProductValue']);

        $attributeIds = $this->getProductImageAttributeIds();
        if ($attributeIds) {
            $select = $connection->select()
                ->distinct()
                ->from($this->table('catalog_product_entity_varchar'), ['value'])
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('value IS NOT NULL');
            yield from $this->streamPaths($select, [MediaPath::class, 'fromProductValue']);
        }
    }

    /**
     * @return \Generator<string>
     */
    public function getCategoryImagePaths(): \Generator
    {
        $attributeIds = $this->getCategoryImageAttributeIds();
        if (!$attributeIds) {
            return;
        }
        $select = $this->getConnection()->select()
            ->distinct()
            ->from($this->table('catalog_category_entity_varchar'), ['value'])
            ->where('attribute_id IN (?)', $attributeIds)
            ->where('value IS NOT NULL');

        yield from $this->streamPaths($select, [MediaPath::class, 'fromCategoryValue']);
    }

    /**
     * Gallery rows and image values of one product, as stored ("/a/b/image.jpg").
     *
     * @return array{value_ids: int[], files: string[]}
     */
    public function getProductImages(int $linkId): array
    {
        $connection = $this->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(['g' => $this->table(Gallery::GALLERY_TABLE)], ['value_id', 'value'])
                ->join(
                    ['e' => $this->table(Gallery::GALLERY_VALUE_TO_ENTITY_TABLE)],
                    'e.value_id = g.value_id',
                    []
                )
                ->where('e.' . $linkField . ' = ?', $linkId)
        );
        $files = array_values($rows);

        $attributeIds = $this->getProductImageAttributeIds();
        if ($attributeIds) {
            $files = array_merge($files, $connection->fetchCol(
                $connection->select()
                    ->from($this->table('catalog_product_entity_varchar'), ['value'])
                    ->where('attribute_id IN (?)', $attributeIds)
                    ->where($linkField . ' = ?', $linkId)
                    ->where('value IS NOT NULL')
            ));
        }

        return [
            'value_ids' => array_map('intval', array_keys($rows)),
            'files' => array_values(array_unique(array_filter($files, static fn ($file) => MediaPath::fromProductValue($file) !== null))),
        ];
    }

    /**
     * Category image paths ("catalog/category/image.jpg") of the given categories, all store views.
     *
     * @param int[] $entityIds
     * @return string[]
     */
    public function getCategoryImages(array $entityIds): array
    {
        $attributeIds = $this->getCategoryImageAttributeIds();
        if (!$entityIds || !$attributeIds) {
            return [];
        }
        $connection = $this->getConnection();
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();

        $values = $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from(['v' => $this->table('catalog_category_entity_varchar')], ['value'])
                ->join(['c' => $this->table('catalog_category_entity')], 'c.' . $linkField . ' = v.' . $linkField, [])
                ->where('c.entity_id IN (?)', $entityIds)
                ->where('v.attribute_id IN (?)', $attributeIds)
                ->where('v.value IS NOT NULL')
        );

        return array_values(array_unique(array_filter(array_map([MediaPath::class, 'fromCategoryValue'], $values))));
    }

    /**
     * @return int[] the category and all its children
     */
    public function getCategoryTreeIds(int $categoryId, ?string $path): array
    {
        $ids = [$categoryId];
        if ($path) {
            $connection = $this->getConnection();
            $ids = array_merge($ids, array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($this->table('catalog_category_entity'), ['entity_id'])
                    ->where('path LIKE ?', $path . '/%')
            )));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param string $file gallery value ("/a/b/image.jpg")
     */
    public function isProductImageUsed(string $file): bool
    {
        $connection = $this->getConnection();
        $variants = array_values(array_unique(['/' . ltrim($file, '/'), ltrim($file, '/')]));

        if ($connection->fetchOne(
            $connection->select()
                ->from($this->table(Gallery::GALLERY_TABLE), ['value_id'])
                ->where('value IN (?)', $variants)
                ->limit(1)
        )) {
            return true;
        }

        $attributeIds = $this->getProductImageAttributeIds();

        return $attributeIds && (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->table('catalog_product_entity_varchar'), ['value_id'])
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('value IN (?)', $variants)
                ->limit(1)
        );
    }

    /**
     * @param string $path "catalog/category/image.jpg"
     */
    public function isCategoryImageUsed(string $path): bool
    {
        $attributeIds = $this->getCategoryImageAttributeIds();
        if (!$attributeIds) {
            return false;
        }
        $connection = $this->getConnection();
        $values = $connection->fetchCol(
            $connection->select()
                ->from($this->table('catalog_category_entity_varchar'), ['value'])
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('value LIKE ?', '%' . basename($path))
        );

        $key = MediaPath::key($path);
        foreach ($values as $value) {
            $valuePath = MediaPath::fromCategoryValue($value);
            if ($valuePath !== null && MediaPath::key($valuePath) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gallery rows left behind by products deleted without their images (imports, direct SQL...).
     *
     * @param int[]|null $valueIds limit the check to these rows
     */
    public function countUnlinkedGalleryRows(?array $valueIds = null): int
    {
        if ($valueIds !== null && !$valueIds) {
            return 0;
        }

        return count($this->getUnlinkedGalleryRowIds($valueIds));
    }

    /**
     * @param int[]|null $valueIds limit the cleanup to these rows
     * @return int deleted rows
     */
    public function deleteUnlinkedGalleryRows(?array $valueIds = null): int
    {
        if ($valueIds !== null && !$valueIds) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($this->getUnlinkedGalleryRowIds($valueIds), 1000) as $chunk) {
            $deleted += $this->getConnection()->delete($this->table(Gallery::GALLERY_TABLE), ['value_id IN (?)' => $chunk]);
        }

        return $deleted;
    }

    /**
     * Deletes the Media Gallery (Content > Media Gallery) index rows of deleted files, when that module is installed.
     *
     * @param string[] $paths paths relative to the media directory
     */
    public function deleteMediaGalleryAssets(array $paths): void
    {
        $connection = $this->getConnection();
        $table = $this->table('media_gallery_asset');
        if (!$paths || !$connection->isTableExists($table)) {
            return;
        }
        foreach (array_chunk($paths, 1000) as $chunk) {
            $connection->delete($table, ['path IN (?)' => $chunk]);
        }
    }

    public function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * @param int[]|null $valueIds
     * @return int[]
     */
    private function getUnlinkedGalleryRowIds(?array $valueIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['g' => $this->table(Gallery::GALLERY_TABLE)], ['value_id'])
            ->joinLeft(['e' => $this->table(Gallery::GALLERY_VALUE_TO_ENTITY_TABLE)], 'e.value_id = g.value_id', [])
            ->where('e.value_id IS NULL');
        if ($valueIds !== null) {
            $select->where('g.value_id IN (?)', $valueIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * @return \Generator<string>
     */
    private function streamPaths(\Magento\Framework\DB\Select $select, callable $normalize): \Generator
    {
        $statement = $this->getConnection()->query($select);
        while (($value = $statement->fetchColumn()) !== false) {
            $path = $normalize($value);
            if ($path !== null) {
                yield $path;
            }
        }
    }

    /**
     * @return int[]
     */
    private function getProductImageAttributeIds(): array
    {
        if ($this->productImageAttributeIds === null) {
            $this->productImageAttributeIds = $this->fetchAttributeIds(
                ProductInterface::class,
                'catalog_product',
                ['frontend_input = ?' => 'media_image']
            );
        }

        return $this->productImageAttributeIds;
    }

    /**
     * @return int[]
     */
    private function getCategoryImageAttributeIds(): array
    {
        if ($this->categoryImageAttributeIds === null) {
            $this->categoryImageAttributeIds = $this->fetchAttributeIds(
                CategoryInterface::class,
                'catalog_category',
                ['backend_model = ?' => CategoryImageBackend::class]
            );
        }

        return $this->categoryImageAttributeIds;
    }

    private function fetchAttributeIds(string $entityInterface, string $entityTypeCode, array $conditions): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], ['attribute_id'])
            ->join(['t' => $this->table('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
            ->where('t.entity_type_code = ?', $entityTypeCode)
            ->where('a.backend_type = ?', 'varchar');
        foreach ($conditions as $condition => $value) {
            $select->where('a.' . $condition, $value);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
