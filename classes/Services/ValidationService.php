<?php
/**
 * Validation Service for common validations
 * Centralizes validation logic used across the module
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;

class ValidationService
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
     * Validate backup requirements
     *
     * @throws \Exception
     */
    public function validateBackupRequirements(): void
    {
        // Check if backup directory is writable
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        if (!is_writable($backupDir)) {
            throw new \Exception("Backup directory is not writable: " . $backupDir);
        }

        // Check disk space (require at least 100MB free)
        $diskInfo = $this->backupContainer->getDiskSpaceInfo();
        if ($diskInfo['free_space'] < 100 * 1024 * 1024) {
            throw new \Exception("Insufficient disk space. At least 100MB free space required.");
        }

        // Check required PHP extensions
        if (!extension_loaded('zip')) {
            throw new \Exception("ZIP extension is required for file backups");
        }
    }

    /**
     * Validate ZIP file integrity
     *
     * @param string $zipPath
     * @return bool
     */
    public function verifyZipIntegrity(string $zipPath): bool
    {
        if (!file_exists($zipPath)) {
            return false;
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CHECKCONS);
        
        if ($result === true) {
            $zip->close();
            return true;
        }
        
        $this->logger->warning("ZIP integrity check failed", [
            'error_code' => $result,
            'error_message' => ResponseHelper::getZipError($result)
        ]);
        
        return false;
    }

    /**
     * Validate backup structure in ZIP
     *
     * @param ZipArchive $zip
     * @return bool
     */
    public function validateBackupStructure(ZipArchive $zip): bool
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
            
            // Early exit if we found everything
            if ($hasInfo && $hasDatabase && $hasFiles) {
                break;
            }
        }
        
        return $hasInfo && $hasDatabase && $hasFiles;
    }

    /**
     * Validate server upload file for security
     *
     * @param string $zipPath
     * @param string $filename
     * @param string $uploadsPath
     * @return bool
     */
    public function validateServerUploadFile(string $zipPath, string $filename, string $uploadsPath): bool
    {
        // Check if file exists
        if (!file_exists($zipPath)) {
            return false;
        }
        
        // Check if it's within the uploads directory (prevent path traversal)
        $realZipPath = realpath($zipPath);
        $realUploadsPath = realpath($uploadsPath);
        
        if (!$realZipPath || !$realUploadsPath || strpos($realZipPath, $realUploadsPath) !== 0) {
            return false;
        }
        
        // Check extension
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            return false;
        }
        
        // Check if file is readable
        if (!is_readable($zipPath)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if file should be excluded from backup
     *
     * @param string $filePath
     * @param array $excludePaths
     * @return bool
     */
    public function shouldExcludeFile(string $filePath, array $excludePaths): bool
    {
        // Check exclude paths
        foreach ($excludePaths as $excludePath) {
            if ($excludePath && strpos($filePath, $excludePath) === 0) {
                return true;
            }
        }

        // Get relative path from PS_ROOT_DIR for specific checks
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
     * Get paths to exclude from backup
     *
     * @return array
     */
    public function getExcludePaths(): array
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
     * Quick integrity check for ZIP files
     *
     * @param string $zipPath
     * @return bool
     */
    public function quickIntegrityCheck(string $zipPath): bool
    {
        try {
            $handle = fopen($zipPath, 'rb');
            if (!$handle) {
                return false;
            }
            
            // Read first 4 bytes to check ZIP signature
            $header = fread($handle, 4);
            fclose($handle);
            
            // Check ZIP signature (PK\x03\x04 or PK\x05\x06 or PK\x07\x08)
            return $header !== false && (
                substr($header, 0, 2) === 'PK' ||
                $header === "\x50\x4b\x03\x04" ||
                $header === "\x50\x4b\x05\x06" ||
                $header === "\x50\x4b\x07\x08"
            );
            
        } catch (\Exception $e) {
            $this->logger->warning("Quick integrity check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Ultra-fast validation based only on filename and size
     *
     * @param string $filename
     * @param int $fileSize
     * @return bool
     */
    public function ultraFastValidation(string $filename, int $fileSize): bool
    {
        // Basic size validation - must be at least 1KB but no more than 10GB
        if ($fileSize < 1024 || $fileSize > 10 * 1024 * 1024 * 1024) {
            return false;
        }
        
        // Basic filename validation
        $filename = strtolower($filename);
        
        // Patterns that suggest it's a valid backup
        $validPatterns = [
            'backup', 'prestashop', 'ps_copia', 'complete', 'database', 'files',
            'export', 'migration', 'site', 'dump', 'copy', 'copia'
        ];
        
        foreach ($validPatterns as $pattern) {
            if (strpos($filename, $pattern) !== false) {
                return true;
            }
        }
        
        // If it has a date in the filename, it's probably a backup
        if (preg_match('/\d{4}[-_]\d{2}[-_]\d{2}/', $filename)) {
            return true;
        }
        
        // Default to potentially valid (better to include than exclude)
        return true;
    }

    /**
     * Check if directory is an admin directory
     *
     * @param string $dirPath
     * @return bool
     */
    public function isAdminDirectory(string $dirPath): bool
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
     * Check if we're running in DDEV environment
     *
     * @return bool
     */
    public function isDdevEnvironment(): bool
    {
        // Check for DDEV environment variables
        if (getenv('DDEV_SITENAME') || getenv('DDEV_TLD')) {
            return true;
        }
        
        // Check for DDEV config file
        $ddevConfig = _PS_ROOT_DIR_ . '/.ddev/config.yaml';
        if (file_exists($ddevConfig)) {
            return true;
        }
        
        // Check if database host is 'db' (common in Docker environments including DDEV)
        if (defined('_DB_SERVER_') && _DB_SERVER_ === 'db') {
            return true;
        }
        
        return false;
    }
} 