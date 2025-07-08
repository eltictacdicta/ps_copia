<?php
/**
 * Script de Diagnóstico para el Problema de Escaneo
 * ps_copia Module - Scan Debug Test
 */

// Incluir configuración de PrestaShop
if (!defined("_PS_ADMIN_DIR_")) {
    $adminDir = null;
    $currentDir = dirname(__FILE__);
    
    // Buscar el directorio admin
    $possibleAdminDirs = glob($currentDir . '/../../admin*');
    foreach ($possibleAdminDirs as $dir) {
        if (is_dir($dir) && file_exists($dir . '/index.php')) {
            $adminDir = $dir;
            break;
        }
    }
    
    if ($adminDir) {
        define("_PS_ADMIN_DIR_", $adminDir . "/");
    } else {
        echo "❌ No se pudo encontrar el directorio admin\n";
        exit;
    }
}

require_once dirname(__FILE__) . "/../../config/config.inc.php";

echo "🔍 DIAGNÓSTICO DEL PROBLEMA DE ESCANEO\n";
echo "======================================\n\n";

// Función para mostrar estado
function showStatus($message, $status = 'info') {
    $icons = [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️'
    ];
    
    echo $icons[$status] . ' ' . $message . "\n";
}

// 1. Verificar configuración básica
showStatus("Verificando configuración básica...");

if (!defined('_PS_ROOT_DIR_')) {
    showStatus("_PS_ROOT_DIR_ no está definido", 'error');
    exit;
}

showStatus("PrestaShop Root: " . _PS_ROOT_DIR_);

// 2. Verificar la clase BackupContainer
showStatus("Verificando clase BackupContainer...");

$backupContainerPath = dirname(__FILE__) . "/classes/BackupContainer.php";
if (!file_exists($backupContainerPath)) {
    showStatus("BackupContainer.php no encontrado en: " . $backupContainerPath, 'error');
    exit;
}

require_once $backupContainerPath;

if (!class_exists('BackupContainer')) {
    showStatus("Clase BackupContainer no se pudo cargar", 'error');
    exit;
}

showStatus("BackupContainer cargado correctamente");

// 3. Crear instancia y verificar uploads path
showStatus("Creando instancia de BackupContainer...");

try {
    $backupContainer = new BackupContainer();
    $uploadsPath = $backupContainer->getProperty(BackupContainer::UPLOADS_PATH);
    
    showStatus("Ruta de uploads configurada: " . $uploadsPath);
    
} catch (Exception $e) {
    showStatus("Error al crear BackupContainer: " . $e->getMessage(), 'error');
    exit;
}

// 4. Verificar directorio uploads
showStatus("Verificando directorio uploads...");

if (!is_dir($uploadsPath)) {
    showStatus("Directorio uploads no existe: " . $uploadsPath, 'warning');
    
    // Intentar crear
    showStatus("Intentando crear directorio...");
    if (@mkdir($uploadsPath, 0755, true)) {
        showStatus("Directorio creado exitosamente");
        
        // Crear archivos de seguridad
        $htaccessContent = "Order Deny,Allow\nDeny from all\n";
        file_put_contents($uploadsPath . '/.htaccess', $htaccessContent);
        
        $indexContent = "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit;\n";
        file_put_contents($uploadsPath . '/index.php', $indexContent);
        
        showStatus("Archivos de seguridad creados");
    } else {
        showStatus("No se pudo crear el directorio", 'error');
        exit;
    }
} else {
    showStatus("Directorio uploads existe");
}

// 5. Verificar permisos
showStatus("Verificando permisos...");

if (is_readable($uploadsPath)) {
    showStatus("Directorio es legible");
} else {
    showStatus("Directorio NO es legible", 'error');
}

if (is_writable($uploadsPath)) {
    showStatus("Directorio es escribible");
} else {
    showStatus("Directorio NO es escribible", 'warning');
}

// 6. Listar contenido actual del directorio
showStatus("Escaneando contenido del directorio...");

