<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

/**
 * Converts the values stored in the database into paths relative to the media directory.
 */
class MediaPath
{
    public const PRODUCT_DIR = 'catalog/product';
    public const CATEGORY_DIR = 'catalog/category';
    public const PRODUCT_CACHE_DIR = 'catalog/product/cache';

    /**
     * Product directories that never hold gallery images.
     */
    public const PRODUCT_SKIP_DIRS = [
        self::PRODUCT_CACHE_DIR,
        'catalog/product/placeholder',
        'catalog/product/watermark',
        'catalog/product/tmp',
    ];

    public const CATEGORY_SKIP_DIRS = [
        'catalog/category/tmp',
    ];

    private const REFERENCE_PATTERN = '#catalog/(?:product|category)/[^\s"\'<>(){}\[\]\\\\,;?\#|*]+#i';

    /**
     * Gallery value ("/a/b/image.jpg") to "catalog/product/a/b/image.jpg".
     */
    public static function fromProductValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'no_selection' || self::isUnsafe($value)) {
            return null;
        }

        return self::PRODUCT_DIR . '/' . ltrim($value, '/');
    }

    /**
     * Category image value to "catalog/category/image.jpg". The value is a file name
     * ("image.jpg") or, for newer uploads, a path ("/media/catalog/category/image.jpg").
     * Returns null for images outside the category directory: those are not ours to delete.
     */
    public static function fromCategoryValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || self::isUnsafe($value)) {
            return null;
        }
        $value = strtok($value, '?#') ?: '';

        $position = strpos($value, self::CATEGORY_DIR . '/');
        if ($position !== false) {
            $file = substr($value, $position + strlen(self::CATEGORY_DIR) + 1);
            return $file !== '' ? self::CATEGORY_DIR . '/' . $file : null;
        }
        if (!str_contains($value, '/')) {
            return self::CATEGORY_DIR . '/' . $value;
        }

        return null;
    }

    /**
     * Resized image ("catalog/product/cache/<hash>/a/b/image.jpg") to its original
     * ("catalog/product/a/b/image.jpg"). Null when the layout is not recognised.
     */
    public static function cacheSource(string $path): ?string
    {
        if (!str_starts_with($path, self::PRODUCT_CACHE_DIR . '/')) {
            return null;
        }
        if (preg_match('#^catalog/product/cache/.+/([^/]/[^/]/[^/]+)$#', $path, $match)) {
            return self::PRODUCT_DIR . '/' . $match[1];
        }
        if (preg_match('#^catalog/product/cache/[^/]+/([^/]+)$#', $path, $match)) {
            return self::PRODUCT_DIR . '/' . $match[1];
        }

        return null;
    }

    /**
     * Catalog image paths written in a text (CMS content, descriptions, configuration values...).
     *
     * @return string[]
     */
    public static function extractReferences(string $text): array
    {
        if (!preg_match_all(self::REFERENCE_PATTERN, $text, $matches)) {
            return [];
        }

        $paths = [];
        foreach ($matches[0] as $path) {
            $path = rtrim($path, '.');
            $paths[] = $path;
            $source = self::cacheSource($path);
            if ($source !== null) {
                $paths[] = $source;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Lookup key: the filesystem may be case insensitive, so compare lowercase paths to stay on the safe side.
     */
    public static function key(string $path): string
    {
        return strtolower(ltrim($path, '/'));
    }

    public static function isUnsafe(string $value): bool
    {
        return str_contains($value, '..') || str_contains($value, "\0") || str_contains($value, '\\');
    }
}
