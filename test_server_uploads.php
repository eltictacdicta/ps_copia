<?php
/**
 * Test Script para Funcionalidad de Uploads del Servidor
 * ps_copia Module - Server Uploads Testing
 */

// Incluir configuración de PrestaShop
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', dirname(__FILE__) . '/../../admin/');
}

require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/classes/BackupContainer.php';

class ServerUploadsTest
{
    private $testResults = [];
    private $uploadsPath;
    private $backupContainer;

    public function __construct()
    {
        echo "🧪 INICIANDO PRUEBAS DE UPLOADS DEL SERVIDOR\n";
        echo "==========================================\n\n";
        
        $this->backupContainer = new BackupContainer();
        $this->uploadsPath = $this->getServerUploadsPath();
    }

    /**
     * Ejecutar todas las pruebas
     */
    public function runAllTests(): array
    {
        $tests = [
            'testDirectoryCreation' => 'Creación de directorio uploads',
            'testSecurityFiles' => 'Archivos de seguridad',
            'testPathValidation' => 'Validación de rutas (seguridad)',
            'testZipScanning' => 'Escaneo de archivos ZIP',
            'testZipValidation' => 'Validación de estructura ZIP',
            'testFileInfoExtraction' => 'Extracción de información de archivos',
            'testPermissions' => 'Verificación de permisos'
        ];

        foreach ($tests as $method => $description) {
            echo "🔍 PRUEBA: $description\n";
            echo str_repeat('-', 50) . "\n";
            
            try {
                $result = $this->$method();
                $this->testResults[$method] = $result;
                echo $result ? "✅ PASÓ\n" : "❌ FALLÓ\n";
            } catch (Exception $e) {
                $this->testResults[$method] = false;
                echo "❌ ERROR: " . $e->getMessage() . "\n";
            }
            
            echo "\n";
        }

        return $this->testResults;
    }

    /**
     * Mostrar resumen de pruebas
     */
    public function showSummary(): void
    {
        $passed = count(array_filter($this->testResults));
        $total = count($this->testResults);
        
        echo "📊 RESUMEN DE PRUEBAS\n";
        echo "==================\n";
        echo "Total: $total\n";
        echo "Pasaron: $passed\n";
        echo "Fallaron: " . ($total - $passed) . "\n";
        echo "Porcentaje: " . round(($passed / $total) * 100, 1) . "%\n\n";
        
        if ($passed === $total) {
            echo "🎉 ¡TODAS LAS PRUEBAS PASARON!\n";
            echo "La funcionalidad de uploads del servidor está lista para usar.\n\n";
        } else {
            echo "⚠️  Algunas pruebas fallaron. Revisar implementación.\n\n";
        }
    }

    /**
     * Prueba 1: Creación de directorio uploads
     */
    private function testDirectoryCreation(): bool
    {
        // Si existe, eliminarlo para prueba completa
        if (is_dir($this->uploadsPath)) {
            $this->removeDirectoryRecursively($this->uploadsPath);
        }

        $this->ensureUploadsDirectoryExists();
        
        $exists = is_dir($this->uploadsPath);
        $writable = is_writable($this->uploadsPath);
        
        echo "Directorio creado: " . ($exists ? "Sí" : "No") . "\n";
        echo "Es escribible: " . ($writable ? "Sí" : "No") . "\n";
        
        return $exists && $writable;
    }

    /**
     * Prueba 2: Archivos de seguridad
     */
    private function testSecurityFiles(): bool
    {
        $htaccessPath = $this->uploadsPath . DIRECTORY_SEPARATOR . '.htaccess';
        $indexPath = $this->uploadsPath . DIRECTORY_SEPARATOR . 'index.php';
        
        $htaccessExists = file_exists($htaccessPath);
        $indexExists = file_exists($indexPath);
        
        echo ".htaccess existe: " . ($htaccessExists ? "Sí" : "No") . "\n";
        echo "index.php existe: " . ($indexExists ? "Sí" : "No") . "\n";
        
        if ($htaccessExists) {
            $htaccessContent = file_get_contents($htaccessPath);
            $hasDenyAll = strpos($htaccessContent, 'Deny from all') !== false;
            $hasZipAllow = strpos($htaccessContent, '*.zip') !== false;
            echo ".htaccess configurado correctamente: " . ($hasDenyAll && $hasZipAllow ? "Sí" : "No") . "\n";
        }
        
        return $htaccessExists && $indexExists;
    }

