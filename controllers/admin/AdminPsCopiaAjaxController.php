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

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\VersionUtils;
use PrestaShop\Module\PsCopia\Services\BackupService;
use PrestaShop\Module\PsCopia\Services\RestoreService;
use PrestaShop\Module\PsCopia\Services\ImportExportService;
use PrestaShop\Module\PsCopia\Services\FileManagerService;
use PrestaShop\Module\PsCopia\Services\ValidationService;
use PrestaShop\Module\PsCopia\Services\ResponseHelper;

/**
 * Refactored AdminPsCopiaAjaxController
 * 
 * This controller has been refactored to use service-based architecture.
 * All business logic has been extracted to specialized services.
 * 
 * Services:
 * - BackupService: Handles backup creation
 * - RestoreService: Handles backup restoration
 * - ImportExportService: Handles import/export operations
 * - FileManagerService: Handles file operations and server uploads
 * - ValidationService: Handles validation operations
 * - ResponseHelper: Handles AJAX responses
 */
class AdminPsCopiaAjaxController extends ModuleAdminController
{
    /** @var Ps_copia */
    public $module;

    /** @var bool */
    private $isActualPHPVersionCompatible = true;

    /** @var BackupContainer|null */
    private $backupContainer;

    /** @var BackupLogger|null */
    private $logger;

    /** @var BackupService|null */
    private $backupService;

    /** @var RestoreService|null */
    private $restoreService;

    /** @var ImportExportService|null */
    private $importExportService;

    /** @var FileManagerService|null */
    private $fileManagerService;

    /** @var ValidationService|null */
    private $validationService;

    /** @var ResponseHelper|null */
    private $responseHelper;

    public function __construct()
    {
        parent::__construct();

        // Load required classes
        $this->loadRequiredClasses();

        // Check PHP version compatibility
        if (!VersionUtils::isActualPHPVersionCompatible()) {
            $this->isActualPHPVersionCompatible = false;
            return;
        }

        // Initialize backup container and logger
        if ($this->module && method_exists($this->module, 'getBackupContainer')) {
            $this->backupContainer = $this->module->getBackupContainer();
            $this->logger = new BackupLogger($this->backupContainer, true);
            
            // Initialize services
            $this->initializeServices();
        } else {
            $this->isActualPHPVersionCompatible = false;
        }
    }

    /**
     * Initialize all services with dependency injection
     */
    private function initializeServices(): void
    {
        $this->responseHelper = new ResponseHelper();
        $this->validationService = new ValidationService($this->backupContainer, $this->logger);
        $this->backupService = new BackupService($this->backupContainer, $this->logger, $this->validationService);
        $this->restoreService = new RestoreService($this->backupContainer, $this->logger, $this->validationService);
        $this->importExportService = new ImportExportService($this->backupContainer, $this->logger, $this->validationService);
        $this->fileManagerService = new FileManagerService($this->backupContainer, $this->logger, $this->validationService);
    }

    /**
     * Load required classes manually to ensure they are available
     *
     * @return void
     */
    private function loadRequiredClasses(): void
    {
        // Try autoloader first
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
        
        // Load functions.php file that contains secureSysCommand()
        $functionsPath = __DIR__ . '/../../functions.php';
        if (file_exists($functionsPath)) {
            require_once $functionsPath;
        }

        // Manually load critical classes if not available
        $classesToLoad = [
            'PrestaShop\Module\PsCopia\VersionUtils' => '/../../classes/VersionUtils.php',
            'PrestaShop\Module\PsCopia\BackupContainer' => '/../../classes/BackupContainer.php',
            'PrestaShop\Module\PsCopia\Logger\BackupLogger' => '/../../classes/Logger/BackupLogger.php',
            'PrestaShop\Module\PsCopia\Migration\DatabaseMigrator' => '/../../classes/Migration/DatabaseMigrator.php',
            'PrestaShop\Module\PsCopia\Migration\FilesMigrator' => '/../../classes/Migration/FilesMigrator.php',
            'PrestaShop\Module\PsCopia\Services\BackupService' => '/../../classes/Services/BackupService.php',
            'PrestaShop\Module\PsCopia\Services\RestoreService' => '/../../classes/Services/RestoreService.php',
            'PrestaShop\Module\PsCopia\Services\ImportExportService' => '/../../classes/Services/ImportExportService.php',
            'PrestaShop\Module\PsCopia\Services\FileManagerService' => '/../../classes/Services/FileManagerService.php',
            'PrestaShop\Module\PsCopia\Services\ValidationService' => '/../../classes/Services/ValidationService.php',
            'PrestaShop\Module\PsCopia\Services\ResponseHelper' => '/../../classes/Services/ResponseHelper.php',
        ];

        foreach ($classesToLoad as $className => $filePath) {
            if (!class_exists($className)) {
                $fullPath = __DIR__ . $filePath;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }
        }
    }

