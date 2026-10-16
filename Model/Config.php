<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_AUTO_DELETE = 'catalog/image_cleanup/auto_delete';
    public const XML_CHECK_CONTENT = 'catalog/image_cleanup/check_content';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isAutoDeleteEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_AUTO_DELETE);
    }

    public function isContentCheckEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_CHECK_CONTENT);
    }
}
