<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Deletes (or moves to a backup directory) image files inside the media directory.
 */
class FileRemover
{
    private ?WriteInterface $mediaDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @param string $path relative to the media directory
     * @param string|null $backupDir absolute directory: the file is moved there (keeping its media path) instead of deleted
     */
    public function remove(string $path, ?string $backupDir = null): bool
    {
        $media = $this->getMediaDirectory();
        if (MediaPath::isUnsafe($path) || !$this->isCatalogPath($path) || !$media->isFile($path)) {
            return false;
        }

        if ($backupDir === null) {
            return $media->delete($path);
        }

        $target = rtrim($backupDir, '/') . '/' . $path;
        $targetDir = dirname($target);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Cannot create the backup directory %s', $targetDir));
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return rename($media->getAbsolutePath($path), $target);
    }

    /**
     * Deletes an original product image and all its resized copies (every theme and size).
     *
     * @param string $path "catalog/product/a/b/image.jpg"
     * @return string[] deleted paths
     */
    public function removeProductImage(string $path): array
    {
        $deleted = [];
        if ($this->remove($path)) {
            $deleted[] = $path;
        }

        $media = $this->getMediaDirectory();
        $relative = substr($path, strlen(MediaPath::PRODUCT_DIR) + 1);
        $cacheRoot = $media->getAbsolutePath(MediaPath::PRODUCT_CACHE_DIR);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        foreach (glob($cacheRoot . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $hashDir) {
            $cachePath = MediaPath::PRODUCT_CACHE_DIR . '/' . basename($hashDir) . '/' . $relative;
            if ($this->remove($cachePath)) {
                $deleted[] = $cachePath;
                $this->pruneEmptyDirectories(dirname($cachePath), MediaPath::PRODUCT_CACHE_DIR);
            }
        }
        $this->pruneEmptyDirectories(dirname($path), MediaPath::PRODUCT_DIR);

        return $deleted;
    }

    /**
     * Removes empty directories from $directory up to (excluding) $stopAt.
     */
    public function pruneEmptyDirectories(string $directory, string $stopAt): void
    {
        $media = $this->getMediaDirectory();
        $stopAt = trim($stopAt, '/');
        $directory = trim($directory, '/');
        while ($directory !== $stopAt && str_starts_with($directory, $stopAt . '/')) {
            $absolute = $media->getAbsolutePath($directory);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (!is_dir($absolute) || (new \FilesystemIterator($absolute))->valid() || !@rmdir($absolute)) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    /**
     * Removes every empty directory below $root (the root itself is kept).
     */
    public function pruneEmptyTree(string $root): int
    {
        $absolute = $this->getMediaDirectory()->getAbsolutePath($root);
        if (!is_dir($absolute)) {
            return 0;
        }
        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink() && !(new \FilesystemIterator($item->getPathname()))->valid()) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $removed += @rmdir($item->getPathname()) ? 1 : 0;
            }
        }

        return $removed;
    }

    public function getMediaDirectory(): WriteInterface
    {
        return $this->mediaDirectory ??= $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    private function isCatalogPath(string $path): bool
    {
        return str_starts_with($path, MediaPath::PRODUCT_DIR . '/')
            || str_starts_with($path, MediaPath::CATEGORY_DIR . '/');
    }
}
