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

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\VersionUtils;

class AdminPsCopiaAjaxController extends ModuleAdminController
{
    /** @var Ps_copia */
    public $module;

    /** @var bool */
    private $isActualPHPVersionCompatible = true;

    /** @var BackupContainer|null */
    private $backupContainer;

    /** @var BackupLogger|null */
    private $logger;

    public function __construct()
    {
        parent::__construct();

        // Load required classes
        $this->loadRequiredClasses();

        // Check PHP version compatibility
        if (!VersionUtils::isActualPHPVersionCompatible()) {
            $this->isActualPHPVersionCompatible = false;
            return;
        }

        // Initialize backup container and logger
        if ($this->module && method_exists($this->module, 'getBackupContainer')) {
            $this->backupContainer = $this->module->getBackupContainer();
            $this->logger = new BackupLogger($this->backupContainer, true);
        } else {
            $this->isActualPHPVersionCompatible = false;
        }
    }

    /**
     * Load required classes manually to ensure they are available
     *
     * @return void
     */
    private function loadRequiredClasses(): void
    {
        // Try autoloader first
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        // Manually load critical classes if not available
        $classesToLoad = [
            'PrestaShop\Module\PsCopia\VersionUtils' => '/../../classes/VersionUtils.php',
            'PrestaShop\Module\PsCopia\BackupContainer' => '/../../classes/BackupContainer.php',
            'PrestaShop\Module\PsCopia\Logger\BackupLogger' => '/../../classes/Logger/BackupLogger.php',
        ];

        foreach ($classesToLoad as $className => $filePath) {
            if (!class_exists($className)) {
                $fullPath = __DIR__ . $filePath;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }
        }
    }

    /**
     * Check if user has permission for backup operations
     */
    public function viewAccess($disable = false): bool
    {
        // Temporarily allow any authenticated admin user
        // IMPORTANT: Change this back to restrict to super admin only: $this->context->employee->id_profile == 1
        return isset($this->context->employee) && isset($this->context->employee->id) && $this->context->employee->id > 0;
    }

