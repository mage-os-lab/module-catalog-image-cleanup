# Changelog

## 1.0.0 - 2026-10-07

- `bin/magento mageos:catalog-images:cleanup`: finds and deletes (or moves to a backup) the files in
  `catalog/product`, `catalog/product/cache` and `catalog/category` no longer used in the database.
- Category images: the file is deleted when the image is removed or replaced, or the category is deleted.
- Products: resized copies of every theme and size are deleted together with the original; deleting a
  product also removes its gallery rows and files when the gallery was not loaded (grid, collections).
- Files linked from CMS pages/blocks, descriptions, widgets and configuration are kept.
