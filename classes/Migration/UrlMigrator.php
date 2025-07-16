<?php
/**
 * URL Migrator for PS_Copia
 * Specialized class for handling URL and domain migrations between different PrestaShop environments
 */

namespace PrestaShop\Module\PsCopia\Migration;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use Exception;

class UrlMigrator
{
    /** @var BackupContainer */
    private $backupContainer;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var \Db */
    private $db;

    public function __construct(BackupContainer $backupContainer, BackupLogger $logger)
    {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->db = \Db::getInstance();
    }

    /**
     * Comprehensive URL migration handling all URL-related fields
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    public function migrateAllUrls(array $migrationConfig): void
    {
        $this->logger->info("Starting comprehensive URL migration", [
            'source_domain' => $migrationConfig['source_domain'] ?? 'unknown',
            'target_domain' => $migrationConfig['target_domain'] ?? 'unknown',
            'prefix' => $migrationConfig['target_prefix'] ?? _DB_PREFIX_
        ]);

        try {
            // Step 1: Migrate shop_url table (domain and domain_ssl fields)
            $this->migrateShopUrlTable($migrationConfig);
            
            // Step 2: Migrate configuration table URLs
            $this->migrateConfigurationUrls($migrationConfig);
            
            // Step 3: Migrate content URLs (CMS, products, categories)
            $this->migrateContentUrls($migrationConfig);
            
            // Step 4: Migrate module-specific URLs
            $this->migrateModuleUrls($migrationConfig);
            
            // Step 5: Update SSL configuration if needed
            $this->updateSslConfiguration($migrationConfig);
            
            // Step 6: Validate URL migration
            $this->validateUrlMigration($migrationConfig);
            
            $this->logger->info("URL migration completed successfully");
            
        } catch (Exception $e) {
            $this->logger->error("URL migration failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Migrate shop_url table - the most critical URL table
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function migrateShopUrlTable(array $migrationConfig): void
    {
        $this->logger->info("Migrating shop_url table");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $targetDomain = $migrationConfig['target_domain'] ?? $this->getCurrentDomain();
        
        if (!$targetDomain) {
            throw new Exception("Target domain not specified for shop_url migration");
        }
        
        // Clean domain (remove protocol and trailing slash)
        $cleanDomain = $this->cleanDomain($targetDomain);
        
        try {
            // Update all entries in shop_url table
            $sql = "UPDATE `{$prefix}shop_url` SET 
                    `domain` = '" . pSQL($cleanDomain) . "',
                    `domain_ssl` = '" . pSQL($cleanDomain) . "'";
            
            $result = $this->db->execute($sql);
            
            if (!$result) {
                throw new Exception("Failed to update shop_url table");
            }
            
            // Log the changes
            $updatedRows = $this->db->Affected_Rows();
            $this->logger->info("Updated shop_url table", [
                'domain' => $cleanDomain,
                'updated_rows' => $updatedRows
            ]);
            
            // Verify the update
            $this->verifyShopUrlUpdate($prefix, $cleanDomain);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to migrate shop_url table", [
                'error' => $e->getMessage(),
                'target_domain' => $cleanDomain
            ]);
            throw $e;
        }
    }

    /**
     * Migrate configuration table URLs
     *
     * @param array $migrationConfig
     */
    private function migrateConfigurationUrls(array $migrationConfig): void
    {
        $this->logger->info("Migrating configuration URLs");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $targetDomain = $migrationConfig['target_domain'] ?? $this->getCurrentDomain();
        $sourceDomain = $migrationConfig['source_domain'] ?? '';
        
        $cleanDomain = $this->cleanDomain($targetDomain);
        
        // Configuration keys that contain domain information
        $domainConfigs = [
            'PS_SHOP_DOMAIN' => $cleanDomain,
            'PS_SHOP_DOMAIN_SSL' => $cleanDomain,
            'PS_SHOP_NAME' => null, // Don't change shop name
            'PS_META_TITLE' => null // Don't change meta title
        ];
        
        foreach ($domainConfigs as $configKey => $configValue) {
            if ($configValue === null) {
                continue; // Skip configs we don't want to change
            }
            
            try {
                $sql = "UPDATE `{$prefix}configuration` 
                        SET `value` = '" . pSQL($configValue) . "' 
                        WHERE `name` = '" . pSQL($configKey) . "'";
                
                $result = $this->db->execute($sql);
                
                if ($result) {
                    $this->logger->info("Updated configuration", [
                        'key' => $configKey,
                        'value' => $configValue
                    ]);
                } else {
                    $this->logger->warning("Failed to update configuration", [
                        'key' => $configKey
                    ]);
                }
                
            } catch (Exception $e) {
                $this->logger->warning("Error updating configuration", [
                    'key' => $configKey,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Update any URLs that contain the old domain
        if ($sourceDomain && $sourceDomain !== $targetDomain) {
            $this->replaceDomainsInConfiguration($prefix, $sourceDomain, $targetDomain);
        }
    }

    /**
     * Migrate URLs in content (CMS, products, categories)
     *
     * @param array $migrationConfig
     */
    private function migrateContentUrls(array $migrationConfig): void
    {
        $this->logger->info("Migrating content URLs");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $sourceDomain = $migrationConfig['source_domain'] ?? '';
        $targetDomain = $migrationConfig['target_domain'] ?? '';
        
        if (!$sourceDomain || !$targetDomain || $sourceDomain === $targetDomain) {
            $this->logger->info("Skipping content URL migration - domains are the same or not specified");
            return;
        }
        
        // Tables that might contain URLs in content
        $contentTables = [
            'cms_lang' => ['content', 'meta_description', 'meta_keywords'],
            'product_lang' => ['description', 'description_short', 'meta_description', 'meta_keywords'],
            'category_lang' => ['description', 'meta_description', 'meta_keywords'],
            'hook_module' => ['position'],
            'meta_lang' => ['title', 'description', 'keywords']
        ];
        
        foreach ($contentTables as $table => $columns) {
            $this->migrateTableUrls($prefix, $table, $columns, $sourceDomain, $targetDomain);
        }
    }

    /**
     * Migrate module-specific URLs
     *
     * @param array $migrationConfig
     */
    private function migrateModuleUrls(array $migrationConfig): void
    {
        $this->logger->info("Migrating module-specific URLs");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $sourceDomain = $migrationConfig['source_domain'] ?? '';
        $targetDomain = $migrationConfig['target_domain'] ?? '';
        
        if (!$sourceDomain || !$targetDomain || $sourceDomain === $targetDomain) {
            return;
        }
        
        // Module-specific tables that might contain URLs
        $moduleTables = [
            'ps_linklist_link_lang' => ['title', 'description'],
            'ps_banner_lang' => ['description'],
            'ps_customtext_lang' => ['text'],
            'ps_emailsubscription' => ['email']
        ];
        
        foreach ($moduleTables as $table => $columns) {
            // Check if table exists before attempting migration
            if ($this->tableExists($prefix . $table)) {
                $this->migrateTableUrls($prefix, $table, $columns, $sourceDomain, $targetDomain);
            }
        }
    }

    /**
     * Update SSL configuration based on target environment
     *
     * @param array $migrationConfig
     */
    private function updateSslConfiguration(array $migrationConfig): void
    {
        $this->logger->info("Updating SSL configuration");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $forceHttps = $migrationConfig['force_https'] ?? false;
        $targetUrl = $migrationConfig['target_url'] ?? '';
        
        // Detect if target should use HTTPS
        $useHttps = $forceHttps || (strpos($targetUrl, 'https://') === 0);
        
        $sslConfigs = [
            'PS_SSL_ENABLED' => $useHttps ? '1' : '0',
            'PS_SSL_ENABLED_EVERYWHERE' => $useHttps ? '1' : '0'
        ];
        
        foreach ($sslConfigs as $configKey => $configValue) {
            try {
                $sql = "UPDATE `{$prefix}configuration` 
                        SET `value` = '" . pSQL($configValue) . "' 
                        WHERE `name` = '" . pSQL($configKey) . "'";
                
                $result = $this->db->execute($sql);
                
                if ($result) {
                    $this->logger->info("Updated SSL configuration", [
                        'key' => $configKey,
                        'value' => $configValue
                    ]);
                }
                
            } catch (Exception $e) {
                $this->logger->warning("Failed to update SSL configuration", [
                    'key' => $configKey,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Validate that URL migration was successful
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function validateUrlMigration(array $migrationConfig): void
    {
        $this->logger->info("Validating URL migration");
        
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        $targetDomain = $migrationConfig['target_domain'] ?? '';
        
        if (!$targetDomain) {
            return;
        }
        
        $cleanDomain = $this->cleanDomain($targetDomain);
        
        // Check shop_url table
        $sql = "SELECT domain, domain_ssl FROM `{$prefix}shop_url` LIMIT 1";
        $result = $this->db->getRow($sql);
        
        if (!$result) {
            throw new Exception("No records found in shop_url table after migration");
        }
        
        if ($result['domain'] !== $cleanDomain || $result['domain_ssl'] !== $cleanDomain) {
            $this->logger->warning("URL migration validation failed", [
                'expected_domain' => $cleanDomain,
                'actual_domain' => $result['domain'],
                'actual_domain_ssl' => $result['domain_ssl']
            ]);
        } else {
            $this->logger->info("URL migration validation successful", [
                'domain' => $result['domain'],
                'domain_ssl' => $result['domain_ssl']
            ]);
        }
        
        // Check configuration table
        $configSql = "SELECT value FROM `{$prefix}configuration` WHERE name = 'PS_SHOP_DOMAIN'";
        $configResult = $this->db->getValue($configSql);
        
        if ($configResult && $configResult !== $cleanDomain) {
            $this->logger->warning("Configuration validation failed", [
                'expected' => $cleanDomain,
                'actual' => $configResult
            ]);
        }
    }

    /**
     * Replace domains in configuration table
     *
     * @param string $prefix
     * @param string $sourceDomain
     * @param string $targetDomain
     */
    private function replaceDomainsInConfiguration(string $prefix, string $sourceDomain, string $targetDomain): void
    {
        $this->logger->info("Replacing domains in configuration table", [
            'from' => $sourceDomain,
            'to' => $targetDomain
        ]);
        
        $cleanSourceDomain = $this->cleanDomain($sourceDomain);
        $cleanTargetDomain = $this->cleanDomain($targetDomain);
        
        try {
            // Replace both HTTP and HTTPS URLs
            $sql = "UPDATE `{$prefix}configuration` SET 
                    `value` = REPLACE(`value`, 'http://{$cleanSourceDomain}', 'http://{$cleanTargetDomain}'),
                    `value` = REPLACE(`value`, 'https://{$cleanSourceDomain}', 'https://{$cleanTargetDomain}')
                    WHERE `value` LIKE '%{$cleanSourceDomain}%'";
            
            $result = $this->db->execute($sql);
            
            if ($result) {
                $updatedRows = $this->db->Affected_Rows();
                $this->logger->info("Replaced domains in configuration", [
                    'updated_rows' => $updatedRows
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to replace domains in configuration", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Migrate URLs in a specific table
     *
     * @param string $prefix
     * @param string $table
     * @param array $columns
     * @param string $sourceDomain
     * @param string $targetDomain
     */
    private function migrateTableUrls(string $prefix, string $table, array $columns, string $sourceDomain, string $targetDomain): void
    {
        $cleanSourceDomain = $this->cleanDomain($sourceDomain);
        $cleanTargetDomain = $this->cleanDomain($targetDomain);
        
        $fullTableName = $prefix . $table;
        
        if (!$this->tableExists($fullTableName)) {
            return;
        }
        
        foreach ($columns as $column) {
            if (!$this->columnExists($fullTableName, $column)) {
                continue;
            }
            
            try {
                $sql = "UPDATE `{$fullTableName}` SET 
                        `{$column}` = REPLACE(`{$column}`, 'http://{$cleanSourceDomain}', 'http://{$cleanTargetDomain}'),
                        `{$column}` = REPLACE(`{$column}`, 'https://{$cleanSourceDomain}', 'https://{$cleanTargetDomain}')
                        WHERE `{$column}` LIKE '%{$cleanSourceDomain}%'";
                
                $result = $this->db->execute($sql);
                
                if ($result) {
                    $updatedRows = $this->db->Affected_Rows();
                    if ($updatedRows > 0) {
                        $this->logger->info("Updated URLs in table content", [
                            'table' => $table,
                            'column' => $column,
                            'updated_rows' => $updatedRows
                        ]);
                    }
                }
                
            } catch (Exception $e) {
                $this->logger->warning("Failed to update URLs in table", [
                    'table' => $table,
                    'column' => $column,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Verify shop_url table update
     *
     * @param string $prefix
     * @param string $expectedDomain
     * @throws Exception
     */
    private function verifyShopUrlUpdate(string $prefix, string $expectedDomain): void
    {
        $sql = "SELECT COUNT(*) as count FROM `{$prefix}shop_url` 
                WHERE `domain` = '" . pSQL($expectedDomain) . "' 
                AND `domain_ssl` = '" . pSQL($expectedDomain) . "'";
        
        $result = $this->db->getValue($sql);
        
        if (!$result || $result == 0) {
            throw new Exception("shop_url table update verification failed - no records with expected domain");
        }
        
        $this->logger->info("shop_url table update verified", [
            'expected_domain' => $expectedDomain,
            'verified_records' => $result
        ]);
    }

    /**
     * Clean domain (remove protocol, www, trailing slash)
     *
     * @param string $domain
     * @return string
     */
    private function cleanDomain(string $domain): string
    {
        // Remove protocol
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        
        // Remove www prefix
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Remove trailing slash and path
        $domain = explode('/', $domain)[0];
        
        // Remove port if present
        $domain = explode(':', $domain)[0];
        
        return strtolower(trim($domain));
    }

    /**
     * Get current domain from environment
     *
     * @return string|null
     */
    private function getCurrentDomain(): ?string
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            return $this->cleanDomain($_SERVER['HTTP_HOST']);
        }
        
        // Try to get from configuration
        try {
            $sql = "SELECT value FROM `" . _DB_PREFIX_ . "configuration` WHERE name = 'PS_SHOP_DOMAIN'";
            $result = $this->db->getValue($sql);
            
            if ($result) {
                return $this->cleanDomain($result);
            }
        } catch (Exception $e) {
            // Ignore errors when reading from configuration
        }
        
        return null;
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
     * Get URL migration summary
     *
     * @param array $migrationConfig
     * @return array
     */
    public function getUrlMigrationSummary(array $migrationConfig): array
    {
        $prefix = $migrationConfig['target_prefix'] ?? _DB_PREFIX_;
        
        $summary = [
            'shop_url_records' => 0,
            'configuration_records' => 0,
            'current_domain' => null,
            'current_domain_ssl' => null
        ];
        
        try {
            // Count shop_url records
            $sql = "SELECT COUNT(*) FROM `{$prefix}shop_url`";
            $summary['shop_url_records'] = $this->db->getValue($sql);
            
            // Get current domain from shop_url
            $sql = "SELECT domain, domain_ssl FROM `{$prefix}shop_url` LIMIT 1";
            $result = $this->db->getRow($sql);
            
            if ($result) {
                $summary['current_domain'] = $result['domain'];
                $summary['current_domain_ssl'] = $result['domain_ssl'];
            }
            
            // Count configuration records
            $sql = "SELECT COUNT(*) FROM `{$prefix}configuration` WHERE name LIKE 'PS_SHOP_DOMAIN%'";
            $summary['configuration_records'] = $this->db->getValue($sql);
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to get URL migration summary", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $summary;
    }
} 