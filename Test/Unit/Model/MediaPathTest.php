<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Test\Unit\Model;

use MageOS\CatalogImageCleanup\Model\MediaPath;
use PHPUnit\Framework\TestCase;

class MediaPathTest extends TestCase
{
    public function testProductValue(): void
    {
        $this->assertSame('catalog/product/a/b/ab.jpg', MediaPath::fromProductValue('/a/b/ab.jpg'));
        $this->assertSame('catalog/product/a/b/ab.jpg', MediaPath::fromProductValue('a/b/ab.jpg'));
        $this->assertNull(MediaPath::fromProductValue('no_selection'));
        $this->assertNull(MediaPath::fromProductValue(''));
        $this->assertNull(MediaPath::fromProductValue('/../../env.php'));
    }

    public function testCategoryValue(): void
    {
        $this->assertSame('catalog/category/banner.jpg', MediaPath::fromCategoryValue('banner.jpg'));
        $this->assertSame('catalog/category/banner.jpg', MediaPath::fromCategoryValue('/media/catalog/category/banner.jpg'));
        $this->assertSame('catalog/category/banner.jpg', MediaPath::fromCategoryValue('/pub/media/catalog/category/banner.jpg?v=2'));
        $this->assertNull(MediaPath::fromCategoryValue('/media/wysiwyg/banner.jpg'));
        $this->assertNull(MediaPath::fromCategoryValue('../banner.jpg'));
    }

    public function testCacheSource(): void
    {
        $this->assertSame(
            'catalog/product/a/b/ab.jpg',
            MediaPath::cacheSource('catalog/product/cache/0f831c1845fc143d00d6d1ebc49f446a/a/b/ab.jpg')
        );
        $this->assertSame(
            'catalog/product/a/b/ab.jpg',
            MediaPath::cacheSource('catalog/product/cache/1/image/265x/9df78eab33525d08d6e5fb8d27136e95/a/b/ab.jpg')
        );
        $this->assertSame('catalog/product/ab.jpg', MediaPath::cacheSource('catalog/product/cache/hash/ab.jpg'));
        $this->assertNull(MediaPath::cacheSource('catalog/product/cache/hash/x/ab.jpg'));
        $this->assertNull(MediaPath::cacheSource('catalog/product/a/b/ab.jpg'));
    }

    public function testExtractReferences(): void
    {
        $html = '<img src="{{media url=catalog/category/banner.jpg}}">'
            . '<img src="https://example.com/media/catalog/product/cache/abc/a/b/ab.jpg?x=1">'
            . "{{media url='catalog/product/c/d/cd.png'}}";

        $this->assertSame(
            [
                'catalog/category/banner.jpg',
                'catalog/product/cache/abc/a/b/ab.jpg',
                'catalog/product/a/b/ab.jpg',
                'catalog/product/c/d/cd.png',
            ],
            MediaPath::extractReferences($html)
        );
    }
}
