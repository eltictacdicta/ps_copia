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
        
        // Log specific URL migration configuration
        $migrate_urls = isset($migrationConfig['migrate_urls']) ? $migrationConfig['migrate_urls'] : false;
        $old_url = isset($migrationConfig['old_url']) ? $migrationConfig['old_url'] : '';
        $new_url = isset($migrationConfig['new_url']) ? $migrationConfig['new_url'] : '';
        
        $this->logger->info("URL Migration Configuration", [
            'migrate_urls' => $migrate_urls,
            'old_url' => $old_url,
            'new_url' => $new_url,
            'will_migrate_urls' => ($migrate_urls && !empty($old_url) && !empty($new_url))
        ]);

        // Validate migration config
        $this->validateMigrationConfig($migrationConfig);

        // Create temporary backup of current database
        $tempBackup = $this->createTemporaryBackup();

        try {
            // Restore the external database
            $this->restoreExternalDatabase($backupFile);

            // Apply URL migrations
            if (isset($migrationConfig['migrate_urls']) && $migrationConfig['migrate_urls'] && 
                !empty($migrationConfig['old_url']) && !empty($migrationConfig['new_url'])) {
                $this->migrateUrls($migrationConfig['old_url'], $migrationConfig['new_url']);
            } else {
                // Always update shop_url table with current domain even if URLs are not explicitly configured
                $this->updateShopUrlTable();
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

            // Force update shop_url if requested or if migration was incomplete
            if (isset($migrationConfig['force_shop_url_update']) && $migrationConfig['force_shop_url_update']) {
                $this->forceUpdateShopUrl($migrationConfig);
            }

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
        $newDomain = parse_url($newUrl, PHP_URL_HOST);
        
        $this->logger->info("Extracted domain from new URL: " . ($newDomain ?: 'NULL'));

        // Update shop_url table with the new domain
        try {
            $shopUrlTable = _DB_PREFIX_ . 'shop_url';
            
            if (!$this->tableExists($shopUrlTable)) {
                $this->logger->warning("shop_url table does not exist");
            } elseif (!$newDomain) {
                $this->logger->error("Could not extract domain from new URL: {$newUrl}");
            } else {
                // Log current state before update
                $currentData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                $this->logger->info("Current shop_url data before update", $currentData ?: []);
                
                // Update both domain and domain_ssl, and also update the physical_uri and virtual_uri if needed
                $newParsedUrl = parse_url($newUrl);
                $physicalUri = isset($newParsedUrl['path']) ? rtrim($newParsedUrl['path'], '/') . '/' : '/';
                
                $sql = "UPDATE `{$shopUrlTable}` SET 
                        `domain` = '" . pSQL($newDomain) . "', 
                        `domain_ssl` = '" . pSQL($newDomain) . "',
                        `physical_uri` = '" . pSQL($physicalUri) . "'";
                        
                $result = $this->db->execute($sql);
                
                $this->logger->info("Update query executed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // Log state after update
                $newData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                $this->logger->info("New shop_url data after update", $newData ?: []);
                
                $this->logger->info("Updated shop_url table - domain: {$newDomain}, physical_uri: {$physicalUri}");
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to update shop_url table: " . $e->getMessage());
        }

        // Tables and columns that commonly contain URLs
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
     * Migrate URLs in configuration table
     *
     * @param string $oldUrl
     * @param string $newUrl
     */
    private function migrateConfigurationUrls(string $oldUrl, string $newUrl): void
    {
        $configKeys = [
            'PS_SHOP_DOMAIN',
            'PS_SHOP_DOMAIN_SSL',
            'PS_BASE_URI',
            'PS_SHOP_EMAIL',
            'PS_IMG_URL',
            'PS_CSS_URL',
            'PS_JS_URL',
            'PS_MEDIA_SERVER_1',
            'PS_MEDIA_SERVER_2',
            'PS_MEDIA_SERVER_3'
        ];

        foreach ($configKeys as $key) {
            try {
                $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` 
                        SET `value` = REPLACE(`value`, '" . pSQL($oldUrl) . "', '" . pSQL($newUrl) . "') 
                        WHERE `name` = '" . pSQL($key) . "' AND `value` LIKE '%" . pSQL($oldUrl) . "%'";
                        
                $this->db->execute($sql);
                
                $this->logger->info("Updated configuration key: {$key}");
            } catch (Exception $e) {
                $this->logger->error("Failed to update configuration {$key}: " . $e->getMessage());
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

    /**
     * Update shop_url table with current domain
     * This method is called when no specific URL migration is configured
     * but we still want to update the shop_url table with the current domain
     */
    private function updateShopUrlTable(): void
    {
        $this->logger->info("Updating shop_url table with current domain (no URL migration specified)");

        try {
            // Get current domain from various sources
            $currentDomain = $this->getCurrentDomain();
            
            $this->logger->info("Detected current domain: " . ($currentDomain ?: 'NULL'));
            
            if (!$currentDomain) {
                $this->logger->warning("Could not determine current domain from any source");
                
                // Try to use a fallback domain from server configuration
                if (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
                    $currentDomain = $_SERVER['SERVER_NAME'];
                    $this->logger->info("Using SERVER_NAME as fallback domain: " . $currentDomain);
                } elseif (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
                    $currentDomain = $_SERVER['HTTP_HOST'];
                    $this->logger->info("Using HTTP_HOST as fallback domain: " . $currentDomain);
                } else {
                    $this->logger->error("Could not determine any domain, skipping shop_url update");
                    return;
                }
            }

            $shopUrlTable = _DB_PREFIX_ . 'shop_url';
            if ($this->tableExists($shopUrlTable)) {
                // Log current state before update
                $currentData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                $this->logger->info("Current shop_url data before update", $currentData ?: []);
                
                // Update domain and domain_ssl
                $sql = "UPDATE `{$shopUrlTable}` SET `domain` = '" . pSQL($currentDomain) . "', `domain_ssl` = '" . pSQL($currentDomain) . "'";
                $result = $this->db->execute($sql);
                
                $this->logger->info("Update query executed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // Log state after update
                $newData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                $this->logger->info("New shop_url data after update", $newData ?: []);
                
                $this->logger->info("Updated domain and domain_ssl in {$shopUrlTable} to {$currentDomain}");
            } else {
                $this->logger->warning("shop_url table does not exist");
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to update shop_url table: " . $e->getMessage());
        }
    }

    /**
     * Get current domain from various sources
     *
     * @return string|null
     */
    private function getCurrentDomain(): ?string
    {
        $this->logger->info("Attempting to detect current domain...");
        
        // Try to get domain from HTTP_HOST
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $this->logger->info("Found domain from HTTP_HOST: " . $_SERVER['HTTP_HOST']);
            return $_SERVER['HTTP_HOST'];
        }
        
        // Try to get from SERVER_NAME
        if (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $this->logger->info("Found domain from SERVER_NAME: " . $_SERVER['SERVER_NAME']);
            return $_SERVER['SERVER_NAME'];
        }
        
        // Try to get from configuration if available
        try {
            $sql = "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_DOMAIN' LIMIT 1";
            $result = $this->db->executeS($sql);
            if (!empty($result) && !empty($result[0]['value'])) {
                $this->logger->info("Found domain from PS_SHOP_DOMAIN config: " . $result[0]['value']);
                return $result[0]['value'];
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to get domain from configuration: " . $e->getMessage());
        }
        
        // Try to get from Context if available
        if (class_exists('Context') && Context::getContext() && Context::getContext()->shop) {
            try {
                $shop = Context::getContext()->shop;
                if (method_exists($shop, 'getBaseURL')) {
                    $baseUrl = $shop->getBaseURL(true);
                    $parsedUrl = parse_url($baseUrl);
                    if (isset($parsedUrl['host'])) {
                        $this->logger->info("Found domain from Context shop: " . $parsedUrl['host']);
                        return $parsedUrl['host'];
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to get domain from Context: " . $e->getMessage());
            }
        }
        
        // As a last resort, try to extract from current shop_url table
        try {
            $sql = "SELECT `domain` FROM `" . _DB_PREFIX_ . "shop_url` WHERE `domain` != '' AND `domain` IS NOT NULL LIMIT 1";
            $result = $this->db->executeS($sql);
            if (!empty($result) && !empty($result[0]['domain'])) {
                // But only if it's not obviously from a different environment
                $domain = $result[0]['domain'];
                if (!strpos($domain, 'localhost') && !strpos($domain, '127.0.0.1') && !strpos($domain, '.local')) {
                    $this->logger->info("Found domain from existing shop_url: " . $domain);
                    return $domain;
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to get domain from shop_url table: " . $e->getMessage());
        }
        
                 $this->logger->warning("Could not detect domain from any source");
         return null;
     }

     /**
      * Force update shop_url table with specified configuration
      * This method can be called to manually update shop_url when automatic detection fails
      *
      * @param array $migrationConfig
      */
     private function forceUpdateShopUrl(array $migrationConfig): void
     {
         $this->logger->info("Force updating shop_url table");

         try {
             $shopUrlTable = _DB_PREFIX_ . 'shop_url';
             
             if (!$this->tableExists($shopUrlTable)) {
                 $this->logger->error("shop_url table does not exist, cannot force update");
                 return;
             }

             // Determine what domain to use
             $targetDomain = null;
             $targetPath = '/';

             // Use new_url if available
             if (!empty($migrationConfig['new_url'])) {
                 $parsedUrl = parse_url($migrationConfig['new_url']);
                 $targetDomain = $parsedUrl['host'] ?? null;
                 $targetPath = isset($parsedUrl['path']) ? rtrim($parsedUrl['path'], '/') . '/' : '/';
                 $this->logger->info("Using new_url for force update: {$migrationConfig['new_url']}");
             } 
             // Fallback to current domain detection
             else {
                 $targetDomain = $this->getCurrentDomain();
                 $this->logger->info("Using detected current domain for force update: " . ($targetDomain ?: 'NULL'));
             }

             if (!$targetDomain) {
                 $this->logger->error("Could not determine target domain for force update");
                 return;
             }

             // Log current state
             $currentData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
             $this->logger->info("Current shop_url data before force update", $currentData ?: []);

             // Update the shop_url table
             $sql = "UPDATE `{$shopUrlTable}` SET 
                     `domain` = '" . pSQL($targetDomain) . "', 
                     `domain_ssl` = '" . pSQL($targetDomain) . "',
                     `physical_uri` = '" . pSQL($targetPath) . "'";
                     
             $result = $this->db->execute($sql);
             
             $this->logger->info("Force update query executed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
             
             // Log state after update
             $newData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
             $this->logger->info("New shop_url data after force update", $newData ?: []);
             
             $this->logger->info("Force updated shop_url table - domain: {$targetDomain}, physical_uri: {$targetPath}");

         } catch (Exception $e) {
             $this->logger->error("Failed to force update shop_url table: " . $e->getMessage());
         }
     }
} 