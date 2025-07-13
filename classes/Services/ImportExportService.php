<?php
/**
 * Import/Export Service for handling backup imports and exports
 * Manages backup file transfers and processing
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Migration\DatabaseMigrator;
use PrestaShop\Module\PsCopia\Migration\FilesMigrator;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;

class ImportExportService
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
     * Export backup to downloadable ZIP
     *
     * @param string $backupName
     * @return array
     * @throws \Exception
     */
    public function exportBackup(string $backupName): array
    {
        $this->logger->info("Starting backup export", ['backup_name' => $backupName]);

        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup not found: $backupName");
        }
        
        $backupData = $metadata[$backupName];
        
        // Verify files exist
        $dbFile = $backupDir . DIRECTORY_SEPARATOR . $backupData['database_file'];
        $filesFile = $backupDir . DIRECTORY_SEPARATOR . $backupData['files_file'];
        
        if (!file_exists($dbFile)) {
            throw new \Exception("Database file not found: " . $backupData['database_file']);
        }
        
        if (!file_exists($filesFile)) {
            throw new \Exception("Files backup not found: " . $backupData['files_file']);
        }
        
        // Create temporary ZIP for export
        $exportZipPath = $backupDir . DIRECTORY_SEPARATOR . $backupName . '_export.zip';
        
        $zip = new ZipArchive();
        $result = $zip->open($exportZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot create export ZIP file: ' . ResponseHelper::getZipError($result));
        }
        
        // Add files to ZIP
        $zip->addFile($dbFile, 'database/' . basename($dbFile));
        $zip->addFile($filesFile, 'files/' . basename($filesFile));
        
        // Add metadata
        $zip->addFromString('backup_info.json', json_encode($backupData, JSON_PRETTY_PRINT));
        
        $zip->close();
        
        // Verify ZIP was created
        if (!file_exists($exportZipPath) || filesize($exportZipPath) === 0) {
            throw new \Exception('Error creating export ZIP file');
        }
        
        $this->logger->info("Backup export created successfully", [
            'backup_name' => $backupName,
            'export_file' => basename($exportZipPath),
            'size' => filesize($exportZipPath)
        ]);
        
        return [
            'download_url' => $this->getDownloadUrl($exportZipPath),
            'filename' => $backupName . '_export.zip',
            'size' => filesize($exportZipPath),
            'size_formatted' => ResponseHelper::formatBytes(filesize($exportZipPath))
        ];
    }

    /**
     * Import backup from uploaded file
     *
     * @param array $uploadedFile
     * @return array
     * @throws \Exception
     */
    public function importBackup(array $uploadedFile): array
    {
        $this->logger->info("Starting backup import");

        $this->optimizeForLargeOperations();
        
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('Upload error: ' . ResponseHelper::getUploadError($uploadedFile['error']));
        }
        
        // Verify ZIP file
        $fileInfo = pathinfo($uploadedFile['name']);
        if (strtolower($fileInfo['extension']) !== 'zip') {
            throw new \Exception('File must be a valid ZIP');
        }
        
        $fileSize = $uploadedFile['size'];
        $this->logger->info("Processing backup file", [
            'filename' => $uploadedFile['name'],
            'size' => ResponseHelper::formatBytes($fileSize)
        ]);
        
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $tempZipPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_import_' . time() . '.zip';
        
        // Move uploaded file
        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempZipPath)) {
            throw new \Exception('Error moving uploaded file');
        }
        
        // Verify ZIP integrity
        if (!$this->validationService->verifyZipIntegrity($tempZipPath)) {
            @unlink($tempZipPath);
            throw new \Exception('ZIP file is corrupted');
        }

        // Determine processing method based on file size
        $isLargeFile = $fileSize > 100 * 1024 * 1024; // 100MB
        
        if ($isLargeFile) {
            $this->logger->info("Large file detected, using streaming import");
            return $this->processLargeBackupImport($tempZipPath, $uploadedFile['name']);
        } else {
            return $this->processStandardBackupImport($tempZipPath, $uploadedFile['name']);
        }
    }

    /**
     * Import backup with migration
     *
     * @param array $uploadedFile
     * @param array $migrationConfig
     * @return array
     * @throws \Exception
     */
    public function importBackupWithMigration(array $uploadedFile, array $migrationConfig = []): array
    {
        $this->logger->info("Starting backup import with migration");

        // Set intelligent defaults for migration
        $migrationConfig = array_merge([
            'migrate_urls' => true,
            'old_url' => '',
            'new_url' => '',
            'migrate_admin_dir' => false,
            'preserve_db_config' => true,
            'configurations' => []
        ], $migrationConfig);

        $this->logger->info("Migration configuration applied", $migrationConfig);

        // Verify uploaded file
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('Upload error: ' . ResponseHelper::getUploadError($uploadedFile['error']));
        }
        
        $fileInfo = pathinfo($uploadedFile['name']);
        if (strtolower($fileInfo['extension']) !== 'zip') {
            throw new \Exception('File must be a valid ZIP');
        }
        
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $tempZipPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_migration_' . time() . '.zip';
        
        // Move uploaded file
        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempZipPath)) {
            throw new \Exception('Error moving uploaded file');
        }
        
        // Extract and process the ZIP
        $zip = new ZipArchive();
        $result = $zip->open($tempZipPath);
        
        if ($result !== TRUE) {
            @unlink($tempZipPath);
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }
        
        // Verify backup structure
        if (!$this->validationService->validateBackupStructure($zip)) {
            $zip->close();
            @unlink($tempZipPath);
            throw new \Exception('ZIP file does not have correct backup structure');
        }
        
        // Read metadata
        $infoContent = $zip->getFromName('backup_info.json');
        $backupInfo = json_decode($infoContent, true);
        
        if (!$backupInfo) {
            $zip->close();
            @unlink($tempZipPath);
            throw new \Exception('Cannot read backup information');
        }
        
        // Extract files
        $extractPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_migrate_extract_' . time();
        
        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            @unlink($tempZipPath);
            throw new \Exception('Error extracting backup files');
        }
        
        $zip->close();
        
        try {
            // Get extracted file paths
            $discoveredFiles = $this->findBackupFilesAutomatically($extractPath);
            $dbFile = $discoveredFiles['database'];
            $filesFile = $discoveredFiles['files'];
            
            // Perform database migration
            $this->logger->info("Performing database migration");
            
            if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
                $dbMigrator = new DatabaseMigrator($this->backupContainer, $this->logger);
                $dbMigrator->migrateDatabase($dbFile, $migrationConfig);
            } else {
                throw new \Exception('DatabaseMigrator class not found');
            }

            // Migrate files if needed
            if ($migrationConfig['migrate_admin_dir'] || !empty($migrationConfig['file_mappings'])) {
                $this->logger->info("Performing files migration");
                
                if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
                    $filesMigrator = new FilesMigrator($this->backupContainer, $this->logger);
                    $filesMigrator->migrateFiles($filesFile, $migrationConfig);
                } else {
                    throw new \Exception('FilesMigrator class not found');
                }
            } else {
                // Restore files without migration
                $this->logger->info("Restoring files without migration");
                $this->restoreFilesFromPath($filesFile);
            }

            // Generate name for migrated backup
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'migrated_backup_' . $timestamp;
            
            // Update metadata
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
            
            $this->logger->info("Backup import with migration completed successfully");
            
            return [
                'backup_name' => $newBackupName,
                'imported_from' => $uploadedFile['name'],
                'migration_applied' => true
            ];
            
        } finally {
            // Clean up temporary files
            $this->removeDirectoryRecursively($extractPath);
            @unlink($tempZipPath);
        }
    }

    /**
     * Process standard backup import
     *
     * @param string $tempZipPath
     * @param string $originalFilename
     * @return array
     * @throws \Exception
     */
    private function processStandardBackupImport(string $tempZipPath, string $originalFilename): array
    {
        $zip = new ZipArchive();
        $result = $zip->open($tempZipPath);
        
        if ($result !== TRUE) {
            @unlink($tempZipPath);
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }
        
        try {
            // Verify backup structure
            if (!$this->validationService->validateBackupStructure($zip)) {
                throw new \Exception('ZIP file does not have correct backup structure');
            }
            
            // Read metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generate unique name
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'imported_backup_' . $timestamp;
            
            // Extract files
            $result = $this->extractBackupStandard($zip, $newBackupName, $originalFilename, $backupInfo);
            
            return $result;
            
        } finally {
            $zip->close();
            @unlink($tempZipPath);
        }
    }

    /**
     * Process large backup import
     *
     * @param string $tempZipPath
     * @param string $originalFilename
     * @return array
     * @throws \Exception
     */
    private function processLargeBackupImport(string $tempZipPath, string $originalFilename): array
    {
        $zip = new ZipArchive();
        $result = $zip->open($tempZipPath);
        
        if ($result !== TRUE) {
            @unlink($tempZipPath);
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }
        
        try {
            // Verify backup structure
            if (!$this->validationService->validateBackupStructure($zip)) {
                throw new \Exception('ZIP file does not have correct backup structure');
            }
            
            // Read metadata
            $backupInfo = $this->extractBackupInfo($zip);
            
            // Generate unique name
            $timestamp = date('Y-m-d_H-i-s');
            $newBackupName = 'imported_backup_' . $timestamp;
            
            // Extract using streaming
            $result = $this->extractBackupStreaming($zip, $newBackupName, $originalFilename, $backupInfo);
            
            return $result;
            
        } finally {
            $zip->close();
            @unlink($tempZipPath);
        }
    }

    /**
     * Extract backup info from ZIP
     *
     * @param ZipArchive $zip
     * @return array
     * @throws \Exception
     */
    private function extractBackupInfo(ZipArchive $zip): array
    {
        $infoContent = $zip->getFromName('backup_info.json');
        $backupInfo = json_decode($infoContent, true);
        
        if (!$backupInfo) {
            throw new \Exception('Cannot read backup information');
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
     * @return array
     * @throws \Exception
     */
    private function extractBackupStandard(ZipArchive $zip, string $newBackupName, string $originalFilename, array $backupInfo): array
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $extractPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_extract_' . time();
        
        if (!$zip->extractTo($extractPath)) {
            throw new \Exception('Error extracting backup files');
        }
        
        try {
            return $this->processExtractedFiles($extractPath, $newBackupName, $originalFilename, $backupInfo);
        } finally {
            $this->removeDirectoryRecursively($extractPath);
        }
    }

    /**
     * Extract backup using streaming
     *
     * @param ZipArchive $zip
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @return array
     * @throws \Exception
     */
    private function extractBackupStreaming(ZipArchive $zip, string $newBackupName, string $originalFilename, array $backupInfo): array
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        // Find backup files in ZIP
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
            throw new \Exception('Incomplete backup files');
        }
        
        // Extract database file using streaming
        $dbFilename = $dbFiles[0];
        $newDbFilename = $newBackupName . '_db_' . basename($dbFilename);
        $newDbPath = $backupDir . DIRECTORY_SEPARATOR . $newDbFilename;
        
        $this->logger->info("Extracting database file", [
            'db_filename' => $dbFilename,
            'output_path' => $newDbPath
        ]);
        
        $this->extractFileStreaming($zip, $dbFilename, $newDbPath);
        
        // Extract files backup using streaming
        $filesFilename = $filesFiles[0];
        $newFilesFilename = $newBackupName . '_files_' . basename($filesFilename);
        $newFilesPath = $backupDir . DIRECTORY_SEPARATOR . $newFilesFilename;
        
        $this->logger->info("Extracting files archive", [
            'files_filename' => $filesFilename,
            'output_path' => $newFilesPath
        ]);
        
        $this->extractFileStreaming($zip, $filesFilename, $newFilesPath);
        
        // Update metadata
        $this->saveImportedBackupMetadata($newBackupName, $newDbFilename, $newFilesFilename, $originalFilename, $backupInfo);
        
        $this->logger->info("Large backup import completed successfully");
        
        return [
            'backup_name' => $newBackupName,
            'imported_from' => $originalFilename
        ];
    }

    /**
     * Extract single file from ZIP using streaming
     *
     * @param ZipArchive $zip
     * @param string $filename
     * @param string $outputPath
     * @throws \Exception
     */
    private function extractFileStreaming(ZipArchive $zip, string $filename, string $outputPath): void
    {
        $stream = $zip->getStream($filename);
        if (!$stream) {
            throw new \Exception('Cannot get stream from file: ' . $filename);
        }
        
        $output = fopen($outputPath, 'wb');
        if (!$output) {
            fclose($stream);
            throw new \Exception('Cannot create output file: ' . $outputPath);
        }
        
        try {
            $chunkSize = 8192; // 8KB chunks
            $totalWritten = 0;
            
            while (!feof($stream)) {
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) {
                    throw new \Exception('Error reading stream from file: ' . $filename);
                }
                
                $written = fwrite($output, $chunk);
                if ($written === false) {
                    throw new \Exception('Error writing to file: ' . $outputPath);
                }
                
                $totalWritten += $written;
                
                // Prevent timeout and clear memory periodically
                if ($totalWritten % (1024 * 1024) === 0) { // Every MB
                    $this->preventTimeout();
                    $this->clearMemory();
                }
            }
            
            $this->logger->info("Extracted file using streaming", [
                'filename' => $filename,
                'output_path' => basename($outputPath),
                'size' => ResponseHelper::formatBytes($totalWritten)
            ]);
            
        } finally {
            fclose($stream);
            fclose($output);
        }
    }

    /**
     * Process extracted files
     *
     * @param string $extractPath
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @return array
     * @throws \Exception
     */
    private function processExtractedFiles(string $extractPath, string $newBackupName, string $originalFilename, array $backupInfo): array
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        // Move extracted files to final locations
        $dbSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'database';
        $filesSourcePath = $extractPath . DIRECTORY_SEPARATOR . 'files';
        
        $dbFiles = glob($dbSourcePath . DIRECTORY_SEPARATOR . '*');
        $filesFiles = glob($filesSourcePath . DIRECTORY_SEPARATOR . '*');
        
        if (empty($dbFiles) || empty($filesFiles)) {
            throw new \Exception('Incomplete backup files');
        }
        
        // Copy files with new names
        $newDbFilename = $newBackupName . '_db_' . basename($dbFiles[0]);
        $newFilesFilename = $newBackupName . '_files_' . basename($filesFiles[0]);
        
        $newDbPath = $backupDir . DIRECTORY_SEPARATOR . $newDbFilename;
        $newFilesPath = $backupDir . DIRECTORY_SEPARATOR . $newFilesFilename;
        
        // Use optimized copy for large files
        if (!$this->copyFileOptimized($dbFiles[0], $newDbPath)) {
            throw new \Exception('Error copying database file');
        }
        
        if (!$this->copyFileOptimized($filesFiles[0], $newFilesPath)) {
            @unlink($newDbPath);
            throw new \Exception('Error copying files backup');
        }
        
        // Update metadata
        $this->saveImportedBackupMetadata($newBackupName, $newDbFilename, $newFilesFilename, $originalFilename, $backupInfo);
        
        $this->logger->info("Backup import completed successfully");
        
        return [
            'backup_name' => $newBackupName,
            'imported_from' => $originalFilename
        ];
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
        
        // For small files, use standard copy
        if ($sourceSize < 50 * 1024 * 1024) { // 50MB
            return copy($source, $destination);
        }
        
        // For large files, use streaming
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
                
                // Prevent timeout every 10MB
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
     * Find backup files automatically
     *
     * @param string $extractPath
     * @return array
     * @throws \Exception
     */
    private function findBackupFilesAutomatically(string $extractPath): array
    {
        $this->logger->info("Finding backup files automatically", ['extract_path' => $extractPath]);
        
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
            }
        }
        
        // Validate results
        if (!$result['database'] || !file_exists($result['database'])) {
            throw new \Exception("Could not find database backup file");
        }
        
        if (!$result['files'] || !file_exists($result['files'])) {
            throw new \Exception("Could not find files backup");
        }
        
        return $result;
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
     * Save backup metadata
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
        return \Context::getContext()->link->getAdminLink('AdminPsCopiaAjax') . 
               '&action=download_export&file=' . urlencode($filename) . 
               '&token=' . \Tools::getAdminTokenLite('AdminPsCopiaAjax');
    }

    /**
     * Optimize for large operations
     */
    private function optimizeForLargeOperations(): void
    {
        $currentMemory = ini_get('memory_limit');
        if ($currentMemory !== '-1') {
            $memoryInBytes = $this->parseMemoryLimit($currentMemory);
            $recommendedMemory = max($memoryInBytes, 256 * 1024 * 1024); // Minimum 256MB
            
            if (function_exists('ini_set')) {
                @ini_set('memory_limit', $recommendedMemory);
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        
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
     * Prevent timeout
     */
    private function preventTimeout(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutes more
        }
        
        if (ob_get_level()) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * Clear memory
     */
    private function clearMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
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

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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
     * Restore files from path (used during migration)
     *
     * @param string $filesBackupPath
     * @throws \Exception
     */
    private function restoreFilesFromPath(string $filesBackupPath): void
    {
        if (!file_exists($filesBackupPath)) {
            throw new \Exception("Files backup does not exist: " . $filesBackupPath);
        }

        $this->logger->info("Restoring files from path: " . $filesBackupPath);

        $zip = new ZipArchive();
        $result = $zip->open($filesBackupPath);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Copy files to real location
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);
            
        } finally {
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully");
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
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
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
     * Download exported backup file
     *
     * @param string $filename
     * @throws \Exception
     */
    public function downloadExport(string $filename): void
    {
        // Validate filename
        if (empty($filename)) {
            throw new \Exception('Filename is required');
        }

        // Security check - prevent directory traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new \Exception('Invalid filename');
        }

        // Check if filename ends with _export.zip
        if (!preg_match('/^[a-zA-Z0-9_-]+_export\.zip$/', $filename)) {
            throw new \Exception('Invalid export filename format');
        }

        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        // Check if file exists
        if (!file_exists($filePath)) {
            throw new \Exception('Export file not found: ' . $filename);
        }

        // Check if file is readable
        if (!is_readable($filePath)) {
            throw new \Exception('Export file is not readable: ' . $filename);
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new \Exception('Export file is empty or corrupted: ' . $filename);
        }

        $this->logger->info("Starting download of export file", [
            'filename' => $filename,
            'size' => ResponseHelper::formatBytes($fileSize)
        ]);

        // Clear any output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');

        // Disable time limit for large files
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        // Use chunked reading for large files to avoid memory issues
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \Exception('Cannot open export file for reading');
        }

        try {
            $chunkSize = 8192; // 8KB chunks
            $totalSent = 0;

            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \Exception('Error reading export file');
                }

                echo $chunk;
                $totalSent += strlen($chunk);

                // Flush output to browser
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Clear memory periodically
                if ($totalSent % (1024 * 1024) === 0) { // Every MB
                    $this->clearMemory();
                }
            }

            $this->logger->info("Export file download completed successfully", [
                'filename' => $filename,
                'bytes_sent' => ResponseHelper::formatBytes($totalSent)
            ]);

        } finally {
            fclose($handle);
        }

        // Clean up the temporary export file after successful download
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        // Exit to prevent any additional output
        exit;
    }
} 