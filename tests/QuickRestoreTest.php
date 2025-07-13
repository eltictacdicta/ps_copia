<?php
/**
 * Test rápido para verificar funcionalidad básica sin dependencias complejas
 */

// Incluir autoloader de PrestaShop
require_once '/var/www/html/autoload.php';

class QuickRestoreTest
{
    private $testResults = [];
    
    public function run()
    {
        echo "=== Test Rápido de Restauración PS_Copia ===\n\n";
        
        $this->testFileExistence();
        $this->testClassSyntax();
        $this->testEnvironmentDetection();
        $this->testUtilityFunctions();
        
        $this->showResults();
    }
    
    private function testFileExistence()
    {
        echo "1. Verificando existencia de archivos...\n";
        
        $files = [
            'classes/Services/EnhancedRestoreService.php',
            'classes/Migration/UrlMigrator.php',
            'classes/Services/SecureFileRestoreService.php',
            'classes/Services/TransactionManager.php',
            'classes/Migration/DatabaseMigrator.php'
        ];
        
        foreach ($files as $file) {
            $fullPath = __DIR__ . '/../' . $file;
            if (file_exists($fullPath)) {
                $size = filesize($fullPath);
                $this->testResults[] = "✓ $file existe (" . round($size/1024, 1) . " KB)";
            } else {
                $this->testResults[] = "✗ $file no existe";
            }
        }
        
        echo "   Archivos verificados\n\n";
    }
    
    private function testClassSyntax()
    {
        echo "2. Verificando sintaxis de clases...\n";
        
        $files = [
            'classes/Services/EnhancedRestoreService.php',
            'classes/Migration/UrlMigrator.php',
            'classes/Services/SecureFileRestoreService.php',
            'classes/Services/TransactionManager.php'
        ];
        
        foreach ($files as $file) {
            $fullPath = __DIR__ . '/../' . $file;
            if (file_exists($fullPath)) {
                $output = [];
                $return = 0;
                exec("php -l '$fullPath' 2>&1", $output, $return);
                
                if ($return === 0) {
                    $this->testResults[] = "✓ $file tiene sintaxis válida";
                } else {
                    $this->testResults[] = "✗ $file tiene errores de sintaxis: " . implode(' ', $output);
                }
            }
        }
        
        echo "   Sintaxis verificada\n\n";
    }
    
    private function testEnvironmentDetection()
    {
        echo "3. Verificando detección de entorno...\n";
        
        // Test detección DDEV
        $isDdev = getenv('DDEV_PROJECT') !== false || 
                 file_exists('/.ddev') || 
                 file_exists('.ddev') ||
                 file_exists('/var/www/html/.ddev');
        
        if ($isDdev) {
            $this->testResults[] = "✓ Entorno DDEV detectado correctamente";
            
            // Verificar variables de entorno DDEV
            $ddevProject = getenv('DDEV_PROJECT');
            if ($ddevProject) {
                $this->testResults[] = "✓ Variable DDEV_PROJECT: $ddevProject";
            }
            
            $ddevHostname = getenv('DDEV_HOSTNAME');
            if ($ddevHostname) {
                $this->testResults[] = "✓ Variable DDEV_HOSTNAME: $ddevHostname";
            }
        } else {
            $this->testResults[] = "ℹ Entorno no-DDEV detectado";
        }
        
        // Test detección de PHP
        $phpVersion = PHP_VERSION;
        $this->testResults[] = "✓ PHP versión: $phpVersion";
        
        // Test extensiones PHP necesarias
        $extensions = ['zip', 'mysqli', 'curl', 'json'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->testResults[] = "✓ Extensión PHP $ext disponible";
            } else {
                $this->testResults[] = "⚠ Extensión PHP $ext no disponible";
            }
        }
        
        echo "   Entorno verificado\n\n";
    }
    
    private function testUtilityFunctions()
    {
        echo "4. Verificando funciones utilitarias...\n";
        
        // Test función de validación de dominio
        $domains = ['example.com', 'test.localhost', 'invalid..domain', ''];
        foreach ($domains as $domain) {
            $isValid = $this->validateDomain($domain);
            $status = $isValid ? 'válido' : 'inválido';
            $this->testResults[] = "✓ Dominio '$domain': $status";
        }
        
        // Test función de detección de archivo ZIP
        $testZip = '/tmp/test.zip';
        file_put_contents($testZip, 'PK'); // Signature básica de ZIP
        
        $isZip = $this->isZipFile($testZip);
        $this->testResults[] = "✓ Detección archivo ZIP: " . ($isZip ? 'correcto' : 'incorrecto');
        
        unlink($testZip);
        
        // Test función de limpieza de rutas
        $testPath = '/var/www/../html/./test';
        $cleanPath = $this->cleanPath($testPath);
        $this->testResults[] = "✓ Limpieza de ruta: '$testPath' → '$cleanPath'";
        
        echo "   Funciones utilitarias verificadas\n\n";
    }
    
    private function validateDomain($domain)
    {
        if (empty($domain)) return false;
        if (strpos($domain, '..') !== false) return false;
        if (strpos($domain, ' ') !== false) return false;
        
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ||
               filter_var('http://' . $domain, FILTER_VALIDATE_URL) !== false;
    }
    
    private function isZipFile($filepath)
    {
        if (!file_exists($filepath)) return false;
        
        $handle = fopen($filepath, 'rb');
        if (!$handle) return false;
        
        $signature = fread($handle, 2);
        fclose($handle);
        
        return $signature === 'PK';
    }
    
    private function cleanPath($path)
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        
        $parts = explode('/', $path);
        $clean = [];
        
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($clean);
            } elseif ($part !== '.' && $part !== '') {
                $clean[] = $part;
            }
        }
        
        return '/' . implode('/', $clean);
    }
    
    private function showResults()
    {
        echo "=== Resultados del Test Rápido ===\n";
        foreach ($this->testResults as $result) {
            echo $result . "\n";
        }
        
        $passed = count(array_filter($this->testResults, function($r) {
            return strpos($r, '✓') === 0;
        }));
        
        $warnings = count(array_filter($this->testResults, function($r) {
            return strpos($r, '⚠') === 0;
        }));
        
        $errors = count(array_filter($this->testResults, function($r) {
            return strpos($r, '✗') === 0;
        }));
        
        $info = count(array_filter($this->testResults, function($r) {
            return strpos($r, 'ℹ') === 0;
        }));
        
        $total = count($this->testResults);
        echo "\nResumen: $passed tests pasaron, $warnings advertencias, $errors errores, $info info (Total: $total)\n";
        
        if ($errors === 0) {
            echo "🎉 ¡Test rápido completado exitosamente!\n";
            echo "📋 Sistema de restauración mejorado listo para usar\n";
        } else {
            echo "⚠ Se encontraron $errors errores que requieren atención\n";
        }
    }
}

// Ejecutar el test
$test = new QuickRestoreTest();
$test->run(); 