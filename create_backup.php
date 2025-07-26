<?php
require_once __DIR__ . '/config/config.inc.php';

// Ensure we're in admin context
define('_PS_ADMIN_DIR_', __DIR__ . '/admin-dev');

try {
    // Load the ps_copia module
    $module = Module::getInstanceByName('ps_copia');
    
    if (!$module) {
        throw new Exception('Module ps_copia not found');
    }
    
    echo "Module ps_copia loaded successfully\n";
    
    // Try to access the BackupService
    $backupServicePath = $module->getLocalPath() . 'classes/Services/BackupService.php';
    
    if (file_exists($backupServicePath)) {
        require_once $backupServicePath;
        
        // Create backup service instance
        $context = Context::getContext();
        $backupService = new \PsCopia\Services\BackupService($context);
        
        echo "Creating backup...\n";
        
        // Create a complete backup
        $result = $backupService->createBackup('complete', 'corrected_installer_test');
        
        echo "Backup created successfully!\n";
        echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
    } else {
        echo "BackupService not found, trying alternative approach...\n";
        
        // Alternative: Use AJAX controller directly
        $_GET['ajax'] = '1';
        $_GET['action'] = 'create_backup';
        $_POST['backup_type'] = 'complete';
        $_POST['custom_name'] = 'corrected_installer_test';
        
        require_once $module->getLocalPath() . 'controllers/admin/AdminPsCopiaAjaxController.php';
        
        $controller = new AdminPsCopiaAjaxController();
        $controller->init();
        $controller->postProcess();
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
} 