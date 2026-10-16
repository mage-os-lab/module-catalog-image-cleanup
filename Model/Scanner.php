<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

/**
 * Finds the files in catalog/product and catalog/category that no product, category or content uses.
 */
class Scanner
{
    public const AREA_PRODUCT = 'product';
    public const AREA_CACHE = 'cache';
    public const AREA_CATEGORY = 'category';

    public function __construct(
        private readonly References $references,
        private readonly ContentReferences $contentReferences,
        private readonly FileRemover $fileRemover
    ) {
    }

    /**
     * @param string[] $areas self::AREA_*
     * @param bool $linkedOnly ignore gallery rows no product is linked to (they are about to be purged)
     * @param bool $checkContent keep files linked from CMS/descriptions/configuration
     * @param string[] $exclude glob patterns (relative to the media directory) never deleted
     * @return array<string, array<string, int>> area => [path => size]
     */
    public function findUnusedFiles(array $areas, bool $linkedOnly, bool $checkContent, array $exclude = []): array
    {
        $used = [];
        $wantsProducts = in_array(self::AREA_PRODUCT, $areas, true) || in_array(self::AREA_CACHE, $areas, true);
        if ($wantsProducts) {
            foreach ($this->references->getProductImagePaths($linkedOnly) as $path) {
                $used[MediaPath::key($path)] = true;
            }
        }
        if (in_array(self::AREA_CATEGORY, $areas, true)) {
            foreach ($this->references->getCategoryImagePaths() as $path) {
                $used[MediaPath::key($path)] = true;
            }
        }
        if ($checkContent) {
            foreach ($this->contentReferences->getPaths() as $path) {
                $used[MediaPath::key($path)] = true;
            }
        }

        $result = array_fill_keys($areas, []);
        foreach ($areas as $area) {
            [$root, $skip] = match ($area) {
                self::AREA_PRODUCT => [MediaPath::PRODUCT_DIR, MediaPath::PRODUCT_SKIP_DIRS],
                self::AREA_CACHE => [MediaPath::PRODUCT_CACHE_DIR, []],
                self::AREA_CATEGORY => [MediaPath::CATEGORY_DIR, MediaPath::CATEGORY_SKIP_DIRS],
            };
            foreach ($this->walk($root, $skip) as $path => $size) {
                if (isset($used[MediaPath::key($path)]) || $this->isExcluded($path, $exclude)) {
                    continue;
                }
                if ($area === self::AREA_CACHE) {
                    $source = MediaPath::cacheSource($path);
                    if ($source === null || isset($used[MediaPath::key($source)])) {
                        continue;
                    }
                }
                $result[$area][$path] = $size;
            }
        }

        return $result;
    }

    /**
     * @param string[] $skipDirs
     * @return \Generator<string, int> path relative to the media directory => size
     */
    private function walk(string $root, array $skipDirs): \Generator
    {
        $media = $this->fileRemover->getMediaDirectory();
        $mediaRoot = rtrim($media->getAbsolutePath(), '/') . '/';
        $absoluteRoot = $media->getAbsolutePath($root);
        if (!is_dir($absoluteRoot)) {
            return;
        }

        $skip = array_flip($skipDirs);
        $filter = static function (\SplFileInfo $item) use ($skip, $mediaRoot): bool {
            if (str_starts_with($item->getFilename(), '.')) {
                return false;
            }
            return !$item->isDir() || !isset($skip[substr($item->getPathname(), strlen($mediaRoot))]);
        };
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
                $filter
            )
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                yield substr($item->getPathname(), strlen($mediaRoot)) => (int) $item->getSize();
            }
        }
    }

    private function isExcluded(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }
}
