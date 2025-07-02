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

namespace PrestaShop\Module\PsCopia;

use Exception;
use PrestaShop\Module\PsCopia\UpgradeTools\Translator;
use Symfony\Component\Filesystem\Filesystem;
use Context;
use Db;

/**
 * Class responsible of the management of backup and restore operations
 * Simplified version focused on backup and restore functionality
 */
class BackupContainer
{
    const WORKSPACE_PATH = 'workspace';
    const BACKUP_PATH = 'backup';
    const LOGS_PATH = 'logs';
    const PS_ROOT_PATH = 'ps_root';
    const PS_ADMIN_PATH = 'ps_admin';

    /** @var Filesystem|null */
    private $fileSystem;

    /** @var Translator|null */
    private $translator;

    /** @var Db|null */
    private $db;

    /**
     * @var string Absolute path to ps root folder of PS
     */
    private $psRootDir;

    /**
     * @var string Absolute path to the admin folder
     */
    private $adminDir;

    /**
     * @var string Path to the backup working directory
     */
    private $backupWorkDir;

    /**
     * @var string Module subdirectory name
     */
    private $moduleSubDir;

    public function __construct(string $psRootDir, string $adminDir, string $moduleSubDir = 'ps_copia')
    {
        $this->psRootDir = rtrim($psRootDir, DIRECTORY_SEPARATOR);
        $this->adminDir = rtrim($adminDir, DIRECTORY_SEPARATOR);
        $this->moduleSubDir = $moduleSubDir;
        $this->backupWorkDir = $this->adminDir . DIRECTORY_SEPARATOR . $moduleSubDir;
    }

    /**
     * Get various paths used by the module
     *
     * @param string $property
     * @return string
     */
    public function getProperty(string $property): string
    {
        switch ($property) {
            case self::PS_ROOT_PATH:
                return $this->psRootDir;
            case self::PS_ADMIN_PATH:
                return $this->adminDir;
            case self::WORKSPACE_PATH:
                return $this->backupWorkDir;
            case self::BACKUP_PATH:
                return $this->backupWorkDir . DIRECTORY_SEPARATOR . 'backup';
            case self::LOGS_PATH:
                return $this->backupWorkDir . DIRECTORY_SEPARATOR . 'logs';
            default:
                return '';
        }
    }

    /**
     * Get filesystem instance
     *
     * @return Filesystem
     */
    public function getFileSystem(): Filesystem
    {
        if (null === $this->fileSystem) {
            $this->fileSystem = new Filesystem();
        }

        return $this->fileSystem;
    }

    /**
     * Get translator instance
     *
     * @return Translator
     */
    public function getTranslator(): Translator
    {
        if (null === $this->translator) {
            $languageCode = 'en';
            
            // Try to get current language from context
            if (class_exists('Context') && Context::getContext() && Context::getContext()->language) {
                $languageCode = Context::getContext()->language->iso_code;
            }
            
            $this->translator = new Translator(
                $this->psRootDir . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'ps_copia' . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR,
                $languageCode
            );
        }

        return $this->translator;
    }

    /**
     * Get database instance
     *
     * @return Db
     */
    public function getDb(): Db
    {
        if (null === $this->db) {
            $this->db = Db::getInstance();
        }

        return $this->db;
    }

    /**
     * Get current PrestaShop version
     *
     * @return string
     */
    public function getPsVersion(): string
    {
        if (defined('_PS_VERSION_')) {
            return _PS_VERSION_;
        }

        // Fallback: try to read from settings file
        $settingsFile = $this->psRootDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.inc.php';
        if ($this->getFileSystem()->exists($settingsFile)) {
            $content = file_get_contents($settingsFile);
            if (preg_match("/define\(['\"]_PS_VERSION_['\"], ['\"](.*?)['\"]\)/", $content, $matches)) {
                return $matches[1];
            }
        }

        return '1.7.0.0'; // Fallback version
    }

    /**
     * Get a unique filename for the backup.
     *
     * @param bool $isDatabase
     * @param string|null $customName Custom name for the backup
     *
     * @return string
     */
    public function getBackupFilename(bool $isDatabase = true, ?string $customName = null): string
    {
        $prefix = $isDatabase ? 'db_backup_' : 'files_backup_';
        $timestamp = date('Y-m-d_H-i-s');
        
        if ($customName) {
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customName);
            
            // Avoid prefix duplication - if customName already contains "backup", don't add prefix
            if (strpos($safeName, 'backup') === 0) {
                // If it starts with backup, just use it as is with timestamp
                $filename = $safeName . '_' . $timestamp;
            } else {
                // Normal case - add prefix
                $filename = $prefix . $safeName . '_' . $timestamp;
            }
        } else {
            $hash = substr(md5(uniqid()), 0, 8);
            $filename = $prefix . $timestamp . '_' . $hash;
        }
        
        $extension = $isDatabase ? '.sql.gz' : '.zip';