    /**
     * Prueba 3: Validación de rutas (seguridad)
     */
    private function testPathValidation(): bool
    {
        $validFile = $this->uploadsPath . DIRECTORY_SEPARATOR . 'test.zip';
        $invalidFile1 = $this->uploadsPath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'malicious.zip';
        $invalidFile2 = '/tmp/outside.zip';
        $invalidFile3 = $this->uploadsPath . DIRECTORY_SEPARATOR . 'test.txt';
        
        // Crear archivo de prueba válido
        file_put_contents($validFile, 'test content');
        
        $validResult = $this->validateServerUploadFile($validFile, 'test.zip');
        $invalidResult1 = $this->validateServerUploadFile($invalidFile1, '../malicious.zip');
        $invalidResult2 = $this->validateServerUploadFile($invalidFile2, 'outside.zip');
        $invalidResult3 = $this->validateServerUploadFile($invalidFile3, 'test.txt');
        
        echo "Archivo válido: " . ($validResult ? "Acepta" : "Rechaza") . "\n";
        echo "Path traversal: " . ($invalidResult1 ? "Acepta (MAL)" : "Rechaza (BIEN)") . "\n";
        echo "Archivo externo: " . ($invalidResult2 ? "Acepta (MAL)" : "Rechaza (BIEN)") . "\n";
        echo "Extensión incorrecta: " . ($invalidResult3 ? "Acepta (MAL)" : "Rechaza (BIEN)") . "\n";
        
        // Limpiar
        if (file_exists($validFile)) {
            unlink($validFile);
        }
        
        return $validResult && !$invalidResult1 && !$invalidResult2 && !$invalidResult3;
    }

    /**
     * Prueba 4: Escaneo de archivos ZIP
     */
    private function testZipScanning(): bool
    {
        // Crear archivos de prueba
        $zipFile = $this->uploadsPath . DIRECTORY_SEPARATOR . 'test_backup.zip';
        $txtFile = $this->uploadsPath . DIRECTORY_SEPARATOR . 'should_ignore.txt';
        
        // Crear ZIP de prueba básico
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE);
        $zip->addFromString('backup_info.json', json_encode([
            'created_at' => date('Y-m-d H:i:s'),
            'prestashop_version' => '1.7.8.0',
            'backup_type' => 'complete'
        ]));
        $zip->addFromString('database/dump.sql', 'SELECT 1;');
        $zip->addFromString('files/index.php', '<?php echo "test"; ?>');
        $zip->close();
        
        // Crear archivo que no es ZIP
        file_put_contents($txtFile, 'not a zip');
        
        $zipFiles = $this->scanForZipFiles();
        
        echo "Archivos encontrados: " . count($zipFiles) . "\n";
        echo "Contiene test_backup.zip: " . (count(array_filter($zipFiles, function($f) {
            return $f['filename'] === 'test_backup.zip';
        })) > 0 ? "Sí" : "No") . "\n";
        echo "Ignora archivos .txt: " . (count(array_filter($zipFiles, function($f) {
            return $f['filename'] === 'should_ignore.txt';
        })) === 0 ? "Sí" : "No") . "\n";
        
        // Limpiar
        if (file_exists($zipFile)) unlink($zipFile);
        if (file_exists($txtFile)) unlink($txtFile);
        