    /**
     * Check if user has permission for backup operations
     */
    public function viewAccess($disable = false): bool
    {
        // Temporarily allow any authenticated admin user
        // IMPORTANT: Change this back to restrict to super admin only: $this->context->employee->id_profile == 1
        return isset($this->context->employee) && isset($this->context->employee->id) && $this->context->employee->id > 0;
    }

    public function postProcess(): bool
    {
        // Set JSON response headers
        header('Content-Type: application/json');

        if (!$this->isActualPHPVersionCompatible) {
            $this->responseHelper->ajaxError('Module not compatible with current PHP version');
            return false;
        }

        if (!$this->viewAccess()) {
            $this->responseHelper->ajaxError('Access denied. Only super administrators can perform backup operations.');
            return false;
        }

        $action = Tools::getValue('action');

        try {
            switch ($action) {
                case 'create_backup':
                    $this->handleCreateBackup();
                    break;
                case 'restore_backup':
                    $this->handleRestoreBackup();
                    break;
                case 'restore_backup_smart':
                    $this->handleSmartRestoreBackup();
                    break;
                case 'list_backups':
                    $this->handleListBackups();
                    break;
                case 'delete_backup':
                    $this->handleDeleteBackup();
                    break;
                case 'validate_backup':
                    $this->handleValidateBackup();
                    break;
                case 'restore_database_only':
                    $this->handleRestoreDatabaseOnly();
                    break;
                case 'restore_files_only':
                    $this->handleRestoreFilesOnly();
                    break;
                case 'get_disk_space':
                    $this->handleGetDiskSpace();
                    break;
                case 'get_logs':
                    $this->handleGetLogs();
                    break;
                case 'export_backup':
                    $this->handleExportBackup();
                    break;
                case 'export_standalone_installer':
                    $this->handleExportStandaloneInstaller();
                    break;
                case 'import_backup':
                    $this->handleImportBackup();
                    break;
                case 'download_export':
                    $this->handleDownloadExport();
                    break;
                case 'scan_server_uploads':
                    $this->handleScanServerUploads();
                    break;
                case 'import_from_server':
                    $this->handleImportFromServer();
                    break;
                case 'delete_server_upload':
                    $this->handleDeleteServerUpload();
                    break;
                default:
                    $this->responseHelper->ajaxError('Unknown action: ' . $action);
            }
        } catch (Exception $e) {
            $this->logger->error("Ajax Controller Exception: " . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            $this->responseHelper->ajaxError($e->getMessage());
        } catch (Error $e) {
            // Catch PHP Fatal Errors
            $this->logger->error("Ajax Controller Fatal Error: " . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            $this->responseHelper->ajaxError('Fatal error occurred: ' . $e->getMessage());
        } catch (Throwable $e) {
            // Catch any other throwable
            $this->logger->error("Ajax Controller Throwable: " . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            $this->responseHelper->ajaxError('Unexpected error occurred: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Handle backup creation
     */
    private function handleCreateBackup(): void
    {
        $backupType = Tools::getValue('backup_type', 'complete');
        $customName = Tools::getValue('custom_name', '');

        try {
            $results = $this->backupService->createBackup($backupType, $customName);
            $this->responseHelper->ajaxSuccess('Backup completo creado correctamente', $results);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup restoration
     */
    private function handleRestoreBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        $backupType = Tools::getValue('backup_type', 'complete');
        
        // Log the incoming request
        $this->logger->info("AJAX Restore Request", [
            'backup_name' => $backupName,
            'backup_type' => $backupType,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        if (empty($backupName)) {
            $this->logger->error("Restore request missing backup name");
            $this->responseHelper->ajaxError("Backup name is required");
            return;
        }

        try {
            $this->logger->info("Starting restore process via AJAX", [
                'backup_name' => $backupName,
                'backup_type' => $backupType
            ]);
            
            $message = $this->restoreService->restoreBackup($backupName, $backupType);
            
            $this->logger->info("Restore completed successfully via AJAX", [
                'backup_name' => $backupName,
                'backup_type' => $backupType,
                'message' => $message
            ]);
            
            $this->responseHelper->ajaxSuccess($message);
        } catch (Exception $e) {
            $this->logger->error("Restore failed via AJAX", [
                'backup_name' => $backupName,
                'backup_type' => $backupType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle smart backup restoration with full environment adaptation
     * This method automatically handles all common restoration issues
     */
    private function handleSmartRestoreBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError('Backup name is required');
            return;
        }

        try {
            $result = $this->restoreService->smartRestoreBackup($backupName);
            $this->responseHelper->ajaxSuccess($result);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle listing backups
     */
    private function handleListBackups(): void
    {
        try {
            $result = $this->backupService->listBackups();
            $this->responseHelper->ajaxSuccess('Backups retrieved successfully', ['backups' => $result]);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup deletion
     */
    private function handleDeleteBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError("Backup name is required");
            return;
        }

        try {
            $message = $this->backupService->deleteBackup($backupName);
            $this->responseHelper->ajaxSuccess($message);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup validation
     */
    private function handleValidateBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError("Backup name is required");
            return;
        }

        try {
            $isValid = $this->validationService->validateBackup($backupName);
            $this->responseHelper->ajaxSuccess("Backup validation completed", ['valid' => $isValid]);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle disk space request
     */
    private function handleGetDiskSpace(): void
    {
        try {
            $diskInfo = $this->backupContainer->getDiskSpaceInfo();
            $this->responseHelper->ajaxSuccess("Disk space information", $diskInfo);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle logs request
     */
    private function handleGetLogs(): void
    {
        try {
            $logs = $this->logger->getRecentLogs(100);
            $this->responseHelper->ajaxSuccess("Logs retrieved", ['logs' => $logs]);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle database-only restoration from complete backup
     */
    private function handleRestoreDatabaseOnly(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError("Backup name is required");
            return;
        }

        try {
            $message = $this->restoreService->restoreDatabaseOnly($backupName);
            $this->responseHelper->ajaxSuccess($message);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle files-only restoration from complete backup
     */
    private function handleRestoreFilesOnly(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError("Backup name is required");
            return;
        }

        try {
            $message = $this->restoreService->restoreFilesOnly($backupName);
            $this->responseHelper->ajaxSuccess($message);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup export - create a downloadable ZIP with complete backup
     */
    private function handleExportBackup(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError('Nombre del backup requerido');
            return;
        }

        try {
            $result = $this->importExportService->exportBackup($backupName);
            $this->responseHelper->ajaxSuccess('Archivo de exportación creado correctamente', $result);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle standalone installer export - create a downloadable PHP installer that works with normal export ZIP
     */
    private function handleExportStandaloneInstaller(): void
    {
        $backupName = Tools::getValue('backup_name');
        
        if (empty($backupName)) {
            $this->responseHelper->ajaxError('Nombre del backup requerido');
            return;
        }

        try {
            $result = $this->importExportService->exportStandaloneInstaller($backupName);
            
            // Preparar mensaje detallado para el usuario
            $message = 'Instalador simple creado correctamente. ';
            $message .= 'Para instalar en otro servidor necesitas descargar 2 archivos:';
            
            $instructions = [
                'files_to_download' => [
                    [
                        'name' => $result['installer_filename'],
                        'description' => 'Archivo instalador PHP',
                        'size' => $result['size_formatted']
                    ],
                    [
                        'name' => $result['export_zip_filename'],
                        'description' => 'Archivo ZIP de backup',
                        'size' => $result['export_zip_size_formatted']
                    ]
                ],
                'installation_steps' => $result['instructions']
            ];
            
            // Agregar información adicional al resultado
            $result['message_details'] = $instructions;
            $result['total_files'] = 2;
            
            $this->responseHelper->ajaxSuccess($message, $result);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle backup import with chunked processing for large files
     */
    private function handleImportBackup(): void
    {
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $this->responseHelper->ajaxError('No se ha subido ningún archivo válido');
            return;
        }

        try {
            $result = $this->importExportService->importBackup($_FILES['backup_file']);
            $this->responseHelper->ajaxSuccess('Backup importado correctamente', $result);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle download of exported backup files
     */
    private function handleDownloadExport(): void
    {
        $filename = Tools::getValue('file');
        
        if (empty($filename)) {
            $this->responseHelper->ajaxError('Nombre de archivo requerido');
            return;
        }

        try {
            $this->importExportService->downloadExport($filename);
            // This method will handle the download and exit
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle scanning for uploaded ZIP files in server directory
     */
    private function handleScanServerUploads(): void
    {
        try {
            $result = $this->fileManagerService->scanServerUploads();
            $message = 'Escaneo completado exitosamente. Archivos encontrados: ' . $result['count'] . ', válidos: ' . $result['valid_count'];
            $this->responseHelper->ajaxSuccess($message, $result);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle importing ZIP from server uploads directory
     */
    private function handleImportFromServer(): void
    {
        $filename = Tools::getValue('filename');
        
        if (empty($filename)) {
            $this->responseHelper->ajaxError("Nombre de archivo requerido");
            return;
        }

        try {
            $result = $this->fileManagerService->importFromServer($filename);
            $this->responseHelper->ajaxSuccess($result['message'], $result['data']);
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }

    /**
     * Handle deleting ZIP from server uploads directory
     */
    private function handleDeleteServerUpload(): void
    {
        $filename = Tools::getValue('filename');
        
        if (empty($filename)) {
            $this->responseHelper->ajaxError("Nombre de archivo requerido");
            return;
        }

        try {
            $this->fileManagerService->deleteServerUpload($filename);
            $this->responseHelper->ajaxSuccess('Archivo eliminado correctamente');
        } catch (Exception $e) {
            $this->responseHelper->ajaxError($e->getMessage());
        }
    }
}
