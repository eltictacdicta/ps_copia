<?php
/**
 * Transaction Manager for PS_Copia
 * Handles transactional operations for backup restoration without interruptions
 */

namespace PrestaShop\Module\PsCopia\Services;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use Exception;

class TransactionManager
{
    /** @var BackupContainer */
    private $backupContainer;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var \Db */
    private $db;
    
    /** @var array */
    private $transactionState = [];
    
    /** @var array */
    private $rollbackActions = [];
    
    /** @var string */
    private $transactionId;
    
    /** @var bool */
    private $transactionActive = false;
    
    /** @var string */
    private $lockFilePath;
    
    /** @var resource */
    private $lockFileHandle;

    public function __construct(BackupContainer $backupContainer, BackupLogger $logger)
    {
        $this->backupContainer = $backupContainer;
        $this->logger = $logger;
        $this->db = \Db::getInstance();
        $this->transactionId = uniqid('ps_copia_transaction_', true);
        
        $this->initializeLockFile();
    }

    /**
     * Begin a restoration transaction
     *
     * @param string $operation
     * @param array $context
     * @return string Transaction ID
     * @throws Exception
     */
    public function beginTransaction(string $operation, array $context = []): string
    {
        $this->logger->info("Beginning transaction", [
            'transaction_id' => $this->transactionId,
            'operation' => $operation,
            'context' => $context
        ]);

        try {
            // Acquire exclusive lock
            $this->acquireExclusiveLock();
            
            // Initialize transaction state
            $this->transactionState = [
                'id' => $this->transactionId,
                'operation' => $operation,
                'context' => $context,
                'start_time' => microtime(true),
                'status' => 'active',
                'checkpoint' => null,
                'rollback_actions' => []
            ];
            
            // Save transaction state
            $this->saveTransactionState();
            
            // Begin database transaction
            $this->beginDatabaseTransaction();
            
            $this->transactionActive = true;
            
            $this->logger->info("Transaction started successfully", [
                'transaction_id' => $this->transactionId
            ]);
            
            return $this->transactionId;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to begin transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
            
            $this->releaseExclusiveLock();
            throw $e;
        }
    }

    /**
     * Create a checkpoint in the transaction
     *
     * @param string $checkpointName
     * @param array $state
     * @throws Exception
     */
    public function createCheckpoint(string $checkpointName, array $state = []): void
    {
        if (!$this->transactionActive) {
            throw new Exception("No active transaction for checkpoint creation");
        }

        $this->logger->info("Creating transaction checkpoint", [
            'transaction_id' => $this->transactionId,
            'checkpoint' => $checkpointName
        ]);

        $checkpoint = [
            'name' => $checkpointName,
            'timestamp' => microtime(true),
            'state' => $state,
            'database_backup' => null
        ];

        // Create database backup for rollback
        try {
            $checkpoint['database_backup'] = $this->createDatabaseBackup($checkpointName);
        } catch (Exception $e) {
            $this->logger->warning("Failed to create database backup for checkpoint", [
                'checkpoint' => $checkpointName,
                'error' => $e->getMessage()
            ]);
        }

        $this->transactionState['checkpoint'] = $checkpoint;
        $this->saveTransactionState();

        $this->logger->info("Checkpoint created successfully", [
            'transaction_id' => $this->transactionId,
            'checkpoint' => $checkpointName
        ]);
    }

    /**
     * Add a rollback action
     *
     * @param string $actionType
     * @param array $actionData
     */
    public function addRollbackAction(string $actionType, array $actionData): void
    {
        $rollbackAction = [
            'type' => $actionType,
            'data' => $actionData,
            'timestamp' => microtime(true)
        ];

        $this->rollbackActions[] = $rollbackAction;
        $this->transactionState['rollback_actions'] = $this->rollbackActions;
        
        $this->saveTransactionState();

        $this->logger->debug("Rollback action added", [
            'transaction_id' => $this->transactionId,
            'action_type' => $actionType
        ]);
    }

