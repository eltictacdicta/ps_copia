<?php
/**
 * File Manager Service for managing server files
 * Handles server file operations like scanning, importing, and deleting
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;

class FileManagerService
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
     * Scan server uploads directory for ZIP files
     *
     * @return array
     */
    public function scanServerUploads(): array
    {
        try {
            $uploadsPath = $this->getServerUploadsPath();
            
            $this->logger->info("Starting server uploads scan", [
                'uploads_path' => $uploadsPath
            ]);
            
            // Ensure uploads directory exists
            $this->ensureUploadsDirectoryExists($uploadsPath);
            
            // Scan for ZIP files
            $zipFiles = $this->scanForZipFilesUltraLight($uploadsPath);
            
            // Count valid files
            $validCount = count(array_filter($zipFiles, function($f) { 
                return $f['is_valid_backup']; 
            }));
            
            $this->logger->info("Server uploads scan completed successfully", [
                'uploads_path' => $uploadsPath,
                'total_files' => count($zipFiles),
                'valid_files' => $validCount
            ]);
            
            return [
                'zip_files' => $zipFiles,
                'uploads_path' => str_replace(_PS_ROOT_DIR_, '', $uploadsPath),
                'count' => count($zipFiles),
                'valid_count' => $validCount
            ];
            
        } catch (\Exception $e) {
            $this->logger->error("Server uploads scan failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Import ZIP file from server uploads
     *
     * @param string $filename
     * @return array
     * @throws \Exception
     */
    public function importFromServer(string $filename): array
    {
        $this->logger->info("Starting server import", ['filename' => $filename]);

        try {
            // Optimize for current limits
            $this->optimizeForCurrentLimits();
            
            $uploadsPath = $this->getServerUploadsPath();
            $zipPath = $uploadsPath . DIRECTORY_SEPARATOR . $filename;
            
            // Validate file
            if (!$this->validationService->validateServerUploadFile($zipPath, $filename, $uploadsPath)) {
                throw new \Exception('Invalid or not found file');
            }
            
            $fileSize = filesize($zipPath);
            $this->logger->info("Processing server upload with adaptive approach", [
                'filename' => $filename,
                'size' => ResponseHelper::formatBytes($fileSize)
            ]);
            
            // Adaptive processing based on file size and server limits
            return $this->processServerUploadAdaptive($zipPath, $filename, $fileSize);
            
        } catch (\Exception $e) {
            $this->logger->error("Server upload import failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete ZIP file from server uploads
     *
     * @param string $filename
     * @return bool
     * @throws \Exception
     */
    public function deleteServerUpload(string $filename): bool
    {
        try {
            $uploadsPath = $this->getServerUploadsPath();
            $zipPath = $uploadsPath . DIRECTORY_SEPARATOR . $filename;
            
            if (!$this->validationService->validateServerUploadFile($zipPath, $filename, $uploadsPath)) {
                throw new \Exception('Invalid or not found file');
            }
            
            if (!@unlink($zipPath)) {
                throw new \Exception('Could not delete file');
            }
            
            $this->logger->info("Server upload deleted", ['filename' => $filename]);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Server upload deletion failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get server uploads directory path
     *
     * @return string
     */
    private function getServerUploadsPath(): string
    {
        return $this->backupContainer->getProperty(BackupContainer::UPLOADS_PATH);
    }

    /**
     * Ensure uploads directory exists and is accessible
     *
     * @param string $uploadsPath
     * @throws \Exception
     */
    private function ensureUploadsDirectoryExists(string $uploadsPath): void
    {
        $this->logger->debug("Checking uploads directory", ['path' => $uploadsPath]);
        
        if (!is_dir($uploadsPath)) {
            $this->logger->warning("Uploads directory does not exist, attempting to create", [
                'path' => $uploadsPath
            ]);
            
            try {
                if (!@mkdir($uploadsPath, 0755, true)) {
                    throw new \Exception('Could not create uploads directory: ' . $uploadsPath);
                }
                
                // Create security files
                $this->createSecurityFiles($uploadsPath);
                
                $this->logger->info("Created uploads directory successfully", [
                    'path' => $uploadsPath
                ]);
                
            } catch (\Exception $e) {
                throw new \Exception('Error creating uploads directory: ' . $e->getMessage());
            }
        }
        
        if (!is_readable($uploadsPath)) {
            throw new \Exception('Uploads directory is not readable: ' . $uploadsPath);
        }
        
        $this->logger->debug("Uploads directory check completed successfully", [
            'path' => $uploadsPath,
            'readable' => is_readable($uploadsPath),
            'writable' => is_writable($uploadsPath)
        ]);
    }
    
    /**
     * Create security files in uploads directory
     *
     * @param string $uploadsPath
     */
    private function createSecurityFiles(string $uploadsPath): void
    {
        // Create .htaccess to block direct access
        $htaccessPath = $uploadsPath . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "Order Deny,Allow\nDeny from all\n";
            @file_put_contents($htaccessPath, $htaccessContent);
        }
        
        // Create index.php to prevent directory listing
        $indexPath = $uploadsPath . DIRECTORY_SEPARATOR . 'index.php';
        if (!file_exists($indexPath)) {
            $indexContent = "<?php\n// Access denied\nheader('HTTP/1.0 403 Forbidden');\nexit;\n";
            @file_put_contents($indexPath, $indexContent);
        }
    }

    /**
     * Ultra-robust scan for ZIP files with timeout protection
     *
     * @param string $uploadsPath
     * @return array
     */
    private function scanForZipFilesUltraLight(string $uploadsPath): array
    {
        $zipFiles = [];
        $startTime = microtime(true);
        $maxExecutionTime = 25; // 25 seconds limit
        
        if (!is_dir($uploadsPath)) {
            $this->logger->debug("Uploads directory does not exist", ['path' => $uploadsPath]);
            return $zipFiles;
        }
        
        try {
            // Use opendir instead of scandir for efficiency
            $handle = opendir($uploadsPath);
            if (!$handle) {
                $this->logger->warning("Cannot open uploads directory", ['path' => $uploadsPath]);
                return $zipFiles;
            }
            
            $processedCount = 0;
            $skippedCount = 0;
            $maxFiles = 30; // Reduced to avoid timeouts
            
            $this->logger->info("Starting robust ZIP scan", [
                'uploads_path' => $uploadsPath,
                'max_files' => $maxFiles,
                'max_time' => $maxExecutionTime
            ]);
            
            while (($file = readdir($handle)) !== false && $processedCount < $maxFiles) {
                // Control total execution time
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime > $maxExecutionTime) {
                    $this->logger->warning("Scan timeout reached, stopping early", [
                        'elapsed_time' => $elapsedTime,
                        'processed_count' => $processedCount
                    ]);
                    break;
                }
                
                // Skip special files
                if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php') {
                    continue;
                }
                
                $filePath = $uploadsPath . DIRECTORY_SEPARATOR . $file;
                
                // Only process files (not directories) with operation timeout
                try {
                    $startOpTime = microtime(true);
                    
                    if (!is_file($filePath)) {
                        continue;
                    }
                    
                    // Control timeout for individual operations (2 seconds max per file)
                    if ((microtime(true) - $startOpTime) > 2) {
                        $this->logger->warning("File operation timeout", ['file' => $file]);
                        $skippedCount++;
                        continue;
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->warning("Error checking file type", [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    $skippedCount++;
                    continue;
                }
                
                // Check ZIP extension without pathinfo (faster)
                $extension = strtolower(substr($file, -4));
                if ($extension !== '.zip') {
                    continue;
                }
                
                $processedCount++;
                
                // Basic information with protection against problematic files
                $fileSize = null;
                $fileTime = null;
                $isValidBackup = false;
                
                try {
                    // Specific timeout for file operations
                    $fileOpStart = microtime(true);
                    
                    // Use @ to suppress warnings and control timeout manually
                    $fileSize = @filesize($filePath);
                    
                    if ((microtime(true) - $fileOpStart) > 3) {
                        throw new \Exception("Timeout getting file size");
                    }
                    
                    if ($fileSize !== false) {
                        $fileTime = @filemtime($filePath);
                        
                        if ((microtime(true) - $fileOpStart) > 5) {
                            throw new \Exception("Timeout getting file time");
                        }
                    }
                    
                    // Only validate if we got basic information
                    if ($fileSize !== false && $fileTime !== false) {
                        $isValidBackup = $this->validationService->ultraFastValidation($file, $fileSize);
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->warning("Error processing file info", [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Use default values for problematic files
                    $fileSize = 0;
                    $fileTime = time();
                    $isValidBackup = false;
                }
                
                // Create entry with available information
                $zipFiles[] = [
                    'filename' => $file,
                    'size_formatted' => $fileSize !== false ? ResponseHelper::formatBytes($fileSize) : 'Unknown',
                    'size_bytes' => $fileSize !== false ? $fileSize : 0,
                    'modified' => $fileTime !== false ? date('Y-m-d H:i:s', $fileTime) : 'Unknown',
                    'is_valid_backup' => $isValidBackup,
                    'is_large' => ($fileSize !== false && $fileSize > 100 * 1024 * 1024), // >100MB
                    'validation_method' => 'protected_scan',
                    'processed_successfully' => ($fileSize !== false && $fileTime !== false)
                ];
                
                $this->logger->debug("Processed ZIP file safely", [
                    'file' => $file,
                    'size' => $fileSize !== false ? ResponseHelper::formatBytes($fileSize) : 'unknown',
                    'valid' => $isValidBackup,
                    'operation_time' => microtime(true) - $startOpTime
                ]);
            }
            
            closedir($handle);
            
            // Sort by date (most recent first), handle unknown dates
            usort($zipFiles, function($a, $b) {
                if ($a['modified'] === 'Unknown' && $b['modified'] === 'Unknown') {
                    return 0;
                }
                if ($a['modified'] === 'Unknown') {
                    return 1; // Unknown dates go to the end
                }
                if ($b['modified'] === 'Unknown') {
                    return -1;
                }
                return strcmp($b['modified'], $a['modified']);
            });
            
            $totalTime = microtime(true) - $startTime;
            $validBackups = count(array_filter($zipFiles, function($f) { return $f['is_valid_backup']; }));
            $successfullyProcessed = count(array_filter($zipFiles, function($f) { return $f['processed_successfully']; }));
            
            $this->logger->info("Robust scan completed successfully", [
                'total_files_found' => count($zipFiles),
                'successfully_processed' => $successfullyProcessed,
                'valid_backups' => $validBackups,
                'skipped_files' => $skippedCount,
                'execution_time' => round($totalTime, 2) . 's',
                'avg_time_per_file' => $processedCount > 0 ? round($totalTime / $processedCount, 2) . 's' : '0s'
            ]);
            
            return $zipFiles;
            
        } catch (\Exception $e) {
            $this->logger->error("Critical scan error: " . $e->getMessage());
            
            // Return partial results if possible
            return $zipFiles;
        }
    }

    /**
     * Process server upload adaptively based on file size and server limits
     *
     * @param string $zipPath
     * @param string $filename
     * @param int $fileSize
     * @return array
     * @throws \Exception
     */
    private function processServerUploadAdaptive(string $zipPath, string $filename, int $fileSize): array
    {
        $currentMemoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $availableMemory = $currentMemoryLimit === -1 ? PHP_INT_MAX : $currentMemoryLimit;
        
        // Strategy based on available memory and file size
        $memoryRatio = $availableMemory > 0 ? ($fileSize / $availableMemory) : 0;
        
        $this->logger->info("Adaptive processing analysis", [
            'file_size' => $fileSize,
            'available_memory' => $availableMemory,
            'memory_ratio' => $memoryRatio
        ]);
        
        if ($memoryRatio > 0.5 || $fileSize > 100 * 1024 * 1024) {
            // Large file or limited memory: use ultra-efficient streaming
            return $this->processServerUploadStreaming($zipPath, $filename);
        } else {
            // Moderate file: use optimized standard method
            return $this->processServerUploadStandard($zipPath, $filename);
        }
    }

    /**
     * Ultra-efficient streaming processing for large files
     *
     * @param string $zipPath
     * @param string $filename
     * @return array
     * @throws \Exception
     */
    private function processServerUploadStreaming(string $zipPath, string $filename): array
    {
        $this->logger->info("Using ultra-efficient streaming processing");
        
        // Quick integrity check without loading the full file
        if (!$this->validationService->quickIntegrityCheck($zipPath)) {
            throw new \Exception('ZIP file does not appear to be valid');
        }
        
        // Generate unique name for imported backup
        $timestamp = date('Y-m-d_H-i-s');
        $newBackupName = 'server_import_' . $timestamp;
        
        // Process using direct copy method (most efficient)
        return $this->importByDirectCopy($zipPath, $filename, $newBackupName);
    }

    /**
     * Standard processing for moderate-sized files
     *
     * @param string $zipPath
     * @param string $filename
     * @return array
     * @throws \Exception
     */
    private function processServerUploadStandard(string $zipPath, string $filename): array
    {
        $this->logger->info("Using standard optimized processing");
        
        // Basic verification
        if (!$this->validationService->quickIntegrityCheck($zipPath)) {
            throw new \Exception('ZIP file does not appear to be valid');
        }
        
        // Generate unique name for imported backup
        $timestamp = date('Y-m-d_H-i-s');
        $newBackupName = 'server_import_' . $timestamp;
        
        // For moderate files, also use direct copy (more efficient than extraction)
        return $this->importByDirectCopy($zipPath, $filename, $newBackupName);
    }

    /**
     * Import by direct copy - most memory efficient method
     *
     * @param string $zipPath
     * @param string $originalFilename
     * @param string $newBackupName
     * @return array
     * @throws \Exception
     */
    private function importByDirectCopy(string $zipPath, string $originalFilename, string $newBackupName): array
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        // Copy file directly without extraction (most efficient)
        $newZipFilename = $newBackupName . '.zip';
        $newZipPath = $backupDir . DIRECTORY_SEPARATOR . $newZipFilename;
        
        // Use chunked copy for large files
        if (!$this->copyFileWithChunks($zipPath, $newZipPath)) {
            throw new \Exception('Error copying backup file');
        }

        try {
            // Now process the file that's in the backup folder
            $this->logger->info("Starting ZIP processing after copy", [
                'new_zip_path' => basename($newZipPath),
                'file_size' => ResponseHelper::formatBytes(filesize($newZipPath))
            ]);
            
            $zip = new ZipArchive();
            $result = $zip->open($newZipPath);

            if ($result !== TRUE) {
                throw new \Exception('Cannot open copied ZIP file: ' . ResponseHelper::getZipError($result));
            }
            
            $this->logger->info("ZIP opened successfully, validating structure");
            
            if (!$this->validationService->validateBackupStructure($zip)) {
                throw new \Exception('ZIP file does not have correct backup structure');
            }

            $this->logger->info("Structure validated, extracting backup info");
            
            $backupInfo = $this->extractBackupInfo($zip);
            
            $this->logger->info("Backup info extracted, using standard extraction");
            
            // For server imports, always use standard extraction
            $this->extractBackupStandard($zip, $newBackupName, $originalFilename, $backupInfo, $zipPath);
            
            return [
                'backup_name' => $newBackupName,
                'imported_from' => $originalFilename
            ];

        } catch (\Exception $e) {
            // Clean up copied file if something fails
            if (file_exists($newZipPath)) {
                @unlink($newZipPath);
            }
            throw $e;
        } finally {
            if (isset($zip) && $zip->filename) {
                $zip->close();
            }
        }
    }

    /**
     * Copy file using chunks to avoid memory issues
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    private function copyFileWithChunks(string $source, string $destination): bool
    {
        $sourceHandle = fopen($source, 'rb');
        $destHandle = fopen($destination, 'wb');
        
        if (!$sourceHandle || !$destHandle) {
            if ($sourceHandle) fclose($sourceHandle);
            if ($destHandle) fclose($destHandle);
            return false;
        }
        
        try {
            $chunkSize = 64 * 1024; // 64KB chunks (very conservative)
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
                
                // Clear memory every 1MB
                if ($totalCopied % (1024 * 1024) === 0) {
                    $this->clearMemoryAggressive();
                }
            }
            
            return true;
            
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
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
     * @param string $serverZipPath
     * @throws \Exception
     */
    private function extractBackupStandard(ZipArchive $zip, string $newBackupName, string $originalFilename, array $backupInfo, string $serverZipPath): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $extractPath = $backupDir . DIRECTORY_SEPARATOR . 'temp_extract_' . time();
        
        if (!$zip->extractTo($extractPath)) {
            throw new \Exception('Error extracting backup files');
        }
        
        try {
            $this->processExtractedFiles($extractPath, $newBackupName, $originalFilename, $backupInfo, $serverZipPath);
        } finally {
            $this->removeDirectoryRecursively($extractPath);
        }
    }

    /**
     * Process extracted files
     *
     * @param string $extractPath
     * @param string $newBackupName
     * @param string $originalFilename
     * @param array $backupInfo
     * @param string $serverZipPath
     * @throws \Exception
     */
    private function processExtractedFiles(string $extractPath, string $newBackupName, string $originalFilename, array $backupInfo, string $serverZipPath): void
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
        
        // Automatically delete original server file after successful import
        $this->deleteServerUploadFileAfterImport($serverZipPath, $originalFilename);
        
        $this->logger->info("Backup import completed successfully", [
            'new_backup_name' => $newBackupName,
            'original_filename' => $originalFilename
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
                    $this->clearMemoryAggressive();
                }
            }
            
            return true;
            
        } finally {
            fclose($sourceHandle);
            fclose($destHandle);
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
            $uploadsPath = $this->getServerUploadsPath();
            
            // Verify that the file is safe to delete
            if (!$this->validationService->validateServerUploadFile($zipPath, $originalFilename, $uploadsPath)) {
                $this->logger->warning("Cannot delete server upload file - validation failed", [
                    'filename' => $originalFilename,
                    'path' => $zipPath
                ]);
                return;
            }
            
            // Delete the original ZIP file
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
            
        } catch (\Exception $e) {
            $this->logger->error("Error deleting server upload file after import", [
                'filename' => $originalFilename,
                'error' => $e->getMessage()
            ]);
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
     * Optimize for current server limits
     */
    private function optimizeForCurrentLimits(): void
    {
        // Detect current server limits
        $currentMemoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $currentTimeLimit = ini_get('max_execution_time');
        
        $this->logger->debug("Current server limits detected", [
            'memory_limit' => ini_get('memory_limit'),
            'memory_bytes' => $currentMemoryLimit,
            'max_execution_time' => $currentTimeLimit
        ]);
        
        // Only enable garbage collection (doesn't require special permissions)
        if (function_exists('gc_enable')) {
            gc_enable();
        }
        
        // Try initial memory cleanup
        $this->clearMemoryAggressive();
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
     * Aggressive memory clearing
     */
    private function clearMemoryAggressive(): void
    {
        // Multiple memory cleanup attempts
        if (function_exists('gc_collect_cycles')) {
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }
        }
        
        // Free memory from unused variables
        if (function_exists('memory_get_usage')) {
            $before = memory_get_usage(true);
            gc_collect_cycles();
            $after = memory_get_usage(true);
            
            if ($before > $after) {
                $this->logger->debug("Memory cleaned", [
                    'freed_bytes' => $before - $after,
                    'current_usage' => ResponseHelper::formatBytes($after)
                ]);
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
} 