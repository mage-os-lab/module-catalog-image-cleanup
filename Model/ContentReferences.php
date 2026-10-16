<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Catalog images linked from text content: CMS pages and blocks, descriptions, widgets, configuration.
 * Tables and columns are configured in di.xml; missing tables are skipped.
 */
class ContentReferences
{
    private ?array $existingColumns = null;

    /**
     * @param array<string, string> $columns table => column
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly array $columns = []
    ) {
    }

    /**
     * @return \Generator<string> paths relative to the media directory
     */
    public function getPaths(): \Generator
    {
        $connection = $this->resource->getConnection();
        foreach ($this->getColumns() as $table => $column) {
            $select = $connection->select()
                ->from($table, [$column])
                ->where($column . ' LIKE ?', '%catalog/product/%')
                ->orWhere($column . ' LIKE ?', '%catalog/category/%');
            $statement = $connection->query($select);
            while (($text = $statement->fetchColumn()) !== false) {
                yield from MediaPath::extractReferences((string) $text);
            }
        }
    }

    /**
     * Whether some content contains the given text (a file path or its distinctive tail).
     */
    public function contains(string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        $connection = $this->resource->getConnection();
        $like = '%' . addcslashes($needle, '\\%_') . '%';
        foreach ($this->getColumns() as $table => $column) {
            $found = $connection->fetchOne(
                $connection->select()
                    ->from($table, [new \Zend_Db_Expr('1')])
                    ->where($column . ' LIKE ?', $like)
                    ->limit(1)
            );
            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> real table name => column
     */
    private function getColumns(): array
    {
        if ($this->existingColumns === null) {
            $this->existingColumns = [];
            $connection = $this->resource->getConnection();
            foreach ($this->columns as $table => $column) {
                $tableName = $this->resource->getTableName($table);
                if ($column && $connection->isTableExists($tableName)
                    && $connection->tableColumnExists($tableName, $column)
                ) {
                    $this->existingColumns[$tableName] = $column;
                }
            }
        }

        return $this->existingColumns;
    }
}
