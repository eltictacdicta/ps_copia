<?php
/**
 * Restore Service for restoring backups
 * Handles all backup restoration logic
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Migration\DatabaseMigrator;
use PrestaShop\Module\PsCopia\Migration\FilesMigrator;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class RestoreService
{
    /** @var BackupContainer */
    private $backupContainer;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var ValidationService */
    private $validationService;

    public function __construct(BackupContainer $backupContainer, BackupLogger $logger, ValidationService $validationService)
    {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->validationService = $validationService;
    }

    /**
     * Restore complete backup (database + files) with automatic migration
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreCompleteBackup(string $backupName): void
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        // Validate that both files exist
        $this->backupContainer->validateBackupFile($backupInfo['database_file']);
        $this->backupContainer->validateBackupFile($backupInfo['files_file']);

        $this->logger->info("Starting complete restoration with automatic migration: database + files");

        // Automatic migration configuration for complete restore
        $migrationConfig = [
            'migrate_urls' => true,
            'old_url' => '',
            'new_url' => '',
            'migrate_admin_dir' => false,
            'old_admin_dir' => '',
            'new_admin_dir' => '',
            'preserve_db_config' => false,
            'configurations' => []
        ];

        $this->logger->info("Applying automatic migration configuration for COMPLETE RESTORE", [
            'migrate_urls' => $migrationConfig['migrate_urls'],
            'migrate_admin_dir' => $migrationConfig['migrate_admin_dir'],
            'preserve_db_config' => $migrationConfig['preserve_db_config'],
            'note' => 'preserve_db_config=false means we restore ALL database from backup except URLs'
        ]);

        // Get full paths to backup files
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];

        // Apply database migration
        $this->logger->info("Restoring database with automatic migration from: " . $backupInfo['database_file']);
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
            $dbMigrator = new DatabaseMigrator($this->backupContainer, $this->logger);
            $dbMigrator->migrateDatabase($dbFilePath, $migrationConfig);
        } else {
            $this->logger->warning("DatabaseMigrator class not found, falling back to standard restore");
            $this->restoreDatabase($backupInfo['database_file']);
        }

        // Restore files
        $this->logger->info("Restoring files with preserved admin directory from: " . $backupInfo['files_file']);
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
            try {
                $filesMigrator = new FilesMigrator($this->backupContainer, $this->logger);
                $filesMigrator->migrateFiles($filesFilePath, $migrationConfig);
            } catch (\Exception $e) {
                $this->logger->error("Files migration failed, falling back to simple restoration: " . $e->getMessage());
                $this->restoreFiles($backupInfo['files_file']);
            }
        } else {
            $this->logger->warning("FilesMigrator class not found, falling back to standard restore");
            $this->restoreFiles($backupInfo['files_file']);
        }

        $this->logger->info("Complete backup restored successfully with automatic migration applied");
    }

    /**
     * Smart restore with full environment adaptation
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function smartRestoreBackup(string $backupName): void
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        // Validate that backup files exist
        $this->backupContainer->validateBackupFile($backupInfo['database_file']);
        $this->backupContainer->validateBackupFile($backupInfo['files_file']);

        // Configuration for smart restoration
        $migrationConfig = [
            'clean_destination' => true,
            'migrate_urls' => true,
            'preserve_db_config' => true,
            'disable_problematic_modules' => true,
            'auto_detect_environment' => true
        ];

        $this->logger->info("Smart restoration configuration", $migrationConfig);

        // Get full paths to backup files
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];

        // Apply enhanced database migration
        $this->logger->info("Applying enhanced database migration");
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
            $dbMigrator = new DatabaseMigrator($this->backupContainer, $this->logger);
            $dbMigrator->migrateWithFullAdaptation($dbFilePath, $migrationConfig);
        } else {
            throw new \Exception('Enhanced DatabaseMigrator not available');
        }

        // Restore files with smart handling
        $this->logger->info("Restoring files with smart handling");
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
            try {
                $filesMigrator = new FilesMigrator($this->backupContainer, $this->logger);
                $filesMigrator->migrateFiles($filesFilePath, $migrationConfig);
            } catch (\Exception $e) {
                $this->logger->error("Smart files migration failed, falling back to simple restoration: " . $e->getMessage());
                $this->restoreFiles($backupInfo['files_file']);
            }
        } else {
            $this->logger->warning("FilesMigrator not available, using standard file restoration");
            $this->restoreFiles($backupInfo['files_file']);
        }

        // Additional cleanup for problematic modules
        $this->cleanupProblematicModuleFiles();

        $this->logger->info("Smart backup restoration completed successfully");
    }

    /**
     * Restore database from backup
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreDatabase(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Database backup file does not exist: " . $backupName);
        }

        // Check if mysql command is available
        exec('which mysql 2>/dev/null', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new \Exception("MySQL command line client is not available");
        }

        $command = $this->buildMysqlRestoreCommand($backupFile);

        // Execute restoration
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception("Database restoration failed. Code: " . $returnVar . ". Output: " . implode("\n", $output));
        }

        $this->logger->info("Database restored successfully from " . $backupName);
    }

    /**
     * Restore files from backup
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreFiles(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Files backup does not exist: " . $backupName);
        }

        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP PHP extension is not installed');
        }

        $zip = new ZipArchive();
        $result = $zip->open($backupFile);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }

        // Extract to temporary directory first
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Detect admin directories for cleanup
            $backupAdminDir = $this->detectAdminDirectoryInBackup($tempDir);
            $currentAdminDir = $this->getCurrentAdminDirectory();

            // Copy files from temp to real location
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);

            // Clean up obsolete admin directories after successful restoration
            $this->cleanupObsoleteAdminDirectories($backupAdminDir, $currentAdminDir);
            
        } finally {
            // Clean up temp directory
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully from " . $backupName);
    }

    /**
     * Restore files from a specific file path
     *
     * @param string $filesBackupPath
     * @throws \Exception
     */
    public function restoreFilesFromPath(string $filesBackupPath): void
    {
        if (!file_exists($filesBackupPath)) {
            throw new \Exception("Files backup does not exist: " . $filesBackupPath);
        }

        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP PHP extension is not installed');
        }

        $this->logger->info("Restoring files from path: " . $filesBackupPath);

        $zip = new ZipArchive();
        $result = $zip->open($filesBackupPath);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }

        // Extract to temporary directory first
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Detect admin directories for cleanup
            $backupAdminDir = $this->detectAdminDirectoryInBackup($tempDir);
            $currentAdminDir = $this->getCurrentAdminDirectory();

            // Copy files from temp to real location
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);

            // Clean up obsolete admin directories after successful restoration
            $this->cleanupObsoleteAdminDirectories($backupAdminDir, $currentAdminDir);
            
        } finally {
            // Clean up temp directory
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully from " . basename($filesBackupPath));
    }

    /**
     * Get backup metadata
     *
     * @return array
     */
    public function getBackupMetadata(): array
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        
        if (!file_exists($metadataFile)) {
            return [];
        }
        
        $content = file_get_contents($metadataFile);
        return json_decode($content, true) ?: [];
    }

    /**
     * Get complete backups from metadata
     *
     * @return array
     */
    public function getCompleteBackups(): array
    {
        $metadata = $this->getBackupMetadata();
        $completeBackups = [];
        
        if (empty($metadata)) {
            return $this->getBackupsFromFiles();
        }
        
        // Check if it's array format or original format
        $isArrayFormat = array_keys($metadata) === range(0, count($metadata) - 1);
        
        if ($isArrayFormat) {
            // New format: array of metadata objects
            foreach ($metadata as $backupData) {
                if (!isset($backupData['backup_name'])) {
                    continue;
                }
                
                $backupName = $backupData['backup_name'];
                
                if (isset($backupData['database_file']) && isset($backupData['files_file'])) {
                    // Traditional complete backup
                    $dbFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['database_file'];
                    $filesFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['files_file'];
                    
                    if (file_exists($dbFile) && file_exists($filesFile)) {
                        $totalSize = filesize($dbFile) + filesize($filesFile);
                        
                        $completeBackups[] = [
                            'name' => $backupName,
                            'date' => $backupData['created_at'],
                            'size' => $totalSize,
                            'size_formatted' => ResponseHelper::formatBytes($totalSize),
                            'type' => 'complete',
                            'database_file' => $backupData['database_file'],
                            'files_file' => $backupData['files_file']
                        ];
                    }
                } elseif (isset($backupData['zip_file']) && $backupData['type'] === 'server_import') {
                    // Server imported backup
                    $zipFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['zip_file'];
                    
                    if (file_exists($zipFile)) {
                        $fileSize = filesize($zipFile);
                        
                        $completeBackups[] = [
                            'name' => $backupName,
                            'date' => $backupData['created_at'],
                            'size' => $fileSize,
                            'size_formatted' => ResponseHelper::formatBytes($fileSize),
                            'type' => 'server_import',
                            'zip_file' => $backupData['zip_file'],
                            'imported_from' => $backupData['imported_from'] ?? 'Unknown',
                            'import_method' => $backupData['import_method'] ?? 'direct_copy'
                        ];
                    }
                }
            }
        } else {
            // Original format: object with keys as backup names
            foreach ($metadata as $backupName => $backupInfo) {
                $dbFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'];
                $filesFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'];
                
                if (file_exists($dbFile) && file_exists($filesFile)) {
                    $totalSize = filesize($dbFile) + filesize($filesFile);
                    
                    $completeBackups[] = [
                        'name' => $backupName,
                        'date' => $backupInfo['created_at'],
                        'size' => $totalSize,
                        'size_formatted' => ResponseHelper::formatBytes($totalSize),
                        'type' => 'complete',
                        'database_file' => $backupInfo['database_file'],
                        'files_file' => $backupInfo['files_file']
                    ];
                }
            }
        }
        
        return $completeBackups;
    }

    /**
     * Delete backup
     *
     * @param string $backupName
     * @return bool
     */
    public function deleteBackup(string $backupName): bool
    {
        $metadata = $this->getBackupMetadata();
        $deleted = false;
        
        if (empty($metadata)) {
            // No metadata, try deleting as individual file
            $this->backupContainer->deleteBackup($backupName);
            $this->logger->info("Individual backup deleted: " . $backupName);
            return true;
        }
        
        // Check if it's array format or original format
        $isArrayFormat = array_keys($metadata) === range(0, count($metadata) - 1);
        
        if ($isArrayFormat) {
            // New format: search in array
            foreach ($metadata as $index => $backupData) {
                if (isset($backupData['backup_name']) && $backupData['backup_name'] === $backupName) {
                    if (isset($backupData['database_file']) && isset($backupData['files_file'])) {
                        // Traditional complete backup
                        if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['database_file'])) {
                            $this->backupContainer->deleteBackup($backupData['database_file']);
                        }
                        if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['files_file'])) {
                            $this->backupContainer->deleteBackup($backupData['files_file']);
                        }
                    } elseif (isset($backupData['zip_file'])) {
                        // Imported backup
                        if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['zip_file'])) {
                            $this->backupContainer->deleteBackup($backupData['zip_file']);
                        }
                    }
                    
                    // Remove from array
                    array_splice($metadata, $index, 1);
                    $deleted = true;
                    break;
                }
            }
            
            if ($deleted) {
                $this->saveUpdatedMetadata($metadata);
            }
        } else {
            // Original format: search by key
            if (isset($metadata[$backupName])) {
                $backupInfo = $metadata[$backupName];
                
                // Delete database file
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['database_file']);
                }
                
                // Delete files backup
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['files_file']);
                }
                
                // Remove from metadata
                unset($metadata[$backupName]);
                $this->saveCompleteBackupMetadata($metadata);
                $deleted = true;
            }
        }
        
        if ($deleted) {
            $this->logger->info("Complete backup deleted: " . $backupName);
            return true;
        } else {
            // Individual backup file
            $this->backupContainer->deleteBackup($backupName);
            $this->logger->info("Individual backup deleted: " . $backupName);
            return true;
        }
    }

    /**
     * Build MySQL restore command
     *
     * @param string $backupFile
     * @return string
     */
    private function buildMysqlRestoreCommand(string $backupFile): string
    {
        $credentials = $this->getCurrentDbCredentials();
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            return sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name'])
            );
        } else {
            return sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name']),
                escapeshellarg($backupFile)
            );
        }
    }

    /**
     * Get current database credentials
     *
     * @return array
     */
    private function getCurrentDbCredentials(): array
    {
        // Check if we're in DDEV environment
        if (getenv('DDEV_SITENAME') || $this->validationService->isDdevEnvironment()) {
            return [
                'host' => 'db',
                'user' => 'db', 
                'password' => 'db',
                'name' => 'db'
            ];
        }
        
        return [
            'host' => _DB_SERVER_,
            'user' => _DB_USER_,
            'password' => _DB_PASSWD_,
            'name' => _DB_NAME_
        ];
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

        $possibleAdminDirs = [];
        
        if (is_dir($tempDir)) {
            $items = scandir($tempDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $tempDir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    if ($this->validationService->isAdminDirectory($itemPath)) {
                        $possibleAdminDirs[] = $item;
                    }
                }
            }
        }

        if (empty($possibleAdminDirs)) {
            $this->logger->warning("No admin directory detected in backup");
            return null;
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
     * Copy directory recursively
     *
     * @param string $source
     * @param string $destination
     * @throws \Exception
     */
    private function copyDirectoryRecursively(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $excludePaths = $this->validationService->getExcludePaths();

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($this->validationService->shouldExcludeFile($destinationPath, $excludePaths)) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }
            } else {
                $destinationDir = dirname($destinationPath);
                if (!is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                
                if (!copy($item->getPathname(), $destinationPath)) {
                    throw new \Exception('Failed to copy file: ' . $relativePath);
                }
            }
        }
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
     * Clean up problematic module files
     */
    private function cleanupProblematicModuleFiles(): void
    {
        $this->logger->info("Cleaning up problematic module files");
        
        $problematicModules = [
            'ps_mbo',
            'ps_eventbus',
            'ps_metrics',
            'ps_facebook'
        ];
        
        $modulesDir = _PS_ROOT_DIR_ . '/modules/';
        
        foreach ($problematicModules as $module) {
            $modulePath = $modulesDir . $module;
            $disabledPath = $modulesDir . $module . '.disabled';
            
            if (is_dir($modulePath)) {
                $vendorPath = $modulePath . '/vendor';
                $composerPath = $modulePath . '/composer.json';
                
                if (file_exists($composerPath) && !is_dir($vendorPath)) {
                    rename($modulePath, $disabledPath);
                    $this->logger->info("Disabled problematic module: " . $module);
                }
            }
        }
    }

    /**
     * Clean up obsolete admin directories
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
            if ($adminDir === $backupAdminDir) {
                $this->logger->info("Preserving backup admin directory: " . $adminDir);
                continue;
            }

            $adminPath = $psRootDir . DIRECTORY_SEPARATOR . $adminDir;
            if (is_dir($adminPath)) {
                $this->logger->info("Removing obsolete admin directory: " . $adminDir);
                
                try {
                    if ($this->removeDirectoryRecursively($adminPath)) {
                        $this->logger->info("Successfully removed obsolete admin directory: " . $adminDir);
                    } else {
                        $this->logger->error("Failed to remove obsolete admin directory: " . $adminDir);
                    }
                } catch (\Exception $e) {
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
            if (is_dir($itemPath) && $this->validationService->isAdminDirectory($itemPath)) {
                $adminDirectories[] = $item;
            }
        }

        return $adminDirectories;
    }

    /**
     * Fallback method to get backups from files
     *
     * @return array
     */
    private function getBackupsFromFiles(): array
    {
        try {
            $backups = $this->backupContainer->getAvailableBackups();
            $completeBackups = [];
            
            foreach ($backups as $backup) {
                if ($backup['type'] === 'complete') {
                    $completeBackups[] = [
                        'name' => $backup['name'],
                        'date' => $backup['date'],
                        'size' => $backup['size'],
                        'size_formatted' => $backup['size_formatted'],
                        'type' => 'file_based'
                    ];
                }
            }
            
            return $completeBackups;
        } catch (\Exception $e) {
            $this->logger->warning("Could not get backups from files", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save updated metadata in array format
     *
     * @param array $metadata
     */
    private function saveUpdatedMetadata(array $metadata): void
    {
        try {
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $metadataFile = $backupDir . DIRECTORY_SEPARATOR . 'backup_metadata.json';
            
            $jsonData = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($jsonData !== false) {
                file_put_contents($metadataFile, $jsonData, LOCK_EX);
                $this->logger->debug("Updated metadata saved");
            }
        } catch (\Exception $e) {
            $this->logger->warning("Could not save updated metadata", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Save complete backup metadata
     *
     * @param array $metadata
     */
    private function saveCompleteBackupMetadata(array $metadata): void
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }
} 