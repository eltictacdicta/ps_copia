<?php
/**
 * Secure File Restore Service for PS_Copia
 * Handles secure file restoration with comprehensive security validations
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Services\ValidationService;
use Exception;
use ZipArchive;

class SecureFileRestoreService
{
    /** @var BackupContainer */
    private $backupContainer;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var ValidationService */
    private $validationService;
    
    /** @var array */
    private $securityConfig;
    
    /** @var string */
    private $tempRestoreDir;
    
    /** @var array */
    private $restoredFiles = [];
    
    /** @var array */
    private $skippedFiles = [];

    public function __construct(
        BackupContainer $backupContainer,
        BackupLogger $logger,
        ValidationService $validationService
    ) {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->validationService = $validationService;
        
        $this->initializeSecurityConfig();
    }

    /**
     * Secure file restoration with comprehensive security checks
     *
     * @param string $filesBackupPath
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function restoreFilesSecurely(string $filesBackupPath, array $options = []): array
    {
        $this->logger->info("Starting secure file restoration", [
            'backup_file' => basename($filesBackupPath),
            'options' => $options
        ]);

        $startTime = microtime(true);
        
        try {
            // Step 1: Initialize secure restoration
            $this->initializeSecureRestore($filesBackupPath, $options);
            
            // Step 2: Extract and validate archive
            $this->extractAndValidateArchive($filesBackupPath);
            
            // Step 3: Scan and classify files
            $this->scanAndClassifyFiles();
            
            // Step 4: Apply security filters
            $this->applySecurityFilters();
            
            // Step 5: Execute file restoration
            $this->executeSecureFileRestore();
            
            // Step 6: Post-restoration security tasks
            $this->performPostRestorationSecurity();
            
            // Step 7: Cleanup
            $this->cleanupTemporaryFiles();
            
            $duration = microtime(true) - $startTime;
            
            $result = [
                'success' => true,
                'duration' => round($duration, 2),
                'files_restored' => count($this->restoredFiles),
                'files_skipped' => count($this->skippedFiles),
                'restored_files' => $this->restoredFiles,
                'skipped_files' => $this->skippedFiles
            ];
            
            $this->logger->info("Secure file restoration completed", $result);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->error("Secure file restoration failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->cleanupTemporaryFiles();
            throw $e;
        }
    }

    /**
     * Initialize security configuration
     */
    private function initializeSecurityConfig(): void
    {
        $this->securityConfig = [
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_extensions' => [
                // PHP files
                'php', 'phtml',
                // Template files
                'tpl', 'html', 'htm',
                // Style files
                'css', 'scss', 'sass', 'less',
                // Script files
                'js', 'ts', 'json',
                // Image files
                'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico',
                // Font files
                'ttf', 'otf', 'woff', 'woff2', 'eot',
                // Document files
                'pdf', 'txt', 'md', 'xml', 'yml', 'yaml',
                // Archive files (for modules)
                'zip', 'tar', 'gz',
                // Configuration files
                'conf', 'config', 'ini', 'env'
            ],
            'blocked_extensions' => [
                'exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'vbs', 'vbe', 'js', 'jar',
                'sh', 'bash', 'zsh', 'fish', 'pl', 'py', 'rb', 'go', 'c', 'cpp'
            ],
            'critical_paths' => [
                '/app/config',
                '/config',
                '/admin',
                '/.htaccess'
            ],
            'protected_files' => [
                'parameters.php',
                'settings.inc.php',
                'config.inc.php',
                '.htaccess',
                '.htpasswd',
                'robots.txt'
            ],
            'scan_for_malware' => true,
            'validate_php_syntax' => true,
            'check_file_permissions' => true,
            'backup_existing_files' => true
        ];
    }

    /**
     * Initialize secure restoration
     *
     * @param string $filesBackupPath
     * @param array $options
     * @throws Exception
     */
    private function initializeSecureRestore(string $filesBackupPath, array $options): void
    {
        if (!file_exists($filesBackupPath)) {
            throw new Exception("Files backup does not exist: " . $filesBackupPath);
        }
        
        if (!extension_loaded('zip')) {
            throw new Exception('ZIP extension is required for file restoration');
        }
        
        // Merge options with default security config
        $this->securityConfig = array_merge($this->securityConfig, $options);
        
        // Create temporary directory
        $this->tempRestoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_secure_restore_' . time();
        if (!mkdir($this->tempRestoreDir, 0755, true)) {
            throw new Exception('Cannot create temporary restoration directory');
        }
        
        $this->logger->info("Secure restoration initialized", [
            'temp_dir' => $this->tempRestoreDir,
            'max_file_size' => $this->securityConfig['max_file_size'],
            'scan_for_malware' => $this->securityConfig['scan_for_malware']
        ]);
    }

    /**
     * Extract and validate archive
     *
     * @param string $filesBackupPath
     * @throws Exception
     */
    private function extractAndValidateArchive(string $filesBackupPath): void
    {
        $this->logger->info("Extracting and validating archive");
        
        $zip = new ZipArchive();
        $result = $zip->open($filesBackupPath);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot open ZIP file: ' . $this->getZipError($result));
        }
        
        try {
            // Validate archive before extraction
            $this->validateArchiveStructure($zip);
            
            // Extract to temporary directory
            if (!$zip->extractTo($this->tempRestoreDir)) {
                throw new Exception('Failed to extract ZIP file');
            }
            
            $zip->close();
            
            $this->logger->info("Archive extracted successfully", [
                'extracted_to' => $this->tempRestoreDir
            ]);
            
        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    /**
     * Validate archive structure
     *
     * @param ZipArchive $zip
     * @throws Exception
     */
    private function validateArchiveStructure(ZipArchive $zip): void
    {
        $this->logger->info("Validating archive structure");
        
        $numFiles = $zip->numFiles;
        
        if ($numFiles > 50000) { // Reasonable limit for PrestaShop files
            throw new Exception("Archive contains too many files: " . $numFiles);
        }
        
        $totalSize = 0;
        $suspiciousFiles = [];
        
        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            
            if ($stat === false) {
                continue;
            }
            
            $filename = $stat['name'];
            $filesize = $stat['size'];
            
            // Check file size
            if ($filesize > $this->securityConfig['max_file_size']) {
                $suspiciousFiles[] = [
                    'file' => $filename,
                    'reason' => 'File too large: ' . $this->formatBytes($filesize)
                ];
                continue;
            }
            
            // Check for suspicious paths
            if ($this->isSuspiciousPath($filename)) {
                $suspiciousFiles[] = [
                    'file' => $filename,
                    'reason' => 'Suspicious path'
                ];
                continue;
            }
            
            // Check file extension
            if ($this->hasBlockedExtension($filename)) {
                $suspiciousFiles[] = [
                    'file' => $filename,
                    'reason' => 'Blocked file extension'
                ];
                continue;
            }
            
            $totalSize += $filesize;
        }
        
        // Check total extracted size
        if ($totalSize > 2 * 1024 * 1024 * 1024) { // 2GB limit
            throw new Exception("Archive content too large: " . $this->formatBytes($totalSize));
        }
        
        if (!empty($suspiciousFiles)) {
            $this->logger->warning("Found suspicious files in archive", [
                'suspicious_files' => array_slice($suspiciousFiles, 0, 10) // Log first 10
            ]);
            
            if (count($suspiciousFiles) > 100) {
                throw new Exception("Too many suspicious files in archive: " . count($suspiciousFiles));
            }
        }
        
        $this->logger->info("Archive structure validation completed", [
            'total_files' => $numFiles,
            'total_size' => $this->formatBytes($totalSize),
            'suspicious_files' => count($suspiciousFiles)
        ]);
    }

    /**
     * Scan and classify files
     */
    private function scanAndClassifyFiles(): void
    {
        $this->logger->info("Scanning and classifying extracted files");
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempRestoreDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $fileCount = 0;
        $totalSize = 0;
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($this->tempRestoreDir) + 1);
                $fileSize = $file->getSize();
                
                $classification = $this->classifyFile($file, $relativePath);
                
                $this->restoredFiles[] = [
                    'path' => $relativePath,
                    'size' => $fileSize,
                    'classification' => $classification,
                    'validated' => false
                ];
                
                $fileCount++;
                $totalSize += $fileSize;
            }
        }
        
        $this->logger->info("File classification completed", [
            'total_files' => $fileCount,
            'total_size' => $this->formatBytes($totalSize)
        ]);
    }

    /**
     * Classify a file
     *
     * @param \SplFileInfo $file
     * @param string $relativePath
     * @return array
     */
    private function classifyFile(\SplFileInfo $file, string $relativePath): array
    {
        $extension = strtolower($file->getExtension());
        $filename = $file->getFilename();
        
        $classification = [
            'type' => 'unknown',
            'critical' => false,
            'executable' => false,
            'safe' => false
        ];
        
        // Classify by extension
        if (in_array($extension, ['php', 'phtml'])) {
            $classification['type'] = 'php';
            $classification['executable'] = true;
        } elseif (in_array($extension, ['js', 'ts'])) {
            $classification['type'] = 'script';
        } elseif (in_array($extension, ['css', 'scss', 'sass', 'less'])) {
            $classification['type'] = 'style';
            $classification['safe'] = true;
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
            $classification['type'] = 'image';
            $classification['safe'] = true;
        } elseif (in_array($extension, ['tpl', 'html', 'htm'])) {
            $classification['type'] = 'template';
        }
        
        // Check if critical file
        if (in_array($filename, $this->securityConfig['protected_files'])) {
            $classification['critical'] = true;
        }
        
        // Check if in critical path
        foreach ($this->securityConfig['critical_paths'] as $criticalPath) {
            if (strpos($relativePath, trim($criticalPath, '/')) === 0) {
                $classification['critical'] = true;
                break;
            }
        }
        
        return $classification;
    }

    /**
     * Apply security filters
     */
    private function applySecurityFilters(): void
    {
        $this->logger->info("Applying security filters");
        
        $filteredFiles = [];
        
        foreach ($this->restoredFiles as $fileInfo) {
            $filePath = $this->tempRestoreDir . DIRECTORY_SEPARATOR . $fileInfo['path'];
            
            if (!file_exists($filePath)) {
                continue;
            }
            
            // Security checks
            $securityIssues = $this->performSecurityChecks($filePath, $fileInfo);
            
            if (!empty($securityIssues)) {
                $this->skippedFiles[] = [
                    'path' => $fileInfo['path'],
                    'reason' => implode(', ', $securityIssues)
                ];
                
                // Delete suspicious file
                @unlink($filePath);
                continue;
            }
            
            $fileInfo['validated'] = true;
            $filteredFiles[] = $fileInfo;
        }
        
        $this->restoredFiles = $filteredFiles;
        
        $this->logger->info("Security filtering completed", [
            'files_passed' => count($this->restoredFiles),
            'files_blocked' => count($this->skippedFiles)
        ]);
    }

    /**
     * Perform security checks on a file
     *
     * @param string $filePath
     * @param array $fileInfo
     * @return array
     */
    private function performSecurityChecks(string $filePath, array $fileInfo): array
    {
        $issues = [];
        
        // Check file size
        if (filesize($filePath) > $this->securityConfig['max_file_size']) {
            $issues[] = 'File too large';
        }
        
        // Check file extension
        if ($this->hasBlockedExtension($fileInfo['path'])) {
            $issues[] = 'Blocked file extension';
        }
        
        // Check for malware signatures
        if ($this->securityConfig['scan_for_malware']) {
            $malwareFound = $this->scanForMalware($filePath, $fileInfo);
            if ($malwareFound) {
                $issues[] = 'Potential malware detected';
            }
        }
        
        // Validate PHP syntax
        if ($this->securityConfig['validate_php_syntax'] && 
            $fileInfo['classification']['type'] === 'php') {
            if (!$this->validatePhpSyntax($filePath)) {
                $issues[] = 'Invalid PHP syntax';
            }
        }
        
        // Check file permissions
        if ($this->securityConfig['check_file_permissions']) {
            if (!$this->validateFilePermissions($filePath)) {
                $issues[] = 'Invalid file permissions';
            }
        }
        
        return $issues;
    }

    /**
     * Scan file for malware signatures
     *
     * @param string $filePath
     * @param array $fileInfo
     * @return bool
     */
    private function scanForMalware(string $filePath, array $fileInfo): bool
    {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return false;
        }
        
        // Common malware signatures for PHP files
        $malwareSignatures = [
            'eval\s*\(\s*base64_decode',
            'eval\s*\(\s*gzinflate',
            'eval\s*\(\s*str_rot13',
            'system\s*\(\s*\$_',
            'exec\s*\(\s*\$_',
            'shell_exec\s*\(\s*\$_',
            'passthru\s*\(\s*\$_',
            'file_get_contents\s*\(\s*["\']php://input',
            '\$GLOBALS\s*\[\s*["\']_',
            'create_function\s*\(',
            'assert\s*\(\s*\$_',
            'preg_replace\s*\(\s*["\'][^"\']*\/e'
        ];
        
        foreach ($malwareSignatures as $signature) {
            if (preg_match('/' . $signature . '/i', $content)) {
                $this->logger->warning("Malware signature detected", [
                    'file' => $fileInfo['path'],
                    'signature' => $signature
                ]);
                return true;
            }
        }
        
        // Check for suspicious patterns in any file type
        $suspiciousPatterns = [
            '(?:\$_(?:GET|POST|REQUEST|COOKIE|SESSION|SERVER|FILES)\[.*?\].*?(?:eval|exec|system|shell_exec|passthru))',
            '(?:base64_decode\s*\(\s*["\'][A-Za-z0-9+\/=]{50,})',
            '(?:chr\s*\(\s*\d+\s*\)\s*\.){10,}',
            '(?:\\\\x[0-9a-f]{2}){20,}'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $content)) {
                $this->logger->warning("Suspicious pattern detected", [
                    'file' => $fileInfo['path'],
                    'pattern' => substr($pattern, 0, 50) . '...'
                ]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Validate PHP syntax
     *
     * @param string $filePath
     * @return bool
     */
    private function validatePhpSyntax(string $filePath): bool
    {
        $output = [];
        $returnVar = 0;
        
        exec('php -l ' . escapeshellarg($filePath) . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->logger->warning("PHP syntax error detected", [
                'file' => basename($filePath),
                'error' => implode("\n", $output)
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Validate file permissions
     *
     * @param string $filePath
     * @return bool
     */
    private function validateFilePermissions(string $filePath): bool
    {
        $perms = fileperms($filePath);
        
        // Check if file is executable
        if ($perms & 0111) {
            $this->logger->warning("Executable file detected", [
                'file' => basename($filePath),
                'permissions' => sprintf('%o', $perms)
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Execute secure file restoration
     *
     * @throws Exception
     */
    private function executeSecureFileRestore(): void
    {
        $this->logger->info("Executing secure file restoration");
        
        $psRootDir = _PS_ROOT_DIR_;
        $excludePaths = $this->validationService->getExcludePaths();
        
        foreach ($this->restoredFiles as &$fileInfo) {
            $sourcePath = $this->tempRestoreDir . DIRECTORY_SEPARATOR . $fileInfo['path'];
            $destinationPath = $psRootDir . DIRECTORY_SEPARATOR . $fileInfo['path'];
            
            // Skip excluded files
            if ($this->validationService->shouldExcludeFile($destinationPath, $excludePaths)) {
                $this->skippedFiles[] = [
                    'path' => $fileInfo['path'],
                    'reason' => 'File excluded by validation rules'
                ];
                continue;
            }
            
            // Backup existing file if it's critical
            if ($fileInfo['classification']['critical'] && file_exists($destinationPath)) {
                $this->backupExistingFile($destinationPath);
            }
            
            // Create destination directory if needed
            $destinationDir = dirname($destinationPath);
            if (!is_dir($destinationDir)) {
                if (!mkdir($destinationDir, 0755, true)) {
                    throw new Exception('Failed to create directory: ' . $destinationDir);
                }
            }
            
            // Copy file securely
            if (!$this->copyFileSecurely($sourcePath, $destinationPath)) {
                throw new Exception('Failed to copy file: ' . $fileInfo['path']);
            }
            
            // Set appropriate permissions
            $this->setSecurePermissions($destinationPath, $fileInfo['classification']);
            
            $fileInfo['restored'] = true;
        }
        
        $this->logger->info("File restoration completed", [
            'files_restored' => count(array_filter($this->restoredFiles, function($f) { return $f['restored'] ?? false; }))
        ]);
    }

    /**
     * Backup existing file
     *
     * @param string $filePath
     */
    private function backupExistingFile(string $filePath): void
    {
        $backupPath = $filePath . '.backup.' . date('Y-m-d_H-i-s');
        
        if (copy($filePath, $backupPath)) {
            $this->logger->info("Backed up existing file", [
                'original' => basename($filePath),
                'backup' => basename($backupPath)
            ]);
        } else {
            $this->logger->warning("Failed to backup existing file", [
                'file' => basename($filePath)
            ]);
        }
    }

    /**
     * Copy file securely
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    private function copyFileSecurely(string $source, string $destination): bool
    {
        // Use chunked copy for large files
        $chunkSize = 1024 * 1024; // 1MB chunks
        
        $sourceHandle = fopen($source, 'rb');
        $destHandle = fopen($destination, 'wb');
        
        if (!$sourceHandle || !$destHandle) {
            if ($sourceHandle) fclose($sourceHandle);
            if ($destHandle) fclose($destHandle);
            return false;
        }
        
        while (!feof($sourceHandle)) {
            $chunk = fread($sourceHandle, $chunkSize);
            if ($chunk === false || fwrite($destHandle, $chunk) === false) {
                fclose($sourceHandle);
                fclose($destHandle);
                @unlink($destination);
                return false;
            }
        }
        
        fclose($sourceHandle);
        fclose($destHandle);
        
        return true;
    }

    /**
     * Set secure permissions
     *
     * @param string $filePath
     * @param array $classification
     */
    private function setSecurePermissions(string $filePath, array $classification): void
    {
        if ($classification['critical']) {
            // Critical files: read-only
            chmod($filePath, 0644);
        } elseif ($classification['executable']) {
            // Executable files: read and execute
            chmod($filePath, 0644);
        } else {
            // Regular files: read-only
            chmod($filePath, 0644);
        }
    }

    /**
     * Perform post-restoration security tasks
     */
    private function performPostRestorationSecurity(): void
    {
        $this->logger->info("Performing post-restoration security tasks");
        
        // Clear any PHP opcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Update file modification times
        foreach ($this->restoredFiles as $fileInfo) {
            if ($fileInfo['restored'] ?? false) {
                $filePath = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . $fileInfo['path'];
                if (file_exists($filePath)) {
                    touch($filePath);
                }
            }
        }
        
        // Log security summary
        $this->logSecuritySummary();
    }

    /**
     * Log security summary
     */
    private function logSecuritySummary(): void
    {
        $summary = [
            'total_files_processed' => count($this->restoredFiles) + count($this->skippedFiles),
            'files_restored' => count($this->restoredFiles),
            'files_skipped' => count($this->skippedFiles),
            'critical_files_restored' => 0,
            'php_files_restored' => 0
        ];
        
        foreach ($this->restoredFiles as $fileInfo) {
            if ($fileInfo['classification']['critical']) {
                $summary['critical_files_restored']++;
            }
            if ($fileInfo['classification']['type'] === 'php') {
                $summary['php_files_restored']++;
            }
        }
        
        $this->logger->info("Security restoration summary", $summary);
    }

    /**
     * Check if path is suspicious
     *
     * @param string $path
     * @return bool
     */
    private function isSuspiciousPath(string $path): bool
    {
        $suspiciousPaths = [
            '../',
            '..\\',
            '/etc/',
            '/var/',
            '/usr/',
            '/home/',
            '/root/',
            'C:/',
            'C:\\',
            '/proc/',
            '/sys/'
        ];
        
        foreach ($suspiciousPaths as $suspicious) {
            if (strpos($path, $suspicious) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if file has blocked extension
     *
     * @param string $filename
     * @return bool
     */
    private function hasBlockedExtension(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return in_array($extension, $this->securityConfig['blocked_extensions']);
    }

    /**
     * Format bytes to human readable
     *
     * @param int $size
     * @return string
     */
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, 2) . ' ' . $units[$i];
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
            case ZipArchive::ER_NOZIP:
                return 'Not a ZIP archive';
            case ZipArchive::ER_INCONS:
                return 'ZIP archive inconsistent';
            case ZipArchive::ER_CRC:
                return 'CRC error';
            case ZipArchive::ER_MEMORY:
                return 'Memory allocation failure';
            case ZipArchive::ER_READ:
                return 'Read error';
            case ZipArchive::ER_SEEK:
                return 'Seek error';
            default:
                return 'Unknown ZIP error (code: ' . $code . ')';
        }
    }

    /**
     * Cleanup temporary files
     */
    private function cleanupTemporaryFiles(): void
    {
        if ($this->tempRestoreDir && is_dir($this->tempRestoreDir)) {
            $this->logger->info("Cleaning up temporary files");
            $this->removeDirectoryRecursively($this->tempRestoreDir);
        }
    }

    /**
     * Remove directory recursively
     *
     * @param string $directory
     */
    private function removeDirectoryRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        
        rmdir($directory);
    }
} 