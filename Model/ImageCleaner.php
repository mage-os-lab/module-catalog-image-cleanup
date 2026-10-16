<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

use Magento\Framework\Model\ResourceModel\AbstractResource;
use Psr\Log\LoggerInterface;

/**
 * Deletes the files of images removed from products and categories, once nothing uses them anymore.
 */
class ImageCleaner
{
    public function __construct(
        private readonly Config $config,
        private readonly References $references,
        private readonly ContentReferences $contentReferences,
        private readonly FileRemover $fileRemover,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isAutoDeleteEnabled();
    }

    /**
     * Runs $callback once the current database transaction is committed (right away when there is none),
     * so a rolled back save never loses its files. Errors are logged, never thrown at the admin.
     */
    public function afterCommit(AbstractResource $resource, callable $callback): void
    {
        $safeCallback = function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->error('Catalog image cleanup failed: ' . $e->getMessage(), ['exception' => $e]);
            }
        };

        if ($this->references->getConnection()->getTransactionLevel() > 0) {
            $resource->addCommitCallback($safeCallback);
        } else {
            $safeCallback();
        }
    }

    /**
     * @param string[] $files gallery values ("/a/b/image.jpg")
     * @return string[] deleted paths
     */
    public function cleanProductImages(array $files): array
    {
        $deleted = [];
        foreach (array_unique($files) as $file) {
            $path = MediaPath::fromProductValue($file);
            if ($path === null
                || $this->references->isProductImageUsed($file)
                || $this->isInContent('/' . ltrim($file, '/'))
            ) {
                continue;
            }
            $deleted = array_merge($deleted, $this->fileRemover->removeProductImage($path));
        }

        return $this->finish($deleted);
    }

    /**
     * @param string[] $paths "catalog/category/image.jpg"
     * @return string[] deleted paths
     */
    public function cleanCategoryImages(array $paths): array
    {
        $deleted = [];
        foreach (array_unique($paths) as $path) {
            if ($this->references->isCategoryImageUsed($path) || $this->isInContent($path)) {
                continue;
            }
            if ($this->fileRemover->remove($path)) {
                $deleted[] = $path;
                $this->fileRemover->pruneEmptyDirectories(dirname($path), MediaPath::CATEGORY_DIR);
            }
        }

        return $this->finish($deleted);
    }

    private function isInContent(string $needle): bool
    {
        return $this->config->isContentCheckEnabled() && $this->contentReferences->contains($needle);
    }

    private function finish(array $deleted): array
    {
        if ($deleted) {
            $this->references->deleteMediaGalleryAssets($deleted);
            $this->logger->info('Catalog image cleanup: deleted ' . implode(', ', $deleted));
        }

        return $deleted;
    }
}