    /**
     * Commit the transaction
     *
     * @throws Exception
     */
    public function commitTransaction(): void
    {
        if (!$this->transactionActive) {
            throw new Exception("No active transaction to commit");
        }

        $this->logger->info("Committing transaction", [
            'transaction_id' => $this->transactionId
        ]);

        try {
            // Commit database transaction
            $this->commitDatabaseTransaction();
            
            // Update transaction state
            $this->transactionState['status'] = 'committed';
            $this->transactionState['end_time'] = microtime(true);
            $this->transactionState['duration'] = $this->transactionState['end_time'] - $this->transactionState['start_time'];
            
            $this->saveTransactionState();
            
            // Clean up rollback data
            $this->cleanupRollbackData();
            
            $this->transactionActive = false;
            
            $this->logger->info("Transaction committed successfully", [
                'transaction_id' => $this->transactionId,
                'duration' => round($this->transactionState['duration'], 2) . 's'
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to commit transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->releaseExclusiveLock();
        }
    }

    /**
     * Rollback the transaction
     *
     * @param string $reason
     * @throws Exception
     */
    public function rollbackTransaction(string $reason = ''): void
    {
        if (!$this->transactionActive) {
            $this->logger->warning("Attempted rollback on inactive transaction", [
                'transaction_id' => $this->transactionId
            ]);
            return;
        }

        $this->logger->info("Rolling back transaction", [
            'transaction_id' => $this->transactionId,
            'reason' => $reason
        ]);

        try {
            // Rollback database transaction
            $this->rollbackDatabaseTransaction();
            
            // Execute rollback actions in reverse order
            $this->executeRollbackActions();
            
            // Restore from checkpoint if available
            if (isset($this->transactionState['checkpoint'])) {
                $this->restoreFromCheckpoint();
            }
            
            // Update transaction state
            $this->transactionState['status'] = 'rolled_back';
            $this->transactionState['rollback_reason'] = $reason;
            $this->transactionState['end_time'] = microtime(true);
            $this->transactionState['duration'] = $this->transactionState['end_time'] - $this->transactionState['start_time'];
            
            $this->saveTransactionState();
            
            $this->transactionActive = false;
            
            $this->logger->info("Transaction rolled back successfully", [
                'transaction_id' => $this->transactionId,
                'reason' => $reason
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to rollback transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->releaseExclusiveLock();
        }
    }

    /**
     * Execute a callable within the transaction context
     *
     * @param callable $operation
     * @param string $operationName
     * @param array $context
     * @return mixed
     * @throws Exception
     */
    public function executeInTransaction(callable $operation, string $operationName, array $context = [])
    {
        $transactionId = $this->beginTransaction($operationName, $context);
        
        try {
            $result = $operation($this);
            $this->commitTransaction();
            return $result;
            
        } catch (Exception $e) {
            $this->rollbackTransaction($e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if transaction is active
     *
     * @return bool
     */
    public function isTransactionActive(): bool
    {
        return $this->transactionActive;
    }

    /**
     * Get transaction ID
     *
     * @return string
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * Get transaction state
     *
     * @return array
     */
    public function getTransactionState(): array
    {
        return $this->transactionState;
    }

    /**
     * Initialize lock file for exclusive access
     */
    private function initializeLockFile(): void
    {
        $tempDir = sys_get_temp_dir();
        $this->lockFilePath = $tempDir . DIRECTORY_SEPARATOR . 'ps_copia_transaction.lock';
    }

    /**
     * Acquire exclusive lock
     *
     * @throws Exception
     */
    private function acquireExclusiveLock(): void
    {
        $this->lockFileHandle = fopen($this->lockFilePath, 'w');
        
        if (!$this->lockFileHandle) {
            throw new Exception("Cannot create lock file: " . $this->lockFilePath);
        }
        
        if (!flock($this->lockFileHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockFileHandle);
            throw new Exception("Another restoration process is already running");
        }
        
        fwrite($this->lockFileHandle, $this->transactionId . "\n" . date('Y-m-d H:i:s'));
        
        $this->logger->info("Exclusive lock acquired", [
            'transaction_id' => $this->transactionId,
            'lock_file' => $this->lockFilePath
        ]);
    }

    /**
     * Release exclusive lock
     */
    private function releaseExclusiveLock(): void
    {
        if ($this->lockFileHandle) {
            flock($this->lockFileHandle, LOCK_UN);
            fclose($this->lockFileHandle);
            @unlink($this->lockFilePath);
            
            $this->logger->info("Exclusive lock released", [
                'transaction_id' => $this->transactionId
            ]);
        }
    }

    /**
     * Save transaction state to file
     */
    private function saveTransactionState(): void
    {
        $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_transaction_' . $this->transactionId . '.json';
        
        $stateJson = json_encode($this->transactionState, JSON_PRETTY_PRINT);
        
        if (file_put_contents($statePath, $stateJson) === false) {
            $this->logger->warning("Failed to save transaction state", [
                'transaction_id' => $this->transactionId,
                'state_path' => $statePath
            ]);
        }
    }

    /**
     * Begin database transaction
     *
     * @throws Exception
     */
    private function beginDatabaseTransaction(): void
    {
        try {
            // Disable autocommit
            $this->db->execute("SET autocommit = 0");
            
            // Start transaction
            $this->db->execute("START TRANSACTION");
            
            $this->logger->debug("Database transaction started", [
                'transaction_id' => $this->transactionId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to start database transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Commit database transaction
     *
     * @throws Exception
     */
    private function commitDatabaseTransaction(): void
    {
        try {
            $this->db->execute("COMMIT");
            $this->db->execute("SET autocommit = 1");
            
            $this->logger->debug("Database transaction committed", [
                'transaction_id' => $this->transactionId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to commit database transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Rollback database transaction
     */
    private function rollbackDatabaseTransaction(): void
    {
        try {
            $this->db->execute("ROLLBACK");
            $this->db->execute("SET autocommit = 1");
            
            $this->logger->debug("Database transaction rolled back", [
                'transaction_id' => $this->transactionId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to rollback database transaction", [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create database backup for checkpoint
     *
     * @param string $checkpointName
     * @return string|null
     */
    private function createDatabaseBackup(string $checkpointName): ?string
    {
        try {
            $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 
                         'ps_copia_checkpoint_' . $this->transactionId . '_' . $checkpointName . '.sql';
            
            // Get database credentials
            $credentials = [
                'host' => _DB_SERVER_,
                'user' => _DB_USER_,
                'password' => _DB_PASSWD_,
                'name' => _DB_NAME_
            ];
            
            // Create backup using mysqldump
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s %s > %s',
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name']),
                escapeshellarg($backupPath)
            );
            
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0 && file_exists($backupPath)) {
                $this->logger->debug("Database backup created for checkpoint", [
                    'transaction_id' => $this->transactionId,
                    'checkpoint' => $checkpointName,
                    'backup_path' => $backupPath
                ]);
                return $backupPath;
            }
            
            return null;
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to create database backup for checkpoint", [
                'transaction_id' => $this->transactionId,
                'checkpoint' => $checkpointName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Execute rollback actions in reverse order
     */
    private function executeRollbackActions(): void
    {
        $actions = array_reverse($this->rollbackActions);
        
        foreach ($actions as $action) {
            try {
                $this->executeRollbackAction($action);
            } catch (Exception $e) {
                $this->logger->warning("Failed to execute rollback action", [
                    'transaction_id' => $this->transactionId,
                    'action_type' => $action['type'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Execute a single rollback action
     *
     * @param array $action
     * @throws Exception
     */
    private function executeRollbackAction(array $action): void
    {
        $this->logger->debug("Executing rollback action", [
            'transaction_id' => $this->transactionId,
            'action_type' => $action['type']
        ]);

        switch ($action['type']) {
            case 'restore_file':
                $this->rollbackRestoreFile($action['data']);
                break;
                
            case 'restore_directory':
                $this->rollbackRestoreDirectory($action['data']);
                break;
                
            case 'database_query':
                $this->rollbackDatabaseQuery($action['data']);
                break;
                
            case 'configuration_change':
                $this->rollbackConfigurationChange($action['data']);
                break;
                
            default:
                $this->logger->warning("Unknown rollback action type", [
                    'transaction_id' => $this->transactionId,
                    'action_type' => $action['type']
                ]);
        }
    }

    /**
     * Rollback file restoration
     *
     * @param array $data
     */
    private function rollbackRestoreFile(array $data): void
    {
        $filePath = $data['file_path'];
        $backupPath = $data['backup_path'] ?? null;
        
        if ($backupPath && file_exists($backupPath)) {
            // Restore original file
            copy($backupPath, $filePath);
            @unlink($backupPath);
        } else {
            // Remove restored file
            @unlink($filePath);
        }
    }

    /**
     * Rollback directory restoration
     *
     * @param array $data
     */
    private function rollbackRestoreDirectory(array $data): void
    {
        $directoryPath = $data['directory_path'];
        
        if (is_dir($directoryPath)) {
            $this->removeDirectoryRecursively($directoryPath);
        }
    }

    /**
     * Rollback database query
     *
     * @param array $data
     */
    private function rollbackDatabaseQuery(array $data): void
    {
        $rollbackQuery = $data['rollback_query'] ?? null;
        
        if ($rollbackQuery) {
            $this->db->execute($rollbackQuery);
        }
    }

    /**
     * Rollback configuration change
     *
     * @param array $data
     */
    private function rollbackConfigurationChange(array $data): void
    {
        $configKey = $data['config_key'];
        $originalValue = $data['original_value'];
        $prefix = $data['prefix'] ?? _DB_PREFIX_;
        
        $sql = "UPDATE `{$prefix}configuration` SET `value` = '" . pSQL($originalValue) . "' 
                WHERE `name` = '" . pSQL($configKey) . "'";
        
        $this->db->execute($sql);
    }

    /**
     * Restore from checkpoint
     */
    private function restoreFromCheckpoint(): void
    {
        $checkpoint = $this->transactionState['checkpoint'];
        
        if (!$checkpoint) {
            return;
        }
        
        $this->logger->info("Restoring from checkpoint", [
            'transaction_id' => $this->transactionId,
            'checkpoint' => $checkpoint['name']
        ]);
        
        // Restore database from checkpoint backup
        if ($checkpoint['database_backup'] && file_exists($checkpoint['database_backup'])) {
            $this->restoreDatabaseFromBackup($checkpoint['database_backup']);
        }
    }

    /**
     * Restore database from backup
     *
     * @param string $backupPath
     */
    private function restoreDatabaseFromBackup(string $backupPath): void
    {
        try {
            $credentials = [
                'host' => _DB_SERVER_,
                'user' => _DB_USER_,
                'password' => _DB_PASSWD_,
                'name' => _DB_NAME_
            ];
            
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($credentials['host']),
                escapeshellarg($credentials['user']),
                escapeshellarg($credentials['password']),
                escapeshellarg($credentials['name']),
                escapeshellarg($backupPath)
            );
            
            exec($command . ' 2>&1', $output, $returnVar);
            
            if ($returnVar === 0) {
                $this->logger->debug("Database restored from checkpoint backup", [
                    'transaction_id' => $this->transactionId,
                    'backup_path' => $backupPath
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to restore database from checkpoint backup", [
                'transaction_id' => $this->transactionId,
                'backup_path' => $backupPath,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up rollback data
     */
    private function cleanupRollbackData(): void
    {
        // Remove checkpoint backup if exists
        if (isset($this->transactionState['checkpoint']['database_backup'])) {
            $backupPath = $this->transactionState['checkpoint']['database_backup'];
            if ($backupPath && file_exists($backupPath)) {
                @unlink($backupPath);
            }
        }
        
        // Remove transaction state file
        $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_transaction_' . $this->transactionId . '.json';
        if (file_exists($statePath)) {
            @unlink($statePath);
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

    /**
     * Destructor - ensures cleanup
     */
    public function __destruct()
    {
        if ($this->transactionActive) {
            $this->logger->warning("Transaction was not properly closed, forcing rollback", [
                'transaction_id' => $this->transactionId
            ]);
            
            try {
                $this->rollbackTransaction('Transaction not properly closed');
            } catch (Exception $e) {
                $this->logger->error("Failed to rollback transaction in destructor", [
                    'transaction_id' => $this->transactionId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->releaseExclusiveLock();
    }
} 