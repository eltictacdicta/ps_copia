<?php
/**
 * Test simple para verificar funcionalidad de restauración
 */

// Incluir autoloader de PrestaShop
require_once '/var/www/html/autoload.php';

class SimpleRestoreTest
{
    private $testResults = [];
    
    public function run()
    {
        echo "=== Test Simple de Restauración PS_Copia ===\n\n";
        
        $this->testClassLoading();
        $this->testBasicFunctionality();
        $this->testEnvironmentDetection();
        
        $this->showResults();
    }
    
    private function testClassLoading()
    {
        echo "1. Verificando carga de clases...\n";
        
        $classes = [
            'EnhancedRestoreService',
            'UrlMigrator', 
            'SecureFileRestoreService',
            'TransactionManager'
        ];
        
        foreach ($classes as $class) {
            $classPath = __DIR__ . '/../classes/Services/' . $class . '.php';
            if ($class === 'UrlMigrator') {
                $classPath = __DIR__ . '/../classes/Migration/' . $class . '.php';
            }
            
            if (file_exists($classPath)) {
                require_once $classPath;
                $this->testResults[] = "✓ Clase $class cargada correctamente";
            } else {
                $this->testResults[] = "✗ Clase $class no encontrada en $classPath";
            }
        }
        
        echo "   Clases verificadas\n\n";
    }
    
    private function testBasicFunctionality()
    {
        echo "2. Verificando funcionalidad básica...\n";
        
        try {
            // Test EnhancedRestoreService
            if (class_exists('EnhancedRestoreService')) {
                $restore = new EnhancedRestoreService();
                $this->testResults[] = "✓ EnhancedRestoreService instanciado correctamente";
            }
            
            // Test UrlMigrator
            if (class_exists('UrlMigrator')) {
                $urlMigrator = new UrlMigrator();
                $this->testResults[] = "✓ UrlMigrator instanciado correctamente";
            }
            
            // Test SecureFileRestoreService
            if (class_exists('SecureFileRestoreService')) {
                $fileRestore = new SecureFileRestoreService();
                $this->testResults[] = "✓ SecureFileRestoreService instanciado correctamente";
            }
            
            // Test TransactionManager
            if (class_exists('TransactionManager')) {
                $transactionManager = new TransactionManager();
                $this->testResults[] = "✓ TransactionManager instanciado correctamente";
            }
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error al instanciar clases: " . $e->getMessage();
        }
        
        echo "   Funcionalidad básica verificada\n\n";
    }
    
    private function testEnvironmentDetection()
    {
        echo "3. Verificando detección de entorno...\n";
        
        try {
            // Verificar si estamos en DDEV
            $isDdev = getenv('DDEV_PROJECT') !== false || 
                     file_exists('/.ddev') || 
                     file_exists('.ddev');
            
            if ($isDdev) {
                $this->testResults[] = "✓ Entorno DDEV detectado correctamente";
            } else {
                $this->testResults[] = "ℹ Entorno no-DDEV detectado";
            }
            
            // Verificar conexión a base de datos
            if (defined('_DB_SERVER_') && defined('_DB_NAME_')) {
                $this->testResults[] = "✓ Configuración de base de datos disponible";
            } else {
                $this->testResults[] = "⚠ Configuración de base de datos no disponible";
            }
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error en detección de entorno: " . $e->getMessage();
        }
        
        echo "   Detección de entorno verificada\n\n";
    }
    
    private function showResults()
    {
        echo "=== Resultados del Test ===\n";
        foreach ($this->testResults as $result) {
            echo $result . "\n";
        }
        
        $passed = count(array_filter($this->testResults, function($r) {
            return strpos($r, '✓') === 0;
        }));
        
        $total = count($this->testResults);
        echo "\nResumen: $passed/$total tests pasaron\n";
        
        if ($passed === $total) {
            echo "🎉 ¡Todos los tests básicos pasaron!\n";
        } else {
            echo "⚠ Algunos tests fallaron o mostraron advertencias\n";
        }
    }
}

// Ejecutar el test
$test = new SimpleRestoreTest();
$test->run(); 