    public function postProcess(): bool
    {
        // Set JSON response headers
        header('Content-Type: application/json');

        if (!$this->isActualPHPVersionCompatible) {
            $this->ajaxError('Module not compatible with current PHP version');
            return false;
        }

        if (!$this->viewAccess()) {
            $this->ajaxError('Access denied. Only super administrators can perform backup operations.');
            return false;
        }

        $action = Tools::getValue('action');

        try {
            switch ($action) {
                case 'create_backup':
                    $this->handleCreateBackup();
                    break;
                case 'restore_backup':
                    $this->handleRestoreBackup();
                    break;
                case 'list_backups':
                    $this->handleListBackups();
                    break;
                case 'delete_backup':
                    $this->handleDeleteBackup();
                    break;
                case 'validate_backup':
                    $this->handleValidateBackup();
                    break;
                case 'restore_database_only':
                    $this->handleRestoreDatabaseOnly();
                    break;
                case 'restore_files_only':
                    $this->handleRestoreFilesOnly();
                    break;
                case 'get_disk_space':
                    $this->handleGetDiskSpace();
                    break;
                case 'get_logs':
                    $this->handleGetLogs();
                    break;
                default:
                    $this->ajaxError('Unknown action: ' . $action);
            }
        } catch (Exception $e) {
            $this->logger->error("Ajax Controller Exception: " . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            $this->ajaxError($e->getMessage());
        }

        return true;
    }

    /**
     * Handle backup creation
     */
    private function handleCreateBackup(): void
    {
        $backupType = Tools::getValue('backup_type', 'complete'); // Default to complete backup
        $customName = Tools::getValue('custom_name', '');

        $this->logger->info("Starting backup creation", [
            'type' => $backupType,
            'custom_name' => $customName
        ]);

        try {
            $this->validateBackupRequirements();

            $results = [];
            $timestamp = date('Y-m-d_H-i-s');
            $baseName = $customName ?: 'backup_' . $timestamp;

            if ($backupType === 'complete') {
                // Create complete backup (database + files)
                $results['database'] = $this->createDatabaseBackup($baseName . '_db');
                $results['files'] = $this->createFilesBackup($baseName . '_files');
                
                // Create a unified backup entry
                $results['complete'] = [
                    'backup_name' => $baseName,
                    'database_file' => $results['database']['backup_name'],
                    'files_file' => $results['files']['backup_name'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'type' => 'complete'
                ];
                
                // Save backup metadata
                $this->saveBackupMetadata($results['complete']);
                
            } else {
                // Legacy support for individual backups
                if ($backupType === 'database') {
                    $results['database'] = $this->createDatabaseBackup($customName);
                }
                if ($backupType === 'files') {
                    $results['files'] = $this->createFilesBackup($customName);
                }
            }

            // Clean old backups
            $deleted = $this->backupContainer->cleanOldBackups(5);
            if ($deleted > 0) {
                $this->logger->info("Cleaned $deleted old backup files");
            }

            $this->logger->info("Backup creation completed successfully");
            $this->ajaxSuccess('Backup completo creado correctamente', $results);

        } catch (Exception $e) {
            $this->logger->error("Backup creation failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Validate backup requirements
     *
     * @throws Exception
     */
    private function validateBackupRequirements(): void
    {
        // Check if backup directory is writable
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        if (!is_writable($backupDir)) {
            throw new Exception("Backup directory is not writable: " . $backupDir);
        }

        // Check disk space (require at least 100MB free)
        $diskInfo = $this->backupContainer->getDiskSpaceInfo();
        if ($diskInfo['free_space'] < 100 * 1024 * 1024) {
            throw new Exception("Insufficient disk space. At least 100MB free space required.");
        }

        // Check required PHP extensions
        if (!extension_loaded('zip')) {
            throw new Exception("ZIP extension is required for file backups");
        }
    }

    /**
     * Create database backup
     *
     * @param string|null $customName
     * @return array<string, mixed>
     * @throws Exception
     */
    private function createDatabaseBackup(?string $customName): array
    {
        $this->logger->info("Creating database backup");

        $backupFile = $this->backupContainer->getBackupFilename(true, $customName);
        
        // Build mysqldump command
        $command = $this->buildMysqldumpCommand($backupFile);

        // Execute the command
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Database backup failed. Code: " . $returnVar . ". Output: " . implode("\n", $output));
        }

        // Verify backup file was created
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new Exception("Database backup file was not created or is empty");
        }

        $this->logger->info("Database backup created successfully", [
            'file' => basename($backupFile),
            'size' => filesize($backupFile)
        ]);

        return [
            'backup_name' => basename($backupFile),
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'size_formatted' => $this->formatBytes(filesize($backupFile))
        ];
    }

    /**
     * Build mysqldump command
     *
     * @param string $backupFile
     * @return string
     */
    private function buildMysqldumpCommand(string $backupFile): string
    {
        return sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s | gzip > %s',
            escapeshellarg(_DB_SERVER_),
            escapeshellarg(_DB_USER_),
            escapeshellarg(_DB_PASSWD_),
            escapeshellarg(_DB_NAME_),
            escapeshellarg($backupFile)
        );
    }

    /**
     * Create files backup
     *
     * @param string|null $customName
     * @return array<string, mixed>
     * @throws Exception
     */
    private function createFilesBackup(?string $customName): array
    {
        $this->logger->info("Creating files backup");

        $backupFile = $this->backupContainer->getBackupFilename(false, $customName);
        
        $this->createZipBackup(_PS_ROOT_DIR_, $backupFile);

        // Verify backup file was created
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new Exception("Files backup was not created or is empty");
        }

        $this->logger->info("Files backup created successfully", [
            'file' => basename($backupFile),
            'size' => filesize($backupFile)
        ]);

        return [
            'backup_name' => basename($backupFile),
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'size_formatted' => $this->formatBytes(filesize($backupFile))
        ];
    }

    /**
     * Create ZIP backup of files
     *
     * @param string $sourceDir
     * @param string $backupFile
     * @throws Exception
     */
    private function createZipBackup(string $sourceDir, string $backupFile): void
    {
        if (!extension_loaded('zip')) {
            throw new Exception('ZIP PHP extension is not installed');
        }

        $zip = new ZipArchive();
        $result = $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot create ZIP file: ' . $this->getZipError($result));
        }

        $sourceDir = realpath($sourceDir);
        if (!$sourceDir) {
            throw new Exception('Source directory does not exist: ' . $sourceDir);
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $excludePaths = $this->getExcludePaths();
        $fileCount = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            
            // Check if file should be excluded
            if ($this->shouldExcludeFile($filePath, $excludePaths)) {
                continue;
            }

            $zip->addFile($filePath, $relativePath);
            $fileCount++;
            
            // Prevent timeout on large directories
            if ($fileCount % 1000 === 0) {
                if (function_exists('set_time_limit')) {
                    set_time_limit(300);
                }
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new Exception('Error closing ZIP file');
        }

        if ($fileCount === 0) {
            throw new Exception('No files were added to the backup');
        }

        $this->logger->debug("Added $fileCount files to backup");
    }

    /**
     * Get paths to exclude from backup
     *
     * @return array<string>
     */
    private function getExcludePaths(): array
    {
        $excludePaths = [
            $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH),
            sys_get_temp_dir(),
            _PS_ROOT_DIR_ . '/var/cache',
            _PS_ROOT_DIR_ . '/var/logs',
            _PS_ROOT_DIR_ . '/cache',
            _PS_ROOT_DIR_ . '/log',
            _PS_ROOT_DIR_ . '/.git',
            _PS_ROOT_DIR_ . '/.svn',
            _PS_ROOT_DIR_ . '/.hg',
            _PS_ROOT_DIR_ . '/.ddev',
        ];

        return array_filter(array_map('realpath', $excludePaths));
    }

