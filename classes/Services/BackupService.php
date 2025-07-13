<?php
/**
 * Backup Service for creating backups
 * Handles all backup creation logic
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class BackupService
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
     * Create a complete backup (database + files)
     *
     * @param string $backupType
     * @param string $customName
     * @return array
     * @throws \Exception
     */
    public function createBackup(string $backupType = 'complete', string $customName = ''): array
    {
        $this->logger->info("Starting backup creation", [
            'type' => $backupType,
            'custom_name' => $customName
        ]);

        $this->validationService->validateBackupRequirements();

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
        return $results;
    }

    /**
     * Create database backup
     *
     * @param string|null $customName
     * @return array
     * @throws \Exception
     */
    public function createDatabaseBackup(?string $customName): array
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
            throw new \Exception("Database backup failed. Code: " . $returnVar . ". Output: " . implode("\n", $output));
        }

        // Verify backup file was created
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new \Exception("Database backup file was not created or is empty");
        }

        $this->logger->info("Database backup created successfully", [
            'file' => basename($backupFile),
            'size' => filesize($backupFile)
        ]);

        return [
            'backup_name' => basename($backupFile),
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'size_formatted' => ResponseHelper::formatBytes(filesize($backupFile))
        ];
    }

    /**
     * Create files backup
     *
     * @param string|null $customName
     * @return array
     * @throws \Exception
     */
    public function createFilesBackup(?string $customName): array
    {
        $this->logger->info("Creating files backup");

        $backupFile = $this->backupContainer->getBackupFilename(false, $customName);
        
        $this->createZipBackup(_PS_ROOT_DIR_, $backupFile);

        // Verify backup file was created
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new \Exception("Files backup was not created or is empty");
        }

        $this->logger->info("Files backup created successfully", [
            'file' => basename($backupFile),
            'size' => filesize($backupFile)
        ]);

        return [
            'backup_name' => basename($backupFile),
            'file' => basename($backupFile),
            'size' => filesize($backupFile),
            'size_formatted' => ResponseHelper::formatBytes(filesize($backupFile))
        ];
    }

    /**
     * Create ZIP backup of files with chunked processing for large sites
     *
     * @param string $sourceDir
     * @param string $backupFile
     * @throws \Exception
     */
    private function createZipBackup(string $sourceDir, string $backupFile): void
    {
        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP PHP extension is not installed');
        }

        // Optimize for large operations
        $this->optimizeForLargeOperations();

        $sourceDir = realpath($sourceDir);
        if (!$sourceDir) {
            throw new \Exception('Source directory does not exist: ' . $sourceDir);
        }

        // Check if it's a large site and use appropriate strategy
        $estimatedSize = $this->estimateDirectorySize($sourceDir);
        $isLargeSite = $estimatedSize > 500 * 1024 * 1024; // 500MB

        if ($isLargeSite) {
            $this->logger->info("Large site detected, using chunked processing", [
                'estimated_size' => ResponseHelper::formatBytes($estimatedSize)
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
        $maxSampleFiles = 100; // Sample files for estimation

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $excludePaths = $this->validationService->getExcludePaths();

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            if ($this->validationService->shouldExcludeFile($file->getRealPath(), $excludePaths)) {
                continue;
            }

            $size += $file->getSize();
            $count++;

            // Estimation based on sample
            if ($count >= $maxSampleFiles) {
                // Count remaining files quickly
                $totalFiles = $count;
                foreach ($iterator as $remainingFile) {
                    if ($remainingFile->isFile() && $remainingFile->isReadable() && 
                        !$this->validationService->shouldExcludeFile($remainingFile->getRealPath(), $excludePaths)) {
                        $totalFiles++;
                    }
                    if ($totalFiles % 1000 === 0 && $totalFiles > $maxSampleFiles * 10) {
                        break; // Avoid infinite loop on huge sites
                    }
                }
                
                // Estimate total size based on average
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
     * @throws \Exception
     */
    private function createZipBackupStandard(string $sourceDir, string $backupFile): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot create ZIP file: ' . ResponseHelper::getZipError($result));
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $excludePaths = $this->validationService->getExcludePaths();
        $fileCount = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            
            if ($this->validationService->shouldExcludeFile($filePath, $excludePaths)) {
                continue;
            }

            $zip->addFile($filePath, $relativePath);
            $fileCount++;
            
            // Prevent timeouts and clear memory
            if ($fileCount % 500 === 0) {
                $this->preventTimeout();
                $this->clearMemory();
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new \Exception('Error closing ZIP file');
        }

        if ($fileCount === 0) {
            throw new \Exception('No files were added to the backup');
        }

        $this->logger->info("Standard backup completed", ['files' => $fileCount]);
    }

    /**
     * Create ZIP backup using chunked processing for large sites
     *
     * @param string $sourceDir
     * @param string $backupFile
     * @throws \Exception
     */
    private function createZipBackupChunked(string $sourceDir, string $backupFile): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot create ZIP file: ' . ResponseHelper::getZipError($result));
        }

        $excludePaths = $this->validationService->getExcludePaths();
        $fileCount = 0;
        $chunkSize = 100; // Process files in smaller chunks
        $currentChunk = 0;

        // Get list of files first
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
            if ($this->validationService->shouldExcludeFile($filePath, $excludePaths)) {
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

        // Process files in chunks
        $chunks = array_chunk($filesToProcess, $chunkSize);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $this->logger->debug("Processing chunk " . ($chunkIndex + 1) . " of " . count($chunks));
            
            foreach ($chunk as $fileInfo) {
                // Handle large files specially
                if ($fileInfo['size'] > 50 * 1024 * 1024) { // 50MB
                    $this->addLargeFileToZip($zip, $fileInfo['path'], $fileInfo['relative']);
                } else {
                    $zip->addFile($fileInfo['path'], $fileInfo['relative']);
                }
                $fileCount++;
            }

            // Clear memory and prevent timeout after each chunk
            $this->preventTimeout();
            $this->clearMemory();
            
            // Log progress
            if ($chunkIndex % 10 === 0) {
                $progress = round(($chunkIndex / count($chunks)) * 100, 1);
                $this->logger->info("Backup progress: {$progress}% ({$fileCount} files)");
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new \Exception('Error closing ZIP file');
        }

        if ($fileCount === 0) {
            throw new \Exception('No files were added to the backup');
        }

        $this->logger->info("Chunked backup completed", ['files' => $fileCount]);
    }

    /**
     * Add large file to ZIP using streaming to avoid memory issues
     *
     * @param ZipArchive $zip
     * @param string $filePath
     * @param string $relativePath
     * @throws \Exception
     */
    private function addLargeFileToZip(ZipArchive $zip, string $filePath, string $relativePath): void
    {
        // For very large files, read in chunks to avoid memory issues
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->logger->warning("Cannot open large file, skipping: " . $filePath);
            return;
        }
        
        $content = '';
        $chunkSize = 1024 * 1024; // 1MB chunks
        $maxMemory = 20 * 1024 * 1024; // Maximum 20MB in memory
        
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \Exception('Error reading large file: ' . $filePath);
                }
                
                $content .= $chunk;
                
                // If file is too large to keep in memory, save temporarily
                if (strlen($content) > $maxMemory) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'ps_copia_large_');
                    file_put_contents($tempFile, $content);
                    $content = ''; // Clear memory
                    
                    // Continue reading the rest of the file
                    while (!feof($handle)) {
                        $chunk = fread($handle, $chunkSize);
                        if ($chunk !== false) {
                            file_put_contents($tempFile, $chunk, FILE_APPEND);
                        }
                    }
                    
                    // Add from temp file
                    $zip->addFile($tempFile, $relativePath);
                    
                    // Schedule cleanup of temp file
                    register_shutdown_function(function() use ($tempFile) {
                        if (file_exists($tempFile)) {
                            @unlink($tempFile);
                        }
                    });
                    
                    fclose($handle);
                    return;
                }
            }
            
            // File fits in memory
            if (!$zip->addFromString($relativePath, $content)) {
                throw new \Exception('Failed to add large file to ZIP: ' . $relativePath);
            }
            
        } finally {
            fclose($handle);
            unset($content); // Explicitly clear memory
        }
    }

    /**
     * Build mysqldump command
     *
     * @param string $backupFile
     * @return string
     */
    private function buildMysqldumpCommand(string $backupFile): string
    {
        $credentials = $this->getCurrentDbCredentials();
        
        return sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s | gzip > %s',
            escapeshellarg($credentials['host']),
            escapeshellarg($credentials['user']),
            escapeshellarg($credentials['password']),
            escapeshellarg($credentials['name']),
            escapeshellarg($backupFile)
        );
    }

    /**
     * Get current database credentials from environment
     *
     * @return array
     */
    private function getCurrentDbCredentials(): array
    {
        // First try to read from parameters.php (current environment)
        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        
        if (file_exists($parametersFile)) {
            $parametersContent = file_get_contents($parametersFile);
            if ($parametersContent !== false) {
                // Extract parameters array from file
                $matches = [];
                if (preg_match('/return\s+array\s*\(\s*\'parameters\'\s*=>\s*array\s*\((.*?)\)\s*,?\s*\)\s*;/s', $parametersContent, $matches)) {
                    $paramsString = $matches[1];
                    
                    // Extract individual parameters
                    $credentials = [];
                    if (preg_match('/\'database_host\'\s*=>\s*\'([^\']*)\'/s', $paramsString, $hostMatch)) {
                        $credentials['host'] = $hostMatch[1];
                    }
                    if (preg_match('/\'database_user\'\s*=>\s*\'([^\']*)\'/s', $paramsString, $userMatch)) {
                        $credentials['user'] = $userMatch[1];
                    }
                    if (preg_match('/\'database_password\'\s*=>\s*\'([^\']*)\'/s', $paramsString, $passMatch)) {
                        $credentials['password'] = $passMatch[1];
                    }
                    if (preg_match('/\'database_name\'\s*=>\s*\'([^\']*)\'/s', $paramsString, $nameMatch)) {
                        $credentials['name'] = $nameMatch[1];
                    }
                    
                    // If we have all credentials, use them
                    if (isset($credentials['host']) && isset($credentials['user']) && 
                        isset($credentials['password']) && isset($credentials['name'])) {
                        $this->logger->info("Using database credentials from parameters.php", [
                            'host' => $credentials['host'],
                            'user' => $credentials['user'],
                            'name' => $credentials['name']
                        ]);
                        return $credentials;
                    }
                }
            }
        }
        
        // Check if we're in DDEV environment
        if (getenv('DDEV_SITENAME') || $this->validationService->isDdevEnvironment()) {
            $this->logger->info("Detected DDEV environment, using DDEV database credentials");
            return [
                'host' => 'db',
                'user' => 'db', 
                'password' => 'db',
                'name' => 'db'
            ];
        }
        
        // Fallback to PrestaShop constants
        $this->logger->warning("Using PrestaShop constants as fallback", [
            'host' => _DB_SERVER_,
            'user' => _DB_USER_,
            'name' => _DB_NAME_
        ]);
        
        return [
            'host' => _DB_SERVER_,
            'user' => _DB_USER_,
            'password' => _DB_PASSWD_,
            'name' => _DB_NAME_
        ];
    }

    /**
     * Optimize settings for large operations
     */
    private function optimizeForLargeOperations(): void
    {
        // Try to optimize memory only if possible
        $currentMemory = ini_get('memory_limit');
        if ($currentMemory !== '-1') {
            $memoryInBytes = $this->parseMemoryLimit($currentMemory);
            $recommendedMemory = max($memoryInBytes, 256 * 1024 * 1024); // Minimum 256MB
            
            // Only try to increase if we have permissions
            if (function_exists('ini_set')) {
                @ini_set('memory_limit', $recommendedMemory);
            }
        }

        // Try to increase execution time only if possible
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', 0);
        }

        // Optimize garbage collection
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
            @set_time_limit(300); // 5 minutes more
        }
        
        // Flush output to keep connection active
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
        // Force memory cleanup
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        if (function_exists('memory_get_usage')) {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            
            // If using more than 80% of memory, do aggressive cleanup
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
     * List available backups
     *
     * @return array
     */
    public function listBackups(): array
    {
        $this->logger->info("Listing available backups");
        
        try {
            // Get metadata first
            $metadata = $this->getBackupMetadata();
            $backups = [];
            
            if (!empty($metadata)) {
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
                                
                                $backups[] = [
                                    'name' => $backupName,
                                    'date' => $backupData['created_at'],
                                    'size' => $totalSize,
                                    'size_formatted' => ResponseHelper::formatBytes($totalSize),
                                    'type' => 'complete',
                                    'database_file' => $backupData['database_file'],
                                    'files_file' => $backupData['files_file']
                                ];
                            }
                        } elseif (isset($backupData['zip_file'])) {
                            // Server imported backup
                            $zipFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['zip_file'];
                            
                            if (file_exists($zipFile)) {
                                $fileSize = filesize($zipFile);
                                
                                $backups[] = [
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
                            
                            $backups[] = [
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
            }
            
            // If no metadata or no backups found, fallback to file-based listing
            if (empty($backups)) {
                $backups = $this->getBackupsFromFiles();
            }
            
            // Sort by date (most recent first)
            usort($backups, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            
            $this->logger->info("Found " . count($backups) . " available backups");
            return $backups;
            
        } catch (\Exception $e) {
            $this->logger->error("Error listing backups: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete backup
     *
     * @param string $backupName
     * @return string
     * @throws \Exception
     */
    public function deleteBackup(string $backupName): string
    {
        $this->logger->info("Deleting backup: " . $backupName);
        
        $metadata = $this->getBackupMetadata();
        $deleted = false;
        
        if (empty($metadata)) {
            // No metadata, try deleting as individual file
            $this->backupContainer->deleteBackup($backupName);
            $this->logger->info("Individual backup deleted: " . $backupName);
            return "Backup eliminado correctamente: " . $backupName;
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
        } else {
            // Original format: direct key access
            if (isset($metadata[$backupName])) {
                $backupInfo = $metadata[$backupName];
                
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['database_file']);
                }
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['files_file']);
                }
                
                unset($metadata[$backupName]);
                $deleted = true;
            }
        }
        
        if ($deleted) {
            $this->saveUpdatedMetadata($metadata);
            $this->logger->info("Backup and metadata deleted successfully: " . $backupName);
            return "Backup eliminado correctamente: " . $backupName;
        }
        
        throw new \Exception("Backup no encontrado: " . $backupName);
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
     * Fallback method to get backups from files
     *
     * @return array
     */
    private function getBackupsFromFiles(): array
    {
        try {
            $backups = $this->backupContainer->getAvailableBackups();
            $result = [];
            
            foreach ($backups as $backup) {
                $result[] = [
                    'name' => $backup['name'],
                    'date' => $backup['date'],
                    'size' => $backup['size'],
                    'size_formatted' => $backup['size_formatted'],
                    'type' => $backup['type']
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning("Could not get backups from files", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save updated metadata
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
} 