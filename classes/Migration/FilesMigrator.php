<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\PsCopia\Migration;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use Exception;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Class responsible for migrating files between different PrestaShop installations
 */
class FilesMigrator
{
    /** @var BackupContainer */
    private $backupContainer;

    /** @var BackupLogger */
    private $logger;

    public function __construct(BackupContainer $backupContainer, BackupLogger $logger)
    {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
    }

    /**
     * Migrate files from external PrestaShop
     *
     * @param string $backupFile Path to files backup file
     * @param array $migrationConfig Migration configuration
     * @throws Exception
     */
    public function migrateFiles(string $backupFile, array $migrationConfig): void
    {
        $this->logger->info("Starting files migration", $migrationConfig);

        // Enhanced file validation with detailed logging
        $this->logger->info("Validating backup file", [
            'backup_file' => $backupFile,
            'file_exists' => file_exists($backupFile),
            'file_size' => file_exists($backupFile) ? filesize($backupFile) : 'N/A',
            'is_readable' => file_exists($backupFile) ? is_readable($backupFile) : false,
            'directory_exists' => file_exists($backupFile) ? is_dir(dirname($backupFile)) : false,
            'parent_directory' => dirname($backupFile),
            'filename' => basename($backupFile)
        ]);

        if (!file_exists($backupFile)) {
            // Provide detailed error information
            $parentDir = dirname($backupFile);
            $availableFiles = [];
            
            if (is_dir($parentDir)) {
                $availableFiles = array_diff(scandir($parentDir), ['.', '..']);
            }
            
            $errorInfo = [
                'missing_file' => $backupFile,
                'parent_directory' => $parentDir,
                'parent_directory_exists' => is_dir($parentDir),
                'available_files_in_directory' => $availableFiles,
                'working_directory' => getcwd()
            ];
            
            $this->logger->error("Files backup validation failed", $errorInfo);
            
            throw new Exception("Files backup does not exist: " . $backupFile . 
                "\nParent directory: " . $parentDir . 
                "\nParent directory exists: " . (is_dir($parentDir) ? 'Yes' : 'No') . 
                "\nAvailable files: " . implode(', ', $availableFiles));
        }

        if (!is_readable($backupFile)) {
            throw new Exception("Files backup is not readable: " . $backupFile);
        }

        if (!extension_loaded('zip')) {
            throw new Exception('ZIP PHP extension is not installed');
        }

        // Extract files to temporary directory
        $tempDir = $this->extractToTemporary($backupFile);

        try {
            // Detect admin directories before migration
            $backupAdminDir = $this->detectAdminDirectoryInBackup($tempDir);
            $currentAdminDir = $this->getCurrentAdminDirectory();
            
            // Apply migrations before copying to final location
            $this->applyFileMigrations($tempDir, $migrationConfig);

            // Copy files to final location
            $this->copyMigratedFiles($tempDir, _PS_ROOT_DIR_);

            // Clean up obsolete admin directories after successful migration
            $this->cleanupObsoleteAdminDirectories($backupAdminDir, $currentAdminDir);

            $this->logger->info("Files migration completed successfully");

        } finally {
            // Clean up temporary directory
            $this->removeDirectoryRecursively($tempDir);
        }
    }