    /**
     * Check if file should be excluded
     *
     * @param string $filePath
     * @param array<string> $excludePaths
     * @return bool
     */
    private function shouldExcludeFile(string $filePath, array $excludePaths): bool
    {
        // Check exclude paths
        foreach ($excludePaths as $excludePath) {
            if ($excludePath && strpos($filePath, $excludePath) === 0) {
                return true;
            }
        }

        // Get the relative path from PS_ROOT_DIR for more specific checks
        $relativePath = str_replace(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR, '', $filePath);
        
        // Exclude version control files and directories
        if (preg_match('#(^|/)\.git(/|$)#', $relativePath)) {
            return true;
        }
        if (preg_match('#(^|/)\.svn(/|$)#', $relativePath)) {
            return true;
        }
        if (preg_match('#(^|/)\.hg(/|$)#', $relativePath)) {
            return true;
        }
        
        // Exclude DDEV development environment
        if (preg_match('#(^|/)\.ddev(/|$)#', $relativePath)) {
            return true;
        }

        // Exclude temporary and log files
        if (preg_match('/\.(log|tmp|temp|cache)$/i', $filePath)) {
            return true;
        }

        // Exclude specific problematic files
        if (preg_match('/\.(gitignore|gitkeep|gitattributes)$/i', $filePath)) {
            return true;
        }

        return false;
    }

    /**
     * Handle backup restoration
     */
    private function handleRestoreBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        $backupType = Tools::getValue('backup_type', 'complete');
        
        if (empty($backupName)) {
            $this->ajaxError("Backup name is required");
            return;
        }

        $this->logger->info("Starting backup restoration", [
            'backup_name' => $backupName,
            'backup_type' => $backupType
        ]);

