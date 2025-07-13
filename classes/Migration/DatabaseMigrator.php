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
     * Detect database prefix from backup content
     *
     * @param string $backupFile
     * @return string|null
     */
    public function detectPrefixFromBackup(string $backupFile): ?string
    {
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        $handle = $isGzipped ? gzopen($backupFile, 'r') : fopen($backupFile, 'r');
        if (!$handle) {
            return null;
        }

        while (($line = $isGzipped ? gzgets($handle) : fgets($handle)) !== false) {
            if (preg_match('/CREATE TABLE `([^`]+_)[^`]*`/', $line, $matches) ||
                preg_match('/INSERT INTO `([^`]+_)[^`]*`/', $line, $matches)) {
                $isGzipped ? gzclose($handle) : fclose($handle);
                return $matches[1];
            }
        }

        $isGzipped ? gzclose($handle) : fclose($handle);
        return null;
    }

    /**
     * Get current database credentials from environment
     * This ensures we use the correct credentials even after backup restoration
     *
     * @return array
     */
    public function getCurrentDbCredentials(): array
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
        if (getenv('DDEV_SITENAME') || $this->isDdevEnvironment()) {
            $this->logger->info("Detected DDEV environment, using DDEV database credentials");
            return [
                'host' => 'db',
                'user' => 'db', 
                'password' => 'db',
                'name' => 'db'
            ];
        }
        
        // Fallback to PrestaShop constants (may be incorrect after backup restore)
        $this->logger->warning("Using PrestaShop constants as fallback (may be incorrect after restore)", [
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

    /**
     * Extract source domain from backup (public method)
     *
     * @param string $backupFile
     * @return string|null
     */
    public function extractSourceDomainFromBackup(string $backupFile): ?string
    {
        return $this->extractSourceDomainFromBackupPrivate($backupFile);
    }

    /**
     * Restore external database (public method)
     *
     * @param string $backupFile
     * @throws Exception
     */
    public function restoreExternalDatabase(string $backupFile): void
    {
        $this->restoreExternalDatabasePrivate($backupFile);
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
            $this->restoreExternalDatabasePrivate($backupFile);

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

            // SIEMPRE forzar actualización de shop_url independientemente de la configuración anterior
            $this->logger->info("FORCING shop_url table update to ensure proper domain configuration");
            $this->forceUpdateShopUrl($migrationConfig);

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

            // Verificar que la migración se haya completado correctamente
            $this->verifyMigrationSuccess($migrationConfig);

            $this->logger->info("Database migration completed successfully");

        } catch (Exception $e) {
            $this->logger->error("Database migration failed: " . $e->getMessage());
            
            // Restore from temporary backup
            try {
                $this->restoreExternalDatabasePrivate($tempBackup);
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
        $credentials = $this->getCurrentDbCredentials();
        
        $command = sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s | gzip > %s',
            escapeshellarg($credentials['host']),
            escapeshellarg($credentials['user']),
            escapeshellarg($credentials['password']),
            escapeshellarg($credentials['name']),
            escapeshellarg($tempFile)
        );

        secureSysCommand($command . ' 2>&1', $output, $returnVar);

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
         private function restoreExternalDatabasePrivate(string $backupFile): void
    {
        if (!file_exists($backupFile)) {
            throw new Exception("Database backup file does not exist: " . $backupFile);
        }

        $credentials = $this->getCurrentDbCredentials();
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            $command = sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name'])
            );
        } else {
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name']),
                escapeshellarg($backupFile)
            );
        }

        secureSysCommand($command . ' 2>&1', $output, $returnVar);

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
            $shopUrlTable = $this->getShopUrlTableName();
            
            if (!$shopUrlTable) {
                $this->logger->warning("No shop_url table found in database");
            } elseif (!$newDomain) {
                $this->logger->error("Could not extract domain from new URL: {$newUrl}");
            } else {
                // Log current state before update
                $currentData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
                $this->logger->info("Current shop_url data before update", $currentData ?: []);
                
                // Update both domain and domain_ssl (PrestaShop stores domains without protocol)
                $newParsedUrl = parse_url($newUrl);
                $physicalUri = isset($newParsedUrl['path']) ? rtrim($newParsedUrl['path'], '/') . '/' : '/';
                
                $sql = "UPDATE `{$shopUrlTable}` SET 
                        `domain` = '" . pSQL($newDomain) . "', 
                        `domain_ssl` = '" . pSQL($newDomain) . "',
                        `physical_uri` = '" . pSQL($physicalUri) . "'";
                        
                $result = $this->safeDbQuery($sql, 'execute');
                
                $this->logger->info("Update query executed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // Log state after update
                $newData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
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

            $shopUrlTable = $this->getShopUrlTableName();
            if ($shopUrlTable) {
                // Log current state before update with error handling
                $currentData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
                $this->logger->info("Current shop_url data before AGGRESSIVE update", $currentData ?: []);
                
                // Update domain, domain_ssl, and physical_uri with WHERE clause
                $sql = "UPDATE `{$shopUrlTable}` SET 
                        `domain` = '" . pSQL($currentDomain) . "', 
                        `domain_ssl` = '" . pSQL($currentDomain) . "',
                        `physical_uri` = '/' 
                        WHERE `id_shop_url` > 0";
                $result = $this->safeDbQuery($sql, 'execute');
                
                if ($result) {
                    $this->logger->info("AGGRESSIVE update query executed successfully");
                    
                    // Log state after update with error handling
                    $newData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
                    $this->logger->info("New shop_url data after AGGRESSIVE update", $newData ?: []);
                    
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
        // Priority 3: Try to get from environment variables or common alternatives
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
             $shopUrlTable = $this->getShopUrlTableName();
             
             if (!$shopUrlTable) {
                 $this->logger->error("No shop_url table found, cannot force update");
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

             // Si no se puede detectar el dominio, usar fallbacks más agresivos
             if (!$targetDomain) {
                 $this->logger->warning("Could not determine target domain, trying fallbacks");
                 
                 // Intentar múltiples fallbacks
                 $fallbacks = [
                     $_SERVER['HTTP_HOST'] ?? '',
                     $_SERVER['SERVER_NAME'] ?? '',
                     'localhost'
                 ];
                 
                 foreach ($fallbacks as $fallback) {
                     if (!empty($fallback)) {
                         $targetDomain = $fallback;
                         $this->logger->info("Using fallback domain: " . $targetDomain);
                         break;
                     }
                 }
                 
                 if (!$targetDomain) {
                     $this->logger->error("All fallbacks failed, cannot force update shop_url");
                     return;
                 }
             }

             // Log current state with better error handling
             $currentData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
             $this->logger->info("Current shop_url data before force update", $currentData ?: []);

             // Limpiar el dominio (remover puerto si existe)
             if (strpos($targetDomain, ':') !== false) {
                 $targetDomain = explode(':', $targetDomain)[0];
                 $this->logger->info("Cleaned domain (removed port): " . $targetDomain);
             }

             // Update the shop_url table with improved SQL syntax
             $sql = "UPDATE `{$shopUrlTable}` SET 
                     `domain` = '" . pSQL($targetDomain) . "', 
                     `domain_ssl` = '" . pSQL($targetDomain) . "',
                     `physical_uri` = '" . pSQL($targetPath) . "' 
                     WHERE `id_shop_url` > 0";
                     
             $result = $this->safeDbQuery($sql, 'execute');
             
             if ($result) {
                 $this->logger->info("Force update query executed successfully");
                 
                 // Log state after update with better error handling
                 $newData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
                 $this->logger->info("New shop_url data after force update", $newData ?: []);
                 
                 $this->logger->info("Force updated shop_url table - domain: {$targetDomain}, physical_uri: {$targetPath}");
                 
                 // También actualizar configuraciones de dominio
                 $this->updateDomainConfiguration($targetDomain);
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
            $sourceDomain = $this->extractSourceDomainFromBackupPrivate($backupFile);
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
             $migrationConfig['force_shop_url_update'] = true; // FORZAR actualización de shop_url
             
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
     private function extractSourceDomainFromBackupPrivate(string $backupFile): ?string
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
             $credentials = $this->getCurrentDbCredentials();
             $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
             
             if ($isGzipped) {
                 $command = sprintf(
                     'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                     escapeshellarg($backupFile),
                     escapeshellarg($credentials['host']),
                     escapeshellarg($credentials['user']),
                     escapeshellarg($credentials['password']),
                     escapeshellarg($tempDbName)
                 );
             } else {
                 $command = sprintf(
                     'mysql --host=%s --user=%s --password=%s %s < %s',
                     escapeshellarg($credentials['host']),
                     escapeshellarg($credentials['user']),
                     escapeshellarg($credentials['password']),
                     escapeshellarg($tempDbName),
                     escapeshellarg($backupFile)
                 );
             }

             secureSysCommand($command . ' 2>&1', $output, $returnVar);

             if ($returnVar !== 0) {
                 throw new Exception("Failed to restore to temporary database: " . implode("\n", $output));
             }

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
             $currentPrefix = $this->getCurrentPrefix();
             $this->logger->info("Updating configuration domains to {$domain} using prefix: " . $currentPrefix);
             
             // Actualizar configuraciones de dominio una por una para mejor control
             $configKeys = ['PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL'];
             
             foreach ($configKeys as $configKey) {
                 // Verificar si existe la configuración
                 $existsQuery = "SELECT COUNT(*) FROM `" . $currentPrefix . "configuration` WHERE `name` = '" . pSQL($configKey) . "'";
                 $exists = $this->db->getValue($existsQuery);
                 
                 if ($exists) {
                     // Actualizar configuración existente
                     $sql = "UPDATE `" . $currentPrefix . "configuration` SET `value` = '" . pSQL($domain) . "' WHERE `name` = '" . pSQL($configKey) . "'";
                     $result = $this->db->execute($sql);
                     $this->logger->info("Updated {$configKey} to {$domain}: " . ($result ? 'SUCCESS' : 'FAILED'));
                 } else {
                     // Insertar nueva configuración si no existe
                     $sql = "INSERT INTO `" . $currentPrefix . "configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('" . pSQL($configKey) . "', '" . pSQL($domain) . "', NOW(), NOW())";
                     $result = $this->db->execute($sql);
                     $this->logger->info("Inserted new {$configKey} with value {$domain}: " . ($result ? 'SUCCESS' : 'FAILED'));
                 }
             }
             
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

    /**
     * Enhanced database migration with full environment adaptation
     * This method handles all common restoration issues automatically
     *
     * @param string $backupFile Path to database backup file
     * @param array $migrationConfig Migration configuration
     * @throws Exception
     */
    public function migrateWithFullAdaptation(string $backupFile, array $migrationConfig): void
    {
        $this->logger->info("Starting enhanced database migration with full adaptation");
        
        try {
            // Step 1: Preserve current environment credentials
            $currentCredentials = $this->getCurrentDbCredentials();
            $currentPrefix = $this->getCurrentPrefix();
            
            $this->logger->info("Current environment detected", [
                'host' => $currentCredentials['host'],
                'user' => $currentCredentials['user'],
                'name' => $currentCredentials['name'],
                'prefix' => $currentPrefix
            ]);
            
            // Step 2: Analyze backup content
            $backupInfo = $this->analyzeBackupContent($backupFile);
            
            $this->logger->info("Backup analysis completed", [
                'source_prefix' => $backupInfo['prefix'],
                'source_domain' => $backupInfo['domain'],
                'total_tables' => $backupInfo['table_count']
            ]);
            
            // Step 3: Clean existing data from destination database
            if (!empty($migrationConfig['clean_destination']) && $migrationConfig['clean_destination']) {
                $this->cleanDestinationDatabase($currentPrefix);
            }
            
            // Step 4: Restore backup with prefix adaptation
            $this->restoreBackupWithPrefixAdaptation($backupFile, $backupInfo['prefix'], $currentPrefix);
            
            // Step 5: Update URLs to current environment
            $this->updateUrlsForCurrentEnvironment($currentPrefix);
            
            // Step 6: Disable problematic modules
            $this->disableProblematicModules($currentPrefix);
            
            // Step 7: Preserve environment configuration
            $this->preserveEnvironmentConfiguration($currentCredentials, $currentPrefix);
            
                         // Step 8: Clean cache and regenerate essentials
             $this->cleanAndRegenerateCache();
             
             // Step 9: Ensure .htaccess exists (restore from backup if needed)
             $this->ensureHtaccessExists();
            
            $this->logger->info("Enhanced database migration completed successfully");
            
        } catch (Exception $e) {
            $this->logger->error("Enhanced database migration failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get current database prefix from environment
     *
     * @return string
     */
    private function getCurrentPrefix(): string
    {
        // First try to read from parameters.php (most reliable)
        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        if (file_exists($parametersFile)) {
            try {
                $parametersArray = include $parametersFile;
                if (isset($parametersArray['parameters']['database_prefix'])) {
                    $prefix = $parametersArray['parameters']['database_prefix'];
                    $this->logger->info("Found prefix from parameters.php: " . $prefix);
                    return $prefix;
                }
            } catch (Exception $e) {
                $this->logger->warning("Could not read parameters.php: " . $e->getMessage());
            }
        }
        
        // Fallback to PrestaShop constant
        if (defined('_DB_PREFIX_')) {
            $prefix = _DB_PREFIX_;
            $this->logger->info("Using _DB_PREFIX_ constant: " . $prefix);
            return $prefix;
        }
        
        // Last resort fallback
        $fallbackPrefix = 'ps_';
        $this->logger->warning("Using fallback prefix: " . $fallbackPrefix);
        return $fallbackPrefix;
    }

    /**
     * Analyze backup content to extract important information
     *
     * @param string $backupFile
     * @return array
     */
    private function analyzeBackupContent(string $backupFile): array
    {
        $this->logger->info("Analyzing backup content");
        
        $result = [
            'prefix' => '',
            'domain' => '',
            'table_count' => 0,
            'problematic_modules' => []
        ];
        
        try {
            // Read backup file (handle both gzipped and plain SQL)
            $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
            
            if ($isGzipped) {
                $handle = gzopen($backupFile, 'r');
                if (!$handle) {
                    throw new Exception("Could not open gzipped backup file");
                }
            } else {
                $handle = fopen($backupFile, 'r');
                if (!$handle) {
                    throw new Exception("Could not open backup file");
                }
            }
            
            $linesRead = 0;
            $maxLines = 1000; // Limit analysis to first 1000 lines for performance
            
            while (($line = $isGzipped ? gzgets($handle) : fgets($handle)) !== false && $linesRead < $maxLines) {
                $linesRead++;
                
                // Detect table prefix
                if (empty($result['prefix']) && preg_match('/CREATE TABLE.*?`([^_]+_)/', $line, $matches)) {
                    $result['prefix'] = $matches[1];
                }
                
                // Count tables
                if (strpos($line, 'CREATE TABLE') !== false) {
                    $result['table_count']++;
                }
                
                // Detect source domain from shop_url table
                if (empty($result['domain']) && preg_match('/INSERT INTO.*?shop_url.*?VALUES.*?\'([^\']+\.[^\']+)\'/i', $line, $matches)) {
                    $result['domain'] = $matches[1];
                }
                
                // Detect problematic modules
                if (preg_match('/INSERT INTO.*?module.*?VALUES.*?\'(ps_mbo|ps_eventbus|ps_metrics|ps_facebook|egh_)/i', $line, $matches)) {
                    $result['problematic_modules'][] = $matches[1];
                }
            }
            
            if ($isGzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Could not fully analyze backup content: " . $e->getMessage());
        }
        
        return $result;
    }

    /**
     * Clean destination database tables with current prefix
     *
     * @param string $currentPrefix
     */
    private function cleanDestinationDatabase(string $currentPrefix): void
    {
        $this->logger->info("Cleaning destination database with prefix: " . $currentPrefix);
        
        try {
            // Get all tables with current prefix
            $sql = "SHOW TABLES LIKE '" . $currentPrefix . "%'";
            $tables = $this->db->executeS($sql);
            
            if (!empty($tables)) {
                // Disable foreign key checks
                $this->db->execute("SET FOREIGN_KEY_CHECKS = 0");
                
                foreach ($tables as $table) {
                    $tableName = reset($table);
                    $this->db->execute("DROP TABLE IF EXISTS `" . $tableName . "`");
                }
                
                // Re-enable foreign key checks
                $this->db->execute("SET FOREIGN_KEY_CHECKS = 1");
                
                $this->logger->info("Cleaned " . count($tables) . " existing tables");
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to clean destination database: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Restore backup with prefix adaptation
     *
     * @param string $backupFile
     * @param string $sourcePrefix
     * @param string $targetPrefix
     */
    private function restoreBackupWithPrefixAdaptation(string $backupFile, string $sourcePrefix, string $targetPrefix): void
    {
        $this->logger->info("Restoring backup with prefix adaptation", [
            'source_prefix' => $sourcePrefix,
            'target_prefix' => $targetPrefix
        ]);
        
        if ($sourcePrefix === $targetPrefix) {
            // No prefix change needed, restore directly
            $this->restoreExternalDatabasePrivate($backupFile);
            return;
        }
        
        // Create temporary adapted backup file
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_adapted_' . time() . '.sql';
        
        try {
            $this->createAdaptedBackup($backupFile, $tempFile, $sourcePrefix, $targetPrefix);
            $this->restoreExternalDatabasePrivate($tempFile);
            
            // Clean up temporary file
            @unlink($tempFile);
            
        } catch (Exception $e) {
            @unlink($tempFile);
            throw $e;
        }
    }

    /**
     * Create adapted backup with prefix changes
     *
     * @param string $sourceFile
     * @param string $targetFile
     * @param string $sourcePrefix
     * @param string $targetPrefix
     */
    private function createAdaptedBackup(string $sourceFile, string $targetFile, string $sourcePrefix, string $targetPrefix): void
    {
        $this->logger->info("Creating adapted backup file");
        
        $isGzipped = pathinfo($sourceFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            $sourceHandle = gzopen($sourceFile, 'r');
        } else {
            $sourceHandle = fopen($sourceFile, 'r');
        }
        
        $targetHandle = fopen($targetFile, 'w');
        
        if (!$sourceHandle || !$targetHandle) {
            throw new Exception("Could not open files for prefix adaptation");
        }
        
        try {
            while (($line = $isGzipped ? gzgets($sourceHandle) : fgets($sourceHandle)) !== false) {
                // Replace table prefixes
                $adaptedLine = str_replace('`' . $sourcePrefix, '`' . $targetPrefix, $line);
                $adaptedLine = str_replace('INTO ' . $sourcePrefix, 'INTO ' . $targetPrefix, $adaptedLine);
                
                fwrite($targetHandle, $adaptedLine);
            }
            
        } finally {
            if ($isGzipped) {
                gzclose($sourceHandle);
            } else {
                fclose($sourceHandle);
            }
            fclose($targetHandle);
        }
        
        $this->logger->info("Adapted backup file created successfully");
    }

    /**
     * Update URLs for current environment
     *
     * @param string $currentPrefix
     */
    private function updateUrlsForCurrentEnvironment(string $currentPrefix): void
    {
        $this->logger->info("Updating URLs for current environment");
        
        // Detect current domain
        $currentDomain = $this->detectCurrentDomain();
        
        if (!$currentDomain) {
            $this->logger->warning("Could not detect current domain, skipping URL updates");
            return;
        }
        
        try {
            // Update shop_url table
            $sql = "UPDATE `{$currentPrefix}shop_url` SET 
                    `domain` = '" . pSQL($currentDomain) . "', 
                    `domain_ssl` = '" . pSQL($currentDomain) . "'";
            $this->db->execute($sql);
            
            // Update configuration
            $configUpdates = [
                'PS_SHOP_DOMAIN' => $currentDomain,
                'PS_SHOP_DOMAIN_SSL' => $currentDomain
            ];
            
            foreach ($configUpdates as $key => $value) {
                $sql = "UPDATE `{$currentPrefix}configuration` SET 
                        `value` = '" . pSQL($value) . "' 
                        WHERE `name` = '" . pSQL($key) . "'";
                $this->db->execute($sql);
            }
            
            $this->logger->info("URLs updated to: " . $currentDomain);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to update URLs: " . $e->getMessage());
        }
    }

    /**
     * Detect current domain from environment
     *
     * @return string|null
     */
    private function detectCurrentDomain(): ?string
    {
        // Check DDEV environment
        if ($this->isDdevEnvironment()) {
            $ddevSiteName = getenv('DDEV_SITENAME');
            if ($ddevSiteName) {
                return $ddevSiteName . '.ddev.site';
            }
            
            // Try to read from DDEV config
            $ddevConfig = _PS_ROOT_DIR_ . '/.ddev/config.yaml';
            if (file_exists($ddevConfig)) {
                $content = file_get_contents($ddevConfig);
                if (preg_match('/^name:\s*(.+)$/m', $content, $matches)) {
                    return trim($matches[1]) . '.ddev.site';
                }
            }
        }
        
        // Try HTTP_HOST
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }
        
        return null;
    }

    /**
     * Disable problematic modules that commonly cause issues after restoration
     *
     * @param string $currentPrefix
     */
    private function disableProblematicModules(string $currentPrefix): void
    {
        $this->logger->info("Disabling problematic modules");
        
        $problematicModules = [
            'ps_mbo',
            'ps_eventbus', 
            'ps_metrics',
            'ps_facebook',
            'ps_googleanalytics',
            'ps_checkpayment'  // Often causes issues in dev environments
        ];
        
        // Also disable any module starting with custom prefixes that often cause issues
        $problematicPrefixes = ['egh_', 'custom_', 'dev_'];
        
        try {
            // Disable specific modules
            foreach ($problematicModules as $module) {
                $sql = "UPDATE `{$currentPrefix}module` SET `active` = 0 WHERE `name` = '" . pSQL($module) . "'";
                $this->db->execute($sql);
            }
            
            // Disable modules with problematic prefixes
            foreach ($problematicPrefixes as $prefix) {
                $sql = "UPDATE `{$currentPrefix}module` SET `active` = 0 WHERE `name` LIKE '" . pSQL($prefix) . "%'";
                $this->db->execute($sql);
            }
            
            $this->logger->info("Problematic modules disabled");
            
        } catch (Exception $e) {
            $this->logger->error("Failed to disable problematic modules: " . $e->getMessage());
        }
    }

    /**
     * Preserve environment configuration (database credentials, etc.)
     *
     * @param array $credentials
     * @param string $currentPrefix
     */
    private function preserveEnvironmentConfiguration(array $credentials, string $currentPrefix): void
    {
        $this->logger->info("Preserving environment configuration");
        
        try {
            // Update parameters.php to ensure correct database credentials
            $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
            
            if (file_exists($parametersFile)) {
                $content = file_get_contents($parametersFile);
                
                // Preserve current environment credentials
                $replacements = [
                    "'database_host' => '[^']*'" => "'database_host' => '" . $credentials['host'] . "'",
                    "'database_user' => '[^']*'" => "'database_user' => '" . $credentials['user'] . "'", 
                    "'database_password' => '[^']*'" => "'database_password' => '" . $credentials['password'] . "'",
                    "'database_name' => '[^']*'" => "'database_name' => '" . $credentials['name'] . "'",
                    "'database_prefix' => '[^']*'" => "'database_prefix' => '" . $currentPrefix . "'"
                ];
                
                foreach ($replacements as $pattern => $replacement) {
                    $content = preg_replace('/' . $pattern . '/', $replacement, $content);
                }
                
                file_put_contents($parametersFile, $content);
                
                $this->logger->info("Environment configuration preserved in parameters.php");
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to preserve environment configuration: " . $e->getMessage());
        }
    }

    /**
     * Clean cache and regenerate essential files
     */
    private function cleanAndRegenerateCache(): void
    {
        $this->logger->info("Cleaning cache and regenerating essentials");
        
        try {
            // Clear common cache directories
            $cacheDirs = [
                _PS_ROOT_DIR_ . '/var/cache/',
                _PS_ROOT_DIR_ . '/cache/',
                _PS_ROOT_DIR_ . '/app/cache/'
            ];
            
            foreach ($cacheDirs as $cacheDir) {
                if (is_dir($cacheDir)) {
                    $this->recursiveDelete($cacheDir, true); // Keep directory, delete contents
                }
            }
            
            $this->logger->info("Cache cleaned successfully");
            
        } catch (Exception $e) {
            $this->logger->error("Failed to clean cache: " . $e->getMessage());
        }
    }

    /**
     * Recursively delete directory contents
     *
     * @param string $dir
     * @param bool $keepDir
     */
    private function recursiveDelete(string $dir, bool $keepDir = false): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        
                 if (!$keepDir) {
             @rmdir($dir);
         }
     }

     /**
      * Ensure .htaccess file exists for proper functioning
      * This handles cases where .htaccess might be missing
      */
     private function ensureHtaccessExists(): void
     {
         $this->logger->info("Checking .htaccess file status");
         
         $htaccessPath = _PS_ROOT_DIR_ . '/.htaccess';
         $htaccessBackupPath = _PS_ROOT_DIR_ . '/.htaccess2';
         
         try {
             // If .htaccess doesn't exist but .htaccess2 does, restore it
             if (!file_exists($htaccessPath) && file_exists($htaccessBackupPath)) {
                 copy($htaccessBackupPath, $htaccessPath);
                 $this->logger->info(".htaccess restored from .htaccess2 backup");
             } 
             // If neither exists, generate a minimal .htaccess
             elseif (!file_exists($htaccessPath)) {
                 $this->generateMinimalHtaccess($htaccessPath);
                 $this->logger->info("Minimal .htaccess generated");
             } 
             else {
                 $this->logger->info(".htaccess file already exists");
             }
             
         } catch (Exception $e) {
             $this->logger->error("Failed to ensure .htaccess exists: " . $e->getMessage());
         }
     }

     /**
      * Generate a minimal .htaccess file for PrestaShop
      *
      * @param string $htaccessPath
      */
     private function generateMinimalHtaccess(string $htaccessPath): void
     {
         $content = '# Basic PrestaShop .htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [L]

# Disable directory browsing
Options -Indexes

# Block access to sensitive files
<Files ~ "\.tpl$">
    Order allow,deny
    Deny from all
</Files>

<Files ~ "\.yml$">
    Order allow,deny
    Deny from all
</Files>

<Files ~ "\.log$">
    Order allow,deny
    Deny from all
</Files>
';
         
         file_put_contents($htaccessPath, $content);
     }

     /**
      * Verify that the migration was successful
      *
      * @param array $migrationConfig
      */
     private function verifyMigrationSuccess(array $migrationConfig): void
     {
         $this->logger->info("Verifying migration success");
         
         try {
             // Verificar tabla shop_url
             $shopUrlTable = $this->getShopUrlTableName();
             if ($shopUrlTable) {
                 $shopUrlData = $this->safeDbQuery("SELECT domain, domain_ssl FROM `{$shopUrlTable}` LIMIT 1", 'getRow');
                 if ($shopUrlData) {
                     $this->logger->info("shop_url verification", [
                         'domain' => $shopUrlData['domain'] ?? 'N/A',
                         'domain_ssl' => $shopUrlData['domain_ssl'] ?? 'N/A'
                     ]);
                 }
             }
             
             // Verificar configuraciones de dominio
             $currentPrefix = $this->getCurrentPrefix();
             $configKeys = ['PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL'];
             foreach ($configKeys as $configKey) {
                 $configValue = $this->db->getValue("SELECT `value` FROM `" . $currentPrefix . "configuration` WHERE `name` = '" . pSQL($configKey) . "'");
                 $this->logger->info("Configuration verification", [
                     'key' => $configKey,
                     'value' => $configValue,
                     'prefix_used' => $currentPrefix
                 ]);
             }
             
             $this->logger->info("Migration verification completed");
             
         } catch (Exception $e) {
             $this->logger->error("Migration verification failed: " . $e->getMessage());
         }
     }

    /**
     * Get the correct shop_url table name based on what exists in the database
     *
     * @return string|null
     */
    private function getShopUrlTableName(): ?string
    {
        // Get the correct current prefix
        $currentPrefix = $this->getCurrentPrefix();
        $this->logger->info("Using current prefix for shop_url table: " . $currentPrefix);
        
        // Try current prefix first
        $currentPrefixTable = $currentPrefix . 'shop_url';
        if ($this->tableExists($currentPrefixTable)) {
            $this->logger->info("Found shop_url table with current prefix: " . $currentPrefixTable);
            return $currentPrefixTable;
        }
        
        // If current prefix doesn't work, search for any shop_url table
        try {
            $sql = "SHOW TABLES LIKE '%shop_url'";
            $result = $this->db->executeS($sql);
            
            if (!empty($result)) {
                $tableName = reset($result[0]);
                $this->logger->info("Found alternative shop_url table: " . $tableName);
                return $tableName;
            }
        } catch (Exception $e) {
            $this->logger->error("Error finding shop_url table: " . $e->getMessage());
        }
        
        $this->logger->error("No shop_url table found with any prefix");
        return null;
    }



    /**
     * Debug logger that writes to a separate file
     *
     * @param string $message
     * @param array $context
     */
    private function debugLog(string $message, array $context = []): void
    {
        $debugFile = _PS_ROOT_DIR_ . '/var/logs/ps_copia_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT) : '';
        $logEntry = "[{$timestamp}] {$message}";
        if ($contextStr) {
            $logEntry .= " | Context: {$contextStr}";
        }
        $logEntry .= PHP_EOL;
        
        // Ensure directory exists
        $logDir = dirname($debugFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($debugFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Test SQL query without executing it
     *
     * @param string $sql
     * @return array
     */
    private function testSqlQuery(string $sql): array
    {
        $this->debugLog("TESTING SQL QUERY", ['sql' => $sql]);
        
        try {
            // Try to prepare the statement to check syntax
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                $this->debugLog("SQL SYNTAX OK", ['sql' => $sql]);
                return ['status' => 'ok', 'message' => 'SQL syntax is valid'];
            } else {
                $this->debugLog("SQL SYNTAX ERROR", ['sql' => $sql, 'error' => 'Failed to prepare statement']);
                return ['status' => 'error', 'message' => 'Failed to prepare statement'];
            }
        } catch (Exception $e) {
            $this->debugLog("SQL SYNTAX EXCEPTION", ['sql' => $sql, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Enhanced safe database query with testing mode
     *
     * @param string $sql
     * @param string $method
     * @param bool $testMode
     * @return mixed
     */
    private function safeDbQuery(string $sql, string $method = 'execute', bool $testMode = false)
    {
        $this->debugLog("SAFE DB QUERY START", ['sql' => $sql, 'method' => $method, 'testMode' => $testMode]);
        
        if ($testMode) {
            return $this->testSqlQuery($sql);
        }
        
        try {
            switch ($method) {
                case 'execute':
                    $result = $this->db->execute($sql);
                    $this->debugLog("DB EXECUTE SUCCESS", ['sql' => $sql, 'result' => $result]);
                    return $result;
                case 'executeS':
                    $result = $this->db->executeS($sql);
                    $this->debugLog("DB EXECUTES SUCCESS", ['sql' => $sql, 'count' => is_array($result) ? count($result) : 0]);
                    return $result;
                case 'getRow':
                    // Remove LIMIT 1 from queries as getRow() already returns only one row
                    $cleanSql = preg_replace('/\s+LIMIT\s+1\s*$/i', '', $sql);
                    $result = $this->db->getRow($cleanSql);
                    $this->debugLog("DB GETROW SUCCESS", ['original_sql' => $sql, 'clean_sql' => $cleanSql, 'result' => $result]);
                    return $result;
                case 'getValue':
                    $result = $this->db->getValue($sql);
                    $this->debugLog("DB GETVALUE SUCCESS", ['sql' => $sql, 'result' => $result]);
                    return $result;
                default:
                    $this->debugLog("DB UNKNOWN METHOD", ['sql' => $sql, 'method' => $method]);
                    return false;
            }
        } catch (Exception $e) {
            $this->debugLog("DB QUERY ERROR", ['sql' => $sql, 'method' => $method, 'error' => $e->getMessage()]);
            if ($this->logger) {
                $this->logger->error("Database query failed: " . $e->getMessage(), ['sql' => $sql]);
            }
            return false;
        }
    }

    /**
     * Test all critical SQL queries before restoration
     *
     * @param string $newUrl
     * @return array
     */
    public function testRestoration(string $newUrl): array
    {
        $this->debugLog("=== STARTING RESTORATION TEST ===", ['newUrl' => $newUrl]);
        
        $results = [];
        
        // Test 1: Check current prefix
        $currentPrefix = $this->getCurrentPrefix();
        $results['current_prefix'] = $currentPrefix;
        $this->debugLog("TEST 1: Current prefix", ['prefix' => $currentPrefix]);
        
        // Test 2: Check if shop_url table exists
        $shopUrlTable = $this->getShopUrlTableName();
        $results['shop_url_table'] = $shopUrlTable;
        $this->debugLog("TEST 2: Shop URL table", ['table' => $shopUrlTable]);
        
        // Test 3: Test shop_url queries
        if ($shopUrlTable) {
            $sql1 = "SELECT * FROM `{$shopUrlTable}` LIMIT 1";
            $test1 = $this->safeDbQuery($sql1, 'getRow', true);
            $results['shop_url_select_test'] = $test1;
            
            $newParsedUrl = parse_url($newUrl);
            $newDomain = $newParsedUrl['host'] ?? '';
            if ($newDomain) {
                $sql2 = "UPDATE `{$shopUrlTable}` SET `domain` = '" . pSQL($newDomain) . "', `domain_ssl` = '" . pSQL($newDomain) . "' WHERE 1";
                $test2 = $this->safeDbQuery($sql2, 'execute', true);
                $results['shop_url_update_test'] = $test2;
            }
        }
        
        // Test 4: Test configuration table queries
        $configTable = $currentPrefix . 'configuration';
        $sql3 = "SELECT `value` FROM `{$configTable}` WHERE `name` = 'PS_SHOP_DOMAIN'";
        $test3 = $this->safeDbQuery($sql3, 'getValue', true);
        $results['config_select_test'] = $test3;
        
        $newParsedUrl = parse_url($newUrl);
        $newDomain = $newParsedUrl['host'] ?? '';
        if ($newDomain) {
            $sql4 = "UPDATE `{$configTable}` SET `value` = '" . pSQL($newDomain) . "' WHERE `name` = 'PS_SHOP_DOMAIN'";
            $test4 = $this->safeDbQuery($sql4, 'execute', true);
            $results['config_update_test'] = $test4;
        }
        
        // Test 5: Check what tables exist
        $sql5 = "SHOW TABLES LIKE '%shop_url%'";
        $test5 = $this->safeDbQuery($sql5, 'executeS', true);
        $results['show_tables_test'] = $test5;
        
        // Test 6: Check actual data in shop_url table
        if ($shopUrlTable) {
            $actualData = $this->safeDbQuery("SELECT * FROM `{$shopUrlTable}` LIMIT 1", 'getRow', false);
            $results['actual_shop_url_data'] = $actualData;
        }
        
        $this->debugLog("=== RESTORATION TEST COMPLETE ===", $results);
        
        return $results;
    }

    /**
     * Run a comprehensive diagnostic
     *
     * @return array
     */
    public function runDiagnostic(): array
    {
        $this->debugLog("=== STARTING DIAGNOSTIC ===");
        
        $diagnostic = [];
        
        // Check database connection
        try {
            $diagnostic['db_connection'] = $this->db ? 'OK' : 'FAILED';
        } catch (Exception $e) {
            $diagnostic['db_connection'] = 'ERROR: ' . $e->getMessage();
        }
        
        // Check current prefix sources
        $diagnostic['prefix_from_constant'] = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'NOT_DEFINED';
        $diagnostic['prefix_from_function'] = $this->getCurrentPrefix();
        
        // Check configuration files
        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        $diagnostic['parameters_file_exists'] = file_exists($parametersFile);
        
        if (file_exists($parametersFile)) {
            try {
                $params = include $parametersFile;
                $diagnostic['parameters_prefix'] = $params['parameters']['database_prefix'] ?? 'NOT_FOUND';
            } catch (Exception $e) {
                $diagnostic['parameters_error'] = $e->getMessage();
            }
        }
        
        // Check what shop_url tables exist
        try {
            $tables = $this->db->executeS("SHOW TABLES LIKE '%shop_url%'");
            $diagnostic['shop_url_tables'] = array_map('current', $tables);
        } catch (Exception $e) {
            $diagnostic['shop_url_tables_error'] = $e->getMessage();
        }
        
        // Check current shop_url data
        $shopUrlTable = $this->getShopUrlTableName();
        if ($shopUrlTable) {
            try {
                $data = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                $diagnostic['current_shop_url_data'] = $data;
            } catch (Exception $e) {
                $diagnostic['shop_url_data_error'] = $e->getMessage();
            }
        }
        
        $this->debugLog("=== DIAGNOSTIC COMPLETE ===", $diagnostic);
        
        return $diagnostic;
    }
 }  