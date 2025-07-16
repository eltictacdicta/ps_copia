<?php
/**
 * Restore Service for restoring backups
 * Handles all backup restoration logic
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\Migration\DatabaseMigrator;
use PrestaShop\Module\PsCopia\Migration\FilesMigrator;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class RestoreService
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
     * Restore complete backup (database + files) with automatic migration
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreCompleteBackup(string $backupName): void
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        // Validate that both files exist
        $this->backupContainer->validateBackupFile($backupInfo['database_file']);
        $this->backupContainer->validateBackupFile($backupInfo['files_file']);

        $this->logger->info("Starting complete restoration with cross-environment migration", [
            'backup_name' => $backupName,
            'database_file' => $backupInfo['database_file'],
            'files_file' => $backupInfo['files_file']
        ]);

        // Verify database connectivity before starting
        $this->verifyDatabaseConnectivity();

        // Create safety backup before starting
        $safetyBackupName = $this->createSafetyBackup();
        
        try {
            // Step 1: Analyze backup content to detect environment differences
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
            $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];
            
            $backupAnalysis = $this->analyzeBackupEnvironment($dbFilePath);
            $currentEnvironment = $this->getCurrentEnvironment();
            
            $this->logger->info("Environment analysis completed", [
                'backup_prefix' => $backupAnalysis['prefix'],
                'backup_domain' => $backupAnalysis['domain'],
                'current_prefix' => $currentEnvironment['prefix'],
                'current_domain' => $currentEnvironment['domain'],
                'requires_migration' => $this->requiresMigration($backupAnalysis, $currentEnvironment)
            ]);
            
            // Step 2: Prepare migration configuration
            $migrationConfig = [
                'migrate_urls' => true,
                'migrate_database' => true,
                'migrate_files' => true,
                'clean_destination' => true,
                'preserve_db_config' => false, // We want to completely replace with backup data
                'backup_analysis' => $backupAnalysis,
                'current_environment' => $currentEnvironment,
                'source_prefix' => $backupAnalysis['prefix'],
                'target_prefix' => $currentEnvironment['prefix'],
                'source_domain' => $backupAnalysis['domain'],
                'target_domain' => $currentEnvironment['domain'],
                'prefix_changed' => $backupAnalysis['prefix'] !== $currentEnvironment['prefix'],
                'domain_changed' => $backupAnalysis['domain'] !== $currentEnvironment['domain']
            ];
            
            // Step 3: Execute transactional database restoration
            $this->executeTransactionalDatabaseRestore($dbFilePath, $migrationConfig);
            
            // Step 4: Execute secure file restoration
            $this->executeSecureFileRestore($filesFilePath, $migrationConfig);
            
            // Step 5: Post-restoration verification and cleanup
            $this->performPostRestorationVerification($migrationConfig);
            
            $this->logger->info("Complete backup restored successfully with cross-environment migration", [
                'backup_name' => $backupName,
                'migrations_applied' => [
                    'prefix_migration' => $migrationConfig['prefix_changed'],
                    'domain_migration' => $migrationConfig['domain_changed'],
                    'database_migrated' => true,
                    'files_migrated' => true
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Complete restoration failed, attempting rollback", [
                'backup_name' => $backupName,
                'error' => $e->getMessage()
            ]);
            
            // Attempt to restore from safety backup
            if ($safetyBackupName) {
                try {
                    $this->restoreFromSafetyBackup($safetyBackupName);
                    $this->logger->info("Successfully rolled back to safety backup");
                } catch (\Exception $rollbackError) {
                    $this->logger->error("Rollback also failed", [
                        'rollback_error' => $rollbackError->getMessage()
                    ]);
                }
            }
            
            throw new \Exception("Restoration failed: " . $e->getMessage() . ". System rolled back to previous state.");
        }
    }

    /**
     * Verify database connectivity before restoration
     *
     * @throws \Exception
     */
    private function verifyDatabaseConnectivity(): void
    {
        $this->logger->info("Verifying database connectivity");
        
        try {
            $credentials = $this->getCurrentDbCredentials();
            
            $this->logger->info("Database connection details", [
                'host' => $credentials['host'],
                'user' => $credentials['user'],
                'database' => $credentials['name'],
                'environment' => $credentials['environment']
            ]);
            
            // Test database connection
            $db = \Db::getInstance();
            
            // Test basic query
            $result = $db->getValue("SELECT 1");
            if ($result != 1) {
                throw new \Exception("Database connectivity test failed - unexpected result");
            }
            
            // Test if we can access the database name
            $currentDbName = $db->getValue("SELECT DATABASE()");
            if (empty($currentDbName)) {
                throw new \Exception("Could not determine current database name");
            }
            
            // Verify we have necessary privileges
            $this->verifyDatabasePrivileges($db);
            
            $this->logger->info("Database connectivity verified successfully", [
                'current_database' => $currentDbName,
                'connection_test' => 'passed'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error("Database connectivity verification failed", [
                'error' => $e->getMessage(),
                'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown'
            ]);
            throw new \Exception("Cannot connect to database: " . $e->getMessage());
        }
    }

    /**
     * Verify necessary database privileges
     *
     * @param \Db $db
     * @throws \Exception
     */
    private function verifyDatabasePrivileges(\Db $db): void
    {
        try {
            // Check if we can create/drop tables (needed for restoration)
            $testTableName = 'ps_copia_test_' . time();
            
            // Try to create a test table
            $createSql = "CREATE TABLE `{$testTableName}` (id INT PRIMARY KEY)";
            $db->execute($createSql);
            
            // Try to drop the test table
            $dropSql = "DROP TABLE `{$testTableName}`";
            $db->execute($dropSql);
            
            $this->logger->info("Database privileges verified - can create/drop tables");
            
        } catch (\Exception $e) {
            $this->logger->warning("Database privileges check failed", [
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Insufficient database privileges for restoration: " . $e->getMessage());
        }
    }

    /**
     * Analyze backup environment to detect differences with current environment
     *
     * @param string $dbFilePath
     * @return array
     */
    private function analyzeBackupEnvironment(string $dbFilePath): array
    {
        $this->logger->info("Analyzing backup environment");
        
        // First diagnose the SQL file for potential issues
        $this->diagnoseSqlFile($dbFilePath);
        
        $analysis = [
            'prefix' => 'ps_',
            'domain' => '',
            'mysql_version' => '',
            'charset' => '',
            'collation' => ''
        ];
        
        try {
            // Detect prefix from backup file
            if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
                $dbMigrator = new \PrestaShop\Module\PsCopia\Migration\DatabaseMigrator($this->backupContainer, $this->logger);
                $detectedPrefix = $dbMigrator->detectPrefixFromBackup($dbFilePath);
                if ($detectedPrefix) {
                    $analysis['prefix'] = $detectedPrefix;
                }
                
                // Extract domain from backup
                $detectedDomain = $dbMigrator->extractSourceDomainFromBackup($dbFilePath);
                if ($detectedDomain) {
                    $analysis['domain'] = $detectedDomain;
                }
            }
            
            // Analyze backup file for MySQL version and charset
            $this->analyzeBackupFile($dbFilePath, $analysis);
            
        } catch (\Exception $e) {
            $this->logger->warning("Could not fully analyze backup environment", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $analysis;
    }

    /**
     * Diagnose SQL file for potential syntax issues
     *
     * @param string $dbFilePath
     */
    private function diagnoseSqlFile(string $dbFilePath): void
    {
        $this->logger->info("Diagnosing SQL file for potential issues", [
            'file' => basename($dbFilePath)
        ]);
        
        try {
            $isGzipped = pathinfo($dbFilePath, PATHINFO_EXTENSION) === 'gz';
            
            if ($isGzipped) {
                $handle = gzopen($dbFilePath, 'r');
                $readFunction = 'gzgets';
            } else {
                $handle = fopen($dbFilePath, 'r');
                $readFunction = 'fgets';
            }
            
            if (!$handle) {
                $this->logger->warning("Could not open SQL file for diagnosis");
                return;
            }
            
            $lineNumber = 0;
            $issues = [];
            $sampleLines = [];
            $maxLinesToCheck = 100; // Check first 100 lines for issues
            
            while (($line = $readFunction($handle)) !== false && $lineNumber < $maxLinesToCheck) {
                $lineNumber++;
                $trimmedLine = trim($line);
                
                // Skip empty lines and comments
                if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '/*') === 0) {
                    continue;
                }
                
                // Collect sample of non-comment lines
                if (count($sampleLines) < 10) {
                    $sampleLines[] = [
                        'line' => $lineNumber,
                        'content' => substr($trimmedLine, 0, 100) . (strlen($trimmedLine) > 100 ? '...' : '')
                    ];
                }
                
                // Check for common issues
                if (preg_match('/LIMIT\s+\d+\s*;?\s*$/i', $trimmedLine)) {
                    $issues[] = [
                        'type' => 'potential_limit_issue',
                        'line' => $lineNumber,
                        'content' => substr($trimmedLine, 0, 200)
                    ];
                }
                
                // Check for syntax issues
                if (preg_match('/[^\w\s`\'\"(),=<>!-]LIMIT/i', $trimmedLine)) {
                    $issues[] = [
                        'type' => 'malformed_limit',
                        'line' => $lineNumber,
                        'content' => substr($trimmedLine, 0, 200)
                    ];
                }
                
                // Check for unusual characters before keywords
                if (preg_match('/[^\s]+(LIMIT|SELECT|FROM|WHERE|ORDER)\s/i', $trimmedLine)) {
                    $issues[] = [
                        'type' => 'possible_syntax_issue',
                        'line' => $lineNumber,
                        'content' => substr($trimmedLine, 0, 200)
                    ];
                }
            }
            
            if ($isGzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            
            // Log diagnosis results
            $this->logger->info("SQL file diagnosis completed", [
                'lines_checked' => $lineNumber,
                'issues_found' => count($issues),
                'file_size' => filesize($dbFilePath)
            ]);
            
            if (!empty($sampleLines)) {
                $this->logger->debug("SQL file sample lines", [
                    'sample_lines' => $sampleLines
                ]);
            }
            
            if (!empty($issues)) {
                $this->logger->warning("Potential SQL syntax issues detected", [
                    'issues' => $issues
                ]);
                
                // Log each issue separately for better visibility
                foreach ($issues as $issue) {
                    $this->logger->warning("SQL Issue detected", $issue);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Could not diagnose SQL file", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get current environment information
     *
     * @return array
     */
    private function getCurrentEnvironment(): array
    {
        return [
            'prefix' => _DB_PREFIX_,
            'domain' => $this->getCurrentDomain(),
            'mysql_version' => $this->getMysqlVersion(),
            'charset' => 'utf8',
            'collation' => 'utf8_general_ci'
        ];
    }

    /**
     * Check if migration is required
     *
     * @param array $backupAnalysis
     * @param array $currentEnvironment
     * @return bool
     */
    private function requiresMigration(array $backupAnalysis, array $currentEnvironment): bool
    {
        return $backupAnalysis['prefix'] !== $currentEnvironment['prefix'] ||
               $backupAnalysis['domain'] !== $currentEnvironment['domain'];
    }

    /**
     * Execute transactional database restore with migration
     *
     * @param string $dbFilePath
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function executeTransactionalDatabaseRestore(string $dbFilePath, array $migrationConfig): void
    {
        $this->logger->info("Starting transactional database restore");
        
        // Start database transaction
        $this->db = \Db::getInstance();
        
        try {
            $this->db->execute("START TRANSACTION");
            $this->logger->debug("Database transaction started successfully");
        } catch (\Exception $e) {
            $this->logger->error("Failed to start database transaction", [
                'error' => $e->getMessage(),
                'sql_state' => $e->getCode()
            ]);
            throw new \Exception("Cannot start database transaction: " . $e->getMessage());
        }
        
        try {
            // Step 1: Clean destination database if required
            if ($migrationConfig['clean_destination']) {
                $this->logger->info("Cleaning destination database");
                $this->cleanDestinationDatabase($migrationConfig['target_prefix']);
            }
            
            // Step 2: Restore database with prefix adaptation if needed
            if ($migrationConfig['prefix_changed']) {
                $this->logger->info("Restoring database with prefix adaptation");
                $this->restoreWithPrefixAdaptation($dbFilePath, $migrationConfig);
            } else {
                $this->logger->info("Restoring database directly");
                $this->restoreDirectly($dbFilePath);
            }
            
            // Step 3: Migrate URLs and domains if needed
            if ($migrationConfig['domain_changed']) {
                $this->logger->info("Migrating URLs and domains");
                $this->migrateUrlsAndDomains($migrationConfig);
            }
            
            // Step 4: Update environment-specific configurations
            $this->logger->info("Updating environment-specific configurations");
            $this->updateEnvironmentConfigurations($migrationConfig);
            
            // Commit transaction
            $this->db->execute("COMMIT");
            $this->logger->info("Transactional database restore completed successfully");
            
        } catch (\Exception $e) {
            $this->logger->error("Database restore failed, rolling back transaction", [
                'error' => $e->getMessage(),
                'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            // Rollback transaction
            try {
                $this->db->execute("ROLLBACK");
                $this->logger->info("Transaction rolled back successfully");
            } catch (\Exception $rollbackError) {
                $this->logger->error("Failed to rollback transaction", [
                    'rollback_error' => $rollbackError->getMessage()
                ]);
            }
            
            throw new \Exception("Database restore failed: " . $e->getMessage());
        }
    }

    /**
     * Execute secure file restore
     *
     * @param string $filesFilePath
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function executeSecureFileRestore(string $filesFilePath, array $migrationConfig): void
    {
        $this->logger->info("Starting secure file restore");
        
        try {
            // Use enhanced file restoration if available
            if (class_exists('PrestaShop\Module\PsCopia\Services\SecureFileRestoreService')) {
                $secureFileRestore = new \PrestaShop\Module\PsCopia\Services\SecureFileRestoreService(
                    $this->backupContainer,
                    $this->logger,
                    $this->validationService
                );
                
                $result = $secureFileRestore->restoreFilesSecurely($filesFilePath, [
                    'scan_for_malware' => true,
                    'validate_php_syntax' => true,
                    'backup_critical_files' => true,
                    'preserve_permissions' => true
                ]);
                
                $this->logger->info("Secure file restore completed", $result);
            } else {
                // Fallback to standard file restoration
                $this->restoreFilesFromPath($filesFilePath);
            }
            
        } catch (\Exception $e) {
            throw new \Exception("File restore failed: " . $e->getMessage());
        }
    }

    /**
     * Create safety backup before restoration
     *
     * @return string|null
     */
    private function createSafetyBackup(): ?string
    {
        try {
            $this->logger->info("Creating safety backup before restoration");
            
            $timestamp = date('Y-m-d_H-i-s');
            $safetyBackupName = 'safety_backup_' . $timestamp;
            
            // Create quick backup using existing service
            if (class_exists('PrestaShop\Module\PsCopia\Services\BackupService')) {
                $backupService = new \PrestaShop\Module\PsCopia\Services\BackupService(
                    $this->backupContainer,
                    $this->logger,
                    $this->validationService
                );
                
                $result = $backupService->createBackup('complete', $safetyBackupName);
                
                if (isset($result['complete']['backup_name'])) {
                    $this->logger->info("Safety backup created successfully", [
                        'backup_name' => $result['complete']['backup_name']
                    ]);
                    return $result['complete']['backup_name'];
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Could not create safety backup", [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Analyze backup file for MySQL version and charset
     *
     * @param string $dbFilePath
     * @param array &$analysis
     */
    private function analyzeBackupFile(string $dbFilePath, array &$analysis): void
    {
        try {
            $isGzipped = pathinfo($dbFilePath, PATHINFO_EXTENSION) === 'gz';
            
            if ($isGzipped) {
                $handle = gzopen($dbFilePath, 'r');
                $readFunction = 'gzgets';
            } else {
                $handle = fopen($dbFilePath, 'r');
                $readFunction = 'fgets';
            }
            
            if (!$handle) {
                return;
            }
            
            $linesRead = 0;
            while (($line = $readFunction($handle)) !== false && $linesRead < 50) {
                // Look for MySQL version
                if (preg_match('/-- MySQL dump \d+\.\d+.*Distrib (\d+\.\d+\.\d+)/', $line, $matches)) {
                    $analysis['mysql_version'] = $matches[1];
                }
                
                // Look for charset and collation
                if (preg_match('/DEFAULT CHARSET=(\w+)/', $line, $matches)) {
                    $analysis['charset'] = $matches[1];
                }
                
                if (preg_match('/COLLATE=(\w+)/', $line, $matches)) {
                    $analysis['collation'] = $matches[1];
                }
                
                $linesRead++;
            }
            
            if ($isGzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Could not analyze backup file", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get current domain
     *
     * @return string
     */
    private function getCurrentDomain(): string
    {
        // Try multiple methods to get current domain
        $domain = '';
        
        // Method 1: From server variables
        if (!empty($_SERVER['HTTP_HOST'])) {
            $domain = $_SERVER['HTTP_HOST'];
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $domain = $_SERVER['SERVER_NAME'];
        }
        
        // Method 2: From PrestaShop configuration
        if (empty($domain) && class_exists('Configuration')) {
            try {
                $domain = \Configuration::get('PS_SHOP_DOMAIN');
            } catch (\Exception $e) {
                $this->logger->debug("Could not get domain from Configuration", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Method 3: From database if available
        if (empty($domain)) {
            try {
                $db = \Db::getInstance();
                
                // First check if the table exists
                $tableExists = $db->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . "shop_url'");
                if (!empty($tableExists)) {
                    $sql = "SELECT `domain` FROM `" . _DB_PREFIX_ . "shop_url` WHERE `domain` != '' LIMIT 1";
                    $this->logger->debug("Executing domain query", ['sql' => $sql]);
                    
                    $result = $db->getRow($sql);
                    if ($result && !empty($result['domain'])) {
                        $domain = $result['domain'];
                        $this->logger->debug("Domain found in database", ['domain' => $domain]);
                    }
                } else {
                    $this->logger->debug("shop_url table does not exist yet");
                }
            } catch (\Exception $e) {
                $this->logger->warning("Could not get domain from database", [
                    'error' => $e->getMessage(),
                    'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown'
                ]);
            }
        }
        
        // Fallback
        if (empty($domain)) {
            $domain = 'localhost';
            $this->logger->debug("Using fallback domain", ['domain' => $domain]);
        }
        
        // Clean domain (remove port if present)
        if (strpos($domain, ':') !== false) {
            $domain = explode(':', $domain)[0];
        }
        
        return $domain;
    }

    /**
     * Get MySQL version
     *
     * @return string
     */
    private function getMysqlVersion(): string
    {
        try {
            $db = \Db::getInstance();
            $sql = "SELECT VERSION() as version";
            $this->logger->debug("Getting MySQL version", ['sql' => $sql]);
            
            $result = $db->getRow($sql);
            $version = $result['version'] ?? '';
            
            $this->logger->debug("MySQL version retrieved", ['version' => $version]);
            return $version;
        } catch (\Exception $e) {
            $this->logger->warning("Could not get MySQL version", [
                'error' => $e->getMessage(),
                'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown'
            ]);
            return '';
        }
    }

    /**
     * Clean destination database
     *
     * @param string $prefix
     * @throws \Exception
     */
    private function cleanDestinationDatabase(string $prefix): void
    {
        $this->logger->info("Cleaning destination database", ['prefix' => $prefix]);
        
        try {
            $db = \Db::getInstance();
            
            // Get all tables with the specified prefix
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
                
                $this->logger->info("Cleaned destination database", [
                    'tables_dropped' => count($tables)
                ]);
            }
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to clean destination database: " . $e->getMessage());
        }
    }

    /**
     * Restore with prefix adaptation
     *
     * @param string $dbFilePath
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function restoreWithPrefixAdaptation(string $dbFilePath, array $migrationConfig): void
    {
        $this->logger->info("Restoring database with prefix adaptation", [
            'source_prefix' => $migrationConfig['source_prefix'],
            'target_prefix' => $migrationConfig['target_prefix']
        ]);
        
        // Create adapted backup file
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_adapted_' . time() . '.sql';
        
        try {
            $this->createAdaptedBackupFile($dbFilePath, $tempFile, $migrationConfig);
            $this->restoreDirectly($tempFile);
            
            // Clean up temporary file
            @unlink($tempFile);
            
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw $e;
        }
    }

    /**
     * Restore directly without adaptation
     *
     * @param string $dbFilePath
     * @throws \Exception
     */
    private function restoreDirectly(string $dbFilePath): void
    {
        $this->logger->info("Restoring database directly");
        
        $credentials = $this->getCurrentDbCredentials();
        $isGzipped = pathinfo($dbFilePath, PATHINFO_EXTENSION) === 'gz';
        
        $this->logger->info("Database restoration details", [
            'file_path' => $dbFilePath,
            'is_gzipped' => $isGzipped,
            'file_exists' => file_exists($dbFilePath),
            'file_size' => file_exists($dbFilePath) ? filesize($dbFilePath) : 0,
            'host' => $credentials['host'],
            'user' => $credentials['user'],
            'database' => $credentials['name']
        ]);
        
        // Test MySQL client availability and basic connectivity
        $this->testMysqlClient($credentials);
        
        if ($isGzipped) {
            $command = sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($dbFilePath),
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
                escapeshellarg($dbFilePath)
            );
        }

        // Log the command (without password for security)
        $safeCommand = str_replace($credentials['password'], '***', $command);
        $this->logger->info("Executing MySQL restore command", [
            'command' => $safeCommand
        ]);

        $output = [];
        $returnVar = 0;
        secureSysCommand($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $errorOutput = implode("\n", $output);
            
            $this->logger->error("MySQL restore command failed", [
                'return_code' => $returnVar,
                'command' => $safeCommand,
                'output' => $errorOutput
            ]);
            
            // Extract specific SQL error information
            $sqlError = $this->extractSqlErrorFromOutput($errorOutput);
            if ($sqlError) {
                $this->logger->error("SQL Error details", $sqlError);
                
                // Try to get more context about the specific line that failed
                $this->analyzeFailedSqlLine($dbFilePath, $sqlError['line'] ?? null);
                
                throw new \Exception("Database restoration failed with SQL error: " . $sqlError['message'] . 
                                   " (Line: " . ($sqlError['line'] ?? 'unknown') . ")");
            }
            
            throw new \Exception("Database restoration failed (code: {$returnVar}): " . $errorOutput);
        }

        $this->logger->info("Database restored successfully");
    }

    /**
     * Test MySQL client connectivity
     *
     * @param array $credentials
     * @throws \Exception
     */
    private function testMysqlClient(array $credentials): void
    {
        $this->logger->info("Testing MySQL client connectivity");
        
        // Test if mysql command is available
        $testCommand = 'mysql --version';
        $output = [];
        $returnVar = 0;
        secureSysCommand($testCommand . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            throw new \Exception("MySQL client is not available: " . implode("\n", $output));
        }
        
        $mysqlVersion = implode("\n", $output);
        $this->logger->info("MySQL client available", ['version' => $mysqlVersion]);
        
        // Test basic connectivity
        $connectTestCommand = sprintf(
            'mysql --host=%s --user=%s --password=%s %s -e "SELECT 1"',
            escapeshellarg($credentials['host']),
            escapeshellarg($credentials['user']),
            escapeshellarg($credentials['password']),
            escapeshellarg($credentials['name'])
        );
        
        $output = [];
        $returnVar = 0;
        secureSysCommand($connectTestCommand . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            $safeCommand = str_replace($credentials['password'], '***', $connectTestCommand);
            $this->logger->error("MySQL connectivity test failed", [
                'command' => $safeCommand,
                'output' => implode("\n", $output)
            ]);
            throw new \Exception("Cannot connect to MySQL database: " . implode("\n", $output));
        }
        
        $this->logger->info("MySQL connectivity test passed");
    }

    /**
     * Analyze the specific line that caused SQL failure
     *
     * @param string $dbFilePath
     * @param int|null $failedLine
     */
    private function analyzeFailedSqlLine(string $dbFilePath, ?int $failedLine): void
    {
        if (!$failedLine) {
            $this->logger->warning("No line number provided for failed SQL analysis");
            return;
        }
        
        $this->logger->info("Analyzing failed SQL line", ['line_number' => $failedLine]);
        
        try {
            $isGzipped = pathinfo($dbFilePath, PATHINFO_EXTENSION) === 'gz';
            
            if ($isGzipped) {
                $handle = gzopen($dbFilePath, 'r');
                $readFunction = 'gzgets';
            } else {
                $handle = fopen($dbFilePath, 'r');
                $readFunction = 'fgets';
            }
            
            if (!$handle) {
                $this->logger->warning("Could not open SQL file for line analysis");
                return;
            }
            
            $currentLine = 0;
            $contextLines = [];
            $targetLine = null;
            $contextRange = 5; // Show 5 lines before and after the failed line
            
            while (($line = $readFunction($handle)) !== false) {
                $currentLine++;
                
                // Collect context around the failed line
                if ($currentLine >= ($failedLine - $contextRange) && $currentLine <= ($failedLine + $contextRange)) {
                    $contextLines[] = [
                        'line_number' => $currentLine,
                        'content' => rtrim($line),
                        'is_failed_line' => $currentLine == $failedLine
                    ];
                }
                
                if ($currentLine == $failedLine) {
                    $targetLine = rtrim($line);
                }
                
                // Stop reading if we've passed our context range
                if ($currentLine > ($failedLine + $contextRange)) {
                    break;
                }
            }
            
            if ($isGzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            
            if ($targetLine) {
                $this->logger->error("Failed SQL line content", [
                    'line_number' => $failedLine,
                    'content' => $targetLine,
                    'length' => strlen($targetLine)
                ]);
                
                // Analyze the specific line for issues
                $this->analyzeSqlLineIssues($targetLine, $failedLine);
            }
            
            if (!empty($contextLines)) {
                $this->logger->info("SQL context around failed line", [
                    'context' => $contextLines
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning("Could not analyze failed SQL line", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Analyze specific issues in a SQL line
     *
     * @param string $sqlLine
     * @param int $lineNumber
     */
    private function analyzeSqlLineIssues(string $sqlLine, int $lineNumber): void
    {
        $issues = [];
        
        // Check for common issues
        if (preg_match('/LIMIT\s+\d+\s*;?\s*$/', $sqlLine)) {
            $issues[] = "Line ends with LIMIT clause";
        }
        
        if (preg_match('/[^\x20-\x7E]/', $sqlLine)) {
            $issues[] = "Contains non-ASCII characters";
        }
        
        if (preg_match('/\s+$/', $sqlLine)) {
            $issues[] = "Has trailing whitespace";
        }
        
        if (strlen($sqlLine) > 1000) {
            $issues[] = "Very long line (" . strlen($sqlLine) . " characters)";
        }
        
        // Check for unmatched quotes
        $singleQuotes = substr_count($sqlLine, "'") - substr_count($sqlLine, "\\'");
        $doubleQuotes = substr_count($sqlLine, '"') - substr_count($sqlLine, '\\"');
        
        if ($singleQuotes % 2 !== 0) {
            $issues[] = "Unmatched single quotes";
        }
        
        if ($doubleQuotes % 2 !== 0) {
            $issues[] = "Unmatched double quotes";
        }
        
        // Check for unmatched parentheses
        $openParens = substr_count($sqlLine, '(');
        $closeParens = substr_count($sqlLine, ')');
        
        if ($openParens !== $closeParens) {
            $issues[] = "Unmatched parentheses (open: $openParens, close: $closeParens)";
        }
        
        if (!empty($issues)) {
            $this->logger->warning("SQL line issues detected", [
                'line_number' => $lineNumber,
                'issues' => $issues
            ]);
        } else {
            $this->logger->info("No obvious issues detected in SQL line", [
                'line_number' => $lineNumber
            ]);
        }
    }

    /**
     * Create adapted backup file with prefix changes
     *
     * @param string $sourceFile
     * @param string $targetFile
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function createAdaptedBackupFile(string $sourceFile, string $targetFile, array $migrationConfig): void
    {
        $this->logger->info("Creating adapted backup file");
        
        $sourcePrefix = $migrationConfig['source_prefix'];
        $targetPrefix = $migrationConfig['target_prefix'];
        
        $isGzipped = pathinfo($sourceFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            $sourceHandle = gzopen($sourceFile, 'r');
            $readFunction = 'gzgets';
        } else {
            $sourceHandle = fopen($sourceFile, 'r');
            $readFunction = 'fgets';
        }
        
        $targetHandle = fopen($targetFile, 'w');
        
        if (!$sourceHandle || !$targetHandle) {
            throw new \Exception("Could not open files for prefix adaptation");
        }
        
        try {
            while (($line = $readFunction($sourceHandle)) !== false) {
                // Replace table prefixes in various SQL statements
                $adaptedLine = str_replace('`' . $sourcePrefix, '`' . $targetPrefix, $line);
                $adaptedLine = str_replace('INTO ' . $sourcePrefix, 'INTO ' . $targetPrefix, $adaptedLine);
                $adaptedLine = str_replace('TABLE ' . $sourcePrefix, 'TABLE ' . $targetPrefix, $adaptedLine);
                $adaptedLine = str_replace('FROM ' . $sourcePrefix, 'FROM ' . $targetPrefix, $adaptedLine);
                $adaptedLine = str_replace('UPDATE ' . $sourcePrefix, 'UPDATE ' . $targetPrefix, $adaptedLine);
                $adaptedLine = str_replace('DELETE FROM ' . $sourcePrefix, 'DELETE FROM ' . $targetPrefix, $adaptedLine);
                
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
     * Migrate URLs and domains
     *
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function migrateUrlsAndDomains(array $migrationConfig): void
    {
        $this->logger->info("Migrating URLs and domains");
        
        try {
            // Use enhanced URL migrator if available
            if (class_exists('PrestaShop\Module\PsCopia\Migration\UrlMigrator')) {
                $urlMigrator = new \PrestaShop\Module\PsCopia\Migration\UrlMigrator(
                    $this->backupContainer,
                    $this->logger
                );
                
                $urlMigrator->migrateAllUrls($migrationConfig);
            } else {
                // Fallback to basic URL migration
                $this->basicUrlMigration($migrationConfig);
            }
            
        } catch (\Exception $e) {
            throw new \Exception("URL migration failed: " . $e->getMessage());
        }
    }

    /**
     * Basic URL migration fallback
     *
     * @param array $migrationConfig
     */
    private function basicUrlMigration(array $migrationConfig): void
    {
        $db = \Db::getInstance();
        $targetDomain = $migrationConfig['target_domain'];
        $prefix = $migrationConfig['target_prefix'];
        
        // Update shop_url table
        $sql = "UPDATE `{$prefix}shop_url` SET 
                `domain` = '" . pSQL($targetDomain) . "',
                `domain_ssl` = '" . pSQL($targetDomain) . "'";
        $db->execute($sql);
        
        // Update configuration
        $domainConfigs = [
            'PS_SHOP_DOMAIN' => $targetDomain,
            'PS_SHOP_DOMAIN_SSL' => $targetDomain
        ];
        
        foreach ($domainConfigs as $configKey => $configValue) {
            $sql = "UPDATE `{$prefix}configuration` 
                    SET `value` = '" . pSQL($configValue) . "' 
                    WHERE `name` = '" . pSQL($configKey) . "'";
            $db->execute($sql);
        }
    }

    /**
     * Update environment-specific configurations
     *
     * @param array $migrationConfig
     */
    private function updateEnvironmentConfigurations(array $migrationConfig): void
    {
        $this->logger->info("Updating environment-specific configurations");
        
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        
        // Disable problematic modules that might cause issues
        $problematicModules = [
            'ps_facetedsearch',
            'ps_searchbar',
            'ps_categoryproducts',
            'blockreassurance'
        ];
        
        foreach ($problematicModules as $moduleName) {
            try {
                $sql = "UPDATE `{$prefix}module` SET `active` = 0 WHERE `name` = '" . pSQL($moduleName) . "'";
                $db->execute($sql);
            } catch (\Exception $e) {
                // Ignore errors for modules that don't exist
            }
        }
        
        // Clear cache-related configurations
        $cacheConfigs = [
            'PS_SMARTY_CACHE' => '0',
            'PS_SMARTY_FORCE_COMPILE' => '1',
            'PS_CSS_THEME_CACHE' => '0',
            'PS_JS_THEME_CACHE' => '0'
        ];
        
        foreach ($cacheConfigs as $configKey => $configValue) {
            try {
                $sql = "UPDATE `{$prefix}configuration` 
                        SET `value` = '" . pSQL($configValue) . "' 
                        WHERE `name` = '" . pSQL($configKey) . "'";
                $db->execute($sql);
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
    }

    /**
     * Perform post-restoration verification
     *
     * @param array $migrationConfig
     * @throws \Exception
     */
    private function performPostRestorationVerification(array $migrationConfig): void
    {
        $this->logger->info("Performing post-restoration verification");
        
        $db = \Db::getInstance();
        $prefix = $migrationConfig['target_prefix'];
        
        // Verify essential tables exist
        $essentialTables = [
            'shop_url',
            'configuration',
            'module',
            'customer',
            'product'
        ];
        
        foreach ($essentialTables as $table) {
            try {
                $sql = "SHOW TABLES LIKE '" . $prefix . $table . "'";
                $this->logger->debug("Checking table existence", [
                    'table' => $prefix . $table,
                    'sql' => $sql
                ]);
                
                $result = $db->executeS($sql);
                
                if (empty($result)) {
                    throw new \Exception("Essential table missing after restoration: " . $prefix . $table);
                }
                
                $this->logger->debug("Table verified", ['table' => $prefix . $table]);
            } catch (\Exception $e) {
                $this->logger->error("Table verification failed", [
                    'table' => $prefix . $table,
                    'error' => $e->getMessage(),
                    'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown'
                ]);
                throw new \Exception("Table verification failed for {$prefix}{$table}: " . $e->getMessage());
            }
        }
        
        // Verify domain configuration
        try {
            $sql = "SELECT `domain` FROM `{$prefix}shop_url` LIMIT 1";
            $this->logger->debug("Verifying domain configuration", [
                'sql' => $sql,
                'expected_domain' => $migrationConfig['target_domain']
            ]);
            
            $result = $db->getRow($sql);
            
            if (empty($result) || $result['domain'] !== $migrationConfig['target_domain']) {
                $this->logger->warning("Domain verification failed", [
                    'expected' => $migrationConfig['target_domain'],
                    'actual' => $result['domain'] ?? 'null'
                ]);
            } else {
                $this->logger->info("Domain verification successful", [
                    'domain' => $result['domain']
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error("Domain verification query failed", [
                'error' => $e->getMessage(),
                'sql_state' => method_exists($e, 'getCode') ? $e->getCode() : 'unknown',
                'sql' => $sql
            ]);
            // Don't throw here, just log the warning
            $this->logger->warning("Skipping domain verification due to error");
        }
        
        $this->logger->info("Post-restoration verification completed successfully");
    }

    /**
     * Restore from safety backup
     *
     * @param string $safetyBackupName
     * @throws \Exception
     */
    private function restoreFromSafetyBackup(string $safetyBackupName): void
    {
        $this->logger->info("Restoring from safety backup", [
            'backup_name' => $safetyBackupName
        ]);
        
        // Use simple restoration for safety backup (no migration needed)
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$safetyBackupName])) {
            throw new \Exception("Safety backup not found: " . $safetyBackupName);
        }
        
        $backupInfo = $metadata[$safetyBackupName];
        
        // Restore database
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        $this->restoreDirectly($dbFilePath);
        
        // Restore files
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];
        $this->restoreFilesFromPath($filesFilePath);
        
        $this->logger->info("Safety backup restored successfully");
    }

    /**
     * Main restore method that handles different backup types
     * This method is called by the AJAX controller
     *
     * @param string $backupName
     * @param string $backupType
     * @return string
     * @throws \Exception
     */
    public function restoreBackup(string $backupName, string $backupType): string
    {
        $this->logger->info("Starting restore process", [
            'backup_name' => $backupName,
            'backup_type' => $backupType
        ]);

        try {
            switch ($backupType) {
                case 'complete':
                    $this->restoreCompleteBackup($backupName);
                    return "Backup completo restaurado exitosamente: " . $backupName;
                    
                case 'database':
                    $this->restoreDatabase($backupName);
                    return "Base de datos restaurada exitosamente desde: " . $backupName;
                    
                case 'files':
                    $this->restoreFiles($backupName);
                    return "Archivos restaurados exitosamente desde: " . $backupName;
                    
                default:
                    throw new \Exception("Tipo de backup no soportado: " . $backupType);
            }
        } catch (\Exception $e) {
            $this->logger->error("Restore failed", [
                'backup_name' => $backupName,
                'backup_type' => $backupType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Restore database only from complete backup
     *
     * @param string $backupName
     * @return string
     * @throws \Exception
     */
    public function restoreDatabaseOnly(string $backupName): string
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        if (!isset($backupInfo['database_file'])) {
            throw new \Exception("Database file not found in backup: " . $backupName);
        }

        $this->logger->info("Restoring database only from complete backup: " . $backupName);
        
        // Restore database with migration
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
            $migrationConfig = [
                'migrate_urls' => true,
                'preserve_db_config' => false
            ];
            
            $dbMigrator = new DatabaseMigrator($this->backupContainer, $this->logger);
            $dbMigrator->migrateDatabase($dbFilePath, $migrationConfig);
        } else {
            $this->restoreDatabase($backupInfo['database_file']);
        }

        return "Base de datos restaurada exitosamente desde backup completo: " . $backupName;
    }

    /**
     * Restore files only from complete backup
     *
     * @param string $backupName
     * @return string
     * @throws \Exception
     */
    public function restoreFilesOnly(string $backupName): string
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        if (!isset($backupInfo['files_file'])) {
            throw new \Exception("Files file not found in backup: " . $backupName);
        }

        $this->logger->info("Restoring files only from complete backup: " . $backupName);
        
        // Restore files with migration
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
            try {
                $migrationConfig = [
                    'migrate_admin_dir' => false
                ];
                
                $filesMigrator = new FilesMigrator($this->backupContainer, $this->logger);
                $filesMigrator->migrateFiles($filesFilePath, $migrationConfig);
            } catch (\Exception $e) {
                $this->logger->error("Files migration failed, falling back to simple restoration: " . $e->getMessage());
                $this->restoreFiles($backupInfo['files_file']);
            }
        } else {
            $this->restoreFiles($backupInfo['files_file']);
        }

        return "Archivos restaurados exitosamente desde backup completo: " . $backupName;
    }

    /**
     * Smart restore with full environment adaptation
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function smartRestoreBackup(string $backupName): void
    {
        $metadata = $this->getBackupMetadata();
        
        if (!isset($metadata[$backupName])) {
            throw new \Exception("Backup metadata not found for: " . $backupName);
        }

        $backupInfo = $metadata[$backupName];
        
        // Validate that backup files exist
        $this->backupContainer->validateBackupFile($backupInfo['database_file']);
        $this->backupContainer->validateBackupFile($backupInfo['files_file']);

        // Configuration for smart restoration
        $migrationConfig = [
            'clean_destination' => true,
            'migrate_urls' => true,
            'preserve_db_config' => true,
            'disable_problematic_modules' => true,
            'auto_detect_environment' => true
        ];

        $this->logger->info("Smart restoration configuration", $migrationConfig);

        // Get full paths to backup files
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $dbFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['database_file'];
        $filesFilePath = $backupDir . DIRECTORY_SEPARATOR . $backupInfo['files_file'];

        // Apply enhanced database migration
        $this->logger->info("Applying enhanced database migration");
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\DatabaseMigrator')) {
            $dbMigrator = new DatabaseMigrator($this->backupContainer, $this->logger);
            $dbMigrator->migrateWithFullAdaptation($dbFilePath, $migrationConfig);
        } else {
            throw new \Exception('Enhanced DatabaseMigrator not available');
        }

        // Restore files with smart handling
        $this->logger->info("Restoring files with smart handling");
        
        if (class_exists('PrestaShop\Module\PsCopia\Migration\FilesMigrator')) {
            try {
                $filesMigrator = new FilesMigrator($this->backupContainer, $this->logger);
                $filesMigrator->migrateFiles($filesFilePath, $migrationConfig);
            } catch (\Exception $e) {
                $this->logger->error("Smart files migration failed, falling back to simple restoration: " . $e->getMessage());
                $this->restoreFiles($backupInfo['files_file']);
            }
        } else {
            $this->logger->warning("FilesMigrator not available, using standard file restoration");
            $this->restoreFiles($backupInfo['files_file']);
        }

        // Additional cleanup for problematic modules
        $this->cleanupProblematicModuleFiles();

        $this->logger->info("Smart backup restoration completed successfully");
    }

    /**
     * Restore database from backup
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreDatabase(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Database backup file does not exist: " . $backupName);
        }

        // Check if mysql command is available
        if (!isMysqlCliAvailable()) {
            throw new \Exception("MySQL command line client is not available");
        }

        $command = $this->buildMysqlRestoreCommand($backupFile);

        // Execute restoration
        $output = [];
        $returnVar = 0;
        secureSysCommand($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception("Database restoration failed. Code: " . $returnVar . ". Output: " . implode("\n", $output));
        }

        $this->logger->info("Database restored successfully from " . $backupName);
    }

    /**
     * Restore files from backup
     *
     * @param string $backupName
     * @throws \Exception
     */
    public function restoreFiles(string $backupName): void
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        
        if (!file_exists($backupFile)) {
            throw new \Exception("Files backup does not exist: " . $backupName);
        }

        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP PHP extension is not installed');
        }

        $zip = new ZipArchive();
        $result = $zip->open($backupFile);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }

        // Extract to temporary directory first
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Detect admin directories for cleanup
            $backupAdminDir = $this->detectAdminDirectoryInBackup($tempDir);
            $currentAdminDir = $this->getCurrentAdminDirectory();

            // Copy files from temp to real location
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);

            // Clean up obsolete admin directories after successful restoration
            $this->cleanupObsoleteAdminDirectories($backupAdminDir, $currentAdminDir);
            
        } finally {
            // Clean up temp directory
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully from " . $backupName);
    }

    /**
     * Restore files from a specific file path
     *
     * @param string $filesBackupPath
     * @throws \Exception
     */
    public function restoreFilesFromPath(string $filesBackupPath): void
    {
        if (!file_exists($filesBackupPath)) {
            throw new \Exception("Files backup does not exist: " . $filesBackupPath);
        }

        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP PHP extension is not installed');
        }

        $this->logger->info("Restoring files from path: " . $filesBackupPath);

        $zip = new ZipArchive();
        $result = $zip->open($filesBackupPath);
        
        if ($result !== TRUE) {
            throw new \Exception('Cannot open ZIP file: ' . ResponseHelper::getZipError($result));
        }

        // Extract to temporary directory first
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('Cannot create temporary directory: ' . $tempDir);
        }

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Failed to extract ZIP file');
            }
            
            $zip->close();

            // Detect admin directories for cleanup
            $backupAdminDir = $this->detectAdminDirectoryInBackup($tempDir);
            $currentAdminDir = $this->getCurrentAdminDirectory();

            // STEP 1: Preserve current database credentials before restoration
            $this->logger->info("Preserving current database credentials");
            $currentDbCredentials = $this->getCurrentDbCredentials();
            
            // STEP 2: Copy files from temp to real location (this will overwrite parameters.php)
            $this->copyDirectoryRecursively($tempDir, _PS_ROOT_DIR_);
            
            // STEP 3: Restore the correct database credentials after file restoration
            $this->logger->info("Restoring correct database credentials after file restoration");
            $this->restoreDbCredentials($currentDbCredentials);

            // Clean up obsolete admin directories after successful restoration
            $this->cleanupObsoleteAdminDirectories($backupAdminDir, $currentAdminDir);
            
        } finally {
            // Clean up temp directory
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->logger->info("Files restored successfully from " . basename($filesBackupPath));
    }

    /**
     * Get backup metadata
     *
     * @return array
     */
    public function getBackupMetadata(): array
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        
        if (!file_exists($metadataFile)) {
            return [];
        }
        
        $content = file_get_contents($metadataFile);
        return json_decode($content, true) ?: [];
    }

    /**
     * Get complete backups from metadata
     *
     * @return array
     */
    public function getCompleteBackups(): array
    {
        $metadata = $this->getBackupMetadata();
        $completeBackups = [];
        
        if (empty($metadata)) {
            return $this->getBackupsFromFiles();
        }
        
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
                        
                        $completeBackups[] = [
                            'name' => $backupName,
                            'date' => $backupData['created_at'],
                            'size' => $totalSize,
                            'size_formatted' => ResponseHelper::formatBytes($totalSize),
                            'type' => 'complete',
                            'database_file' => $backupData['database_file'],
                            'files_file' => $backupData['files_file']
                        ];
                    }
                } elseif (isset($backupData['zip_file']) && $backupData['type'] === 'server_import') {
                    // Server imported backup
                    $zipFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupData['zip_file'];
                    
                    if (file_exists($zipFile)) {
                        $fileSize = filesize($zipFile);
                        
                        $completeBackups[] = [
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
                    
                    $completeBackups[] = [
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
        
        return $completeBackups;
    }

    /**
     * Delete backup
     *
     * @param string $backupName
     * @return bool
     */
    public function deleteBackup(string $backupName): bool
    {
        $metadata = $this->getBackupMetadata();
        $deleted = false;
        
        if (empty($metadata)) {
            // No metadata, try deleting as individual file
            $this->backupContainer->deleteBackup($backupName);
            $this->logger->info("Individual backup deleted: " . $backupName);
            return true;
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
            
            if ($deleted) {
                $this->saveUpdatedMetadata($metadata);
            }
        } else {
            // Original format: search by key
            if (isset($metadata[$backupName])) {
                $backupInfo = $metadata[$backupName];
                
                // Delete database file
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['database_file']);
                }
                
                // Delete files backup
                if (file_exists($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'])) {
                    $this->backupContainer->deleteBackup($backupInfo['files_file']);
                }
                
                // Remove from metadata
                unset($metadata[$backupName]);
                $this->saveCompleteBackupMetadata($metadata);
                $deleted = true;
            }
        }
        
        if ($deleted) {
            $this->logger->info("Complete backup deleted: " . $backupName);
            return true;
        } else {
            // Individual backup file
            $this->backupContainer->deleteBackup($backupName);
            $this->logger->info("Individual backup deleted: " . $backupName);
            return true;
        }
    }

    /**
     * Build MySQL restore command
     *
     * @param string $backupFile
     * @return string
     */
    private function buildMysqlRestoreCommand(string $backupFile): string
    {
        $credentials = $this->getCurrentDbCredentials();
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            return sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name'])
            );
        } else {
            return sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name']),
                escapeshellarg($backupFile)
            );
        }
    }

    /**
     * Extract SQL error information from command output
     *
     * @param string $output
     * @return array|null
     */
    private function extractSqlErrorFromOutput(string $output): ?array
    {
        $patterns = [
            // MySQL/MariaDB error with line number
            '/ERROR \d+ \(\d+\) at line (\d+): (.+)/i',
            // SQLSTATE error with line number
            '/SQLSTATE\[(\w+)\]: (.+?) at line (\d+)/i',
            // MariaDB syntax error with line number
            '/You have an error in your SQL syntax.*?at line (\d+)/i',
            // Generic error with line number
            '/error.*?at line (\d+)/i',
            // MySQL error without line number
            '/ERROR \d+ \(\d+\): (.+)/i',
            // SQLSTATE without line number
            '/SQLSTATE\[(\w+)\]: (.+)/i',
        ];
        
        $this->logger->debug("Analyzing error output", [
            'output_length' => strlen($output),
            'output_sample' => substr($output, 0, 500)
        ]);
        
        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                $this->logger->debug("Error pattern matched", [
                    'pattern_index' => $index,
                    'pattern' => $pattern,
                    'matches' => $matches
                ]);
                
                $errorInfo = [
                    'pattern_matched' => $index,
                    'full_output' => $output
                ];
                
                // Extract line number if available
                if (isset($matches[1]) && is_numeric($matches[1])) {
                    $errorInfo['line'] = (int)$matches[1];
                    $errorInfo['message'] = $matches[2] ?? $matches[0];
                } elseif (isset($matches[3]) && is_numeric($matches[3])) {
                    $errorInfo['line'] = (int)$matches[3];
                    $errorInfo['message'] = $matches[2] ?? $matches[1] ?? $matches[0];
                } else {
                    $errorInfo['line'] = null;
                    $errorInfo['message'] = $matches[1] ?? $matches[0];
                }
                
                return $errorInfo;
            }
        }
        
        // If no specific pattern found, check for general error keywords
        $errorKeywords = ['error', 'sqlstate', 'syntax', 'failed', 'denied'];
        $lowerOutput = strtolower($output);
        
        foreach ($errorKeywords as $keyword) {
            if (strpos($lowerOutput, $keyword) !== false) {
                $this->logger->debug("Error keyword found", ['keyword' => $keyword]);
                
                return [
                    'line' => null,
                    'message' => $output,
                    'full_output' => $output,
                    'keyword_matched' => $keyword
                ];
            }
        }
        
        $this->logger->debug("No error patterns matched in output");
        return null;
    }


    /**
     * Detect admin directory in backup
     *
     * @param string $tempDir
     * @return string|null
     */
    private function detectAdminDirectoryInBackup(string $tempDir): ?string
    {
        $this->logger->info("Detecting admin directory in backup");

        $possibleAdminDirs = [];
        
        if (is_dir($tempDir)) {
            $items = scandir($tempDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $tempDir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    if ($this->validationService->isAdminDirectory($itemPath)) {
                        $possibleAdminDirs[] = $item;
                    }
                }
            }
        }

        if (empty($possibleAdminDirs)) {
            $this->logger->warning("No admin directory detected in backup");
            return null;
        }

        $backupAdminDir = $possibleAdminDirs[0];
        $this->logger->info("Detected admin directory in backup: " . $backupAdminDir);
        
        return $backupAdminDir;
    }

    /**
     * Get current admin directory name
     *
     * @return string|null
     */
    private function getCurrentAdminDirectory(): ?string
    {
        $currentAdminPath = $this->backupContainer->getProperty(BackupContainer::PS_ADMIN_PATH);
        $currentAdminDir = basename($currentAdminPath);
        
        $this->logger->info("Current admin directory: " . $currentAdminDir);
        
        return $currentAdminDir;
    }

    /**
     * Copy directory recursively
     *
     * @param string $source
     * @param string $destination
     * @throws \Exception
     */
    private function copyDirectoryRecursively(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
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
                    throw new \Exception('Failed to copy file: ' . $relativePath);
                }
            }
        }
    }

    /**
     * Remove directory recursively
     *
     * @param string $directory
     * @return bool
     */
    private function removeDirectoryRecursively(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($directory);
    }

    /**
     * Clean up problematic module files
     */
    private function cleanupProblematicModuleFiles(): void
    {
        $this->logger->info("Cleaning up problematic module files");
        
        $problematicModules = [
            'ps_mbo',
            'ps_eventbus',
            'ps_metrics',
            'ps_facebook'
        ];
        
        $modulesDir = _PS_ROOT_DIR_ . '/modules/';
        
        foreach ($problematicModules as $module) {
            $modulePath = $modulesDir . $module;
            $disabledPath = $modulesDir . $module . '.disabled';
            
            if (is_dir($modulePath)) {
                $vendorPath = $modulePath . '/vendor';
                $composerPath = $modulePath . '/composer.json';
                
                if (file_exists($composerPath) && !is_dir($vendorPath)) {
                    rename($modulePath, $disabledPath);
                    $this->logger->info("Disabled problematic module: " . $module);
                }
            }
        }
    }

    /**
     * Clean up obsolete admin directories
     *
     * @param string|null $backupAdminDir
     * @param string|null $currentAdminDir
     */
    private function cleanupObsoleteAdminDirectories(?string $backupAdminDir, ?string $currentAdminDir): void
    {
        if (!$backupAdminDir || !$currentAdminDir) {
            $this->logger->info("Skipping admin directory cleanup - unable to detect directories");
            return;
        }

        if ($backupAdminDir === $currentAdminDir) {
            $this->logger->info("Admin directories are the same, no cleanup needed", [
                'admin_directory' => $currentAdminDir
            ]);
            return;
        }

        $this->logger->info("Different admin directories detected", [
            'backup_admin' => $backupAdminDir,
            'current_admin' => $currentAdminDir
        ]);

        // Find all admin-like directories in the root
        $psRootDir = $this->backupContainer->getProperty(BackupContainer::PS_ROOT_PATH);
        $adminDirectories = $this->findAllAdminDirectories($psRootDir);

        $this->logger->info("Found admin directories in system", [
            'directories' => $adminDirectories
        ]);

        // Remove obsolete admin directories
        foreach ($adminDirectories as $adminDir) {
            if ($adminDir === $backupAdminDir) {
                $this->logger->info("Preserving backup admin directory: " . $adminDir);
                continue;
            }

            $adminPath = $psRootDir . DIRECTORY_SEPARATOR . $adminDir;
            if (is_dir($adminPath)) {
                $this->logger->info("Removing obsolete admin directory: " . $adminDir);
                
                try {
                    if ($this->removeDirectoryRecursively($adminPath)) {
                        $this->logger->info("Successfully removed obsolete admin directory: " . $adminDir);
                    } else {
                        $this->logger->error("Failed to remove obsolete admin directory: " . $adminDir);
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Error removing obsolete admin directory: " . $adminDir, [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Find all admin directories in the system
     *
     * @param string $psRootDir
     * @return array
     */
    private function findAllAdminDirectories(string $psRootDir): array
    {
        $adminDirectories = [];
        
        if (!is_dir($psRootDir)) {
            return $adminDirectories;
        }

        $items = scandir($psRootDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $psRootDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && $this->validationService->isAdminDirectory($itemPath)) {
                $adminDirectories[] = $item;
            }
        }

        return $adminDirectories;
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
            $completeBackups = [];
            
            foreach ($backups as $backup) {
                if ($backup['type'] === 'complete') {
                    $completeBackups[] = [
                        'name' => $backup['name'],
                        'date' => $backup['date'],
                        'size' => $backup['size'],
                        'size_formatted' => $backup['size_formatted'],
                        'type' => 'file_based'
                    ];
                }
            }
            
            return $completeBackups;
        } catch (\Exception $e) {
            $this->logger->warning("Could not get backups from files", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save updated metadata in array format
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

    /**
     * Save complete backup metadata
     *
     * @param array $metadata
     */
    private function saveCompleteBackupMetadata(array $metadata): void
    {
        $metadataFile = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Get current database credentials from the environment
     *
     * @return array
     */
    private function getCurrentDbCredentials(): array
    {
        $this->logger->info("Reading current database credentials");
        
        // Check if we're in DDEV environment
        if (getenv('DDEV_SITENAME') || getenv('DDEV_PROJECT') !== false) {
            $this->logger->info("Detected DDEV environment, using DDEV database credentials");
            return [
                'host' => 'db',
                'user' => 'db', 
                'password' => 'db',
                'name' => 'db',
                'prefix' => _DB_PREFIX_,
                'environment' => 'ddev'
            ];
        }
        
        // Try to read from current parameters.php if it exists
        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        if (file_exists($parametersFile)) {
            try {
                $parameters = include $parametersFile;
                if (isset($parameters['parameters'])) {
                    $this->logger->info("Reading credentials from current parameters.php");
                    return [
                        'host' => $parameters['parameters']['database_host'] ?? _DB_SERVER_,
                        'user' => $parameters['parameters']['database_user'] ?? _DB_USER_,
                        'password' => $parameters['parameters']['database_password'] ?? _DB_PASSWD_,
                        'name' => $parameters['parameters']['database_name'] ?? _DB_NAME_,
                        'prefix' => $parameters['parameters']['database_prefix'] ?? _DB_PREFIX_,
                        'environment' => 'prestashop'
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Could not read current parameters.php: " . $e->getMessage());
            }
        }
        
        // Fallback to PrestaShop constants
        $this->logger->info("Using PrestaShop constants as fallback");
        return [
            'host' => _DB_SERVER_,
            'user' => _DB_USER_,
            'password' => _DB_PASSWD_,
            'name' => _DB_NAME_,
            'prefix' => _DB_PREFIX_,
            'environment' => 'constants'
        ];
    }

    /**
     * Restore database credentials to parameters.php after file restoration
     *
     * @param array $credentials
     * @throws \Exception
     */
    private function restoreDbCredentials(array $credentials): void
    {
        $this->logger->info("Restoring database credentials", [
            'environment' => $credentials['environment'],
            'host' => $credentials['host'],
            'user' => $credentials['user'],
            'name' => $credentials['name'],
            'prefix' => $credentials['prefix']
        ]);

        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        
        if (!file_exists($parametersFile)) {
            $this->logger->error("parameters.php file not found after restoration: " . $parametersFile);
            throw new \Exception("parameters.php file not found after restoration");
        }
        
        // Read current content (from backup)
        $content = file_get_contents($parametersFile);
        if ($content === false) {
            throw new \Exception("Failed to read parameters.php file");
        }
        
        // Replace database credentials with current environment values
        $patterns = [
            "/'database_host'\s*=>\s*'[^']*'/" => "'database_host' => '" . $credentials['host'] . "'",
            "/'database_user'\s*=>\s*'[^']*'/" => "'database_user' => '" . $credentials['user'] . "'",
            "/'database_password'\s*=>\s*'[^']*'/" => "'database_password' => '" . $credentials['password'] . "'",
            "/'database_name'\s*=>\s*'[^']*'/" => "'database_name' => '" . $credentials['name'] . "'",
            "/'database_prefix'\s*=>\s*'[^']*'/" => "'database_prefix' => '" . $credentials['prefix'] . "'",
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $newContent = preg_replace($pattern, $replacement, $content);
            if ($newContent !== null) {
                $content = $newContent;
            } else {
                $this->logger->warning("Failed to replace pattern: " . $pattern);
            }
        }
        
        // Write updated content
        if (file_put_contents($parametersFile, $content) === false) {
            throw new \Exception("Failed to write updated parameters.php file");
        }
        
        $this->logger->info("Successfully restored database credentials to parameters.php", [
            'host' => $credentials['host'],
            'user' => $credentials['user'],
            'name' => $credentials['name'],
            'prefix' => $credentials['prefix']
        ]);
    }
} 