        try {
            if ($backupType === 'complete') {
                $this->restoreCompleteBackup($backupName);
                $message = '¡Tienda restaurada completamente desde: ' . $backupName . '!';
            } else {
                // Legacy support for individual restores
                $this->backupContainer->validateBackupFile($backupName);
                
                if ($backupType === 'database') {
                    $this->restoreDatabase($backupName);
                    $message = 'Base de datos restaurada correctamente desde: ' . $backupName;
                } elseif ($backupType === 'files') {
                    $this->restoreFiles($backupName);
                    $message = 'Archivos restaurados correctamente desde: ' . $backupName;
                } else {
                    throw new Exception("Invalid backup type: " . $backupType);
                }
            }

            $this->logger->info("Backup restoration completed successfully");
            $this->ajaxSuccess($message);

        } catch (Exception $e) {
            $this->logger->error("Backup restoration failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Restore complete backup (database + files)
     *
     * @param string $backupName
     * @throws Exception
     */
    private function restoreCompleteBackup(string $backupName): void
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        // Validate that both files exist
        $this->backupContainer->validateBackupFile($backupInfo['database_file']);
        $this->backupContainer->validateBackupFile($backupInfo['files_file']);

        $this->logger->info("Starting complete restoration: database + files");

        // First restore database
        $this->logger->info("Restoring database from: " . $backupInfo['database_file']);
        $this->restoreDatabase($backupInfo['database_file']);

        // Then restore files
        $this->logger->info("Restoring files from: " . $backupInfo['files_file']);
        $this->restoreFiles($backupInfo['files_file']);

        $this->logger->info("Complete backup restored successfully");
    }

    /**
     * Restore database from backup
     *
     * @param string $backupName
     * @throws Exception
     */
    private function restoreDatabase(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new Exception("Database backup file does not exist: " . $backupName);
        }

        // Check if mysql command is available
        exec('which mysql 2>/dev/null', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("MySQL command line client is not available");
        }

        $command = $this->buildMysqlRestoreCommand($backupFile);

        // Execute restoration
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Database restoration failed. Code: " . $returnVar . ". Output: " . implode("\n", $output));
        }