    /**
     * Extract backup to temporary directory
     *
     * @param string $backupFile
     * @return string Temporary directory path
     * @throws Exception
     */
    private function extractToTemporary(string $backupFile): string
    {
        $zip = new ZipArchive();
        $result = $zip->open($backupFile);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot open ZIP file: ' . $this->getZipError($result));
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_files_migrate_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new Exception('Cannot create temporary directory: ' . $tempDir);
        }

        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new Exception('Failed to extract ZIP file');
        }
        
        $zip->close();
        $this->logger->info("Files extracted to temporary directory: " . $tempDir);

        return $tempDir;
    }

    /**
     * Apply file migrations
     *
     * @param string $tempDir
     * @param array $migrationConfig
     * @throws Exception
     */
    private function applyFileMigrations(string $tempDir, array $migrationConfig): void
    {
        // NOTA: Ya no migramos directorio admin - se mantiene la estructura original del backup
        // La carpeta admin conservará su nombre y configuración original
        $this->logger->info("Manteniendo directorio admin con estructura original del backup");

        // Update configuration files
        $this->updateConfigurationFiles($tempDir, $migrationConfig);

        // Handle custom file mappings
        if (!empty($migrationConfig['file_mappings'])) {
            $this->applyFileMappings($tempDir, $migrationConfig['file_mappings']);
        }
    }

    /**
     * Update configuration files
     *
     * @param string $baseDir
     * @param array $migrationConfig
     */
    private function updateConfigurationFiles(string $baseDir, array $migrationConfig): void
    {
        $this->logger->info("Updating configuration files");

        // Update settings.inc.php or parameters.php depending on PS version
        $this->updateMainConfigFile($baseDir, $migrationConfig);

        // Update .htaccess if needed
        $this->updateHtaccessFile($baseDir, $migrationConfig);

        // Update robots.txt if needed
        $this->updateRobotsFile($baseDir, $migrationConfig);
    }

    /**
     * Update main configuration file
     *
     * @param string $baseDir
     * @param array $migrationConfig
     */
    private function updateMainConfigFile(string $baseDir, array $migrationConfig): void
    {
        // Try PS 1.7+ parameters.php first
        $parametersFile = $baseDir . '/app/config/parameters.php';
        $settingsFile = $baseDir . '/config/settings.inc.php';

        if (file_exists($parametersFile)) {
            $this->updateParametersFile($parametersFile, $migrationConfig);
        } elseif (file_exists($settingsFile)) {
            $this->updateSettingsFile($settingsFile, $migrationConfig);
        } else {
            $this->logger->warning("No configuration file found to update");
        }
    }

    /**
     * Update parameters.php file (PS 1.7+)
     *
     * @param string $filePath
     * @param array $migrationConfig
     */
    private function updateParametersFile(string $filePath, array $migrationConfig): void
    {
        if (empty($migrationConfig['preserve_db_config']) || !$migrationConfig['preserve_db_config']) {
            return;
        }

        $this->logger->info("Updating parameters.php file");

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logger->error("Failed to read parameters.php file");
            return;
        }

        // Update database configuration
        $patterns = [
            "'database_host' => '([^']*)'," => "'database_host' => '" . _DB_SERVER_ . "',",
            "'database_name' => '([^']*)'," => "'database_name' => '" . _DB_NAME_ . "',",
            "'database_user' => '([^']*)'," => "'database_user' => '" . _DB_USER_ . "',",
            "'database_password' => '([^']*)'," => "'database_password' => '" . _DB_PASSWD_ . "',",
            "'database_prefix' => '([^']*)'," => "'database_prefix' => '" . _DB_PREFIX_ . "',",
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace('/' . $pattern . '/', $replacement, $content);
        }

        if (file_put_contents($filePath, $content) === false) {
            $this->logger->error("Failed to update parameters.php file");
        } else {
            $this->logger->info("parameters.php file updated successfully");
        }
    }

    /**
     * Update settings.inc.php file (PS 1.6)
     *
     * @param string $filePath
     * @param array $migrationConfig
     */
    private function updateSettingsFile(string $filePath, array $migrationConfig): void
    {
        if (empty($migrationConfig['preserve_db_config']) || !$migrationConfig['preserve_db_config']) {
            return;
        }

        $this->logger->info("Updating settings.inc.php file");

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logger->error("Failed to read settings.inc.php file");
            return;
        }

        // Update database configuration
        $patterns = [
            "define\('_DB_SERVER_', '([^']*)'\);" => "define('_DB_SERVER_', '" . _DB_SERVER_ . "');",
            "define\('_DB_NAME_', '([^']*)'\);" => "define('_DB_NAME_', '" . _DB_NAME_ . "');",
            "define\('_DB_USER_', '([^']*)'\);" => "define('_DB_USER_', '" . _DB_USER_ . "');",
            "define\('_DB_PASSWD_', '([^']*)'\);" => "define('_DB_PASSWD_', '" . _DB_PASSWD_ . "');",
            "define\('_DB_PREFIX_', '([^']*)'\);" => "define('_DB_PREFIX_', '" . _DB_PREFIX_ . "');",
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace('/' . $pattern . '/', $replacement, $content);
        }

        if (file_put_contents($filePath, $content) === false) {
            $this->logger->error("Failed to update settings.inc.php file");
        } else {
            $this->logger->info("settings.inc.php file updated successfully");
        }
    }

    /**
     * Update .htaccess file
     *
     * @param string $baseDir
     * @param array $migrationConfig
     */
    private function updateHtaccessFile(string $baseDir, array $migrationConfig): void
    {
        $htaccessFile = $baseDir . '/.htaccess';
        
        if (!file_exists($htaccessFile)) {
            return;
        }

        $this->logger->info("Updating .htaccess file");

        $content = file_get_contents($htaccessFile);
        if ($content === false) {
            $this->logger->error("Failed to read .htaccess file");
            return;
        }

        // NOTA: Ya no actualizamos referencias del directorio admin - se mantiene la configuración original del backup
        $this->logger->info("Preservando referencias del directorio admin en .htaccess del backup original");

        // Update URL references only (not admin directory references)
        if (!empty($migrationConfig['old_url']) && !empty($migrationConfig['new_url'])) {
            $oldUrl = rtrim($migrationConfig['old_url'], '/');
            $newUrl = rtrim($migrationConfig['new_url'], '/');
            
            $content = str_replace($oldUrl, $newUrl, $content);
        }

        if (file_put_contents($htaccessFile, $content) === false) {
            $this->logger->error("Failed to update .htaccess file");
        } else {
            $this->logger->info(".htaccess file updated successfully (admin references preserved)");
        }
    }

    /**
     * Update robots.txt file
     *
     * @param string $baseDir
     * @param array $migrationConfig
     */
    private function updateRobotsFile(string $baseDir, array $migrationConfig): void
    {
        $robotsFile = $baseDir . '/robots.txt';
        
        if (!file_exists($robotsFile)) {
            return;
        }

        $this->logger->info("Updating robots.txt file");

        $content = file_get_contents($robotsFile);
        if ($content === false) {
            $this->logger->error("Failed to read robots.txt file");
            return;
        }

        // NOTA: Ya no actualizamos referencias del directorio admin - se mantiene la configuración original del backup
        $this->logger->info("Preservando referencias del directorio admin en robots.txt del backup original");

        // Update URL references only (not admin directory references)
        if (!empty($migrationConfig['old_url']) && !empty($migrationConfig['new_url'])) {
            $oldUrl = rtrim($migrationConfig['old_url'], '/');
            $newUrl = rtrim($migrationConfig['new_url'], '/');
            
            $content = str_replace($oldUrl, $newUrl, $content);
        }

        if (file_put_contents($robotsFile, $content) === false) {
            $this->logger->error("Failed to update robots.txt file");
        } else {
            $this->logger->info("robots.txt file updated successfully (admin references preserved)");
        }
    }

    /**
     * Apply custom file mappings
     *
     * @param string $baseDir
     * @param array $mappings
     */
    private function applyFileMappings(string $baseDir, array $mappings): void
    {
        $this->logger->info("Applying custom file mappings");

        foreach ($mappings as $source => $destination) {
            $sourcePath = $baseDir . DIRECTORY_SEPARATOR . ltrim($source, '/');
            $destinationPath = $baseDir . DIRECTORY_SEPARATOR . ltrim($destination, '/');

            if (!file_exists($sourcePath)) {
                $this->logger->warning("Source file not found for mapping: {$source}");
                continue;
            }

            // Create destination directory if needed
            $destinationDir = dirname($destinationPath);
            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }

            // Move/rename the file
            if (rename($sourcePath, $destinationPath)) {
                $this->logger->info("File mapped from {$source} to {$destination}");
            } else {
                $this->logger->error("Failed to map file from {$source} to {$destination}");
            }
        }
    }

    /**
     * Copy migrated files to final location
     *
     * @param string $sourceDir
     * @param string $destinationDir
     * @throws Exception
     */
    private function copyMigratedFiles(string $sourceDir, string $destinationDir): void
    {
        $this->logger->info("Copying migrated files to final location");

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $excludePaths = $this->getExcludePaths();

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            $destinationPath = $destinationDir . DIRECTORY_SEPARATOR . $relativePath;

            // Skip files/directories that should be excluded
            if ($this->shouldExcludeFile($destinationPath, $excludePaths)) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }
            } else {
                $destinationDirPath = dirname($destinationPath);
                if (!is_dir($destinationDirPath)) {
                    mkdir($destinationDirPath, 0755, true);
                }
                
                if (!copy($item->getPathname(), $destinationPath)) {
                    throw new Exception('Failed to copy file: ' . $relativePath);
                }
            }
        }

        $this->logger->info("Files copied successfully to final location");
    }

    /**
     * Get paths to exclude during migration
     *
     * @return array
     */
    private function getExcludePaths(): array
    {
        return [
            '/cache/',
            '/log/',
            '/tmp/',
            '/var/cache/',
            '/var/logs/',
            '/app/cache/',
            '/app/logs/',
            '/vendor/',
            '/node_modules/',
            '.git',
            '.svn',
            'Thumbs.db',
            '.DS_Store',
            // Exclude current backup module directory to avoid conflicts
            '/admin*/ps_copia/',
        ];
    }

    /**
     * Check if a file should be excluded
     *
     * @param string $filePath
     * @param array $excludePaths
     * @return bool
     */
    private function shouldExcludeFile(string $filePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            if (strpos($filePath, $excludePath) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove directory recursively
     *
     * @param string $directory
     * @return bool
     */
    private function removeDirectoryRecursively(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($directory);
    }

    /**
     * Detect admin directory in backup
     *
     * @param string $tempDir
     * @return string|null
     */
    private function detectAdminDirectoryInBackup(string $tempDir): ?string
    {
        $this->logger->info("Detecting admin directory in backup");

        // Look for directories that look like admin directories
        $possibleAdminDirs = [];
        
        if (is_dir($tempDir)) {
            $items = scandir($tempDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $tempDir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    // Check if this looks like an admin directory
                    if ($this->isAdminDirectory($itemPath)) {
                        $possibleAdminDirs[] = $item;
                    }
                }
            }
        }

        if (empty($possibleAdminDirs)) {
            $this->logger->warning("No admin directory detected in backup");
            return null;
        }

        if (count($possibleAdminDirs) > 1) {
            $this->logger->warning("Multiple admin directories found in backup", [
                'directories' => $possibleAdminDirs
            ]);
        }

        $backupAdminDir = $possibleAdminDirs[0];
        $this->logger->info("Detected admin directory in backup: " . $backupAdminDir);
        
        return $backupAdminDir;
    }

    /**
     * Get current admin directory name
     *
     * @return string|null
     */
    private function getCurrentAdminDirectory(): ?string
    {
        $currentAdminPath = $this->backupContainer->getProperty(BackupContainer::PS_ADMIN_PATH);
        $currentAdminDir = basename($currentAdminPath);
        
        $this->logger->info("Current admin directory: " . $currentAdminDir);
        
        return $currentAdminDir;
    }

    /**
     * Check if a directory is an admin directory
     *
     * @param string $dirPath
     * @return bool
     */
    private function isAdminDirectory(string $dirPath): bool
    {
        // Check for admin-specific files/directories
        $adminIndicators = [
            'index.php',
            'themes',
            'tabs',
            'filemanager',
            'functions.php',
            'init.php'
        ];

        $foundIndicators = 0;
        foreach ($adminIndicators as $indicator) {
            if (file_exists($dirPath . DIRECTORY_SEPARATOR . $indicator)) {
                $foundIndicators++;
            }
        }

        // If we find at least 3 admin indicators, it's likely an admin directory
        return $foundIndicators >= 3;
    }

    /**
     * Clean up obsolete admin directories after migration
     *
     * @param string|null $backupAdminDir
     * @param string|null $currentAdminDir
     */
    private function cleanupObsoleteAdminDirectories(?string $backupAdminDir, ?string $currentAdminDir): void
    {
        if (!$backupAdminDir || !$currentAdminDir) {
            $this->logger->info("Skipping admin directory cleanup - unable to detect directories");
            return;
        }

        if ($backupAdminDir === $currentAdminDir) {
            $this->logger->info("Admin directories are the same, no cleanup needed", [
                'admin_directory' => $currentAdminDir
            ]);
            return;
        }

        $this->logger->info("Different admin directories detected", [
            'backup_admin' => $backupAdminDir,
            'current_admin' => $currentAdminDir
        ]);

        // Find all admin-like directories in the root
        $psRootDir = $this->backupContainer->getProperty(BackupContainer::PS_ROOT_PATH);
        $adminDirectories = $this->findAllAdminDirectories($psRootDir);

        $this->logger->info("Found admin directories in system", [
            'directories' => $adminDirectories
        ]);

        // Remove obsolete admin directories
        foreach ($adminDirectories as $adminDir) {
            // Don't remove the backup's admin directory (the one we want to keep)
            if ($adminDir === $backupAdminDir) {
                $this->logger->info("Preserving backup admin directory: " . $adminDir);
                continue;
            }

            // Remove other admin directories
            $adminPath = $psRootDir . DIRECTORY_SEPARATOR . $adminDir;
            if (is_dir($adminPath)) {
                $this->logger->info("Removing obsolete admin directory: " . $adminDir);
                
                try {
                    if ($this->removeDirectoryRecursively($adminPath)) {
                        $this->logger->info("Successfully removed obsolete admin directory: " . $adminDir);
                    } else {
                        $this->logger->error("Failed to remove obsolete admin directory: " . $adminDir);
                    }
                } catch (Exception $e) {
                    $this->logger->error("Error removing obsolete admin directory: " . $adminDir, [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Find all admin directories in the system
     *
     * @param string $psRootDir
     * @return array
     */
    private function findAllAdminDirectories(string $psRootDir): array
    {
        $adminDirectories = [];
        
        if (!is_dir($psRootDir)) {
            return $adminDirectories;
        }

        $items = scandir($psRootDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $psRootDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && $this->isAdminDirectory($itemPath)) {
                $adminDirectories[] = $item;
            }
        }

        return $adminDirectories;
    }

    /**
     * Get ZIP error message
     *
     * @param int $code
     * @return string
     */
    private function getZipError(int $code): string
    {
        switch ($code) {
            case ZipArchive::ER_OK:
                return 'No error';
            case ZipArchive::ER_MULTIDISK:
                return 'Multi-disk zip archives not supported';
            case ZipArchive::ER_RENAME:
                return 'Renaming temporary file failed';
            case ZipArchive::ER_CLOSE:
                return 'Closing zip archive failed';
            case ZipArchive::ER_SEEK:
                return 'Seek error';
            case ZipArchive::ER_READ:
                return 'Read error';
            case ZipArchive::ER_WRITE:
                return 'Write error';
            case ZipArchive::ER_CRC:
                return 'CRC error';
            case ZipArchive::ER_ZIPCLOSED:
                return 'Containing zip archive was closed';
            case ZipArchive::ER_NOENT:
                return 'No such file';
            case ZipArchive::ER_EXISTS:
                return 'File already exists';
            case ZipArchive::ER_OPEN:
                return 'Can\'t open file';
            case ZipArchive::ER_TMPOPEN:
                return 'Failure to create temporary file';
            case ZipArchive::ER_ZLIB:
                return 'Zlib error';
            case ZipArchive::ER_MEMORY:
                return 'Memory allocation failure';
            case ZipArchive::ER_CHANGED:
                return 'Entry has been changed';
            case ZipArchive::ER_COMPNOTSUPP:
                return 'Compression method not supported';
            case ZipArchive::ER_EOF:
                return 'Premature EOF';
            case ZipArchive::ER_INVAL:
                return 'Invalid argument';
            case ZipArchive::ER_NOZIP:
                return 'Not a zip archive';
            case ZipArchive::ER_INTERNAL:
                return 'Internal error';
            case ZipArchive::ER_INCONS:
                return 'Zip archive inconsistent';
            case ZipArchive::ER_REMOVE:
                return 'Can\'t remove file';
            case ZipArchive::ER_DELETED:
                return 'Entry has been deleted';
            default:
                return 'Unknown error code: ' . $code;
        }
    }
} 