        return $this->getProperty(self::BACKUP_PATH) . DIRECTORY_SEPARATOR . $filename . $extension;
    }

    /**
     * Create necessary directories for backup operations
     *
     * @throws Exception
     */
    public function initDirectories(): void
    {
        $directories = [
            $this->getProperty(self::WORKSPACE_PATH),
            $this->getProperty(self::BACKUP_PATH),
            $this->getProperty(self::LOGS_PATH),
        ];

        foreach ($directories as $directory) {
            if (!$this->getFileSystem()->exists($directory)) {
                try {
                    $this->getFileSystem()->mkdir($directory, 0755);
                } catch (Exception $e) {
                    throw new Exception("Cannot create directory: {$directory}. " . $e->getMessage());
                }
            }
            
            // Verify directory is writable
            if (!is_writable($directory)) {
                throw new Exception("Directory is not writable: {$directory}");
            }
        }
    }

    /**
     * Get available backups list
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableBackups(): array
    {
        $backupDir = $this->getProperty(self::BACKUP_PATH);
        $backups = [];
        
        if (!is_dir($backupDir)) {
            return $backups;
        }
        
        $files = scandir($backupDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'backup_metadata.json') {
                continue;
            }
            
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) {
                continue;
            }
            
            $backups[] = [
                'name' => $file,
                'size' => filesize($filePath),
                'size_formatted' => $this->formatBytes(filesize($filePath)),
                'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                'timestamp' => filemtime($filePath),
                'type' => $this->getBackupType($file),
                'path' => $filePath,
            ];
        }
        
        // Sort by date (most recent first)
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $backups;
    }

    /**
     * Determine backup type from filename
     *
     * @param string $filename
     * @return string
     */
    private function getBackupType(string $filename): string
    {
        if (strpos($filename, 'db_backup_') === 0) {
            return 'database';
        } elseif (strpos($filename, 'files_backup_') === 0) {
            return 'files';
        }
        
        return 'unknown';
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
     * Delete a backup file
     *
     * @param string $filename
     * @return bool
     * @throws Exception
     */
    public function deleteBackup(string $filename): bool
    {
        $backupPath = $this->getProperty(self::BACKUP_PATH) . DIRECTORY_SEPARATOR . $filename;
        
        if (!$this->getFileSystem()->exists($backupPath)) {
            throw new Exception("Backup file does not exist: {$filename}");
        }
        
        try {
            $this->getFileSystem()->remove($backupPath);
            return true;
        } catch (Exception $e) {
            throw new Exception("Cannot delete backup file: {$filename}. " . $e->getMessage());
        }
    }

    /**
     * Validate backup file
     *
     * @param string $filename
     * @return bool
     * @throws Exception
     */
    public function validateBackupFile(string $filename): bool
    {
        $backupPath = $this->getProperty(self::BACKUP_PATH) . DIRECTORY_SEPARATOR . $filename;
        
        if (!$this->getFileSystem()->exists($backupPath)) {
            throw new Exception("Backup file does not exist: {$filename}");
        }
        
        if (filesize($backupPath) === 0) {
            throw new Exception("Backup file is empty: {$filename}");
        }
        
        // Additional validation based on file type
        $type = $this->getBackupType($filename);
        
        if ($type === 'database') {
            return $this->validateDatabaseBackup($backupPath);
        } elseif ($type === 'files') {
            return $this->validateFilesBackup($backupPath);
        }
        
        return true;
    }

    /**
     * Validate database backup file
     *
     * @param string $backupPath
     * @return bool
     */
    private function validateDatabaseBackup(string $backupPath): bool
    {
        // For .sql.gz files, try to read first few bytes
        if (pathinfo($backupPath, PATHINFO_EXTENSION) === 'gz') {
            $fp = gzopen($backupPath, 'r');
            if ($fp === false) {
                return false;
            }
            
            $header = gzread($fp, 1024);
            gzclose($fp);
            
            // Check for SQL header patterns
            return strpos($header, 'SQL') !== false || strpos($header, 'INSERT') !== false || strpos($header, 'CREATE') !== false;
        }
        
        return true;
    }

    /**
     * Validate files backup (ZIP)
     *
     * @param string $backupPath
     * @return bool
     */
    private function validateFilesBackup(string $backupPath): bool
    {
        if (!extension_loaded('zip')) {
            return true; // Cannot validate without ZIP extension
        }
        
        $zip = new \ZipArchive();
        $result = $zip->open($backupPath, \ZipArchive::CHECKCONS);
        
        if ($result === true) {
            $zip->close();
            return true;
        }
        
        return false;
    }

    /**
     * Clean old backup files (keep only specified number)
     *
     * @param int $keepCount Number of backups to keep
     * @return int Number of files deleted
     */
    public function cleanOldBackups(int $keepCount = 5): int
    {
        $backups = $this->getAvailableBackups();
        $deleted = 0;
        
        if (count($backups) <= $keepCount) {
            return 0;
        }
        
        // Remove oldest backups
        $toDelete = array_slice($backups, $keepCount);
        
        foreach ($toDelete as $backup) {
            try {
                $this->deleteBackup($backup['name']);
                $deleted++;
            } catch (Exception $e) {
                // Log error but continue
                error_log("PS_Copia: Could not delete old backup {$backup['name']}: " . $e->getMessage());
            }
        }
        
        return $deleted;
    }

    /**
     * Get disk space information
     *
     * @return array<string, mixed>
     */
    public function getDiskSpaceInfo(): array
    {
        $backupPath = $this->getProperty(self::BACKUP_PATH);
        
        return [
            'free_space' => disk_free_space($backupPath),
            'total_space' => disk_total_space($backupPath),
            'used_space' => disk_total_space($backupPath) - disk_free_space($backupPath),
            'free_space_formatted' => $this->formatBytes(disk_free_space($backupPath)),
            'total_space_formatted' => $this->formatBytes(disk_total_space($backupPath)),
            'used_space_formatted' => $this->formatBytes(disk_total_space($backupPath) - disk_free_space($backupPath)),
        ];
    }
} 