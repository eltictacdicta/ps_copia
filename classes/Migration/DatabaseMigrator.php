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
use Db;

/**
 * Class responsible for migrating database content between different PrestaShop installations
 */
class DatabaseMigrator
{
    /** @var BackupContainer */
    private $backupContainer;

    /** @var BackupLogger */
    private $logger;

    /** @var Db */
    private $db;

    public function __construct(BackupContainer $backupContainer, BackupLogger $logger)
    {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->db = Db::getInstance();
    }

    /**
     * Migrate database content from external PrestaShop
     *
     * @param string $backupFile Path to database backup file
     * @param array $migrationConfig Migration configuration
     * @throws Exception
     */
    public function migrateDatabase(string $backupFile, array $migrationConfig): void
    {
        $this->logger->info("Starting database migration with configuration", $migrationConfig);

        // Validate migration config
        $this->validateMigrationConfig($migrationConfig);

        // Create temporary backup of current database
        $tempBackup = $this->createTemporaryBackup();

        try {
            // Restore the external database
            $this->restoreExternalDatabase($backupFile);

            // Apply URL migrations
            if (!empty($migrationConfig['old_url']) && !empty($migrationConfig['new_url'])) {
                $this->migrateUrls($migrationConfig['old_url'], $migrationConfig['new_url']);
            }

            // Apply admin directory migrations
            if (!empty($migrationConfig['old_admin_dir']) && !empty($migrationConfig['new_admin_dir'])) {
                $this->migrateAdminDirectory($migrationConfig['old_admin_dir'], $migrationConfig['new_admin_dir']);
            }

            // Update database configuration if provided
            if (!empty($migrationConfig['preserve_db_config']) && $migrationConfig['preserve_db_config']) {
                $this->preserveCurrentDbConfig();
            }

            // Update specific configurations
            $this->updateConfigurations($migrationConfig);

            // Clean temporary backup
            @unlink($tempBackup);

            $this->logger->info("Database migration completed successfully");

        } catch (Exception $e) {
            $this->logger->error("Database migration failed: " . $e->getMessage());
            
            // Restore from temporary backup
            try {
                $this->restoreExternalDatabase($tempBackup);
                $this->logger->info("Database restored from temporary backup");
            } catch (Exception $restoreError) {
                $this->logger->error("Failed to restore from temporary backup: " . $restoreError->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Validate migration configuration
     *
     * @param array $config
     * @throws Exception
     */
    private function validateMigrationConfig(array $config): void
    {
        // Check required fields if URL migration is enabled
        if (isset($config['migrate_urls']) && $config['migrate_urls']) {
            if (empty($config['old_url']) || empty($config['new_url'])) {
                throw new Exception('URLs antiguas y nuevas son requeridas para la migración de URLs');
            }

            // Validate URL format
            if (!filter_var($config['old_url'], FILTER_VALIDATE_URL) || !filter_var($config['new_url'], FILTER_VALIDATE_URL)) {
                throw new Exception('Las URLs deben tener un formato válido');
            }
        }

        // Check admin directory migration
        if (isset($config['migrate_admin_dir']) && $config['migrate_admin_dir']) {
            if (empty($config['old_admin_dir']) || empty($config['new_admin_dir'])) {
                throw new Exception('Directorios de admin antiguos y nuevos son requeridos para la migración');
            }
        }
    }

    /**
     * Create temporary backup of current database
     *
     * @return string Path to temporary backup file
     * @throws Exception
     */
    private function createTemporaryBackup(): string
    {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_temp_backup_' . time() . '.sql.gz';
        
        $command = sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s | gzip > %s',
            escapeshellarg(_DB_SERVER_),
            escapeshellarg(_DB_USER_),
            escapeshellarg(_DB_PASSWD_),
            escapeshellarg(_DB_NAME_),
            escapeshellarg($tempFile)
        );

        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Failed to create temporary backup: " . implode("\n", $output));
        }

        $this->logger->info("Temporary backup created: " . basename($tempFile));
        return $tempFile;
    }

    /**
     * Restore external database
     *
     * @param string $backupFile
     * @throws Exception
     */
    private function restoreExternalDatabase(string $backupFile): void
    {
        if (!file_exists($backupFile)) {
            throw new Exception("Database backup file does not exist: " . $backupFile);
        }

        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            $command = sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg(_DB_SERVER_),
                escapeshellarg(_DB_USER_),
                escapeshellarg(_DB_PASSWD_),
                escapeshellarg(_DB_NAME_)
            );
        } else {
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg(_DB_SERVER_),
                escapeshellarg(_DB_USER_),
                escapeshellarg(_DB_PASSWD_),
                escapeshellarg(_DB_NAME_),
                escapeshellarg($backupFile)
            );
        }

        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception("Database restoration failed: " . implode("\n", $output));
        }

        $this->logger->info("External database restored successfully");
    }

    /**
     * Migrate URLs in database
     *
     * @param string $oldUrl
     * @param string $newUrl
     * @throws Exception
     */
    private function migrateUrls(string $oldUrl, string $newUrl): void
    {
        $this->logger->info("Migrating URLs from {$oldUrl} to {$newUrl}");

        // Remove trailing slashes for consistency
        $oldUrl = rtrim($oldUrl, '/');
        $newUrl = rtrim($newUrl, '/');

        // Special handling for shop_url table first
        $this->migrateShopUrlTable($oldUrl, $newUrl);

        // Tables and columns that commonly contain URLs (excluding shop_url as it's handled separately)
        $urlTables = [
            'configuration' => ['value'],
            'cms' => ['link_rewrite'],
            'cms_lang' => ['link_rewrite'],
            'category' => ['link_rewrite'],
            'category_lang' => ['link_rewrite'],
            'product' => ['link_rewrite'],
            'product_lang' => ['link_rewrite'],
            'meta' => ['url_rewrite'],
            'meta_lang' => ['url_rewrite'],
        ];

        foreach ($urlTables as $table => $columns) {
            $fullTableName = _DB_PREFIX_ . $table;
            
            // Check if table exists
            if (!$this->tableExists($fullTableName)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!$this->columnExists($fullTableName, $column)) {
                    continue;
                }

                try {
                    $sql = "UPDATE `{$fullTableName}` SET `{$column}` = REPLACE(`{$column}`, '" . pSQL($oldUrl) . "', '" . pSQL($newUrl) . "') WHERE `{$column}` LIKE '%" . pSQL($oldUrl) . "%'";
                    $this->db->execute($sql);
                    
                    $this->logger->info("Updated URLs in table {$table}, column {$column}");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update URLs in {$table}.{$column}: " . $e->getMessage());
                }
            }
        }

        // Special handling for configuration table
        $this->migrateConfigurationUrls($oldUrl, $newUrl);
    }

    /**
     * Migrate shop_url table with specific handling for domain fields
     *
     * @param string $oldUrl
     * @param string $newUrl
     */
    private function migrateShopUrlTable(string $oldUrl, string $newUrl): void
    {
        $shopUrlTable = _DB_PREFIX_ . 'shop_url';
        
        // Check if table exists
        if (!$this->tableExists($shopUrlTable)) {
            $this->logger->warning("Table {$shopUrlTable} does not exist");
            return;
        }

        // Extract domain from URLs
        $oldParsed = parse_url($oldUrl);
        $newParsed = parse_url($newUrl);
        
        if (!$oldParsed || !$newParsed || !isset($oldParsed['host']) || !isset($newParsed['host'])) {
            $this->logger->error("Invalid URLs provided for shop_url migration");
            return;
        }

        $oldDomain = $oldParsed['host'];
        $newDomain = $newParsed['host'];

        try {
            // Update domain field
            if ($this->columnExists($shopUrlTable, 'domain')) {
                $sql = "UPDATE `{$shopUrlTable}` SET `domain` = '" . pSQL($newDomain) . "' WHERE `domain` = '" . pSQL($oldDomain) . "'";
                $this->db->execute($sql);
                $this->logger->info("Updated domain in shop_url table: {$oldDomain} -> {$newDomain}");
            }

            // Update domain_ssl field
            if ($this->columnExists($shopUrlTable, 'domain_ssl')) {
                $sql = "UPDATE `{$shopUrlTable}` SET `domain_ssl` = '" . pSQL($newDomain) . "' WHERE `domain_ssl` = '" . pSQL($oldDomain) . "'";
                $this->db->execute($sql);
                $this->logger->info("Updated domain_ssl in shop_url table: {$oldDomain} -> {$newDomain}");
            }

            // Also handle physical_uri if needed (base path)
            if ($this->columnExists($shopUrlTable, 'physical_uri')) {
                $oldPath = isset($oldParsed['path']) ? $oldParsed['path'] : '/';
                $newPath = isset($newParsed['path']) ? $newParsed['path'] : '/';
                
                // Ensure paths end with slash
                $oldPath = rtrim($oldPath, '/') . '/';
                $newPath = rtrim($newPath, '/') . '/';
                
                if ($oldPath !== $newPath) {
                    $sql = "UPDATE `{$shopUrlTable}` SET `physical_uri` = '" . pSQL($newPath) . "' WHERE `physical_uri` = '" . pSQL($oldPath) . "'";
                    $this->db->execute($sql);
                    $this->logger->info("Updated physical_uri in shop_url table: {$oldPath} -> {$newPath}");
                }
            }

            // Also handle virtual_uri if needed
            if ($this->columnExists($shopUrlTable, 'virtual_uri')) {
                $oldPath = isset($oldParsed['path']) ? trim($oldParsed['path'], '/') : '';
                $newPath = isset($newParsed['path']) ? trim($newParsed['path'], '/') : '';
                
                if ($oldPath !== $newPath) {
                    $sql = "UPDATE `{$shopUrlTable}` SET `virtual_uri` = '" . pSQL($newPath) . "' WHERE `virtual_uri` = '" . pSQL($oldPath) . "'";
                    $this->db->execute($sql);
                    $this->logger->info("Updated virtual_uri in shop_url table: {$oldPath} -> {$newPath}");
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Failed to update shop_url table: " . $e->getMessage());
        }
    }

    /**
     * Migrate URLs in configuration table
     *
     * @param string $oldUrl
     * @param string $newUrl
     */
    private function migrateConfigurationUrls(string $oldUrl, string $newUrl): void
    {
        // Extract domain from URLs for specific configurations
        $oldParsed = parse_url($oldUrl);
        $newParsed = parse_url($newUrl);
        
        if ($oldParsed && $newParsed && isset($oldParsed['host']) && isset($newParsed['host'])) {
            $oldDomain = $oldParsed['host'];
            $newDomain = $newParsed['host'];
            
            // Update domain-specific configurations
            $domainKeys = [
                'PS_SHOP_DOMAIN',
                'PS_SHOP_DOMAIN_SSL'
            ];
            
            foreach ($domainKeys as $key) {
                try {
                    $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` 
                            SET `value` = '" . pSQL($newDomain) . "' 
                            WHERE `name` = '" . pSQL($key) . "' AND `value` = '" . pSQL($oldDomain) . "'";
                            
                    $this->db->execute($sql);
                    
                    $this->logger->info("Updated domain configuration key: {$key} = {$newDomain}");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update domain configuration {$key}: " . $e->getMessage());
                }
            }
        }

        // General URL replacement for other configurations
        $urlKeys = [
            'PS_BASE_URI',
            'PS_SHOP_EMAIL',
            'PS_IMG_URL',
            'PS_CSS_URL',
            'PS_JS_URL',
            'PS_MEDIA_SERVER_1',
            'PS_MEDIA_SERVER_2',
            'PS_MEDIA_SERVER_3'
        ];

        foreach ($urlKeys as $key) {
            try {
                $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` 
                        SET `value` = REPLACE(`value`, '" . pSQL($oldUrl) . "', '" . pSQL($newUrl) . "') 
                        WHERE `name` = '" . pSQL($key) . "' AND `value` LIKE '%" . pSQL($oldUrl) . "%'";
                        
                $this->db->execute($sql);
                
                $this->logger->info("Updated URL configuration key: {$key}");
            } catch (Exception $e) {
                $this->logger->error("Failed to update URL configuration {$key}: " . $e->getMessage());
            }
        }
    }

    /**
     * Migrate admin directory references
     *
     * @param string $oldAdminDir
     * @param string $newAdminDir
     */
    private function migrateAdminDirectory(string $oldAdminDir, string $newAdminDir): void
    {
        $this->logger->info("Migrating admin directory from {$oldAdminDir} to {$newAdminDir}");

        // Remove slashes for consistency
        $oldAdminDir = trim($oldAdminDir, '/');
        $newAdminDir = trim($newAdminDir, '/');

        // Update configuration values that might contain admin directory references
        $configKeys = [
            'PS_ADMIN_DIR',
            'PS_BASE_URI'
        ];

        foreach ($configKeys as $key) {
            try {
                $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` 
                        SET `value` = REPLACE(`value`, '/" . pSQL($oldAdminDir) . "', '/" . pSQL($newAdminDir) . "') 
                        WHERE `name` = '" . pSQL($key) . "' AND `value` LIKE '%" . pSQL($oldAdminDir) . "%'";
                        
                $this->db->execute($sql);
                
                $this->logger->info("Updated admin directory in configuration key: {$key}");
            } catch (Exception $e) {
                $this->logger->error("Failed to update admin directory in configuration {$key}: " . $e->getMessage());
            }
        }

        // Update any hardcoded admin paths in configuration values
        try {
            $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` 
                    SET `value` = REPLACE(`value`, '/" . pSQL($oldAdminDir) . "/', '/" . pSQL($newAdminDir) . "/') 
                    WHERE `value` LIKE '%/" . pSQL($oldAdminDir) . "/%'";
                    
            $this->db->execute($sql);
            
            $this->logger->info("Updated hardcoded admin paths in configuration");
        } catch (Exception $e) {
            $this->logger->error("Failed to update hardcoded admin paths: " . $e->getMessage());
        }
    }

    /**
     * Preserve current database configuration
     */
    private function preserveCurrentDbConfig(): void
    {
        $this->logger->info("Preserving current database configuration");

        $currentConfig = [
            'PS_SHOP_DOMAIN' => defined('_PS_BASE_URL_') ? _PS_BASE_URL_ : '',
            'PS_SHOP_DOMAIN_SSL' => defined('_PS_BASE_URL_SSL_') ? _PS_BASE_URL_SSL_ : '',
            'PS_BASE_URI' => defined('__PS_BASE_URI__') ? __PS_BASE_URI__ : '',
        ];

        foreach ($currentConfig as $key => $value) {
            if (empty($value)) continue;
            
            try {
                $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($value) . "' WHERE `name` = '" . pSQL($key) . "'";
                $this->db->execute($sql);
                
                $this->logger->info("Preserved configuration: {$key} = {$value}");
            } catch (Exception $e) {
                $this->logger->error("Failed to preserve configuration {$key}: " . $e->getMessage());
            }
        }
    }

    /**
     * Update specific configurations
     *
     * @param array $config
     */
    private function updateConfigurations(array $config): void
    {
        if (!empty($config['configurations'])) {
            foreach ($config['configurations'] as $key => $value) {
                try {
                    $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($value) . "' WHERE `name` = '" . pSQL($key) . "'";
                    $this->db->execute($sql);
                    
                    $this->logger->info("Updated custom configuration: {$key} = {$value}");
                } catch (Exception $e) {
                    $this->logger->error("Failed to update custom configuration {$key}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if table exists
     *
     * @param string $tableName
     * @return bool
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $sql = "SHOW TABLES LIKE '" . pSQL($tableName) . "'";
            $result = $this->db->executeS($sql);
            return !empty($result);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if column exists in table
     *
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        try {
            $sql = "SHOW COLUMNS FROM `{$tableName}` LIKE '" . pSQL($columnName) . "'";
            $result = $this->db->executeS($sql);
            return !empty($result);
        } catch (Exception $e) {
            return false;
        }
    }
} 