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
            'PrestaShop\Module\PsCopia\Migration\DatabaseMigrator' => '/../../classes/Migration/DatabaseMigrator.php',
            'PrestaShop\Module\PsCopia\Migration\FilesMigrator' => '/../../classes/Migration/FilesMigrator.php',
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
                case 'export_backup':
                    $this->handleExportBackup();
                    break;
                case 'import_backup':
                    $this->handleImportBackup();
                    break;
                case 'download_export':
                    $this->handleDownloadExport();
                    break;
                case 'scan_server_uploads':
                    $this->handleScanServerUploads();
                    break;
                case 'import_from_server':
                    $this->handleImportFromServer();
                    break;
                case 'delete_server_upload':
                    $this->handleDeleteServerUpload();
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
     * Create ZIP backup of files with chunked processing for large sites
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

        // Optimizar para sitios grandes sin cambiar configuración del servidor
        $this->optimizeForLargeOperations();

        $sourceDir = realpath($sourceDir);
        if (!$sourceDir) {
            throw new Exception('Source directory does not exist: ' . $sourceDir);
        }

        // Verificar si es un sitio grande y usar estrategia apropiada
        $estimatedSize = $this->estimateDirectorySize($sourceDir);
        $isLargeSite = $estimatedSize > 500 * 1024 * 1024; // 500MB

        if ($isLargeSite) {
            $this->logger->info("Large site detected, using chunked processing", [
                'estimated_size' => $this->formatBytes($estimatedSize)
            ]);
            $this->createZipBackupChunked($sourceDir, $backupFile);
        } else {
            $this->createZipBackupStandard($sourceDir, $backupFile);
        }
    }

    /**
     * Estimate directory size quickly without full scan
     *
     * @param string $dir
     * @return int
     */
    private function estimateDirectorySize(string $dir): int
    {
        $size = 0;
        $count = 0;
        $maxSampleFiles = 100; // Muestra de archivos para estimación

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $excludePaths = $this->getExcludePaths();

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            if ($this->shouldExcludeFile($file->getRealPath(), $excludePaths)) {
                continue;
            }

            $size += $file->getSize();
            $count++;

            // Estimación basada en muestra
            if ($count >= $maxSampleFiles) {
                // Contar archivos restantes rápidamente
                $totalFiles = $count;
                foreach ($iterator as $remainingFile) {
                    if ($remainingFile->isFile() && $remainingFile->isReadable() && 
                        !$this->shouldExcludeFile($remainingFile->getRealPath(), $excludePaths)) {
                        $totalFiles++;
                    }
                    if ($totalFiles % 1000 === 0 && $totalFiles > $maxSampleFiles * 10) {
                        break; // Evitar bucle infinito en sitios enormes
                    }
                }
                
                // Estimar tamaño total basado en promedio
                $avgFileSize = $totalFiles > 0 ? $size / $count : 0;
                return (int)($avgFileSize * $totalFiles);
            }
        }

        return $size;
    }

    /**
     * Create ZIP backup using standard method for smaller sites
     *
     * @param string $sourceDir
     * @param string $backupFile
     * @throws Exception
     */
    private function createZipBackupStandard(string $sourceDir, string $backupFile): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot create ZIP file: ' . $this->getZipError($result));
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
            
            if ($this->shouldExcludeFile($filePath, $excludePaths)) {
                continue;
            }

            $zip->addFile($filePath, $relativePath);
            $fileCount++;
            
            // Prevenir timeouts y limpiar memoria
            if ($fileCount % 500 === 0) {
                $this->preventTimeout();
                $this->clearMemory();
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new Exception('Error closing ZIP file');
        }

        if ($fileCount === 0) {
            throw new Exception('No files were added to the backup');
        }

        $this->logger->info("Standard backup completed", ['files' => $fileCount]);
    }

    /**
     * Create ZIP backup using chunked processing for large sites
     *
     * @param string $sourceDir
     * @param string $backupFile
     * @throws Exception
     */
    private function createZipBackupChunked(string $sourceDir, string $backupFile): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot create ZIP file: ' . $this->getZipError($result));
        }

        $excludePaths = $this->getExcludePaths();
        $fileCount = 0;
        $chunkSize = 100; // Procesar archivos en chunks más pequeños
        $currentChunk = 0;

        // Obtener lista de archivos primero
        $filesToProcess = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $filePath = $file->getRealPath();
            if ($this->shouldExcludeFile($filePath, $excludePaths)) {
                continue;
            }

            $filesToProcess[] = [
                'path' => $filePath,
                'relative' => substr($filePath, strlen($sourceDir) + 1),
                'size' => $file->getSize()
            ];
        }

        $this->logger->info("Processing files in chunks", [
            'total_files' => count($filesToProcess),
            'chunk_size' => $chunkSize
        ]);

        // Procesar archivos en chunks
        $chunks = array_chunk($filesToProcess, $chunkSize);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $this->logger->debug("Processing chunk " . ($chunkIndex + 1) . " of " . count($chunks));
            
            foreach ($chunk as $fileInfo) {
                // Manejar archivos grandes de forma especial
                if ($fileInfo['size'] > 50 * 1024 * 1024) { // 50MB
                    $this->addLargeFileToZip($zip, $fileInfo['path'], $fileInfo['relative']);
                } else {
                    $zip->addFile($fileInfo['path'], $fileInfo['relative']);
                }
                $fileCount++;
            }

            // Limpiar memoria y prevenir timeout después de cada chunk
            $this->preventTimeout();
            $this->clearMemory();
            
            // Log progreso
            if ($chunkIndex % 10 === 0) {
                $progress = round(($chunkIndex / count($chunks)) * 100, 1);
                $this->logger->info("Backup progress: {$progress}% ({$fileCount} files)");
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new Exception('Error closing ZIP file');
        }

        if ($fileCount === 0) {
            throw new Exception('No files were added to the backup');
        }

        $this->logger->info("Chunked backup completed", ['files' => $fileCount]);
    }

    /**
     * Add large file to ZIP using streaming to avoid memory issues
     *
     * @param ZipArchive $zip
     * @param string $filePath
     * @param string $relativePath
     * @throws Exception
     */
    private function addLargeFileToZip(ZipArchive $zip, string $filePath, string $relativePath): void
    {
        // Para archivos muy grandes, leer por chunks para evitar problemas de memoria
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->logger->warning("Cannot open large file, skipping: " . $filePath);
            return;
        }
        
        $content = '';
        $chunkSize = 1024 * 1024; // 1MB chunks
        $maxMemory = 20 * 1024 * 1024; // Máximo 20MB en memoria
        
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new Exception('Error reading large file: ' . $filePath);
                }
                
                $content .= $chunk;
                
                // Si el archivo es demasiado grande para mantenerlo en memoria,
                // guardarlo temporalmente
                if (strlen($content) > $maxMemory) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'ps_copia_large_');
                    file_put_contents($tempFile, $content);
                    $content = ''; // Limpiar memoria
                    
                    // Continuar leyendo el resto del archivo
                    while (!feof($handle)) {
                        $chunk = fread($handle, $chunkSize);
                        if ($chunk !== false) {
                            file_put_contents($tempFile, $chunk, FILE_APPEND);
                        }
                    }
                    
                    // Añadir desde archivo temporal
                    $zip->addFile($tempFile, $relativePath);
                    
                    // Programar limpieza del archivo temporal
                    register_shutdown_function(function() use ($tempFile) {
                        if (file_exists($tempFile)) {
                            @unlink($tempFile);
                        }
                    });
                    
                    fclose($handle);
                    return;
                }
            }
            
            // Archivo cabe en memoria
            if (!$zip->addFromString($relativePath, $content)) {
                throw new Exception('Failed to add large file to ZIP: ' . $relativePath);
            }
            
        } finally {
            fclose($handle);
            unset($content); // Limpiar memoria explícitamente
        }
    }

    /**
     * Optimize settings for large operations without changing server config
     */
    private function optimizeForLargeOperations(): void
    {
        // Intentar optimizar memoria solo si es posible
        $currentMemory = ini_get('memory_limit');
        if ($currentMemory !== '-1') {
            $memoryInBytes = $this->parseMemoryLimit($currentMemory);
            $recommendedMemory = max($memoryInBytes, 256 * 1024 * 1024); // Mínimo 256MB
            
            // Solo intentar aumentar si tenemos permisos
            if (function_exists('ini_set')) {
                @ini_set('memory_limit', $recommendedMemory);
            }
        }

        // Intentar aumentar tiempo de ejecución solo si es posible
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', 0);
        }

        // Optimizar garbage collection
        if (function_exists('gc_enable')) {
            gc_enable();
        }
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $memoryLimit
     * @return int
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }
        
        $memoryLimit = trim($memoryLimit);
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Prevent timeout by refreshing execution time
     */
    private function preventTimeout(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutos más
        }
        
        // Flush output para mantener conexión activa
        if (ob_get_level()) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Clear memory to prevent memory limit issues
     */
    private function clearMemory(): void
    {
        // Forzar limpieza de memoria
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        if (function_exists('memory_get_usage')) {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            
            // Si estamos usando más del 80% de la memoria, hacer limpieza agresiva
            if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.8)) {
                if (function_exists('gc_collect_cycles')) {
                    for ($i = 0; $i < 3; $i++) {
                        gc_collect_cycles();
                    }
                }
            }
        }
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

        $this->logger->info("Starting complete restoration with automatic migration: database + files");

        // Configuración automática de migración (igual que en MIGRAR DESDE OTRO PRESTASHOP)
        $migrationConfig = [
            // URLs siempre habilitadas con autodetección (valor predeterminado inteligente)
            'migrate_urls' => true,
            'old_url' => '',
            'new_url' => '',
            // Admin directory siempre deshabilitado (se preserva del backup)
            'migrate_admin_dir' => false,
            'old_admin_dir' => '',
            'new_admin_dir' => '',
            // Preserve DB config siempre obligatorio
            'preserve_db_config' => true,
            'configurations' => []
        ];

        $this->logger->info("Applying automatic migration configuration for complete restore", [
            'migrate_urls' => $migrationConfig['migrate_urls'],
            'migrate_admin_dir' => $migrationConfig['migrate_admin_dir'],
            'preserve_db_config' => $migrationConfig['preserve_db_config']
        ]);

        // Get full paths to backup files
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];

        // Apply database migration (includes automatic URL detection and migration)
        $this->logger->info("Restoring database with automatic migration from: " . $backupInfo['database_file']);
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
            $dbMigrator = new \PrestaShop\Module\PsCopia\Migration\DatabaseMigrator($this->backupContainer, $this->logger);
            $dbMigrator->migrateDatabase($dbFilePath, $migrationConfig);
        } else {
            // Fallback to standard restore if migration class not available
            $this->logger->warning("DatabaseMigrator class not found, falling back to standard restore");
            $this->restoreDatabase($backupInfo['database_file']);
        }

        // Restore files (preserving admin directory structure from backup)
        $this->logger->info("Restoring files with preserved admin directory from: " . $backupInfo['files_file']);
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
            try {
                $filesMigrator = new \PrestaShop\Module\PsCopia\Migration\FilesMigrator($this->backupContainer, $this->logger);
                $filesMigrator->migrateFiles($filesFilePath, $migrationConfig);
            } catch (Exception $e) {
                $this->logger->error("Files migration failed, falling back to simple restoration: " . $e->getMessage());
                // Fallback: try to restore files without migration
                $this->logger->info("Attempting fallback files restoration without migration");
                $this->restoreFiles($backupInfo['files_file']);
            }
        } else {
            // Fallback to standard restore if migration class not available
            $this->logger->warning("FilesMigrator class not found, falling back to standard restore");
            $this->restoreFiles($backupInfo['files_file']);
        }

        $this->logger->info("Complete backup restored successfully with automatic migration applied");
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

    /**
     * Handle backup export - create a downloadable ZIP with complete backup
     */
    private function handleExportBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->ajaxError('Nombre del backup requerido');
            return;
        }

        $this->logger->info("Starting backup export", ['backup_name' => $backupName]);

        try {
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $metadata = $this->getBackupMetadata();
            
            // Verificar que el backup existe
            if (!isset($metadata[$backupName])) {
                throw new Exception("Backup no encontrado: $backupName");
            }
            
            $backupData = $metadata[$backupName];
            
            // Verificar que los archivos existen
            $dbFile = $backupDir . DIRECTORY_SEPARATOR . $backupData['database_file'];
            $filesFile = $backupDir . DIRECTORY_SEPARATOR . $backupData['files_file'];
            
            if (!file_exists($dbFile)) {
                throw new Exception("Archivo de base de datos no encontrado: " . $backupData['database_file']);
            }
            
            if (!file_exists($filesFile)) {
                throw new Exception("Archivo de archivos no encontrado: " . $backupData['files_file']);
            }
            
            // Crear ZIP temporal para exportar
            $exportZipPath = $backupDir . DIRECTORY_SEPARATOR . $backupName . '_export.zip';
            
            $zip = new ZipArchive();
            $result = $zip->open($exportZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($result !== TRUE) {
                throw new Exception('No se pudo crear el archivo ZIP de exportación: ' . $this->getZipError($result));
            }
            
            // Añadir archivos al ZIP
            $zip->addFile($dbFile, 'database/' . basename($dbFile));
            $zip->addFile($filesFile, 'files/' . basename($filesFile));
            
            // Añadir metadata
            $zip->addFromString('backup_info.json', json_encode($backupData, JSON_PRETTY_PRINT));
            
            $zip->close();
            
            // Verificar que el ZIP se creó correctamente
            if (!file_exists($exportZipPath) || filesize($exportZipPath) === 0) {
                throw new Exception('Error al crear el archivo ZIP de exportación');
            }
            
            $this->logger->info("Backup export created successfully", [
                'backup_name' => $backupName,
                'export_file' => basename($exportZipPath),
                'size' => filesize($exportZipPath)
            ]);
            
            // Devolver la URL de descarga
            $this->ajaxSuccess('Archivo de exportación creado correctamente', [
                'download_url' => $this->getDownloadUrl($exportZipPath),
                'filename' => $backupName . '_export.zip',
                'size' => filesize($exportZipPath),
                'size_formatted' => $this->formatBytes(filesize($exportZipPath))
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Backup export failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup import with chunked processing for large files
     */
    private function handleImportBackup(): void
    {
        $this->logger->info("Starting backup import");

        try {
            // Optimizar para operaciones grandes
            $this->optimizeForLargeOperations();
            
            // Verificar que se subió un archivo
            if (!isset($_FILES['backup_file'])) {
                throw new Exception('No se ha seleccionado ningún archivo');
            }
            
            $uploadedFile = $_FILES['backup_file'];
            
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getUploadError($uploadedFile['error']));
            }
            
            // Verificar que es un archivo ZIP
            $fileInfo = pathinfo($uploadedFile['name']);
            if (strtolower($fileInfo['extension']) !== 'zip') {
                throw new Exception('El archivo debe ser un ZIP válido');
            }
            
            // Verificar tamaño del archivo
            $fileSize = $uploadedFile['size'];
            $this->logger->info("Processing backup file", [
                'filename' => $uploadedFile['name'],
                'size' => $this->formatBytes($fileSize)
            ]);
            
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $tempZipPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_import_' . time() . '.zip';
            
            // Mover archivo subido con verificación
            if (!move_uploaded_file($uploadedFile['tmp_name'], $tempZipPath)) {
                throw new Exception('Error al mover el archivo subido');
            }
            
            // Verificar integridad del ZIP
            if (!$this->verifyZipIntegrity($tempZipPath)) {
                @unlink($tempZipPath);
                throw new Exception('El archivo ZIP está corrupto o dañado');
            }

            // Determinar si es un archivo grande
            $isLargeFile = $fileSize > 100 * 1024 * 1024; // 100MB
            
            if ($isLargeFile) {
                $this->logger->info("Large file detected, using streaming import");
                $this->processLargeBackupImport($tempZipPath, $uploadedFile['name']);
            } else {
                $this->processStandardBackupImport($tempZipPath, $uploadedFile['name']);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Backup import failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Verify ZIP file integrity without loading entire file into memory
     *
     * @param string $zipPath
     * @return bool
     */
    private function verifyZipIntegrity(string $zipPath): bool
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CHECKCONS);
        
        if ($result === TRUE) {
            $zip->close();
            return true;
        }
        
        $this->logger->warning("ZIP integrity check failed", [
            'error_code' => $result,
            'error_message' => $this->getZipError($result)
        ]);
        
        return false;
    }

    /**
     * Process standard backup import for smaller files
     *
     * @param string $tempZipPath
     * @param string $originalFilename
     * @throws Exception
     */
    private function processStandardBackupImport(string $tempZipPath, string $originalFilename): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($tempZipPath);
        
        if ($result !== TRUE) {
            @unlink($tempZipPath);
            throw new Exception('No se pudo abrir el archivo ZIP: ' . $this->getZipError($result));
        }
        
        try {
            // Verificar estructura del backup
            if (!$this->validateBackupStructure($zip)) {
                throw new Exception('El archivo ZIP no tiene la estructura correcta de backup');
            }
            
            // Leer metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generar nuevo nombre único
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'imported_backup_' . $timestamp;
            
            // Extraer archivos usando método estándar
            $this->extractBackupStandard($zip, $newBackupName, $originalFilename, $backupInfo);
            
        } finally {
            $zip->close();
            @unlink($tempZipPath);
        }
    }

    /**
     * Process large backup import using streaming
     *
     * @param string $tempZipPath
     * @param string $originalFilename
     * @throws Exception
     */
    private function processLargeBackupImport(string $tempZipPath, string $originalFilename): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($tempZipPath);
        
        if ($result !== TRUE) {
            @unlink($tempZipPath);
            throw new Exception('No se pudo abrir el archivo ZIP: ' . $this->getZipError($result));
        }
        
        try {
            // Verificar estructura del backup
            if (!$this->validateBackupStructure($zip)) {
                throw new Exception('El archivo ZIP no tiene la estructura correcta de backup');
            }
            
            // Leer metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generar nuevo nombre único
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'imported_backup_' . $timestamp;
            
            // Extraer usando streaming para archivos grandes
            $this->extractBackupStreaming($zip, $newBackupName, $originalFilename, $backupInfo);
            
        } finally {
            $zip->close();
            @unlink($tempZipPath);
        }
    }

    /**
     * Validate backup structure
     *
     * @param ZipArchive $zip
     * @return bool
     */
    private function validateBackupStructure(ZipArchive $zip): bool
    {
        $hasInfo = $zip->locateName('backup_info.json') !== false;
        $hasDatabase = false;
        $hasFiles = false;
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (strpos($filename, 'database/') === 0) {
                $hasDatabase = true;
            }
            if (strpos($filename, 'files/') === 0) {
                $hasFiles = true;
            }
            
            // Optimización: salir temprano si encontramos todo
            if ($hasInfo && $hasDatabase && $hasFiles) {
                break;
            }
        }
        
        return $hasInfo && $hasDatabase && $hasFiles;
    }

    /**
     * Extract backup info from ZIP
     *
     * @param ZipArchive $zip
     * @return array
     * @throws Exception
     */
    private function extractBackupInfo(ZipArchive $zip): array
    {
        $infoContent = $zip->getFromName('backup_info.json');
        $backupInfo = json_decode($infoContent, true);
        
        if (!$backupInfo) {
            throw new Exception('No se pudo leer la información del backup');
        }
        
        return $backupInfo;
    }

    /**
     * Extract backup using standard method
     *
     * @param ZipArchive $zip
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @param string|null $serverZipPath Optional path to server ZIP file to delete after import
     * @throws Exception
     */
    private function extractBackupStandard(ZipArchive $zip, string $newBackupName, string $originalFilename, array $backupInfo, ?string $serverZipPath = null): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $extractPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_extract_' . time();
        
        if (!$zip->extractTo($extractPath)) {
            throw new Exception('Error al extraer los archivos del backup');
        }
        
        try {
            $this->processExtractedFiles($extractPath, $newBackupName, $originalFilename, $backupInfo, $serverZipPath);
        } finally {
            $this->removeDirectoryRecursively($extractPath);
        }
    }

    /**
     * Extract backup using streaming for large files
     *
     * @param ZipArchive $zip
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @param string|null $serverZipPath Optional path to server ZIP file to delete after import
     * @throws Exception
     */
    private function extractBackupStreaming(ZipArchive $zip, string $newBackupName, string $originalFilename, array $backupInfo, ?string $serverZipPath = null): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        // Encontrar archivos de backup en el ZIP
        $dbFiles = [];
        $filesFiles = [];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            if (strpos($filename, 'database/') === 0 && !$zip->isDir($i)) {
                $dbFiles[] = $filename;
            } elseif (strpos($filename, 'files/') === 0 && !$zip->isDir($i)) {
                $filesFiles[] = $filename;
            }
        }
        
        if (empty($dbFiles) || empty($filesFiles)) {
            throw new Exception('Archivos de backup incompletos');
        }
        
        // Extraer archivo de base de datos usando streaming
        $dbFilename = $dbFiles[0];
        $newDbFilename = $newBackupName . '_db_' . basename($dbFilename);
        $newDbPath = $backupDir . DIRECTORY_SEPARATOR . $newDbFilename;
        
        $this->extractFileStreaming($zip, $dbFilename, $newDbPath);
        
        // Extraer archivo de archivos usando streaming
        $filesFilename = $filesFiles[0];
        $newFilesFilename = $newBackupName . '_files_' . basename($filesFilename);
        $newFilesPath = $backupDir . DIRECTORY_SEPARATOR . $newFilesFilename;
        
        $this->extractFileStreaming($zip, $filesFilename, $newFilesPath);
        
        // Actualizar metadata
        $this->saveImportedBackupMetadata($newBackupName, $newDbFilename, $newFilesFilename, $originalFilename, $backupInfo);
        
        // Si llegamos aquí, la importación fue exitosa
        // Eliminar automáticamente el archivo ZIP original del servidor si se proporcionó
        if ($serverZipPath !== null) {
            $this->deleteServerUploadFileAfterImport($serverZipPath, $originalFilename);
        }
        
        $this->logger->info("Large backup import completed successfully", [
            'new_backup_name' => $newBackupName,
            'original_filename' => $originalFilename
        ]);
        
        $this->ajaxSuccess('Backup grande importado correctamente como: ' . $newBackupName, [
            'backup_name' => $newBackupName,
            'imported_from' => $originalFilename
        ]);
    }

    /**
     * Extract single file from ZIP using streaming to avoid memory issues
     *
     * @param ZipArchive $zip
     * @param string $filename
     * @param string $outputPath
     * @throws Exception
     */
    private function extractFileStreaming(ZipArchive $zip, string $filename, string $outputPath): void
    {
        $stream = $zip->getStream($filename);
        if (!$stream) {
            throw new Exception('No se pudo obtener stream del archivo: ' . $filename);
        }
        
        $output = fopen($outputPath, 'wb');
        if (!$output) {
            fclose($stream);
            throw new Exception('No se pudo crear archivo de salida: ' . $outputPath);
        }
        
        try {
            $chunkSize = 8192; // 8KB chunks
            $totalWritten = 0;
            
            while (!feof($stream)) {
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) {
                    throw new Exception('Error leyendo stream del archivo: ' . $filename);
                }
                
                $written = fwrite($output, $chunk);
                if ($written === false) {
                    throw new Exception('Error escribiendo archivo: ' . $outputPath);
                }
                
                $totalWritten += $written;
                
                // Prevenir timeout y limpiar memoria periódicamente
                if ($totalWritten % (1024 * 1024) === 0) { // Cada MB
                    $this->preventTimeout();
                    $this->clearMemory();
                }
            }
            
            $this->logger->debug("Extracted file using streaming", [
                'filename' => $filename,
                'output_path' => basename($outputPath),
                'size' => $this->formatBytes($totalWritten)
            ]);
            
        } finally {
            fclose($stream);
            fclose($output);
        }
    }

    /**
     * Process extracted files from standard extraction
     *
     * @param string $extractPath
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @param string|null $serverZipPath Optional path to server ZIP file to delete after import
     * @throws Exception
     */
    private function processExtractedFiles(string $extractPath, string $newBackupName, string $originalFilename, array $backupInfo, ?string $serverZipPath = null): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        // Mover archivos extraídos a sus ubicaciones finales
        $dbSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'database';
        $filesSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'files';
        
        $dbFiles = glob($dbSourcePath . DIRECTORY_SEPARATOR . '*');
        $filesFiles = glob($filesSourcePath . DIRECTORY_SEPARATOR . '*');
        
        if (empty($dbFiles) || empty($filesFiles)) {
            throw new Exception('Archivos de backup incompletos');
        }
        
        // Copiar archivos con nuevos nombres
        $newDbFilename = $newBackupName . '_db_' . basename($dbFiles[0]);
        $newFilesFilename = $newBackupName . '_files_' . basename($filesFiles[0]);
        
        $newDbPath = $backupDir . DIRECTORY_SEPARATOR . $newDbFilename;
        $newFilesPath = $backupDir . DIRECTORY_SEPARATOR . $newFilesFilename;
        
        // Usar copy optimizado para archivos grandes
        if (!$this->copyFileOptimized($dbFiles[0], $newDbPath)) {
            throw new Exception('Error al copiar el archivo de base de datos');
        }
        
        if (!$this->copyFileOptimized($filesFiles[0], $newFilesPath)) {
            @unlink($newDbPath);
            throw new Exception('Error al copiar el archivo de archivos');
        }
        
        // Actualizar metadata
        $this->saveImportedBackupMetadata($newBackupName, $newDbFilename, $newFilesFilename, $originalFilename, $backupInfo);
        
        // Si llegamos aquí, la importación fue exitosa
        // Eliminar automáticamente el archivo ZIP original del servidor si se proporcionó
        if ($serverZipPath !== null) {
            $this->deleteServerUploadFileAfterImport($serverZipPath, $originalFilename);
        }
        
        $this->logger->info("Backup import completed successfully", [
            'new_backup_name' => $newBackupName,
            'original_filename' => $originalFilename
        ]);
        
        $this->ajaxSuccess('Backup importado correctamente como: ' . $newBackupName, [
            'backup_name' => $newBackupName,
            'imported_from' => $originalFilename
        ]);
    }

    /**
     * Copy file optimized for large files
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    private function copyFileOptimized(string $source, string $destination): bool
    {
        $sourceSize = filesize($source);
        
        // Para archivos pequeños, usar copy estándar
        if ($sourceSize < 50 * 1024 * 1024) { // 50MB
            return copy($source, $destination);
        }
        
        // Para archivos grandes, usar streaming
        $sourceHandle = fopen($source, 'rb');
        $destHandle = fopen($destination, 'wb');
        
        if (!$sourceHandle || !$destHandle) {
            if ($sourceHandle) fclose($sourceHandle);
            if ($destHandle) fclose($destHandle);
            return false;
        }
        
        try {
            $chunkSize = 1024 * 1024; // 1MB chunks
            $totalCopied = 0;
            
            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, $chunkSize);
                if ($chunk === false) {
                    return false;
                }
                
                if (fwrite($destHandle, $chunk) === false) {
                    return false;
                }
                
                $totalCopied += strlen($chunk);
                
                // Prevenir timeout cada 10MB
                if ($totalCopied % (10 * 1024 * 1024) === 0) {
                    $this->preventTimeout();
                    $this->clearMemory();
                }
            }
            
            return true;
            
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
        }
    }

    /**
     * Save imported backup metadata
     *
     * @param string $newBackupName
     * @param string $newDbFilename
     * @param string $newFilesFilename
     * @param string $originalFilename
     * @param array $backupInfo
     */
    private function saveImportedBackupMetadata(string $newBackupName, string $newDbFilename, string $newFilesFilename, string $originalFilename, array $backupInfo): void
    {
        $newBackupData = [
            'backup_name' => $newBackupName,
            'database_file' => $newDbFilename,
            'files_file' => $newFilesFilename,
            'created_at' => date('Y-m-d H:i:s'),
            'type' => 'complete',
            'imported_from' => $originalFilename,
            'original_backup' => $backupInfo['backup_name'] ?? 'unknown'
        ];
        
        $this->saveBackupMetadata($newBackupData);
    }

    /**
     * Get download URL for exported backup
     *
     * @param string $filePath
     * @return string
     */
    private function getDownloadUrl(string $filePath): string
    {
        $filename = basename($filePath);
        return Context::getContext()->link->getAdminLink('AdminPsCopiaAjax') . 
               '&action=download_export&file=' . urlencode($filename) . 
               '&token=' . Tools::getAdminTokenLite('AdminPsCopiaAjax');
    }

    /**
     * Get upload error message
     *
     * @param int $errorCode
     * @return string
     */
    private function getUploadError(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'El archivo es demasiado grande (excede upload_max_filesize)';
            case UPLOAD_ERR_FORM_SIZE:
                return 'El archivo es demasiado grande (excede MAX_FILE_SIZE)';
            case UPLOAD_ERR_PARTIAL:
                return 'El archivo se subió parcialmente';
            case UPLOAD_ERR_NO_FILE:
                return 'No se seleccionó ningún archivo';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Falta el directorio temporal';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Error al escribir el archivo en el disco';
            case UPLOAD_ERR_EXTENSION:
                return 'Una extensión de PHP detuvo la subida del archivo';
            default:
                return 'Error desconocido al subir el archivo: ' . $errorCode;
        }
    }

    /**
     * Handle download of exported backup files
     */
    private function handleDownloadExport(): void
    {
        $filename = Tools::getValue('file');
        
        if (empty($filename)) {
            $this->ajaxError('Nombre de archivo requerido');
            return;
        }

        try {
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $filename;
            
            // Verificar que el archivo existe y está en el directorio correcto
            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new Exception('Archivo no encontrado');
            }
            
            // Verificar que el archivo está dentro del directorio de backups (seguridad)
            $realBackupDir = realpath($backupDir);
            $realFilePath = realpath($filePath);
            
            if (!$realFilePath || strpos($realFilePath, $realBackupDir) !== 0) {
                throw new Exception('Acceso denegado al archivo');
            }
            
            // Verificar que es un archivo de exportación
            if (strpos($filename, '_export.zip') === false) {
                throw new Exception('Tipo de archivo no válido');
            }
            
            $this->logger->info("Starting file download", ['filename' => $filename]);
            
            // Configurar headers para descarga
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            // Limpiar buffer de salida
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Enviar archivo
            readfile($filePath);
            
            // Eliminar archivo temporal después de la descarga
            @unlink($filePath);
            
            exit;
            
        } catch (Exception $e) {
            $this->logger->error("Download export failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup import with migration support
     */
    private function handleImportBackupWithMigration(): void
    {
        $this->logger->info("Starting backup import with migration - using intelligent defaults");

        try {
            // Get migration configuration with intelligent defaults
            $migrationConfig = [
                // URLs siempre habilitadas con autodetección (valor predeterminado inteligente)
                'migrate_urls' => (bool) Tools::getValue('migrate_urls', true),
                'old_url' => Tools::getValue('old_url', ''),
                'new_url' => Tools::getValue('new_url', ''),
                // Admin directory siempre deshabilitado (se preserva del backup)
                'migrate_admin_dir' => false, // Siempre false, ignoramos el valor del formulario
                'old_admin_dir' => '', // No necesario
                'new_admin_dir' => '', // No necesario
                // Preserve DB config siempre obligatorio
                'preserve_db_config' => true, // Siempre true, es obligatorio
                'configurations' => json_decode(Tools::getValue('configurations', '{}'), true) ?: []
            ];

            // Log de configuración aplicada
            $this->logger->info("Migration configuration applied", [
                'migrate_urls' => $migrationConfig['migrate_urls'],
                'old_url' => $migrationConfig['old_url'] ?: '(auto-detect)',
                'new_url' => $migrationConfig['new_url'] ?: '(auto-detect)', 
                'migrate_admin_dir' => $migrationConfig['migrate_admin_dir'],
                'preserve_db_config' => $migrationConfig['preserve_db_config']
            ]);

            // Verificar que se subió un archivo
            if (!isset($_FILES['backup_file'])) {
                throw new Exception('No se ha seleccionado ningún archivo');
            }
            
            $uploadedFile = $_FILES['backup_file'];
            
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error al subir el archivo: ' . $this->getUploadError($uploadedFile['error']));
            }
            
            // Verificar que es un archivo ZIP
            $fileInfo = pathinfo($uploadedFile['name']);
            if (strtolower($fileInfo['extension']) !== 'zip') {
                throw new Exception('El archivo debe ser un ZIP válido');
            }
            
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $tempZipPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_migration_' . time() . '.zip';
            
            // Mover archivo subido
            if (!move_uploaded_file($uploadedFile['tmp_name'], $tempZipPath)) {
                throw new Exception('Error al mover el archivo subido');
            }
            
            // Extraer y procesar el ZIP
            $zip = new ZipArchive();
            $result = $zip->open($tempZipPath);
            
            if ($result !== TRUE) {
                @unlink($tempZipPath);
                throw new Exception('No se pudo abrir el archivo ZIP: ' . $this->getZipError($result));
            }
            
            // Verificar estructura del backup
            $hasInfo = $zip->locateName('backup_info.json') !== false;
            $hasDatabase = false;
            $hasFiles = false;
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, 'database/') === 0) {
                    $hasDatabase = true;
                }
                if (strpos($filename, 'files/') === 0) {
                    $hasFiles = true;
                }
            }
            
            if (!$hasInfo || !$hasDatabase || !$hasFiles) {
                $zip->close();
                @unlink($tempZipPath);
                throw new Exception('El archivo ZIP no tiene la estructura correcta de backup');
            }
            
            // Leer metadata
            $infoContent = $zip->getFromName('backup_info.json');
            $backupInfo = json_decode($infoContent, true);
            
            if (!$backupInfo) {
                $zip->close();
                @unlink($tempZipPath);
                throw new Exception('No se pudo leer la información del backup');
            }
            
            // Extraer archivos
            $extractPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_migrate_extract_' . time();
            
            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                @unlink($tempZipPath);
                throw new Exception('Error al extraer los archivos del backup');
            }
            
            $zip->close();
            
                    // Obtener rutas de archivos extraídos con auto-discovery como fallback
        $dbSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'database';
        $filesSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'files';
        
        $dbFiles = glob($dbSourcePath . DIRECTORY_SEPARATOR . '*');
        $filesFiles = glob($filesSourcePath . DIRECTORY_SEPARATOR . '*');
        
        // Log detailed information about extracted files for debugging
        $this->logger->info("Extracted files debugging information", [
            'db_source_path' => $dbSourcePath,
            'files_source_path' => $filesSourcePath,
            'db_files_found' => !empty($dbFiles) ? array_map('basename', $dbFiles) : [],
            'files_files_found' => !empty($filesFiles) ? array_map('basename', $filesFiles) : [],
            'db_files_count' => count($dbFiles),
            'files_files_count' => count($filesFiles)
        ]);
        
        // Try automatic discovery if standard approach fails
        if (empty($dbFiles) || empty($filesFiles)) {
            $this->logger->warning("Standard file discovery failed, attempting automatic discovery");
            
            try {
                $discoveredFiles = $this->findBackupFilesAutomatically($extractPath);
                $dbFile = $discoveredFiles['database'];
                $filesFile = $discoveredFiles['files'];
                
                $this->logger->info("Automatic discovery successful", [
                    'db_file' => basename($dbFile),
                    'files_file' => basename($filesFile)
                ]);
            } catch (Exception $e) {
                $this->removeDirectoryRecursively($extractPath);
                @unlink($tempZipPath);
                throw new Exception('Archivos de backup incompletos: ' . $e->getMessage());
            }
        } else {
            // Use standard approach
            $dbFile = $dbFiles[0];
            $filesFile = $filesFiles[0];
        }
            
            if (!file_exists($dbFile)) {
                $this->removeDirectoryRecursively($extractPath);
                @unlink($tempZipPath);
                throw new Exception("Database backup file does not exist at expected location: " . $dbFile);
            }
            
            if (!file_exists($filesFile)) {
                $this->removeDirectoryRecursively($extractPath);
                @unlink($tempZipPath);
                throw new Exception("Files backup does not exist at expected location: " . $filesFile);
            }
            
            if (!is_readable($dbFile)) {
                $this->removeDirectoryRecursively($extractPath);
                @unlink($tempZipPath);
                throw new Exception("Database backup file is not readable: " . $dbFile);
            }
            
            if (!is_readable($filesFile)) {
                $this->removeDirectoryRecursively($extractPath);
                @unlink($tempZipPath);
                throw new Exception("Files backup file is not readable: " . $filesFile);
            }

            $this->logger->info("File validation completed successfully", [
                'db_file' => $dbFile,
                'db_file_size' => filesize($dbFile),
                'files_file' => $filesFile,
                'files_file_size' => filesize($filesFile)
            ]);

            // Siempre ejecutar migración de base de datos para actualizar shop_url al menos
            $this->logger->info("Performing database migration");
            
            if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
                $dbMigrator = new \PrestaShop\Module\PsCopia\Migration\DatabaseMigrator($this->backupContainer, $this->logger);
                $dbMigrator->migrateDatabase($dbFile, $migrationConfig);
            } else {
                throw new Exception('DatabaseMigrator class not found');
            }

            // Migrar archivos si está habilitado
            if ($migrationConfig['migrate_admin_dir'] || !empty($migrationConfig['file_mappings'])) {
                $this->logger->info("Performing files migration", [
                    'files_file_path' => $filesFile,
                    'migrate_admin_dir' => $migrationConfig['migrate_admin_dir'],
                    'file_mappings_count' => !empty($migrationConfig['file_mappings']) ? count($migrationConfig['file_mappings']) : 0
                ]);
                
                try {
                    if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
                        $filesMigrator = new \PrestaShop\Module\PsCopia\Migration\FilesMigrator($this->backupContainer, $this->logger);
                        $filesMigrator->migrateFiles($filesFile, $migrationConfig);
                    } else {
                        throw new Exception('FilesMigrator class not found');
                    }
                } catch (Exception $e) {
                    $this->logger->error("Files migration failed, falling back to simple restoration: " . $e->getMessage());
                    
                    // Fallback: try to restore files without migration
                    $this->logger->info("Attempting fallback files restoration without migration");
                    $this->restoreFilesFromPath($filesFile);
                }
            } else {
                // Restaurar archivos sin migración
                $this->logger->info("Restoring files without migration");
                $this->restoreFilesFromPath($filesFile);
            }

            // Generar nombre para el backup migrado
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'migrated_backup_' . $timestamp;
            
            // Actualizar metadata
            $newBackupData = [
                'backup_name' => $newBackupName,
                'database_file' => basename($dbFile),
                'files_file' => basename($filesFile),
                'created_at' => date('Y-m-d H:i:s'),
                'type' => 'complete',
                'imported_from' => $uploadedFile['name'],
                'original_backup' => $backupInfo['backup_name'] ?? 'unknown',
                'migration_applied' => true,
                'migration_config' => $migrationConfig
            ];
            
            $this->saveBackupMetadata($newBackupData);
            
            // Limpiar archivos temporales
            $this->removeDirectoryRecursively($extractPath);
            @unlink($tempZipPath);
            
            $this->logger->info("Backup import with migration completed successfully", [
                'new_backup_name' => $newBackupName,
                'original_filename' => $uploadedFile['name'],
                'migration_config' => $migrationConfig
            ]);
            
            $this->ajaxSuccess('Backup importado y migrado correctamente como: ' . $newBackupName, [
                'backup_name' => $newBackupName,
                'imported_from' => $uploadedFile['name'],
                'migration_applied' => true
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Backup import with migration failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Restore files from a specific file path (used during migration)
     *
     * @param string $filesBackupPath Full path to the files backup file
     * @throws Exception
     */
    private function restoreFilesFromPath(string $filesBackupPath): void
    {
        if (!file_exists($filesBackupPath)) {
            throw new Exception("Files backup does not exist: " . $filesBackupPath);
        }

        if (!extension_loaded('zip')) {
            throw new Exception('ZIP PHP extension is not installed');
        }

        $this->logger->info("Restoring files from path: " . $filesBackupPath);

        $zip = new ZipArchive();
        $result = $zip->open($filesBackupPath);
        
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
     * Try to find backup files automatically if exact names don't match
     * This helps recover from filename generation issues
     *
     * @param string $extractPath Base extraction path
     * @return array Array with 'database' and 'files' paths, or throws exception
     * @throws Exception
     */
    private function findBackupFilesAutomatically(string $extractPath): array
    {
        $this->logger->info("Attempting automatic backup file discovery", ['extract_path' => $extractPath]);
        
        $dbSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'database';
        $filesSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'files';
        
        $result = ['database' => null, 'files' => null];
        
        // Find database files
        if (is_dir($dbSourcePath)) {
            $dbFiles = array_diff(scandir($dbSourcePath), ['.', '..']);
            $dbFiles = array_filter($dbFiles, function($file) use ($dbSourcePath) {
                $fullPath = $dbSourcePath . DIRECTORY_SEPARATOR . $file;
                return is_file($fullPath) && (
                    strpos($file, '.sql') !== false || 
                    strpos($file, '.gz') !== false ||
                    strpos($file, 'db_') !== false ||
                    strpos($file, 'database') !== false
                );
            });
            
            if (!empty($dbFiles)) {
                $result['database'] = $dbSourcePath . DIRECTORY_SEPARATOR . reset($dbFiles);
                $this->logger->info("Auto-discovered database file", ['file' => reset($dbFiles)]);
            }
        }
        
        // Find files backup
        if (is_dir($filesSourcePath)) {
            $filesFiles = array_diff(scandir($filesSourcePath), ['.', '..']);
            $filesFiles = array_filter($filesFiles, function($file) use ($filesSourcePath) {
                $fullPath = $filesSourcePath . DIRECTORY_SEPARATOR . $file;
                return is_file($fullPath) && (
                    strpos($file, '.zip') !== false ||
                    strpos($file, 'files_') !== false ||
                    strpos($file, 'backup') !== false
                );
            });
            
            if (!empty($filesFiles)) {
                $result['files'] = $filesSourcePath . DIRECTORY_SEPARATOR . reset($filesFiles);
                $this->logger->info("Auto-discovered files backup", ['file' => reset($filesFiles)]);
            }
        }
        
        // Validate results
        if (!$result['database'] || !file_exists($result['database'])) {
            throw new Exception("Could not find database backup file in extracted content. Directory structure: " . 
                json_encode($this->getDirectoryStructure($extractPath)));
        }
        
        if (!$result['files'] || !file_exists($result['files'])) {
            throw new Exception("Could not find files backup in extracted content. Directory structure: " . 
                json_encode($this->getDirectoryStructure($extractPath)));
        }
        
        $this->logger->info("Automatic file discovery successful", $result);
        return $result;
    }

    /**
     * Get directory structure for debugging
     *
     * @param string $path
     * @return array
     */
    private function getDirectoryStructure(string $path): array
    {
        $structure = [];
        
        if (!is_dir($path)) {
            return ['error' => 'Not a directory: ' . $path];
        }
        
        try {
            $items = array_diff(scandir($path), ['.', '..']);
            
            foreach ($items as $item) {
                $fullPath = $path . DIRECTORY_SEPARATOR . $item;
                if (is_dir($fullPath)) {
                    $structure['directories'][] = $item;
                    // Get one level deep for directories
                    $subItems = array_diff(scandir($fullPath), ['.', '..']);
                    $structure['directory_contents'][$item] = array_slice($subItems, 0, 10); // Limit to first 10 items
                } else {
                    $structure['files'][] = $item;
                }
            }
        } catch (Exception $e) {
            $structure['error'] = $e->getMessage();
        }
        
        return $structure;
    }

    /**
     * Handle scanning for uploaded ZIP files in server directory
     */
    private function handleScanServerUploads(): void
    {
        // Configurar timeout generoso para el escaneo
        $oldTimeLimit = ini_get('max_execution_time');
        set_time_limit(120); // 2 minutos máximo para escaneo
        
        $this->logger->info("Starting server uploads directory scan");
        $startTime = microtime(true);
        
        // Log específico para debugging
        error_log("PS_COPIA: Iniciando escaneo de uploads del servidor - " . date('Y-m-d H:i:s'));

        try {
            // Optimizar para operaciones potencialmente largas
            $this->optimizeForLargeOperations();
            
            $uploadsPath = $this->getServerUploadsPath();
            $this->ensureUploadsDirectoryExists($uploadsPath);
            
            $this->logger->info("Uploads directory confirmed", ['path' => $uploadsPath]);
            
            $zipFiles = $this->scanForZipFiles($uploadsPath);
            
            $elapsed = microtime(true) - $startTime;
            $this->logger->info("Scan operation completed", [
                'duration' => round($elapsed, 2) . 's',
                'files_found' => count($zipFiles)
            ]);
            
            // Agregar información adicional para debugging
            $response = [
                'uploads_path' => $uploadsPath,
                'zip_files' => $zipFiles,
                'count' => count($zipFiles),
                'scan_duration' => round($elapsed, 2),
                'scan_time' => date('Y-m-d H:i:s')
            ];
            
            // Si no se encontraron archivos, dar más información
            if (empty($zipFiles)) {
                $response['message'] = 'No se encontraron archivos ZIP en el directorio. Sube archivos mediante FTP/SFTP a: ' . $uploadsPath;
                $this->logger->info("No ZIP files found in uploads directory");
            } else {
                $validCount = count(array_filter($zipFiles, function($file) {
                    return $file['is_valid_backup'] ?? false;
                }));
                $response['valid_backups'] = $validCount;
                $this->logger->info("Valid backup files found", ['count' => $validCount]);
            }
            
            $this->ajaxSuccess('Escaneo completado', $response);
            
        } catch (Exception $e) {
            $elapsed = microtime(true) - $startTime;
            $this->logger->error("Server uploads scan failed", [
                'error' => $e->getMessage(),
                'duration' => round($elapsed, 2) . 's',
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->ajaxError('Error durante el escaneo: ' . $e->getMessage());
        } finally {
            // Restaurar timeout original
            set_time_limit((int)$oldTimeLimit);
        }
    }

    /**
     * Handle importing ZIP from server uploads directory
     */
    private function handleImportFromServer(): void
    {
        $filename = Tools::getValue('filename');
        
        if (empty($filename)) {
            $this->ajaxError("Nombre de archivo requerido");
            return;
        }

        $this->logger->info("Importing backup from server uploads", ['filename' => $filename]);

        try {
            // Optimizar para operaciones grandes
            $this->optimizeForLargeOperations();
            
            $uploadsPath = $this->getServerUploadsPath();
            $zipPath = $uploadsPath . DIRECTORY_SEPARATOR . $filename;
            
            // Verificar que el archivo existe y es seguro
            if (!$this->validateServerUploadFile($zipPath, $filename)) {
                throw new Exception('Archivo no válido o no encontrado');
            }
            
            // Verificar integridad del ZIP
            if (!$this->verifyZipIntegrity($zipPath)) {
                throw new Exception('El archivo ZIP está corrupto o dañado');
            }
            
            $fileSize = filesize($zipPath);
            $this->logger->info("Processing server upload", [
                'filename' => $filename,
                'size' => $this->formatBytes($fileSize)
            ]);
            
            // Determinar si es un archivo grande
            $isLargeFile = $fileSize > 100 * 1024 * 1024; // 100MB
            
            if ($isLargeFile) {
                $this->logger->info("Large file detected, using streaming import");
                $this->processLargeServerUpload($zipPath, $filename);
            } else {
                $this->processStandardServerUpload($zipPath, $filename);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Server upload import failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle deleting ZIP from server uploads directory
     */
    private function handleDeleteServerUpload(): void
    {
        $filename = Tools::getValue('filename');
        
        if (empty($filename)) {
            $this->ajaxError("Nombre de archivo requerido");
            return;
        }

        try {
            $uploadsPath = $this->getServerUploadsPath();
            $zipPath = $uploadsPath . DIRECTORY_SEPARATOR . $filename;
            
            if (!$this->validateServerUploadFile($zipPath, $filename)) {
                throw new Exception('Archivo no válido o no encontrado');
            }
            
            if (!@unlink($zipPath)) {
                throw new Exception('No se pudo eliminar el archivo');
            }
            
            $this->logger->info("Server upload deleted", ['filename' => $filename]);
            $this->ajaxSuccess('Archivo eliminado correctamente');
            
        } catch (Exception $e) {
            $this->logger->error("Server upload deletion failed: " . $e->getMessage());
            $this->ajaxError($e->getMessage());
        }
    }

    /**
     * Get server uploads directory path
     * Uses admin directory for enhanced security
     *
     * @return string
     */
    private function getServerUploadsPath(): string
    {
        // Use the uploads path defined in BackupContainer for consistency
        return $this->backupContainer->getProperty(BackupContainer::UPLOADS_PATH);
    }

    /**
     * Ensure uploads directory exists
     *
     * @param string $uploadsPath
     * @throws Exception
     */
    private function ensureUploadsDirectoryExists(string $uploadsPath): void
    {
        // The uploads directory and security files are now automatically created
        // by BackupContainer::initDirectories() when the module is used
        // This method just ensures the directory exists and is writable
        
        if (!is_dir($uploadsPath)) {
            throw new Exception('El directorio de uploads no existe: ' . $uploadsPath . '. Esto debería haberse creado automáticamente al activar el módulo.');
        }
        
        if (!is_writable($uploadsPath)) {
            throw new Exception('El directorio de uploads no tiene permisos de escritura: ' . $uploadsPath);
        }
    }

    /**
     * Scan for ZIP files in uploads directory
     *
     * @param string $uploadsPath
     * @return array
     */
    private function scanForZipFiles(string $uploadsPath): array
    {
        $zipFiles = [];
        
        if (!is_dir($uploadsPath)) {
            $this->logger->debug("Uploads directory does not exist", ['path' => $uploadsPath]);
            return $zipFiles;
        }
        
        try {
            $files = scandir($uploadsPath);
            if (!$files) {
                $this->logger->warning("Cannot scan uploads directory", ['path' => $uploadsPath]);
                return $zipFiles;
            }
            
            $this->logger->info("Scanning uploads directory", [
                'path' => $uploadsPath,
                'total_items' => count($files) - 2 // Excluir . y ..
            ]);
            
            error_log("PS_COPIA: Directorio encontrado con " . (count($files) - 2) . " elementos");
            
            $processedCount = 0;
            $validCount = 0;
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php') {
                    continue;
                }
                
                $filePath = $uploadsPath . DIRECTORY_SEPARATOR . $file;
                
                if (!is_file($filePath)) {
                    continue;
                }
                
                // Verificar extensión ZIP
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'zip') {
                    $this->logger->debug("Skipping non-ZIP file", ['file' => $file]);
                    continue;
                }
                
                $processedCount++;
                
                try {
                    $fileSize = filesize($filePath);
                    $fileTime = filemtime($filePath);
                    
                    if ($fileSize === false || $fileTime === false) {
                        $this->logger->warning("Cannot get file info", ['file' => $file]);
                        continue;
                    }
                    
                    $this->logger->debug("Processing ZIP file", [
                        'file' => $file,
                        'size' => $this->formatBytes($fileSize)
                    ]);
                    
                    // Verificar si es un backup válido revisando la estructura básica
                    // Usar try-catch específico para la validación con timeout agresivo
                    $isValidBackup = false;
                    $validationStart = microtime(true);
                    
                    try {
                        // Timeout super agresivo por archivo
                        $oldLimit = ini_get('max_execution_time');
                        set_time_limit(5); // Solo 5 segundos por archivo
                        
                        $isValidBackup = $this->quickValidateBackupZip($filePath);
                        if ($isValidBackup) {
                            $validCount++;
                        }
                        
                        set_time_limit((int)$oldLimit); // Restaurar
                        
                    } catch (Exception $e) {
                        $elapsed = microtime(true) - $validationStart;
                        $this->logger->warning("Validation failed for ZIP file", [
                            'file' => $file,
                            'error' => $e->getMessage(),
                            'elapsed' => round($elapsed, 2) . 's'
                        ]);
                        $isValidBackup = false;
                        
                        // Restaurar timeout
                        set_time_limit((int)$oldLimit);
                        
                        // Si tardó mucho, asumir que es válido por el nombre
                        if ($elapsed > 3) {
                            $filename = basename($filePath);
                            $isValidBackup = (
                                strpos($filename, 'backup') !== false ||
                                strpos($filename, 'export') !== false
                            );
                            $this->logger->info("Fallback validation by filename", [
                                'file' => $file,
                                'assumed_valid' => $isValidBackup
                            ]);
                        }
                    }
                    
                    $zipFiles[] = [
                        'filename' => $file,
                        'size' => $fileSize,
                        'size_formatted' => $this->formatBytes($fileSize),
                        'modified' => date('Y-m-d H:i:s', $fileTime),
                        'is_large' => $fileSize > 100 * 1024 * 1024,
                        'is_valid_backup' => $isValidBackup,
                        'path' => $filePath,
                        'status' => $isValidBackup ? 'valid' : 'invalid'
                    ];
                    
                } catch (Exception $e) {
                    $this->logger->warning("Error processing ZIP file", [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Añadir el archivo con información limitada
                    $zipFiles[] = [
                        'filename' => $file,
                        'size' => 0,
                        'size_formatted' => 'Error',
                        'modified' => 'Error',
                        'is_large' => false,
                        'is_valid_backup' => false,
                        'path' => $filePath,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Ordenar por fecha de modificación (más recientes primero)
            usort($zipFiles, function($a, $b) {
                // Los archivos con error van al final
                if (isset($a['error']) && !isset($b['error'])) return 1;
                if (!isset($a['error']) && isset($b['error'])) return -1;
                
                // Ordenar por fecha
                return strcmp($b['modified'], $a['modified']);
            });
            
            $this->logger->info("Scan completed", [
                'total_processed' => $processedCount,
                'valid_backups' => $validCount,
                'total_results' => count($zipFiles)
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error during directory scan", [
                'path' => $uploadsPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return $zipFiles;
    }

    /**
     * Quick validation of backup ZIP structure without full extraction
     *
     * @param string $zipPath
     * @return bool
     */
    private function quickValidateBackupZip(string $zipPath): bool
    {
        // Configurar timeout para evitar que se cuelgue
        $oldTimeLimit = ini_get('max_execution_time');
        set_time_limit(5); // Máximo 5 segundos para validación
        
        try {
            // Verificaciones previas básicas
            if (!is_readable($zipPath)) {
                return false;
            }
            
            // Verificar tamaño mínimo (un ZIP válido debe tener al menos algunos KB)
            $fileSize = filesize($zipPath);
            if ($fileSize < 1024) { // Menos de 1KB es sospechoso
                return false;
            }
            
            // NUEVA ESTRATEGIA: Para cualquier archivo > 1MB, usar solo validación por nombre
            // Esto evita completamente los problemas de timeout con ZipArchive
            if ($fileSize > 1024 * 1024) { // Más de 1MB
                $this->logger->debug("Large ZIP file detected, using filename-only validation", [
                    'file' => basename($zipPath),
                    'size' => $this->formatBytes($fileSize)
                ]);
                
                $filename = basename($zipPath);
                $isLikelyBackup = (
                    strpos($filename, 'backup') !== false ||
                    strpos($filename, 'export') !== false ||
                    preg_match('/\d{4}-\d{2}-\d{2}/', $filename) || // Contiene fecha
                    preg_match('/\d{2}-\d{2}-\d{4}/', $filename) || // Otra formato de fecha
                    strpos($filename, 'ps_copia') !== false ||
                    strpos($filename, 'prestashop') !== false
                );
                
                $this->logger->debug("Filename-only validation result", [
                    'file' => $filename,
                    'is_likely_backup' => $isLikelyBackup
                ]);
                
                return $isLikelyBackup;
            }
            
            $zip = new ZipArchive();
            $result = $zip->open($zipPath, ZipArchive::RDONLY);
            
            if ($result !== TRUE) {
                $this->logger->debug("ZIP open failed", [
                    'file' => basename($zipPath),
                    'error' => $this->getZipError($result)
                ]);
                return false;
            }
            
            // Verificaciones de seguridad básicas
            if ($zip->numFiles === 0) {
                $zip->close();
                return false;
            }
            
            // Verificar que tiene la estructura básica de backup
            $hasInfo = false;
            $hasDatabase = false;
            $hasFiles = false;
            
            // Revisar solo los primeros archivos para ser eficiente y evitar timeouts
            $maxCheck = min($zip->numFiles, 5); // Reducido a 5 para ser más rápido
            $startTime = microtime(true);
            
            for ($i = 0; $i < $maxCheck; $i++) {
                // Verificar timeout cada 3 archivos
                if ($i % 3 === 0) {
                    $elapsed = microtime(true) - $startTime;
                    if ($elapsed > 5) { // Más de 5 segundos
                        $this->logger->debug("Validation timeout, aborting", [
                            'file' => basename($zipPath),
                            'elapsed' => $elapsed
                        ]);
                        break;
                    }
                }
                
                $filename = $zip->getNameIndex($i);
                if ($filename === false) {
                    continue;
                }
                
                // Verificar estructura básica
                if ($filename === 'backup_info.json') {
                    $hasInfo = true;
                } elseif (strpos($filename, 'database/') === 0) {
                    $hasDatabase = true;
                } elseif (strpos($filename, 'files/') === 0) {
                    $hasFiles = true;
                }
                
                // Salir temprano si encontramos todo lo necesario
                if ($hasInfo && ($hasDatabase || $hasFiles)) {
                    break;
                }
            }
            
            $zip->close();
            
            $isValid = $hasInfo && ($hasDatabase || $hasFiles);
            
            $this->logger->debug("ZIP validation completed", [
                'file' => basename($zipPath),
                'is_valid' => $isValid,
                'has_info' => $hasInfo,
                'has_database' => $hasDatabase,
                'has_files' => $hasFiles
            ]);
            
            return $isValid;
            
        } catch (Exception $e) {
            $this->logger->warning("ZIP validation error", [
                'file' => basename($zipPath),
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            // Restaurar timeout original
            set_time_limit((int)$oldTimeLimit);
        }
    }

    /**
     * Validate server upload file for security
     *
     * @param string $zipPath
     * @param string $filename
     * @return bool
     */
    private function validateServerUploadFile(string $zipPath, string $filename): bool
    {
        // Verificar que el archivo existe
        if (!file_exists($zipPath)) {
            return false;
        }
        
        // Verificar que está dentro del directorio de uploads (prevenir path traversal)
        $uploadsPath = $this->getServerUploadsPath();
        $realZipPath = realpath($zipPath);
        $realUploadsPath = realpath($uploadsPath);
        
        if (!$realZipPath || !$realUploadsPath || strpos($realZipPath, $realUploadsPath) !== 0) {
            return false;
        }
        
        // Verificar extensión
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            return false;
        }
        
        // Verificar que es un archivo legible
        if (!is_readable($zipPath)) {
            return false;
        }
        
        return true;
    }

    /**
     * Process standard server upload for smaller files
     *
     * @param string $zipPath
     * @param string $originalFilename
     * @throws Exception
     */
    private function processStandardServerUpload(string $zipPath, string $originalFilename): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        
        if ($result !== TRUE) {
            throw new Exception('No se pudo abrir el archivo ZIP: ' . $this->getZipError($result));
        }
        
        try {
            // Verificar estructura del backup
            if (!$this->validateBackupStructure($zip)) {
                throw new Exception('El archivo ZIP no tiene la estructura correcta de backup');
            }
            
            // Leer metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generar nuevo nombre único
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'server_backup_' . $timestamp;
            
            // Extraer archivos usando método estándar
            $this->extractBackupStandard($zip, $newBackupName, $originalFilename, $backupInfo, $zipPath);
            
        } finally {
            $zip->close();
        }
    }

    /**
     * Process large server upload using streaming
     *
     * @param string $zipPath
     * @param string $originalFilename
     * @throws Exception
     */
    private function processLargeServerUpload(string $zipPath, string $originalFilename): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        
        if ($result !== TRUE) {
            throw new Exception('No se pudo abrir el archivo ZIP: ' . $this->getZipError($result));
        }
        
        try {
            // Verificar estructura del backup
            if (!$this->validateBackupStructure($zip)) {
                throw new Exception('El archivo ZIP no tiene la estructura correcta de backup');
            }
            
            // Leer metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generar nuevo nombre único
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'server_backup_' . $timestamp;
            
            // Extraer usando streaming para archivos grandes
            $this->extractBackupStreaming($zip, $newBackupName, $originalFilename, $backupInfo, $zipPath);
            
        } finally {
            $zip->close();
        }
    }

    /**
     * Delete server upload file after successful import
     *
     * @param string $zipPath
     * @param string $originalFilename
     */
    private function deleteServerUploadFileAfterImport(string $zipPath, string $originalFilename): void
    {
        try {
            // Verificar que el archivo existe y es seguro para eliminar
            if (!$this->validateServerUploadFile($zipPath, $originalFilename)) {
                $this->logger->warning("Cannot delete server upload file - validation failed", [
                    'filename' => $originalFilename,
                    'path' => $zipPath
                ]);
                return;
            }
            
            // Eliminar el archivo ZIP original
            if (@unlink($zipPath)) {
                $this->logger->info("Server upload file deleted automatically after successful import", [
                    'filename' => $originalFilename,
                    'path' => $zipPath
                ]);
            } else {
                $this->logger->warning("Failed to delete server upload file after import", [
                    'filename' => $originalFilename,
                    'path' => $zipPath
                ]);
            }
            
        } catch (Exception $e) {
            // No queremos que un error al eliminar el archivo interrumpa la respuesta de éxito
            $this->logger->error("Error deleting server upload file after import", [
                'filename' => $originalFilename,
                'error' => $e->getMessage()
            ]);
        }
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
}
