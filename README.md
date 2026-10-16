# MageOS_CatalogImageCleanup

Keeps `pub/media/catalog/product` and `pub/media/catalog/category` free of image files that no product or
category uses anymore, for Mage-OS / Magento 2.

- A **CLI command** finds and deletes the files left behind over the years (imports, deleted products,
  replaced category images, resized copies of deleted images).
- From then on, files are **deleted when the images are removed** in the admin (or via API/repository).

## Installation

```bash
composer require mage-os/module-catalog-image-cleanup
bin/magento module:enable MageOS_CatalogImageCleanup
bin/magento setup:upgrade
```

## What the core does, and what this module adds

| Action | Core | With this module |
|---|---|---|
| Remove an image from the product gallery | Deletes the original and the resized copies of the current frontend theme sizes | Also the resized copies of every theme/size (`catalog/product/cache/*`) |
| Delete a product | Deletes the files only if the gallery was loaded on the product object | Always: files, resized copies and gallery rows |
| Remove or replace a category image | File stays on disk | File deleted |
| Delete a category (and its children) | Files stay on disk | Files deleted |
| Delete products by import or SQL | Files and gallery rows stay | Cleaned by the CLI command (`--purge-orphan-records`) |

A file is deleted only when nothing references it anymore: other products (the same file can be shared),
other categories or store views, and — with *Keep Files Linked in Content* — CMS pages and blocks, product
and category descriptions, widgets and configuration values. Files are deleted after the database
transaction is committed, so a failed save never loses images. Errors are logged, never shown to the admin.

## CLI

```bash
# Report only (lists the first 50 files, -v for all)
bin/magento mageos:catalog-images:cleanup --dry-run

# Move the unused files to var/catalog-image-cleanup/<date> instead of deleting them
bin/magento mageos:catalog-images:cleanup --backup

# Delete, without confirmation
bin/magento mageos:catalog-images:cleanup --force
```

| Option | |
|---|---|
| `--dry-run` | Report, delete nothing |
| `--area=product\|cache\|category` | Limit to originals, resized product images or category images (repeatable; default all) |
| `--purge-orphan-records` | Also delete gallery rows no product is linked to (left by imports/SQL deletes); their files become unused |
| `--skip-content-check` | Do not keep files linked from CMS/descriptions/widgets/configuration |
| `--exclude=<glob>` / `-x` | Keep matching files, on the media path or the file name (repeatable, e.g. `-x '*.webp'`) |
| `--backup[=<dir>]` / `-b` | Move instead of delete (relative paths are relative to the Magento root) |
| `--force` / `-f` | No confirmation (also implied by `-n`) |

Never touched: `catalog/product/placeholder`, `catalog/product/watermark`, `tmp` directories, hidden files
(`.htaccess`), symlinks. Paths are compared case-insensitively, so a file is kept when the database
references it with a different case. Empty directories are removed and the *Content > Media Gallery* index
(`media_gallery_asset`) is updated.

Resized images in `catalog/product/cache` are deleted when their original is not used; resized images of
used originals are left alone (use `bin/magento catalog:images:resize` / *Flush Catalog Images Cache* for those).

## Configuration

*Stores → Configuration → Catalog → Catalog → Image File Cleanup* (default scope)

| Path | Default | |
|---|---|---|
| `catalog/image_cleanup/auto_delete` | 1 | Delete files when images, products or categories are removed |
| `catalog/image_cleanup/check_content` | 1 | Keep files linked in content (CLI and automatic deletion) |

The content tables searched are set in `etc/di.xml` (`MageOS\CatalogImageCleanup\Model\ContentReferences`,
argument `columns`, table => column) and can be extended by other modules.

## Notes

- Works on the local filesystem (`pub/media`); remote storage (S3) is not scanned by the CLI.
- Run `--dry-run` first and keep a backup of `pub/media` on production.