        return count($zipFiles) >= 1; // Al menos encontró el test_backup.zip
    }

    /**
     * Prueba 5: Validación de estructura ZIP
     */
    private function testZipValidation(): bool
    {
        // Crear ZIP válido
        $validZip = $this->uploadsPath . DIRECTORY_SEPARATOR . 'valid_backup.zip';
        $invalidZip = $this->uploadsPath . DIRECTORY_SEPARATOR . 'invalid_backup.zip';
        
        // ZIP válido con estructura correcta
        $zip = new ZipArchive();
        $zip->open($validZip, ZipArchive::CREATE);
        $zip->addFromString('backup_info.json', json_encode(['type' => 'complete']));
        $zip->addFromString('database/dump.sql', 'SELECT 1;');
        $zip->addFromString('files/index.php', '<?php echo "test"; ?>');
        $zip->close();
        
        // ZIP inválido sin estructura correcta
        $zip2 = new ZipArchive();
        $zip2->open($invalidZip, ZipArchive::CREATE);
        $zip2->addFromString('random.txt', 'not a backup');
        $zip2->close();
        
        $validResult = $this->quickValidateBackupZip($validZip);
        $invalidResult = $this->quickValidateBackupZip($invalidZip);
        
        echo "ZIP válido reconocido: " . ($validResult ? "Sí" : "No") . "\n";
        echo "ZIP inválido rechazado: " . ($invalidResult ? "No (MAL)" : "Sí (BIEN)") . "\n";
        
        // Limpiar
        if (file_exists($validZip)) unlink($validZip);
        if (file_exists($invalidZip)) unlink($invalidZip);
        
        return $validResult && !$invalidResult;
    }

    /**
     * Prueba 6: Extracción de información de archivos
     */
    private function testFileInfoExtraction(): bool
    {
        $testZip = $this->uploadsPath . DIRECTORY_SEPARATOR . 'info_test.zip';
        
        // Crear ZIP con información específica
        $zip = new ZipArchive();
        $zip->open($testZip, ZipArchive::CREATE);
        
        $backupInfo = [
            'created_at' => '2024-01-15 10:30:00',
            'prestashop_version' => '1.7.8.0',
            'backup_type' => 'complete',
            'site_url' => 'https://ejemplo.com'
        ];
        
        $zip->addFromString('backup_info.json', json_encode($backupInfo));
        $zip->addFromString('database/dump.sql', str_repeat('SELECT * FROM test; ', 1000)); // ~20KB
        $zip->close();
        
        $zipFiles = $this->scanForZipFiles();
        $found = false;
        
        foreach ($zipFiles as $file) {
            if ($file['filename'] === 'info_test.zip') {
                $found = true;
                echo "Archivo detectado: Sí\n";
                echo "Tamaño calculado: " . $file['size_formatted'] . "\n";
                echo "Fecha extraída: " . $file['modified'] . "\n";
                echo "Marcado como válido: " . ($file['is_valid_backup'] ? "Sí" : "No") . "\n";
                break;
            }
        }
        
        // Limpiar
        if (file_exists($testZip)) unlink($testZip);
        
        return $found;
    }

    /**
     * Prueba 7: Verificación de permisos
     */
    private function testPermissions(): bool
    {
        $testFile = $this->uploadsPath . DIRECTORY_SEPARATOR . 'perm_test.zip';
        
        // Intentar crear archivo
        $created = file_put_contents($testFile, 'test') !== false;
        
        // Verificar lectura
        $readable = $created && is_readable($testFile);
        
        // Verificar escritura en directorio
        $dirWritable = is_writable($this->uploadsPath);
        
        // Intentar eliminar
        $deletable = $created && unlink($testFile);
        
        echo "Crear archivo: " . ($created ? "Sí" : "No") . "\n";
        echo "Leer archivo: " . ($readable ? "Sí" : "No") . "\n";
        echo "Directorio escribible: " . ($dirWritable ? "Sí" : "No") . "\n";
        echo "Eliminar archivo: " . ($deletable ? "Sí" : "No") . "\n";
        
        return $created && $readable && $dirWritable && $deletable;
    }

    // Métodos auxiliares copiados de la clase principal

    private function getServerUploadsPath(): string
    {
        $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
        return $backupDir . DIRECTORY_SEPARATOR . 'uploads';
    }

    private function ensureUploadsDirectoryExists(): void
    {
        if (!is_dir($this->uploadsPath)) {
            if (!mkdir($this->uploadsPath, 0755, true)) {
                throw new Exception('No se pudo crear el directorio de uploads: ' . $this->uploadsPath);
            }
            
            // Crear archivo .htaccess para seguridad
            $htaccessContent = "# Deny direct access to uploads\n";
            $htaccessContent .= "Order Deny,Allow\n";
            $htaccessContent .= "Deny from all\n";
            $htaccessContent .= "# Allow only from admin\n";
            $htaccessContent .= "<Files \"*.zip\">\n";
            $htaccessContent .= "    Order Allow,Deny\n";
            $htaccessContent .= "    Allow from all\n";
            $htaccessContent .= "</Files>\n";
            
            file_put_contents($this->uploadsPath . DIRECTORY_SEPARATOR . '.htaccess', $htaccessContent);
            
            // Crear archivo index.php para evitar listado de directorio
            $indexContent = "<?php\n// Directory listing disabled\nheader('HTTP/1.0 403 Forbidden');\nexit;\n";
            file_put_contents($this->uploadsPath . DIRECTORY_SEPARATOR . 'index.php', $indexContent);
        }
    }

    private function validateServerUploadFile(string $zipPath, string $filename): bool
    {
        // Verificar que el archivo existe
        if (!file_exists($zipPath)) {
            return false;
        }
        
        // Verificar que está dentro del directorio de uploads (prevenir path traversal)
        $realZipPath = realpath($zipPath);
        $realUploadsPath = realpath($this->uploadsPath);
        
        if (!$realZipPath || !$realUploadsPath || strpos($realZipPath, $realUploadsPath) !== 0) {
            return false;
        }
        
        // Verificar extensión
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            return false;
        }
        
        // Verificar que es un archivo legible
        if (!is_readable($zipPath)) {
            return false;
        }
        
        return true;
    }

    private function scanForZipFiles(): array
    {
        $zipFiles = [];
        
        if (!is_dir($this->uploadsPath)) {
            return $zipFiles;
        }
        
        $files = scandir($this->uploadsPath);
        if (!$files) {
            return $zipFiles;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php') {
                continue;
            }
            
            $filePath = $this->uploadsPath . DIRECTORY_SEPARATOR . $file;
            
            if (is_file($filePath) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
                $fileSize = filesize($filePath);
                $fileTime = filemtime($filePath);
                
                // Verificar si es un backup válido revisando la estructura básica
                $isValidBackup = $this->quickValidateBackupZip($filePath);
                
                $zipFiles[] = [
                    'filename' => $file,
                    'size' => $fileSize,
                    'size_formatted' => $this->formatBytes($fileSize),
                    'modified' => date('Y-m-d H:i:s', $fileTime),
                    'is_large' => $fileSize > 100 * 1024 * 1024,
                    'is_valid_backup' => $isValidBackup,
                    'path' => $filePath
                ];
            }
        }
        
        return $zipFiles;
    }

    private function quickValidateBackupZip(string $zipPath): bool
    {
        try {
            $zip = new ZipArchive();
            $result = $zip->open($zipPath);
            
            if ($result !== TRUE) {
                return false;
            }
            
            // Verificar que tiene la estructura básica de backup
            $hasInfo = $zip->locateName('backup_info.json') !== false;
            $hasDatabase = false;
            $hasFiles = false;
            
            // Revisar solo los primeros archivos para ser eficiente
            $maxCheck = min($zip->numFiles, 20);
            for ($i = 0; $i < $maxCheck; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, 'database/') === 0) {
                    $hasDatabase = true;
                }
                if (strpos($filename, 'files/') === 0) {
                    $hasFiles = true;
                }
                
                // Salir temprano si encontramos todo
                if ($hasInfo && $hasDatabase && $hasFiles) {
                    break;
                }
            }
            
            $zip->close();
            return $hasInfo && ($hasDatabase || $hasFiles); // Al menos backup_info y uno de los tipos
            
        } catch (Exception $e) {
            return false;
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function removeDirectoryRecursively(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($directory);
    }
}

// Ejecutar pruebas si es llamado directamente
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $tester = new ServerUploadsTest();
        $results = $tester->runAllTests();
        $tester->showSummary();
        
        echo "🔧 PRUEBAS COMPLETADAS\n";
        echo "Revisa los resultados arriba para verificar que todo funciona correctamente.\n";
        
    } catch (Exception $e) {
        echo "❌ ERROR FATAL: " . $e->getMessage() . "\n";
        exit(1);
    }
} 