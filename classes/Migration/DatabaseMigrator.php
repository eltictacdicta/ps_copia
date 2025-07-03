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
        
        // Auto-detect URLs if not provided
        $migrationConfig = $this->autoDetectUrls($backupFile, $migrationConfig);
        
        // Log specific URL migration configuration
        $migrate_urls = isset($migrationConfig['migrate_urls']) ? $migrationConfig['migrate_urls'] : false;
        $old_url = isset($migrationConfig['old_url']) ? $migrationConfig['old_url'] : '';
        $new_url = isset($migrationConfig['new_url']) ? $migrationConfig['new_url'] : '';
        
        $this->logger->info("URL Migration Configuration (after auto-detection)", [
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

            // Apply URL migrations - ALWAYS attempt some form of URL migration
            if (isset($migrationConfig['migrate_urls']) && $migrationConfig['migrate_urls'] && 
                !empty($migrationConfig['old_url']) && !empty($migrationConfig['new_url'])) {
                $this->logger->info("URL migration enabled - executing complete URL migration");
                $this->migrateUrls($migrationConfig['old_url'], $migrationConfig['new_url']);
            } elseif (!empty($migrationConfig['new_url'])) {
                // Si tenemos URL de destino pero no de origen, forzamos actualización
                $this->logger->info("Destination URL available - forcing shop_url update to overwrite backup URLs");
                $this->forceUpdateShopUrl($migrationConfig);
                // También intentar migración básica de configuración
                $this->updateDomainConfigurationForced($migrationConfig['new_url']);
            } else {
                $this->logger->info("No destination URL detected - attempting fallback shop_url update", [
                    'migrate_urls_flag' => isset($migrationConfig['migrate_urls']) ? $migrationConfig['migrate_urls'] : 'not_set',
                    'old_url_present' => !empty($migrationConfig['old_url']),
                    'new_url_present' => !empty($migrationConfig['new_url'])
                ]);
                // Always update shop_url table with current domain even if URLs are not explicitly configured
                $this->updateShopUrlTable();
            }

            // NOTA: Ya no migramos directorios admin - siempre se mantiene la configuración original del backup
            // La carpeta admin conservará la URL/configuración antigua independientemente de la configuración
            $this->logger->info("Preservando configuración de directorio admin del backup original");
            $this->preserveAdminConfiguration();

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
        // Check required fields if URL migration is enabled AND auto-detection failed
        if (isset($config['migrate_urls']) && $config['migrate_urls']) {
            // Only require URLs if auto-detection was unable to find them
            if (empty($config['old_url']) && empty($config['new_url'])) {
                $this->logger->warning('No se pudieron autodetectar las URLs, pero la migración de URLs sigue habilitada');
            } elseif (empty($config['old_url']) || empty($config['new_url'])) {
                $this->logger->info('Solo se detectó una URL (origen o destino), la migración continuará con URLs disponibles');
            }

            // Validate URL format only if URLs are present
            if (!empty($config['old_url']) && !filter_var($config['old_url'], FILTER_VALIDATE_URL)) {
                throw new Exception('La URL antigua debe tener un formato válido: ' . $config['old_url']);
            }
            if (!empty($config['new_url']) && !filter_var($config['new_url'], FILTER_VALIDATE_URL)) {
                throw new Exception('La URL nueva debe tener un formato válido: ' . $config['new_url']);
            }
        }

        // NOTA: Ya no validamos directorios admin - siempre se mantiene la configuración original del backup
        // La carpeta admin mantendrá siempre la URL/configuración antigua (del backup)
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
        
        // Extract domains (without protocol, as stored in PrestaShop)
        $oldDomain = parse_url($oldUrl, PHP_URL_HOST);
        $newDomain = parse_url($newUrl, PHP_URL_HOST);
        
        // Remove port from domain if present (PrestaShop stores domain without port)
        if ($oldDomain && strpos($oldDomain, ':') !== false) {
            $oldDomain = explode(':', $oldDomain)[0];
        }
        if ($newDomain && strpos($newDomain, ':') !== false) {
            $newDomain = explode(':', $newDomain)[0];
        }
        
        $this->logger->info("Domain migration: {$oldDomain} → {$newDomain}");

        // Update shop_url table with the new domain (without protocol)
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
                
                // Update both domain and domain_ssl (PrestaShop stores domains without protocol)
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

                // NEW: Also update configuration keys PS_SHOP_DOMAIN and PS_SHOP_DOMAIN_SSL
                $this->updateDomainConfiguration($newDomain);
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
        $this->logger->info("AGGRESSIVELY updating shop_url table to overwrite backup URLs");

        try {
            // Get current domain from various sources - be more aggressive
            $currentDomain = $this->getCurrentDomain();
            
            $this->logger->info("Detected current domain: " . ($currentDomain ?: 'NULL'));
            
            if (!$currentDomain) {
                $this->logger->warning("Could not determine current domain - trying all fallbacks");
                
                // Try multiple fallbacks in order of preference
                $fallbacks = [
                    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? '',
                    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '',
                    'localhost' => 'localhost'
                ];
                
                foreach ($fallbacks as $source => $domain) {
                    if (!empty($domain)) {
                        $currentDomain = $domain;
                        $this->logger->info("Using {$source} as fallback domain: " . $currentDomain);
                        break;
                    }
                }
                
                if (!$currentDomain) {
                    $this->logger->error("All fallbacks failed, cannot update shop_url");
                    return;
                }
            }

            // Remove port if present
            if (strpos($currentDomain, ':') !== false) {
                $originalDomain = $currentDomain;
                $currentDomain = explode(':', $currentDomain)[0];
                $this->logger->info("Removed port from domain: {$originalDomain} → {$currentDomain}");
            }

            $shopUrlTable = _DB_PREFIX_ . 'shop_url';
            if ($this->tableExists($shopUrlTable)) {
                // Log current state before update with error handling
                try {
                    $currentData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                    $this->logger->info("Current shop_url data before AGGRESSIVE update", $currentData ?: []);
                } catch (Exception $e) {
                    $this->logger->warning("Could not read current shop_url data: " . $e->getMessage());
                }
                
                // Update domain, domain_ssl, and physical_uri with WHERE clause
                $sql = "UPDATE `{$shopUrlTable}` SET 
                        `domain` = '" . pSQL($currentDomain) . "', 
                        `domain_ssl` = '" . pSQL($currentDomain) . "',
                        `physical_uri` = '/' 
                        WHERE `id_shop_url` > 0";
                $result = $this->db->execute($sql);
                
                if ($result) {
                    $this->logger->info("AGGRESSIVE update query executed successfully");
                    
                    // Log state after update with error handling
                    try {
                        $newData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                        $this->logger->info("New shop_url data after AGGRESSIVE update", $newData ?: []);
                    } catch (Exception $e) {
                        $this->logger->warning("Could not read updated shop_url data: " . $e->getMessage());
                    }
                    
                    $this->logger->info("AGGRESSIVELY updated shop_url table - domain: {$currentDomain}, physical_uri: /");
                } else {
                    $this->logger->error("AGGRESSIVE update query failed to execute");
                }

                // Also update configuration keys PS_SHOP_DOMAIN and PS_SHOP_DOMAIN_SSL
                $this->updateDomainConfiguration($currentDomain);
            } else {
                $this->logger->warning("shop_url table does not exist");
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to AGGRESSIVELY update shop_url table: " . $e->getMessage());
        }
    }

    /**
     * Get current domain from various sources
     *
     * @return string|null
     */
    private function getCurrentDomain(): ?string
    {
        $this->logger->info("AGGRESSIVELY attempting to detect current domain for URL overwrite...");
        
        $domain = null;
        
        // Priority 1: Try to get domain from HTTP_HOST (most reliable for current request)
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $domain = $_SERVER['HTTP_HOST'];
            $this->logger->info("Found domain from HTTP_HOST (priority 1): " . $domain);
        }
        // Priority 2: Try to get from SERVER_NAME
        elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $domain = $_SERVER['SERVER_NAME'];
            $this->logger->info("Found domain from SERVER_NAME (priority 2): " . $domain);
        }
        // Priority 3: Try environment variables or common alternatives
        elseif (!empty(getenv('HTTP_HOST'))) {
            $domain = getenv('HTTP_HOST');
            $this->logger->info("Found domain from HTTP_HOST env var (priority 3): " . $domain);
        }
        elseif (!empty(getenv('SERVER_NAME'))) {
            $domain = getenv('SERVER_NAME');
            $this->logger->info("Found domain from SERVER_NAME env var (priority 4): " . $domain);
        }
        
        // Remove port from domain if present (PrestaShop stores domain without port)
        if ($domain && strpos($domain, ':') !== false) {
            $originalDomain = $domain;
            $domain = explode(':', $domain)[0];
            $this->logger->info("Removed port from domain: {$originalDomain} → {$domain}");
        }
        
        // Basic validation - reject obviously invalid domains
        if ($domain && ($domain === 'localhost' || $domain === '127.0.0.1' || strpos($domain, '.local') !== false)) {
            $this->logger->warning("Detected local domain ({$domain}), will try other sources");
            $localDomain = $domain; // Keep as fallback
            $domain = null;
        }
        
        if ($domain) {
            $this->logger->info("Successfully detected current domain: " . $domain);
            return $domain;
        }
        
        // Try to get from Context if available (PrestaShop specific)
        if (class_exists('Context')) {
            try {
                $context = Context::getContext();
                if ($context && isset($context->shop) && $context->shop) {
                    $shop = $context->shop;
                    if (method_exists($shop, 'getBaseURL')) {
                        $baseUrl = $shop->getBaseURL(true);
                        $parsedUrl = parse_url($baseUrl);
                        if (isset($parsedUrl['host'])) {
                            $domain = $parsedUrl['host'];
                            // Remove port if present
                            if (strpos($domain, ':') !== false) {
                                $domain = explode(':', $domain)[0];
                            }
                            $this->logger->info("Found domain from Context shop: " . $domain);
                            return $domain;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to get domain from Context: " . $e->getMessage());
            }
        }
        
        // Try to get from configuration if available (but this might be from backup, so be careful)
        try {
            $sql = "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_DOMAIN' LIMIT 1";
            $result = $this->db->executeS($sql);
            if (!empty($result) && !empty($result[0]['value'])) {
                $configDomain = $result[0]['value'];
                // Only use if it doesn't look like a backup domain
                if (!strpos($configDomain, 'localhost') && !strpos($configDomain, '127.0.0.1') && !strpos($configDomain, '.local')) {
                    $this->logger->info("Found domain from PS_SHOP_DOMAIN config: " . $configDomain);
                    return $configDomain;
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to get domain from configuration: " . $e->getMessage());
        }
        
        // As a last resort, use the local domain we found earlier
        if (isset($localDomain)) {
            $this->logger->warning("Using local domain as last resort: " . $localDomain);
            return $localDomain;
        }
        
        $this->logger->error("Could not detect domain from ANY source - this will cause URL migration issues");
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

             // Log current state with better error handling
             try {
                 $currentData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                 $this->logger->info("Current shop_url data before force update", $currentData ?: []);
             } catch (Exception $e) {
                 $this->logger->warning("Could not read current shop_url data: " . $e->getMessage());
             }

             // Update the shop_url table with improved SQL syntax
             $sql = "UPDATE `{$shopUrlTable}` SET 
                     `domain` = '" . pSQL($targetDomain) . "', 
                     `domain_ssl` = '" . pSQL($targetDomain) . "',
                     `physical_uri` = '" . pSQL($targetPath) . "' 
                     WHERE `id_shop_url` > 0";
                     
             $result = $this->db->execute($sql);
             
             if ($result) {
                 $this->logger->info("Force update query executed successfully");
                 
                 // Log state after update with better error handling
                 try {
                     $newData = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                     $this->logger->info("New shop_url data after force update", $newData ?: []);
                 } catch (Exception $e) {
                     $this->logger->warning("Could not read updated shop_url data: " . $e->getMessage());
                 }
                 
                 $this->logger->info("Force updated shop_url table - domain: {$targetDomain}, physical_uri: {$targetPath}");
             } else {
                 $this->logger->error("Force update query failed to execute");
             }

         } catch (Exception $e) {
             $this->logger->error("Failed to force update shop_url table: " . $e->getMessage());
             
             // Try alternative approach with simpler SQL
             try {
                 $this->logger->info("Attempting alternative shop_url update approach");
                 $simpleSql = "UPDATE `{$shopUrlTable}` SET domain = '" . pSQL($targetDomain) . "', domain_ssl = '" . pSQL($targetDomain) . "' WHERE id_shop_url = 1";
                 $simpleResult = $this->db->execute($simpleSql);
                 
                 if ($simpleResult) {
                     $this->logger->info("Alternative shop_url update succeeded");
                 } else {
                     $this->logger->error("Alternative shop_url update also failed");
                 }
             } catch (Exception $e2) {
                 $this->logger->error("Alternative shop_url update threw exception: " . $e2->getMessage());
             }
         }
     }

     /**
      * Auto-detect source and destination URLs if not provided
      *
      * @param string $backupFile
      * @param array $migrationConfig
      * @return array Updated migration config
      */
     private function autoDetectUrls(string $backupFile, array $migrationConfig): array
     {
         $this->logger->info("Auto-detecting URLs for migration - ALWAYS enabling URL migration");

         // SIEMPRE auto-detectar y habilitar migración de URLs para sobrescribir datos del backup
         $shouldAutoDetect = true;
         
         $this->logger->info("URL auto-detection ALWAYS enabled to overwrite backup URLs");

         // Detect destination URL (current system) - this is fast and ALWAYS needed
         if (empty($migrationConfig['new_url'])) {
             $currentDomain = $this->getCurrentDomain();
             if ($currentDomain) {
                 // Build full URL with protocol
                 $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                 $migrationConfig['new_url'] = $protocol . '://' . $currentDomain;
                 $this->logger->info("Auto-detected destination URL: " . $migrationConfig['new_url']);
             } else {
                 // Fallback to localhost if no domain detected
                 $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                 $migrationConfig['new_url'] = $protocol . '://localhost';
                 $this->logger->warning("Could not detect domain, using localhost fallback: " . $migrationConfig['new_url']);
             }
         }

         // Detect source URL from backup database - this is expensive, only do if needed
         if (empty($migrationConfig['old_url'])) {
             $this->logger->info("Attempting to extract source domain from backup (this may take a moment...)");
             $sourceDomain = $this->extractSourceDomainFromBackup($backupFile);
             if ($sourceDomain) {
                 // Assume https for backup URL (most common case)
                 $migrationConfig['old_url'] = 'https://' . $sourceDomain;
                 $this->logger->info("Auto-detected source URL from backup: " . $migrationConfig['old_url']);
             } else {
                 // If we can't detect source URL, we'll still force update the shop_url table
                 $this->logger->warning("Could not extract source domain from backup - will force update shop_url");
                 $migrationConfig['old_url'] = ''; // This will trigger force update
             }
         }

         // SIEMPRE habilitar migración de URLs si tenemos una URL de destino
         if (!empty($migrationConfig['new_url'])) {
             $migrationConfig['migrate_urls'] = true;
             
             if (!empty($migrationConfig['old_url'])) {
                 $oldDomain = parse_url($migrationConfig['old_url'], PHP_URL_HOST);
                 $newDomain = parse_url($migrationConfig['new_url'], PHP_URL_HOST);
                 $this->logger->info("URL migration FORCED: {$oldDomain} → {$newDomain}");
             } else {
                 $newDomain = parse_url($migrationConfig['new_url'], PHP_URL_HOST);
                 $this->logger->info("URL migration FORCED with destination only: {$newDomain} (will force update shop_url)");
             }
         } else {
             $this->logger->error("Could not determine destination URL - migration may fail");
         }

         return $migrationConfig;
     }

     /**
      * Extract source domain from backup database
      *
      * @param string $backupFile
      * @return string|null
      */
     private function extractSourceDomainFromBackup(string $backupFile): ?string
     {
         try {
             $this->logger->info("Extracting source domain from backup: " . basename($backupFile));

             // First try: Extract domain from SQL file content (faster method)
             $domain = $this->extractDomainFromSqlContent($backupFile);
             if ($domain) {
                 return $domain;
             }

             // Fallback: Use full database restoration (slower but more reliable)
             return $this->extractDomainFromFullRestore($backupFile);

         } catch (Exception $e) {
             $this->logger->warning("Failed to extract source domain from backup: " . $e->getMessage());
         }

         return null;
     }

     /**
      * Try to extract domain by parsing SQL file content directly
      *
      * @param string $backupFile
      * @return string|null
      */
     private function extractDomainFromSqlContent(string $backupFile): ?string
     {
         try {
             $this->logger->info("Attempting fast domain extraction from SQL content");

             $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
             
             // Read file content
             if ($isGzipped) {
                 $content = gzfile($backupFile);
             } else {
                 $content = file($backupFile);
             }

             if (!$content) {
                 return null;
             }

             // Look for INSERT statements into shop_url table
             $patterns = [
                 '/INSERT INTO `.*?shop_url`.*?VALUES.*?\(.*?,.*?,.*?,.*?,.*?\'([^\']+)\'/',
                 '/INSERT INTO `.*?shop_url`.*?\(.*?,.*?,.*?,.*?,.*?,.*?\'([^\']+)\'/',
                 '/\(.*?,.*?,.*?,.*?,.*?\'([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\'/'
             ];

             foreach ($content as $line) {
                 if (stripos($line, 'shop_url') !== false && stripos($line, 'INSERT') !== false) {
                     foreach ($patterns as $pattern) {
                         if (preg_match($pattern, $line, $matches)) {
                             $domain = $matches[1];
                             // Validate that it looks like a domain
                             if (preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain) && 
                                 !in_array($domain, ['localhost', '127.0.0.1', 'example.com'])) {
                                 $this->logger->info("Fast extracted domain from SQL: " . $domain);
                                 return $domain;
                             }
                         }
                     }
                 }
             }

             $this->logger->info("Could not extract domain from SQL content, will try full restore");
             return null;

         } catch (Exception $e) {
             $this->logger->warning("Fast domain extraction failed: " . $e->getMessage());
             return null;
         }
     }

     /**
      * Extract domain by restoring to temporary database
      *
      * @param string $backupFile
      * @return string|null
      */
     private function extractDomainFromFullRestore(string $backupFile): ?string
     {
         try {
             $this->logger->info("Using full restore method for domain extraction");

             // Create temporary database to extract source domain
             $tempDbName = 'temp_extract_' . uniqid();
             
             // Create temporary database
             $createDbSql = "CREATE DATABASE IF NOT EXISTS `{$tempDbName}`";
             $this->db->execute($createDbSql);

             // Restore backup to temporary database
             $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
             
             if ($isGzipped) {
                 $command = sprintf(
                     'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                     escapeshellarg($backupFile),
                     escapeshellarg(_DB_SERVER_),
                     escapeshellarg(_DB_USER_),
                     escapeshellarg(_DB_PASSWD_),
                     escapeshellarg($tempDbName)
                 );
             } else {
                 $command = sprintf(
                     'mysql --host=%s --user=%s --password=%s %s < %s',
                     escapeshellarg(_DB_SERVER_),
                     escapeshellarg(_DB_USER_),
                     escapeshellarg(_DB_PASSWD_),
                     escapeshellarg($tempDbName),
                     escapeshellarg($backupFile)
                 );
             }

             exec($command . ' 2>&1', $output, $returnVar);

             if ($returnVar === 0) {
                 // Extract domain from shop_url table
                 $prefix = $this->extractDbPrefixFromBackup($tempDbName);
                 $shopUrlTable = $tempDbName . '.' . $prefix . 'shop_url';
                 
                 $sql = "SELECT `domain` FROM `{$shopUrlTable}` WHERE `domain` != '' AND `domain` IS NOT NULL LIMIT 1";
                 $result = $this->db->executeS($sql);
                 
                 $sourceDomain = null;
                 if (!empty($result) && !empty($result[0]['domain'])) {
                     $sourceDomain = $result[0]['domain'];
                     $this->logger->info("Found source domain in backup: " . $sourceDomain);
                 }

                 // Clean up temporary database
                 $this->db->execute("DROP DATABASE IF EXISTS `{$tempDbName}`");

                 return $sourceDomain;
             } else {
                 $this->logger->warning("Failed to restore backup to temporary database for domain extraction");
                 $this->db->execute("DROP DATABASE IF EXISTS `{$tempDbName}`");
             }

         } catch (Exception $e) {
             $this->logger->warning("Full restore domain extraction failed: " . $e->getMessage());
         }

         return null;
     }

     /**
      * Extract database prefix from backup
      *
      * @param string $tempDbName
      * @return string
      */
     private function extractDbPrefixFromBackup(string $tempDbName): string
     {
         try {
             // Look for shop_url table with different prefixes
             $commonPrefixes = ['ps_', 'prestashop_', ''];
             
             foreach ($commonPrefixes as $prefix) {
                 $tableName = $prefix . 'shop_url';
                 $sql = "SHOW TABLES FROM `{$tempDbName}` LIKE '{$tableName}'";
                 $result = $this->db->executeS($sql);
                 
                 if (!empty($result)) {
                     $this->logger->info("Detected database prefix in backup: '{$prefix}'");
                     return $prefix;
                 }
             }

             // If no common prefix found, try to detect from any table
             $sql = "SHOW TABLES FROM `{$tempDbName}`";
             $tables = $this->db->executeS($sql);
             
             if (!empty($tables)) {
                 $firstTable = reset($tables);
                 $tableName = reset($firstTable);
                 
                 // Extract prefix by looking for underscore pattern
                 if (preg_match('/^(.+?)_/', $tableName, $matches)) {
                     $detectedPrefix = $matches[1] . '_';
                     $this->logger->info("Auto-detected database prefix: '{$detectedPrefix}'");
                     return $detectedPrefix;
                 }
             }

         } catch (Exception $e) {
             $this->logger->warning("Failed to detect database prefix: " . $e->getMessage());
         }

         // Fallback to current prefix
         $currentPrefix = _DB_PREFIX_;
         $this->logger->info("Using current database prefix as fallback: '{$currentPrefix}'");
         return $currentPrefix;
     }

     /**
      * Preserve admin configuration from backup
      * This method ensures that admin directory settings remain unchanged from the original backup
      * The admin folder will always maintain the original URL/configuration
      */
     private function preserveAdminConfiguration(): void
     {
         $this->logger->info("Preserving admin configuration from backup - no admin directory migration will be performed");

         // Log admin-related configuration that will be preserved
         try {
             $adminConfigKeys = [
                 'PS_ADMIN_DIR',
                 'PS_BASE_URI'
             ];

             foreach ($adminConfigKeys as $key) {
                 $sql = "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($key) . "' LIMIT 1";
                 $result = $this->db->executeS($sql);
                 
                 if (!empty($result) && isset($result[0]['value'])) {
                     $this->logger->info("Preserving admin config: {$key} = " . $result[0]['value']);
                 }
             }

             $this->logger->info("Admin configuration preserved successfully - original backup settings maintained");

         } catch (Exception $e) {
             $this->logger->error("Error while logging admin configuration preservation: " . $e->getMessage());
         }
     }

     private function updateDomainConfiguration(string $domain): void
     {
         try {
             $this->logger->info("Updating configuration domains to {$domain}");
             $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($domain) . "' WHERE `name` IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL')";
             $result = $this->db->execute($sql);
             $this->logger->info("Configuration domain update executed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
         } catch (Exception $e) {
             $this->logger->error("Failed to update configuration domains: " . $e->getMessage());
         }
     }

     /**
      * Force update domain configuration from URL
      *
      * @param string $newUrl
      */
     private function updateDomainConfigurationForced(string $newUrl): void
     {
         try {
             $this->logger->info("Force updating domain configuration from URL: {$newUrl}");
             
             $parsedUrl = parse_url($newUrl);
             $domain = $parsedUrl['host'] ?? null;
             
             if (!$domain) {
                 $this->logger->error("Could not extract domain from URL: {$newUrl}");
                 return;
             }
             
             // Remove port if present
             if (strpos($domain, ':') !== false) {
                 $domain = explode(':', $domain)[0];
             }
             
             // Update domain configurations
             $configUpdates = [
                 'PS_SHOP_DOMAIN' => $domain,
                 'PS_SHOP_DOMAIN_SSL' => $domain
             ];
             
             foreach ($configUpdates as $configKey => $configValue) {
                 $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($configValue) . "' WHERE `name` = '" . pSQL($configKey) . "'";
                 $result = $this->db->execute($sql);
                 $this->logger->info("Force updated {$configKey} to {$configValue}: " . ($result ? 'SUCCESS' : 'FAILED'));
             }
             
         } catch (Exception $e) {
             $this->logger->error("Failed to force update domain configuration: " . $e->getMessage());
         }
     }
} 