try {
    $startTime = microtime(true);
    
    $files = scandir($uploadsPath);
    if ($files === false) {
        showStatus("Error al escanear directorio", 'error');
        exit;
    }
    
    $scanTime = microtime(true) - $startTime;
    showStatus("Escaneo básico completado en " . round($scanTime * 1000, 2) . "ms");
    
    $zipFiles = [];
    $totalFiles = 0;
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $totalFiles++;
        $filePath = $uploadsPath . DIRECTORY_SEPARATOR . $file;
        
        showStatus("Encontrado: " . $file);
        
        if (is_file($filePath) && strtolower(substr($file, -4)) === '.zip') {
            $zipFiles[] = $file;
            
            // Información básica del archivo
            $fileSize = @filesize($filePath);
            $fileTime = @filemtime($filePath);
            
            if ($fileSize !== false && $fileTime !== false) {
                showStatus("  └─ ZIP válido: " . number_format($fileSize) . " bytes, " . date('Y-m-d H:i:s', $fileTime));
            } else {
                showStatus("  └─ ZIP con problemas (no se pudo obtener info)", 'warning');
            }
        }
    }
    
    showStatus("Resumen del escaneo:");
    showStatus("  • Total de archivos: " . $totalFiles);
    showStatus("  • Archivos ZIP: " . count($zipFiles));
    
} catch (Exception $e) {
    showStatus("Error durante el escaneo: " . $e->getMessage(), 'error');
}

// 7. Simular el proceso AJAX
showStatus("\nSimulando proceso AJAX...");

try {
    // Incluir clases necesarias
    require_once dirname(__FILE__) . "/classes/BackupLogger.php";
    
    // Crear una versión simplificada del controlador
    class TestScanController {
        private $backupContainer;
        private $logger;
        
        public function __construct() {
            $this->backupContainer = new BackupContainer();
            $this->logger = new BackupLogger();
        }
        
        public function testScan() {
            $uploadsPath = $this->backupContainer->getProperty(BackupContainer::UPLOADS_PATH);
            
            $this->logger->info("Testing scan function", ['uploads_path' => $uploadsPath]);
            
            $zipFiles = $this->scanForZipFilesUltraLight($uploadsPath);
            
            return $zipFiles;
        }
        
        // Copia simplificada de la función problemática
        private function scanForZipFilesUltraLight(string $uploadsPath): array
        {
            $zipFiles = [];
            $startTime = microtime(true);
            $maxExecutionTime = 10; // Reducido para testing
            
            if (!is_dir($uploadsPath)) {
                return $zipFiles;
            }
            
            $handle = opendir($uploadsPath);
            if (!$handle) {
                return $zipFiles;
            }
            
            $processedCount = 0;
            $maxFiles = 10; // Reducido para testing
            
            while (($file = readdir($handle)) !== false && $processedCount < $maxFiles) {
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime > $maxExecutionTime) {
                    echo "⚠️ Timeout alcanzado durante test\n";
                    break;
                }
                
                if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php') {
                    continue;
                }
                
                $filePath = $uploadsPath . DIRECTORY_SEPARATOR . $file;
                
                if (!is_file($filePath)) {
                    continue;
                }
                
                $extension = strtolower(substr($file, -4));
                if ($extension !== '.zip') {
                    continue;
                }
                
                $processedCount++;
                
                $fileSize = @filesize($filePath);
                $fileTime = @filemtime($filePath);
                
                if ($fileSize === false || $fileTime === false) {
                    echo "⚠️ Problema obteniendo info de: " . $file . "\n";
                    continue;
                }
                
                $zipFiles[] = [
                    'filename' => $file,
                    'size_formatted' => $this->formatBytes($fileSize),
                    'size_bytes' => $fileSize,
                    'modified' => date('Y-m-d H:i:s', $fileTime),
                    'is_valid_backup' => true,
                    'is_large' => ($fileSize > 100 * 1024 * 1024),
                ];
                
                echo "✅ Procesado: " . $file . " (" . $this->formatBytes($fileSize) . ")\n";
            }
            
            closedir($handle);
            
            $totalTime = microtime(true) - $startTime;
            echo "ℹ️ Escaneo test completado en " . round($totalTime, 2) . "s\n";
            
            return $zipFiles;
        }
        
        private function formatBytes(int $bytes): string {
            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, 2) . ' ' . $units[$pow];
        }
    }
    
    $testController = new TestScanController();
    $result = $testController->testScan();
    
    showStatus("Test AJAX completado exitosamente");
    showStatus("Archivos encontrados en test: " . count($result));
    
} catch (Exception $e) {
    showStatus("Error en test AJAX: " . $e->getMessage(), 'error');
    showStatus("Trace: " . $e->getTraceAsString());
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "DIAGNÓSTICO COMPLETADO\n";
echo "Si este script se ejecuta sin problemas, el issue está en el frontend.\n";
echo "Si falla, el problema está en el backend.\n";
echo "\nRevisa los logs para más detalles.\n"; 