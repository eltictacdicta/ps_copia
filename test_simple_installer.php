<?php
/**
 * Script de prueba para el nuevo instalador simple
 * Verifica que se pueden generar correctamente los archivos
 */

// Definir directorio base
define('_PS_ROOT_DIR_', dirname(__FILE__) . '/../../../..');
define('_PS_MODULE_DIR_', _PS_ROOT_DIR_ . '/modules');

// Incluir archivos necesarios
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/functions.php';

class SimpleInstallerTest
{
    private $container;
    private $importExportService;
    
    public function __construct()
    {
        echo "=== PRUEBA DEL INSTALADOR SIMPLE ===\n\n";
        
        // Inicializar container
        if (class_exists('PrestaShop\Module\PsCopia\BackupContainer')) {
            $this->container = new PrestaShop\Module\PsCopia\BackupContainer(
                _PS_ROOT_DIR_, 
                _PS_ADMIN_DIR_, 
                'ps_copia'
            );
            $this->container->initDirectories();
            echo "✓ BackupContainer inicializado\n";
        } else {
            echo "✗ No se pudo inicializar BackupContainer\n";
            return;
        }
        
        // Inicializar ImportExportService
        if (class_exists('PrestaShop\Module\PsCopia\Services\ImportExportService')) {
            $logger = new \PrestaShop\Module\PsCopia\Logger\BackupLogger($this->container);
            $validationService = new \PrestaShop\Module\PsCopia\Services\ValidationService($this->container, $logger);
            
            $this->importExportService = new \PrestaShop\Module\PsCopia\Services\ImportExportService(
                $this->container,
                $logger,
                $validationService
            );
            echo "✓ ImportExportService inicializado\n";
        } else {
            echo "✗ No se pudo inicializar ImportExportService\n";
            return;
        }
        
        echo "\n";
    }
    
    public function testSimpleInstallerGeneration()
    {
        echo "1. PRUEBA DE GENERACIÓN DE INSTALADOR SIMPLE:\n";
        
        // Listar backups disponibles
        $backupPath = $this->container->getProperty(\PrestaShop\Module\PsCopia\BackupContainer::BACKUP_PATH);
        echo "   - Directorio de backups: $backupPath\n";
        
        if (!is_dir($backupPath)) {
            echo "   ✗ Directorio de backups no existe\n";
            return;
        }
        
        // Buscar backups
        $metadataFile = $backupPath . DIRECTORY_SEPARATOR . 'backups_metadata.json';
        if (!file_exists($metadataFile)) {
            echo "   ✗ No hay metadata de backups disponible\n";
            echo "   ℹ Primero necesitas crear un backup desde la interfaz\n";
            return;
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        if (empty($metadata)) {
            echo "   ✗ No hay backups disponibles\n";
            return;
        }
        
        // Tomar el primer backup disponible
        $backupName = array_keys($metadata)[0];
        echo "   - Usando backup de prueba: $backupName\n";
        
        try {
            // Probar la generación del instalador simple
            echo "   - Generando instalador simple...\n";
            $result = $this->importExportService->exportStandaloneInstaller($backupName);
            
            echo "   ✓ Instalador generado exitosamente\n";
            echo "   - Archivo instalador: " . $result['filename'] . " (" . $result['size_formatted'] . ")\n";
            echo "   - Archivo ZIP: " . $result['export_zip_filename'] . " (" . $result['export_zip_size_formatted'] . ")\n";
            
            // Verificar que los archivos existen
            $installerPath = $backupPath . DIRECTORY_SEPARATOR . $result['filename'];
            $zipPath = $backupPath . DIRECTORY_SEPARATOR . $result['export_zip_filename'];
            
            if (file_exists($installerPath)) {
                echo "   ✓ Archivo instalador creado correctamente\n";
                
                // Verificar contenido del instalador
                $content = file_get_contents($installerPath);
                if (strpos($content, 'PsCopiasSimpleInstaller') !== false) {
                    echo "   ✓ Contenido del instalador es válido\n";
                } else {
                    echo "   ✗ Contenido del instalador parece incorrecto\n";
                }
            } else {
                echo "   ✗ Archivo instalador no fue creado\n";
            }
            
            if (file_exists($zipPath)) {
                echo "   ✓ Archivo ZIP de exportación existe\n";
            } else {
                echo "   ✗ Archivo ZIP de exportación no encontrado\n";
            }
            
            echo "\n   INSTRUCCIONES GENERADAS:\n";
            foreach ($result['instructions'] as $step => $instruction) {
                echo "   $step: $instruction\n";
            }
            
        } catch (Exception $e) {
            echo "   ✗ Error generando instalador: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    public function testTemplateValidation()
    {
        echo "2. PRUEBA DE TEMPLATE:\n";
        
        $templatePath = dirname(__FILE__) . '/installer_templates/ps_copias_installer_simple_template.php';
        
        if (file_exists($templatePath)) {
            echo "   ✓ Template simple encontrado\n";
            
            $content = file_get_contents($templatePath);
            $size = strlen($content);
            echo "   - Tamaño del template: " . number_format($size) . " bytes\n";
            
            // Verificar placeholders
            $placeholders = ['{EMBEDDED_CONFIG}', '{INSTALLER_VERSION}', '{CREATION_DATE}'];
            $foundPlaceholders = 0;
            
            foreach ($placeholders as $placeholder) {
                if (strpos($content, $placeholder) !== false) {
                    $foundPlaceholders++;
                }
            }
            
            echo "   - Placeholders encontrados: $foundPlaceholders/" . count($placeholders) . "\n";
            
            if ($foundPlaceholders === count($placeholders)) {
                echo "   ✓ Template tiene todos los placeholders necesarios\n";
            } else {
                echo "   ✗ Template falta algunos placeholders\n";
            }
            
            // Verificar que contiene la clase principal
            if (strpos($content, 'class PsCopiasSimpleInstaller') !== false) {
                echo "   ✓ Clase principal encontrada en template\n";
            } else {
                echo "   ✗ Clase principal no encontrada en template\n";
            }
            
        } else {
            echo "   ✗ Template simple no encontrado en: $templatePath\n";
        }
        
        echo "\n";
    }
    
    public function run()
    {
        if (!$this->container || !$this->importExportService) {
            echo "No se pudo inicializar correctamente. Abortando pruebas.\n";
            return;
        }
        
        $this->testTemplateValidation();
        $this->testSimpleInstallerGeneration();
        
        echo "=== PRUEBAS COMPLETADAS ===\n";
    }
}

// Ejecutar pruebas
try {
    $test = new SimpleInstallerTest();
    $test->run();
} catch (Exception $e) {
    echo "ERROR FATAL: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} 