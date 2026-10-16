<?php

declare(strict_types=1);

namespace MageOS\CatalogImageCleanup\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use MageOS\CatalogImageCleanup\Model\FileRemover;
use MageOS\CatalogImageCleanup\Model\MediaPath;
use MageOS\CatalogImageCleanup\Model\References;
use MageOS\CatalogImageCleanup\Model\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CleanupCommand extends Command
{
    private const AREAS = [Scanner::AREA_PRODUCT, Scanner::AREA_CACHE, Scanner::AREA_CATEGORY];

    private const AREA_LABELS = [
        Scanner::AREA_PRODUCT => MediaPath::PRODUCT_DIR,
        Scanner::AREA_CACHE => MediaPath::PRODUCT_CACHE_DIR,
        Scanner::AREA_CATEGORY => MediaPath::CATEGORY_DIR,
    ];

    public function __construct(
        private readonly Scanner $scanner,
        private readonly References $references,
        private readonly FileRemover $fileRemover,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mageos:catalog-images:cleanup')
            ->setDescription('Delete product and category image files that are no longer used in the database')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report the unused files, delete nothing')
            ->addOption(
                'area',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'What to clean: product (originals), cache (resized product images), category. Default: all'
            )
            ->addOption(
                'purge-orphan-records',
                null,
                InputOption::VALUE_NONE,
                'Also delete product gallery rows no product is linked to anymore (left by imports or SQL deletes); their files become unused'
            )
            ->addOption(
                'skip-content-check',
                null,
                InputOption::VALUE_NONE,
                'Do not keep files linked from CMS pages/blocks, descriptions, widgets and configuration'
            )
            ->addOption(
                'exclude',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Glob pattern of files to keep, on the media path or the file name (e.g. "*.webp", "catalog/category/banner-*")'
            )
            ->addOption(
                'backup',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Move the files to this directory instead of deleting them (default: var/catalog-image-cleanup/<date>)',
                false
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $areas = $input->getOption('area') ?: self::AREAS;
        $unknown = array_diff($areas, self::AREAS);
        if ($unknown) {
            $output->writeln(sprintf(
                '<error>Unknown area "%s". Use: %s</error>',
                implode('", "', $unknown),
                implode(', ', self::AREAS)
            ));
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $purgeRecords = (bool) $input->getOption('purge-orphan-records');
        $exclude = $input->getOption('exclude') ?: [];

        $orphanRows = $purgeRecords ? $this->references->countUnlinkedGalleryRows() : 0;

        $output->writeln('Scanning the media directory...');
        $unused = $this->scanner->findUnusedFiles(
            $areas,
            $purgeRecords,
            !$input->getOption('skip-content-check'),
            $exclude
        );

        $this->printSummary($output, $unused, $orphanRows);
        $total = array_sum(array_map('count', $unused));
        if ($output->isVerbose() || $dryRun) {
            $this->printFiles($output, $unused, $dryRun && !$output->isVerbose() ? 50 : null);
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing has been deleted.</comment>');
            return Command::SUCCESS;
        }
        if ($total === 0 && $orphanRows === 0) {
            $output->writeln('<info>Nothing to clean.</info>');
            return Command::SUCCESS;
        }

        $backupDir = $this->getBackupDir($input->getOption('backup'));
        if (!$input->getOption('force') && $input->isInteractive()) {
            $question = new ConfirmationQuestion(
                sprintf(
                    '%s %d files%s? [y/N] ',
                    $backupDir ? 'Move to ' . $backupDir : 'Permanently delete',
                    $total,
                    $orphanRows ? sprintf(' and %d orphan gallery rows', $orphanRows) : ''
                ),
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($purgeRecords) {
            $output->writeln(sprintf('Deleted %d orphan gallery rows.', $this->references->deleteUnlinkedGalleryRows()));
        }

        $removed = [];
        $failed = 0;
        $bytes = 0;
        foreach ($unused as $files) {
            foreach ($files as $path => $size) {
                try {
                    if ($this->fileRemover->remove($path, $backupDir)) {
                        $removed[] = $path;
                        $bytes += $size;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<error>%s: %s</error>', $path, $e->getMessage()));
                }
                $failed++;
            }
        }
        $this->references->deleteMediaGalleryAssets($removed);

        $dirs = 0;
        foreach ($areas as $area) {
            $dirs += $this->fileRemover->pruneEmptyTree(self::AREA_LABELS[$area]);
        }

        $output->writeln(sprintf(
            '<info>%s %d files (%s), removed %d empty directories.</info>',
            $backupDir ? 'Moved' : 'Deleted',
            count($removed),
            $this->formatBytes($bytes),
            $dirs
        ));
        if ($backupDir) {
            $output->writeln('Backup: ' . $backupDir);
        }
        if ($failed) {
            $output->writeln(sprintf('<error>%d files could not be removed.</error>', $failed));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printSummary(OutputInterface $output, array $unused, int $orphanRows): void
    {
        $table = new Table($output);
        $table->setHeaders(['Directory', 'Unused files', 'Size']);
        $files = 0;
        $bytes = 0;
        foreach ($unused as $area => $areaFiles) {
            $size = array_sum($areaFiles);
            $table->addRow([self::AREA_LABELS[$area], count($areaFiles), $this->formatBytes($size)]);
            $files += count($areaFiles);
            $bytes += $size;
        }
        $table->addRow(new TableSeparator());
        $table->addRow(['Total', $files, $this->formatBytes($bytes)]);
        $table->render();

        if ($orphanRows) {
            $output->writeln(sprintf('Orphan product gallery rows: %d', $orphanRows));
        }
    }

    private function printFiles(OutputInterface $output, array $unused, ?int $limit): void
    {
        $shown = 0;
        $total = array_sum(array_map('count', $unused));
        foreach ($unused as $files) {
            foreach (array_keys($files) as $path) {
                if ($limit !== null && $shown >= $limit) {
                    $output->writeln(sprintf('... and %d more (use -v to list them all)', $total - $shown));
                    return;
                }
                $output->writeln('  ' . $path);
                $shown++;
            }
        }
    }

    /**
     * @param string|null|false $option false: option not given, null: given without value
     */
    private function getBackupDir(string|null|false $option): ?string
    {
        if ($option === false) {
            return null;
        }
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if ($option === null || $option === '') {
            return rtrim($var->getAbsolutePath('catalog-image-cleanup/' . date('Ymd-His')), '/');
        }

        return str_starts_with($option, '/') ? rtrim($option, '/') : rtrim(BP . '/' . $option, '/');
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
