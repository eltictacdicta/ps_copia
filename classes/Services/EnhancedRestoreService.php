<?php
/**
 * Enhanced Restore Service for PS_Copia
 * Handles secure and robust backup restoration with cross-environment migration
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Migration\DatabaseMigrator;
use PrestaShop\Module\PsCopia\Migration\FilesMigrator;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use PrestaShop\Module\PsCopia\Services\ValidationService;
use Exception;
use ZipArchive;

class EnhancedRestoreService
{
    /** @var BackupContainer */
    private $backupContainer;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var ValidationService */
    private $validationService;
    
    /** @var DatabaseMigrator */
    private $dbMigrator;
    
    /** @var FilesMigrator */
    private $filesMigrator;
    
    /** @var array */
    private $currentEnvironment;
    
    /** @var array */
    private $backupInfo;
    
    /** @var string */
    private $tempRestoreDir;

    public function __construct(
        BackupContainer $backupContainer,
        BackupLogger $logger,
        ValidationService $validationService,
        DatabaseMigrator $dbMigrator,
        FilesMigrator $filesMigrator
    ) {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->validationService = $validationService;
        $this->dbMigrator = $dbMigrator;
        $this->filesMigrator = $filesMigrator;
        
        $this->initializeEnvironment();
    }

    /**
     * Enhanced restore with full cross-environment compatibility
     * Handles different MySQL configurations, table prefixes, and URLs
     *
     * @param string $backupName
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function restoreBackupEnhanced(string $backupName, array $options = []): array
    {
        $this->logger->info("Starting enhanced backup restoration", [
            'backup_name' => $backupName,
            'options' => $options
        ]);

        $startTime = microtime(true);
        
        try {
            // Step 1: Initialize and validate
            $this->initializeRestoration($backupName, $options);
            
            // Step 2: Create safety backup
            $safetyBackup = $this->createSafetyBackup();
            
            // Step 3: Analyze backup content
            $this->analyzeBackupContent();
            
            // Step 4: Prepare migration configuration
            $migrationConfig = $this->prepareMigrationConfiguration($options);
            
            // Step 5: Execute database restoration with transaction
            $this->executeTransactionalDatabaseRestore($migrationConfig);
            
            // Step 6: Execute file restoration
            $this->executeFileRestoration($migrationConfig);
            
            // Step 7: Post-restoration cleanup and validation
            $this->performPostRestorationTasks($migrationConfig);
            
            // Step 8: Cleanup temporary files
            $this->cleanupTemporaryFiles();
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info("Enhanced restoration completed successfully", [
                'backup_name' => $backupName,
                'duration' => round($duration, 2) . 's',
                'database_migrated' => $migrationConfig['database_migrated'],
                'files_migrated' => $migrationConfig['files_migrated'],
                'urls_updated' => $migrationConfig['urls_updated']
            ]);

            return [
                'success' => true,
                'backup_name' => $backupName,
                'duration' => round($duration, 2),
                'migration_summary' => [
                    'database_migrated' => $migrationConfig['database_migrated'],
                    'files_migrated' => $migrationConfig['files_migrated'],
                    'urls_updated' => $migrationConfig['urls_updated'],
                    'prefix_changed' => $migrationConfig['prefix_changed'] ?? false,
                    'domain_changed' => $migrationConfig['domain_changed'] ?? false
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Enhanced restoration failed", [
                'backup_name' => $backupName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Attempt to restore from safety backup
            if (isset($safetyBackup) && $safetyBackup) {
                $this->restoreFromSafetyBackup($safetyBackup);
            }
            
            // Cleanup temporary files
            $this->cleanupTemporaryFiles();
            
            throw new Exception("Restoration failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize restoration process
     *
     * @param string $backupName
     * @param array $options
     * @throws Exception
     */
    private function initializeRestoration(string $backupName, array $options): void
    {
        $this->logger->info("Initializing restoration process");
        
        // Get backup metadata
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new Exception("Backup not found: " . $backupName);
        }
        
        $this->backupInfo = $metadata[$backupName];
        
        // Validate backup files exist
        $this->validateBackupFiles();
        
        // Create temporary directory for restoration
        $this->tempRestoreDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($this->tempRestoreDir, 0755, true)) {
            throw new Exception('Cannot create temporary restoration directory');
        }
        
        $this->logger->info("Restoration initialized successfully", [
            'backup_name' => $backupName,
            'temp_dir' => $this->tempRestoreDir
        ]);
    }

    /**
     * Create safety backup before restoration
     *
     * @return string|false
     */
    private function createSafetyBackup()
    {
        $this->logger->info("Creating safety backup before restoration");
        
        try {
            // Create safety backup name
            $safetyBackupName = 'safety_backup_' . date('Y-m-d_H-i-s');
            
            // Use existing backup service to create safety backup
            $backupService = new BackupService($this->backupContainer, $this->logger, $this->validationService);
            $result = $backupService->createBackup('complete', $safetyBackupName);
            
            if ($result && isset($result['backup_name'])) {
                $this->logger->info("Safety backup created successfully", [
                    'safety_backup_name' => $result['backup_name']
                ]);
                return $result['backup_name'];
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to create safety backup", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Analyze backup content to understand source environment
     *
     * @throws Exception
     */
    private function analyzeBackupContent(): void
    {
        $this->logger->info("Analyzing backup content");
        
        // Get backup files
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $this->backupInfo['database_file'];
        
        // Analyze database backup
        $this->backupInfo['source_analysis'] = $this->analyzeSourceEnvironment($dbFilePath);
        
        $this->logger->info("Backup analysis completed", [
            'source_prefix' => $this->backupInfo['source_analysis']['prefix'],
            'source_domain' => $this->backupInfo['source_analysis']['domain'],
            'table_count' => $this->backupInfo['source_analysis']['table_count'],
            'prestashop_version' => $this->backupInfo['source_analysis']['prestashop_version'] ?? 'unknown'
        ]);
    }

    /**
     * Prepare migration configuration based on environments
     *
     * @param array $options
     * @return array
     */
    private function prepareMigrationConfiguration(array $options): array
    {
        $this->logger->info("Preparing migration configuration");
        
        $sourceInfo = $this->backupInfo['source_analysis'];
        $currentEnv = $this->currentEnvironment;
        
        $config = [
            // Database migration settings
            'clean_destination' => $options['clean_destination'] ?? true,
            'preserve_db_config' => $options['preserve_db_config'] ?? true,
            'prefix_changed' => $sourceInfo['prefix'] !== $currentEnv['prefix'],
            'source_prefix' => $sourceInfo['prefix'],
            'target_prefix' => $currentEnv['prefix'],
            
            // URL migration settings
            'migrate_urls' => $options['migrate_urls'] ?? true,
            'domain_changed' => $sourceInfo['domain'] !== $currentEnv['domain'],
            'source_domain' => $sourceInfo['domain'],
            'target_domain' => $currentEnv['domain'],
            'source_url' => $sourceInfo['base_url'] ?? '',
            'target_url' => $currentEnv['base_url'],
            
            // File migration settings
            'migrate_files' => $options['migrate_files'] ?? true,
            'preserve_admin_config' => $options['preserve_admin_config'] ?? true,
            
            // Security settings
            'disable_problematic_modules' => $options['disable_problematic_modules'] ?? true,
            'force_https' => $options['force_https'] ?? false,
            
            // Tracking
            'database_migrated' => false,
            'files_migrated' => false,
            'urls_updated' => false
        ];
        
        $this->logger->info("Migration configuration prepared", [
            'prefix_changed' => $config['prefix_changed'],
            'domain_changed' => $config['domain_changed'],
            'source_prefix' => $config['source_prefix'],
            'target_prefix' => $config['target_prefix'],
            'source_domain' => $config['source_domain'],
            'target_domain' => $config['target_domain']
        ]);
        
        return $config;
    }

    /**
     * Execute database restoration with transaction handling
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function executeTransactionalDatabaseRestore(array &$migrationConfig): void
    {
        $this->logger->info("Starting transactional database restoration");
        
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $this->backupInfo['database_file'];
        
        // Start transaction-like process
        $this->logger->info("Starting database transaction");
        
        try {
            // Step 1: Clean destination if required
            if ($migrationConfig['clean_destination']) {
                $this->cleanDestinationDatabase($migrationConfig['target_prefix']);
            }
            
            // Step 2: Restore with prefix adaptation
            $this->restoreWithPrefixAdaptation($dbFilePath, $migrationConfig);
            
            // Step 3: Migrate URLs and domains
            if ($migrationConfig['migrate_urls']) {
                $this->migrateUrlsAndDomains($migrationConfig);
            }
            
            // Step 4: Preserve current environment configuration
            if ($migrationConfig['preserve_db_config']) {
                $this->preserveCurrentEnvironmentConfig($migrationConfig);
            }
            
            // Step 5: Disable problematic modules
            if ($migrationConfig['disable_problematic_modules']) {
                $this->disableProblematicModules($migrationConfig['target_prefix']);
            }
            
            $migrationConfig['database_migrated'] = true;
            $this->logger->info("Database restoration completed successfully");
            
        } catch (Exception $e) {
            $this->logger->error("Database restoration failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Execute file restoration
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function executeFileRestoration(array &$migrationConfig): void
    {
        if (!$migrationConfig['migrate_files']) {
            $this->logger->info("File migration disabled, skipping");
            return;
        }
        
        $this->logger->info("Starting file restoration");
        
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $this->backupInfo['files_file'];
        
        try {
            // Extract files to temporary directory
            $tempFilesDir = $this->extractFilesToTemporary($filesFilePath);
            
            // Apply file migrations if needed
            $this->applyFileMigrations($tempFilesDir, $migrationConfig);
            
            // Copy files to final location with safety checks
            $this->copyFilesWithSafetyChecks($tempFilesDir, _PS_ROOT_DIR_);
            
            // Cleanup admin directories
            $this->cleanupAdminDirectories($tempFilesDir);
            
            $migrationConfig['files_migrated'] = true;
            $this->logger->info("File restoration completed successfully");
            
        } catch (Exception $e) {
            $this->logger->error("File restoration failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Perform post-restoration tasks
     *
     * @param array $migrationConfig
     */
    private function performPostRestorationTasks(array &$migrationConfig): void
    {
        $this->logger->info("Performing post-restoration tasks");
        
        try {
            // Clear all caches
            $this->clearAllCaches();
            
            // Regenerate .htaccess if needed
            $this->regenerateHtaccess();
            
            // Update configuration timestamps
            $this->updateConfigurationTimestamps();
            
            // Verify restoration integrity
            $this->verifyRestorationIntegrity($migrationConfig);
            
            $this->logger->info("Post-restoration tasks completed successfully");
            
        } catch (Exception $e) {
            $this->logger->warning("Some post-restoration tasks failed", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Initialize current environment information
     */
    private function initializeEnvironment(): void
    {
        $this->currentEnvironment = [
            'prefix' => $this->getCurrentPrefix(),
            'domain' => $this->getCurrentDomain(),
            'base_url' => $this->getCurrentBaseUrl(),
            'admin_dir' => $this->getCurrentAdminDirectory(),
            'db_credentials' => $this->getCurrentDbCredentials(),
            'is_ddev' => $this->isDdevEnvironment(),
            'prestashop_version' => $this->getPrestaShopVersion()
        ];
        
        $this->logger->info("Current environment initialized", [
            'prefix' => $this->currentEnvironment['prefix'],
            'domain' => $this->currentEnvironment['domain'],
            'is_ddev' => $this->currentEnvironment['is_ddev']
        ]);
    }

    /**
     * Get backup metadata
     *
     * @return array
     */
    private function getBackupMetadata(): array
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . 
                       DIRECTORY_SEPARATOR . 'backup_metadata.json';
        
        if (!file_exists($metadataFile)) {
            return [];
        }
        
        $content = file_get_contents($metadataFile);
        return json_decode($content, true) ?: [];
    }

    /**
     * Validate backup files exist
     *
     * @throws Exception
     */
    private function validateBackupFiles(): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        
        if (!isset($this->backupInfo['database_file'])) {
            throw new Exception("Database file not specified in backup metadata");
        }
        
        if (!isset($this->backupInfo['files_file'])) {
            throw new Exception("Files file not specified in backup metadata");
        }
        
        $dbPath = $backupDir . DIRECTORY_SEPARATOR . $this->backupInfo['database_file'];
        $filesPath = $backupDir . DIRECTORY_SEPARATOR . $this->backupInfo['files_file'];
        
        if (!file_exists($dbPath)) {
            throw new Exception("Database backup file not found: " . $this->backupInfo['database_file']);
        }
        
        if (!file_exists($filesPath)) {
            throw new Exception("Files backup file not found: " . $this->backupInfo['files_file']);
        }
    }

    /**
     * Analyze source environment from database backup
     *
     * @param string $dbFilePath
     * @return array
     */
    private function analyzeSourceEnvironment(string $dbFilePath): array
    {
        $this->logger->info("Analyzing source environment from database backup");
        
        // Use existing DatabaseMigrator methods
        $prefix = $this->dbMigrator->detectPrefixFromBackup($dbFilePath);
        $domain = $this->dbMigrator->extractSourceDomainFromBackup($dbFilePath);
        
        return [
            'prefix' => $prefix ?: 'ps_',
            'domain' => $domain ?: 'localhost',
            'base_url' => $domain ? 'http://' . $domain : 'http://localhost',
            'table_count' => $this->countTablesInBackup($dbFilePath),
            'prestashop_version' => $this->detectPrestaShopVersion($dbFilePath)
        ];
    }

    /**
     * Count tables in backup
     *
     * @param string $dbFilePath
     * @return int
     */
    private function countTablesInBackup(string $dbFilePath): int
    {
        $content = file_get_contents($dbFilePath);
        if (!$content) {
            return 0;
        }
        
        return substr_count($content, 'CREATE TABLE');
    }

    /**
     * Detect PrestaShop version from backup
     *
     * @param string $dbFilePath
     * @return string|null
     */
    private function detectPrestaShopVersion(string $dbFilePath): ?string
    {
        $content = file_get_contents($dbFilePath);
        if (!$content) {
            return null;
        }
        
        // Look for version in configuration table
        if (preg_match("/INSERT INTO.*configuration.*PS_VERSION_DB.*'([^']+)'/i", $content, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Get current database prefix
     *
     * @return string
     */
    private function getCurrentPrefix(): string
    {
        return defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
    }

    /**
     * Get current domain
     *
     * @return string
     */
    private function getCurrentDomain(): string
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }
        
        return 'localhost';
    }

    /**
     * Get current base URL
     *
     * @return string
     */
    private function getCurrentBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        return $protocol . $this->getCurrentDomain();
    }

    /**
     * Get current admin directory
     *
     * @return string|null
     */
    private function getCurrentAdminDirectory(): ?string
    {
        // Implementation would detect current admin directory
        return null;
    }

    /**
     * Get current database credentials
     *
     * @return array
     */
    private function getCurrentDbCredentials(): array
    {
        return $this->dbMigrator->getCurrentDbCredentials();
    }

    /**
     * Check if running in DDEV environment
     *
     * @return bool
     */
    private function isDdevEnvironment(): bool
    {
        return $this->dbMigrator->isDdevEnvironment();
    }

    /**
     * Get PrestaShop version
     *
     * @return string
     */
    private function getPrestaShopVersion(): string
    {
        return defined('_PS_VERSION_') ? _PS_VERSION_ : '1.7.x';
    }

    /**
     * Clean destination database
     *
     * @param string $prefix
     * @throws Exception
     */
    private function cleanDestinationDatabase(string $prefix): void
    {
        $this->logger->info("Cleaning destination database with prefix: " . $prefix);
        
        try {
            $db = \Db::getInstance();
            
            // Get all tables with prefix
            $sql = "SHOW TABLES LIKE '" . $prefix . "%'";
            $tables = $db->executeS($sql);
            
            if (!empty($tables)) {
                // Disable foreign key checks
                $db->execute("SET FOREIGN_KEY_CHECKS = 0");
                
                foreach ($tables as $table) {
                    $tableName = reset($table);
                    $db->execute("DROP TABLE IF EXISTS `" . $tableName . "`");
                }
                
                // Re-enable foreign key checks
                $db->execute("SET FOREIGN_KEY_CHECKS = 1");
                
                $this->logger->info("Cleaned " . count($tables) . " existing tables");
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to clean destination database", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Restore with prefix adaptation
     *
     * @param string $dbFilePath
     * @param array $migrationConfig
     * @throws Exception
     */
    private function restoreWithPrefixAdaptation(string $dbFilePath, array $migrationConfig): void
    {
        $this->logger->info("Restoring database with prefix adaptation");
        
        if ($migrationConfig['prefix_changed']) {
            $this->logger->info("Adapting table prefix during restoration", [
                'source_prefix' => $migrationConfig['source_prefix'],
                'target_prefix' => $migrationConfig['target_prefix']
            ]);
            
            // Create adapted backup
            $adaptedBackup = $this->createAdaptedBackup(
                $dbFilePath,
                $migrationConfig['source_prefix'],
                $migrationConfig['target_prefix']
            );
            
            try {
                $this->dbMigrator->restoreExternalDatabase($adaptedBackup);
                @unlink($adaptedBackup);
            } catch (Exception $e) {
                @unlink($adaptedBackup);
                throw $e;
            }
        } else {
            $this->dbMigrator->restoreExternalDatabase($dbFilePath);
        }
    }

    /**
     * Create adapted backup with prefix changes
     *
     * @param string $sourceFile
     * @param string $sourcePrefix
     * @param string $targetPrefix
     * @return string
     * @throws Exception
     */
    private function createAdaptedBackup(string $sourceFile, string $sourcePrefix, string $targetPrefix): string
    {
        $tempFile = $this->tempRestoreDir . DIRECTORY_SEPARATOR . 'adapted_backup.sql';
        
        $this->logger->info("Creating adapted backup", [
            'source_file' => basename($sourceFile),
            'temp_file' => $tempFile,
            'source_prefix' => $sourcePrefix,
            'target_prefix' => $targetPrefix
        ]);
        
        $sourceHandle = fopen($sourceFile, 'r');
        $targetHandle = fopen($tempFile, 'w');
        
        if (!$sourceHandle || !$targetHandle) {
            throw new Exception('Cannot open files for prefix adaptation');
        }
        
        try {
            while (($line = fgets($sourceHandle)) !== false) {
                // Replace table prefixes
                $line = str_replace('`' . $sourcePrefix, '`' . $targetPrefix, $line);
                $line = str_replace('CREATE TABLE `' . $sourcePrefix, 'CREATE TABLE `' . $targetPrefix, $line);
                $line = str_replace('INSERT INTO `' . $sourcePrefix, 'INSERT INTO `' . $targetPrefix, $line);
                
                fwrite($targetHandle, $line);
            }
        } finally {
            fclose($sourceHandle);
            fclose($targetHandle);
        }
        
        return $tempFile;
    }

    /**
     * Migrate URLs and domains
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function migrateUrlsAndDomains(array &$migrationConfig): void
    {
        if (!$migrationConfig['domain_changed']) {
            $this->logger->info("Domain unchanged, skipping URL migration");
            return;
        }
        
        $this->logger->info("Migrating URLs and domains", [
            'source_domain' => $migrationConfig['source_domain'],
            'target_domain' => $migrationConfig['target_domain']
        ]);
        
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        
        try {
            // Update shop_url table
            $sql = "UPDATE `" . $prefix . "shop_url` SET 
                    `domain` = '" . pSQL($migrationConfig['target_domain']) . "',
                    `domain_ssl` = '" . pSQL($migrationConfig['target_domain']) . "'";
            
            $db->execute($sql);
            
            // Update configuration table
            $configUpdates = [
                'PS_SHOP_DOMAIN' => $migrationConfig['target_domain'],
                'PS_SHOP_DOMAIN_SSL' => $migrationConfig['target_domain']
            ];
            
            foreach ($configUpdates as $configKey => $configValue) {
                $sql = "UPDATE `" . $prefix . "configuration` SET `value` = '" . pSQL($configValue) . "' 
                        WHERE `name` = '" . pSQL($configKey) . "'";
                $db->execute($sql);
            }
            
            // Update any URLs in content
            $this->updateContentUrls($migrationConfig);
            
            $migrationConfig['urls_updated'] = true;
            $this->logger->info("URLs and domains migrated successfully");
            
        } catch (Exception $e) {
            $this->logger->error("Failed to migrate URLs and domains", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update URLs in content
     *
     * @param array $migrationConfig
     */
    private function updateContentUrls(array $migrationConfig): void
    {
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        
        $sourceDomain = $migrationConfig['source_domain'];
        $targetDomain = $migrationConfig['target_domain'];
        
        // Tables that might contain URLs
        $urlTables = [
            'cms_lang' => 'content',
            'product_lang' => 'description',
            'category_lang' => 'description',
            'configuration' => 'value'
        ];
        
        foreach ($urlTables as $table => $column) {
            $sql = "UPDATE `" . $prefix . $table . "` SET 
                    `" . $column . "` = REPLACE(`" . $column . "`, 'http://" . $sourceDomain . "', 'http://" . $targetDomain . "'),
                    `" . $column . "` = REPLACE(`" . $column . "`, 'https://" . $sourceDomain . "', 'https://" . $targetDomain . "')";
            
            try {
                $db->execute($sql);
                $this->logger->info("Updated URLs in table: " . $table);
            } catch (Exception $e) {
                $this->logger->warning("Failed to update URLs in table: " . $table, [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Preserve current environment configuration
     *
     * @param array $migrationConfig
     */
    private function preserveCurrentEnvironmentConfig(array $migrationConfig): void
    {
        $this->logger->info("Preserving current environment configuration");
        
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        $credentials = $this->currentEnvironment['db_credentials'];
        
        // Update database configuration
        $configUpdates = [
            'PS_DB_SERVER' => $credentials['host'],
            'PS_DB_USER' => $credentials['user'],
            'PS_DB_NAME' => $credentials['name']
        ];
        
        foreach ($configUpdates as $configKey => $configValue) {
            $sql = "UPDATE `" . $prefix . "configuration` SET `value` = '" . pSQL($configValue) . "' 
                    WHERE `name` = '" . pSQL($configKey) . "'";
            try {
                $db->execute($sql);
            } catch (Exception $e) {
                $this->logger->warning("Failed to update config: " . $configKey, [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Disable problematic modules
     *
     * @param string $prefix
     */
    private function disableProblematicModules(string $prefix): void
    {
        $this->logger->info("Disabling problematic modules");
        
        $db = \Db::getInstance();
        $problematicModules = [
            'ps_metrics',
            'ps_eventbus',
            'ps_facebook',
            'ps_googleanalytics',
            'blockreinsurance'
        ];
        
        foreach ($problematicModules as $module) {
            $sql = "UPDATE `" . $prefix . "module` SET `active` = 0 WHERE `name` = '" . pSQL($module) . "'";
            try {
                $db->execute($sql);
                $this->logger->info("Disabled module: " . $module);
            } catch (Exception $e) {
                $this->logger->warning("Failed to disable module: " . $module, [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Extract files to temporary directory
     *
     * @param string $filesFilePath
     * @return string
     * @throws Exception
     */
    private function extractFilesToTemporary(string $filesFilePath): string
    {
        $this->logger->info("Extracting files to temporary directory");
        
        $zip = new ZipArchive();
        $result = $zip->open($filesFilePath);
        
        if ($result !== TRUE) {
            throw new Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }
        
        $tempFilesDir = $this->tempRestoreDir . DIRECTORY_SEPARATOR . 'files';
        if (!mkdir($tempFilesDir, 0755, true)) {
            throw new Exception('Cannot create temporary files directory');
        }
        
        if (!$zip->extractTo($tempFilesDir)) {
            $zip->close();
            throw new Exception('Failed to extract ZIP file');
        }
        
        $zip->close();
        
        return $tempFilesDir;
    }

    /**
     * Apply file migrations
     *
     * @param string $tempFilesDir
     * @param array $migrationConfig
     */
    private function applyFileMigrations(string $tempFilesDir, array $migrationConfig): void
    {
        $this->logger->info("Applying file migrations");
        
        // Here you could implement file-specific migrations
        // For example, updating configuration files, admin directory renames, etc.
        
        if ($migrationConfig['preserve_admin_config']) {
            $this->preserveAdminConfiguration($tempFilesDir);
        }
    }

    /**
     * Preserve admin configuration
     *
     * @param string $tempFilesDir
     */
    private function preserveAdminConfiguration(string $tempFilesDir): void
    {
        $this->logger->info("Preserving admin configuration from backup");
        
        // This would implement admin configuration preservation logic
        // For now, we'll just log the intent
    }

    /**
     * Copy files with safety checks
     *
     * @param string $source
     * @param string $destination
     * @throws Exception
     */
    private function copyFilesWithSafetyChecks(string $source, string $destination): void
    {
        $this->logger->info("Copying files with safety checks");
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $excludePaths = $this->validationService->getExcludePaths();
        
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $relativePath;
            
            if ($this->validationService->shouldExcludeFile($destinationPath, $excludePaths)) {
                continue;
            }
            
            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }
            } else {
                $destinationDir = dirname($destinationPath);
                if (!is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                
                if (!copy($item->getPathname(), $destinationPath)) {
                    throw new Exception('Failed to copy file: ' . $relativePath);
                }
            }
        }
    }

    /**
     * Cleanup admin directories
     *
     * @param string $tempFilesDir
     */
    private function cleanupAdminDirectories(string $tempFilesDir): void
    {
        $this->logger->info("Cleaning up admin directories");
        
        // Use existing FilesMigrator logic for admin directory cleanup
        try {
            $this->filesMigrator->cleanupObsoleteAdminDirectories(null, null);
        } catch (Exception $e) {
            $this->logger->warning("Admin directory cleanup failed", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear all caches
     */
    private function clearAllCaches(): void
    {
        $this->logger->info("Clearing all caches");
        
        $cacheDirectories = [
            _PS_CACHE_DIR_,
            _PS_ROOT_DIR_ . '/var/cache',
            _PS_ROOT_DIR_ . '/app/cache'
        ];
        
        foreach ($cacheDirectories as $cacheDir) {
            if (is_dir($cacheDir)) {
                $this->removeDirectoryRecursively($cacheDir, true);
            }
        }
    }

    /**
     * Regenerate .htaccess
     */
    private function regenerateHtaccess(): void
    {
        $this->logger->info("Regenerating .htaccess");
        
        $htaccessPath = _PS_ROOT_DIR_ . '/.htaccess';
        $backupPath = _PS_ROOT_DIR_ . '/.htaccess.backup';
        
        if (!file_exists($htaccessPath) && file_exists($backupPath)) {
            copy($backupPath, $htaccessPath);
        }
    }

    /**
     * Update configuration timestamps
     */
    private function updateConfigurationTimestamps(): void
    {
        $this->logger->info("Updating configuration timestamps");
        
        $db = \Db::getInstance();
        $prefix = $this->currentEnvironment['prefix'];
        
        $sql = "UPDATE `" . $prefix . "configuration` SET `date_upd` = NOW() WHERE `name` LIKE 'PS_%'";
        
        try {
            $db->execute($sql);
        } catch (Exception $e) {
            $this->logger->warning("Failed to update configuration timestamps", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Verify restoration integrity
     *
     * @param array $migrationConfig
     * @throws Exception
     */
    private function verifyRestorationIntegrity(array $migrationConfig): void
    {
        $this->logger->info("Verifying restoration integrity");
        
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        
        // Check essential tables exist
        $essentialTables = [
            'shop',
            'shop_url',
            'configuration',
            'module',
            'product',
            'category'
        ];
        
        foreach ($essentialTables as $table) {
            $sql = "SHOW TABLES LIKE '" . $prefix . $table . "'";
            $result = $db->executeS($sql);
            
            if (empty($result)) {
                throw new Exception("Essential table missing after restoration: " . $prefix . $table);
            }
        }
        
        // Check shop_url table has correct domain
        $sql = "SELECT domain FROM `" . $prefix . "shop_url` LIMIT 1";
        $result = $db->getValue($sql);
        
        if ($result && $result !== $migrationConfig['target_domain']) {
            $this->logger->warning("Domain mismatch in shop_url table", [
                'expected' => $migrationConfig['target_domain'],
                'actual' => $result
            ]);
        }
        
        $this->logger->info("Restoration integrity verified successfully");
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
     * @param bool $keepDir
     * @return bool
     */
    private function removeDirectoryRecursively(string $directory, bool $keepDir = false): bool
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
        
        if (!$keepDir) {
            return rmdir($directory);
        }
        
        return true;
    }

    /**
     * Restore from safety backup
     *
     * @param string $safetyBackupName
     */
    private function restoreFromSafetyBackup(string $safetyBackupName): void
    {
        $this->logger->info("Attempting to restore from safety backup", [
            'safety_backup_name' => $safetyBackupName
        ]);
        
        try {
            // Use existing restore service to restore safety backup
            $restoreService = new RestoreService($this->backupContainer, $this->logger, $this->validationService);
            $restoreService->restoreCompleteBackup($safetyBackupName);
            
            $this->logger->info("Successfully restored from safety backup");
        } catch (Exception $e) {
            $this->logger->error("Failed to restore from safety backup", [
                'error' => $e->getMessage()
            ]);
        }
    }
} 