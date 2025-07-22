<?php
/**
 * Script de diagnóstico para la exportación del instalador standalone
 * Este script ayuda a identificar problemas en el proceso de exportación
 */

// Definir directorio base
define('_PS_ROOT_DIR_', dirname(__FILE__) . '/../../../..');
define('_PS_MODULE_DIR_', _PS_ROOT_DIR_ . '/modules');

// Incluir archivos necesarios
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/functions.php';

class ExportDiagnostic
{
    private $module;
    private $container;
    
    public function __construct()
    {
        // Simular módulo
        if (class_exists('Module')) {
            $this->module = Module::getInstanceByName('ps_copia');
        }
        
        // Inicializar container
        if (class_exists('PrestaShop\Module\PsCopia\BackupContainer')) {
            $this->container = new PrestaShop\Module\PsCopia\BackupContainer(
                _PS_ROOT_DIR_, 
                _PS_ADMIN_DIR_, 
                'ps_copia'
            );
            $this->container->initDirectories();
        }
    }
    
    public function runDiagnostics()
    {
        echo "=== DIAGNÓSTICO DE EXPORTACIÓN PS_COPIA ===\n\n";
        
        $this->checkEnvironment();
        $this->checkFiles();
        $this->checkBackups();
        $this->testTemplateGeneration();
        
        echo "\n=== DIAGNÓSTICO COMPLETADO ===\n";
    }
    
    private function checkEnvironment()
    {
        echo "1. VERIFICACIÓN DEL ENTORNO:\n";
        echo "   - PHP Version: " . phpversion() . "\n";
        echo "   - Memory Limit: " . ini_get('memory_limit') . "\n";
        echo "   - Max Execution Time: " . ini_get('max_execution_time') . "\n";
        echo "   - ZIP Extension: " . (extension_loaded('zip') ? 'OK' : 'FALTA') . "\n";
        echo "   - PS_ROOT_DIR: " . _PS_ROOT_DIR_ . "\n";
        echo "   - MODULE_DIR: " . _PS_MODULE_DIR_ . "\n";
        echo "\n";
    }
    
    private function checkFiles()
    {
        echo "2. VERIFICACIÓN DE ARCHIVOS:\n";
        
        $files = [
            'ps_copia.php' => dirname(__FILE__) . '/ps_copia.php',
            'Template Installer' => dirname(__FILE__) . '/installer_templates/ps_copias_installer_template.php',
            'ImportExportService' => dirname(__FILE__) . '/classes/Services/ImportExportService.php',
            'BackupContainer' => dirname(__FILE__) . '/classes/BackupContainer.php'
        ];
        
        foreach ($files as $name => $path) {
            $exists = file_exists($path);
            $size = $exists ? filesize($path) : 0;
            echo "   - $name: " . ($exists ? "OK ({$size} bytes)" : "FALTA") . "\n";
        }
        echo "\n";
    }
    
    private function checkBackups()
    {
        echo "3. VERIFICACIÓN DE BACKUPS:\n";
        
        if (!$this->container) {
            echo "   - ERROR: No se pudo inicializar BackupContainer\n\n";
            return;
        }
        
        $backupPath = $this->container->getProperty(\PrestaShop\Module\PsCopia\BackupContainer::BACKUP_PATH);
        echo "   - Directorio backups: $backupPath\n";
        echo "   - Existe: " . (is_dir($backupPath) ? 'SÍ' : 'NO') . "\n";
        echo "   - Escribible: " . (is_writable($backupPath) ? 'SÍ' : 'NO') . "\n";
        
        if (is_dir($backupPath)) {
            $files = scandir($backupPath);
            $backupFiles = array_filter($files, function($file) {
                return !in_array($file, ['.', '..']);
            });
            echo "   - Archivos en directorio: " . count($backupFiles) . "\n";
            
            if (count($backupFiles) > 0) {
                echo "   - Primeros 5 archivos:\n";
                foreach (array_slice($backupFiles, 0, 5) as $file) {
                    $fullPath = $backupPath . DIRECTORY_SEPARATOR . $file;
                    $size = is_file($fullPath) ? filesize($fullPath) : 0;
                    echo "     * $file (" . $this->formatBytes($size) . ")\n";
                }
            }
        }
        echo "\n";
    }
    
    private function testTemplateGeneration()
    {
        echo "4. PRUEBA DE GENERACIÓN DE TEMPLATE:\n";
        
        try {
            if (!class_exists('PrestaShop\Module\PsCopia\Services\ImportExportService')) {
                echo "   - ERROR: ImportExportService no encontrado\n\n";
                return;
            }
            
            $templatePath = dirname(__FILE__) . '/installer_templates/ps_copias_installer_template.php';
            
            if (!file_exists($templatePath)) {
                echo "   - ERROR: Template no encontrado en $templatePath\n\n";
                return;
            }
            
            $templateContent = file_get_contents($templatePath);
            echo "   - Template cargado: " . strlen($templateContent) . " bytes\n";
            
            // Test config generation
            $testConfig = [
                'package_name' => 'test_package_123',
                'created_date' => date('Y-m-d H:i:s'),
                'prestashop_version' => '8.0.0',
                'source_url' => 'https://test.com/',
                'backup_info' => ['test' => 'data']
            ];
            
            $configJson = json_encode($testConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            echo "   - Config JSON generado: " . strlen($configJson) . " bytes\n";
            
            // Test replacements
            $replacements = [
                '{EMBEDDED_CONFIG}' => $configJson,
                '{INSTALLER_VERSION}' => '2.0',
                '{CREATION_DATE}' => date('Y-m-d H:i:s')
            ];
            
            $processedContent = str_replace(array_keys($replacements), array_values($replacements), $templateContent);
            echo "   - Template procesado: " . strlen($processedContent) . " bytes\n";
            
            // Verify replacements
            $replacementsMade = 0;
            foreach (array_keys($replacements) as $placeholder) {
                if (strpos($processedContent, $placeholder) === false) {
                    $replacementsMade++;
                } else {
                    echo "   - ADVERTENCIA: Placeholder no reemplazado: $placeholder\n";
                }
            }
            echo "   - Reemplazos exitosos: $replacementsMade/" . count($replacements) . "\n";
            
            if (strlen($processedContent) < 1000) {
                echo "   - ERROR: Contenido procesado muy pequeño\n";
            } else {
                echo "   - Generación de template: OK\n";
            }
            
        } catch (\Exception $e) {
            echo "   - ERROR en prueba de template: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Ejecutar diagnóstico
try {
    $diagnostic = new ExportDiagnostic();
    $diagnostic->runDiagnostics();
} catch (\Exception $e) {
    echo "ERROR FATAL: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} 