        $this->logger->info("Database restored successfully from " . $backupName);
    }

    /**
     * Build MySQL restore command
     *
     * @param string $backupFile
     * @return string
     */
    private function buildMysqlRestoreCommand(string $backupFile): string
    {
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            return sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg(_DB_SERVER_),
                escapeshellarg(_DB_USER_),
                escapeshellarg(_DB_PASSWD_),
                escapeshellarg(_DB_NAME_)
            );
        } else {
            return sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg(_DB_SERVER_),
                escapeshellarg(_DB_USER_),
                escapeshellarg(_DB_PASSWD_),
                escapeshellarg(_DB_NAME_),
                escapeshellarg($backupFile)
            );
        }
    }

    /**
     * Restore files from backup
     *
     * @param string $backupName
     * @throws Exception
     */
    private function restoreFiles(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new Exception("Files backup does not exist: " . $backupName);
        }

        if (!extension_loaded('zip')) {
            throw new Exception('ZIP PHP extension is not installed');
        }

        $zip = new ZipArchive();
        $result = $zip->open($backupFile);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot open ZIP file: ' . $this->getZipError($result));
        }

        // Extract to temporary directory first
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Copy files from temp to real location
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);
            
        } finally {
            // Clean up temp directory
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully from " . $backupName);
    }

    /**
     * Handle listing backups
     */
    private function handleListBackups(): void
    {
        try {
            $completeBackups = $this->getCompleteBackups();
            $diskInfo = $this->backupContainer->getDiskSpaceInfo();
            
            // Sort complete backups by date (most recent first)
            usort($completeBackups, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
            
            $this->ajaxSuccess('Backups retrieved successfully', [
                'backups' => $completeBackups,
                'disk_info' => $diskInfo
            ]);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Get complete backups from metadata
     *
     * @return array
     */
    private function getCompleteBackups(): array
    {
        $metadata = $this->getBackupMetadata();
        $completeBackups = [];
        
        foreach ($metadata as $backupName => $backupInfo) {
            // Check if both files still exist
            $dbFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'];
            $filesFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'];
            
            if (file_exists($dbFile) && file_exists($filesFile)) {
                $totalSize = filesize($dbFile) + filesize($filesFile);
                
                $completeBackups[] = [
                    'name' => $backupName,
                    'date' => $backupInfo['created_at'],
                    'size' => $totalSize,
                    'size_formatted' => $this->formatBytes($totalSize),
                    'type' => 'complete',
                    'database_file' => $backupInfo['database_file'],
                    'files_file' => $backupInfo['files_file']
                ];
            }
        }
        
        return $completeBackups;
    }

    /**
     * Handle backup deletion
     */
    private function handleDeleteBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->ajaxError("Backup name is required");
            return;
        }

        try {
            // Check if it's a complete backup
            $metadata = $this->getBackupMetadata();
            
            if (isset($metadata[$backupName])) {
                // It's a complete backup, delete both files and metadata
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
                
                $this->logger->info("Complete backup deleted: " . $backupName);
                $this->ajaxSuccess("Backup completo eliminado correctamente: " . $backupName);
            } else {
                // Individual backup file
                $this->backupContainer->deleteBackup($backupName);
                $this->logger->info("Individual backup deleted: " . $backupName);
                $this->ajaxSuccess("Backup eliminado correctamente: " . $backupName);
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to delete backup: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup validation
     */
    private function handleValidateBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->ajaxError("Backup name is required");
            return;
        }

        try {
            $isValid = $this->backupContainer->validateBackupFile($backupName);
            $this->ajaxSuccess("Backup validation completed", ['valid' => $isValid]);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle disk space request
     */
    private function handleGetDiskSpace(): void
    {
        try {
            $diskInfo = $this->backupContainer->getDiskSpaceInfo();
            $this->ajaxSuccess("Disk space information", $diskInfo);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle logs request
     */
    private function handleGetLogs(): void
    {
        try {
            $logs = $this->logger->getRecentLogs(100);
            $this->ajaxSuccess("Logs retrieved", ['logs' => $logs]);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle database-only restoration from complete backup
     */
    private function handleRestoreDatabaseOnly(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->ajaxError("Backup name is required");
            return;
        }

        $this->logger->info("Starting database-only restoration", [
            'backup_name' => $backupName
        ]);

        try {
            $metadata = $this->getBackupMetadata();
            
            if (!isset($metadata[$backupName])) {
                throw new Exception("Backup metadata not found for: " . $backupName);
            }

            $backupInfo = $metadata[$backupName];
            
            // Validate that database file exists
            $this->backupContainer->validateBackupFile($backupInfo['database_file']);

            $this->logger->info("Restoring database from: " . $backupInfo['database_file']);
            $this->restoreDatabase($backupInfo['database_file']);

            $message = 'Base de datos restaurada correctamente desde: ' . $backupName;
            $this->logger->info("Database-only restoration completed successfully");
            $this->ajaxSuccess($message);

        } catch (Exception $e) {
            $this->logger->error("Database-only restoration failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle files-only restoration from complete backup
     */
    private function handleRestoreFilesOnly(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->ajaxError("Backup name is required");
            return;
        }

        $this->logger->info("Starting files-only restoration", [
            'backup_name' => $backupName
        ]);

        try {
            $metadata = $this->getBackupMetadata();
            
            if (!isset($metadata[$backupName])) {
                throw new Exception("Backup metadata not found for: " . $backupName);
            }

            $backupInfo = $metadata[$backupName];
            
            // Validate that files exist
            $this->backupContainer->validateBackupFile($backupInfo['files_file']);

            $this->logger->info("Restoring files from: " . $backupInfo['files_file']);
            $this->restoreFiles($backupInfo['files_file']);

            $message = 'Archivos restaurados correctamente desde: ' . $backupName;
            $this->logger->info("Files-only restoration completed successfully");
            $this->ajaxSuccess($message);

        } catch (Exception $e) {
            $this->logger->error("Files-only restoration failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Helper methods
     */

    /**
     * Send AJAX success response
     *
     * @param string $message
     * @param array<string, mixed> $data
     */
    private function ajaxSuccess(string $message, array $data = []): void
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
        
        $this->ajaxDie(json_encode($response));
    }

    /**
     * Send AJAX error response
     *
     * @param string $message
     */
    private function ajaxError(string $message): void
    {
        $response = [
            'success' => false,
            'error' => $message
        ];
        
        $this->ajaxDie(json_encode($response));
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
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
            case ZipArchive::ER_OK: return 'No error';
            case ZipArchive::ER_MULTIDISK: return 'Multi-disk zip archives not supported';
            case ZipArchive::ER_RENAME: return 'Renaming temporary file failed';
            case ZipArchive::ER_CLOSE: return 'Closing zip archive failed';
            case ZipArchive::ER_SEEK: return 'Seek error';
            case ZipArchive::ER_READ: return 'Read error';
            case ZipArchive::ER_WRITE: return 'Write error';
            case ZipArchive::ER_CRC: return 'CRC error';
            case ZipArchive::ER_ZIPCLOSED: return 'Containing zip archive was closed';
            case ZipArchive::ER_NOENT: return 'No such file';
            case ZipArchive::ER_EXISTS: return 'File already exists';
            case ZipArchive::ER_OPEN: return 'Can\'t open file';
            case ZipArchive::ER_TMPOPEN: return 'Failure to create temporary file';
            case ZipArchive::ER_ZLIB: return 'Zlib error';
            case ZipArchive::ER_MEMORY: return 'Memory allocation failure';
            case ZipArchive::ER_CHANGED: return 'Entry has been changed';
            case ZipArchive::ER_COMPNOTSUPP: return 'Compression method not supported';
            case ZipArchive::ER_EOF: return 'Premature EOF';
            case ZipArchive::ER_INVAL: return 'Invalid argument';
            case ZipArchive::ER_NOZIP: return 'Not a zip archive';
            case ZipArchive::ER_INTERNAL: return 'Internal error';
            case ZipArchive::ER_INCONS: return 'Zip archive inconsistent';
            case ZipArchive::ER_REMOVE: return 'Can\'t remove file';
            case ZipArchive::ER_DELETED: return 'Entry has been deleted';
            default: return "Unknown error code: $code";
        }
    }

    /**
     * Copy directory recursively
     *
     * @param string $source
     * @param string $destination
     * @throws Exception
     */
    private function copyDirectoryRecursively(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $excludePaths = $this->getExcludePaths();

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            // Skip files/directories that should be excluded during restoration
            if ($this->shouldExcludeFile($destinationPath, $excludePaths)) {
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
                    throw new Exception('Failed to copy file: ' . $relativePath);
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
     * Save backup metadata for complete backups
     *
     * @param array $backupData
     */
    private function saveBackupMetadata(array $backupData): void
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        
        $metadata = [];
        if (file_exists($metadataFile)) {
            $content = file_get_contents($metadataFile);
            $metadata = json_decode($content, true) ?: [];
        }
        
        $metadata[$backupData['backup_name']] = $backupData;
        
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Save complete backup metadata array
     *
     * @param array $metadata
     */
    private function saveCompleteBackupMetadata(array $metadata): void
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Get backup metadata
     *
     * @return array
     */
    private function getBackupMetadata(): array
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        
        if (!file_exists($metadataFile)) {
            return [];
        }
        
        $content = file_get_contents($metadataFile);
        return json_decode($content, true) ?: [];
    }
}
