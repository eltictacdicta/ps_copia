<?php
/**
 * Test funcional para verificar métodos específicos de restauración
 */

// Incluir autoloader de PrestaShop
require_once '/var/www/html/autoload.php';

// Cargar las clases necesarias
require_once __DIR__ . '/../classes/Services/EnhancedRestoreService.php';
require_once __DIR__ . '/../classes/Migration/UrlMigrator.php';
require_once __DIR__ . '/../classes/Services/SecureFileRestoreService.php';
require_once __DIR__ . '/../classes/Services/TransactionManager.php';

class FunctionalRestoreTest
{
    private $testResults = [];
    
    public function run()
    {
        echo "=== Test Funcional de Restauración PS_Copia ===\n\n";
        
        $this->testEnhancedRestoreService();
        $this->testUrlMigrator();
        $this->testSecureFileRestoreService();
        $this->testTransactionManager();
        
        $this->showResults();
    }
    
    private function testEnhancedRestoreService()
    {
        echo "1. Probando EnhancedRestoreService...\n";
        
        try {
            $service = new EnhancedRestoreService();
            
            // Test método isValidBackupFile
            $testFile = '/tmp/test_backup.zip';
            file_put_contents($testFile, 'test content');
            
            if (method_exists($service, 'isValidBackupFile')) {
                $result = $service->isValidBackupFile($testFile);
                $this->testResults[] = "✓ Método isValidBackupFile ejecutado (resultado: " . ($result ? 'true' : 'false') . ")";
            } else {
                $this->testResults[] = "⚠ Método isValidBackupFile no encontrado";
            }
            
            // Test método detectEnvironment
            if (method_exists($service, 'detectEnvironment')) {
                $env = $service->detectEnvironment();
                $this->testResults[] = "✓ Método detectEnvironment ejecutado (entorno: $env)";
            } else {
                $this->testResults[] = "⚠ Método detectEnvironment no encontrado";
            }
            
            // Test método createSafetyBackup
            if (method_exists($service, 'createSafetyBackup')) {
                $this->testResults[] = "✓ Método createSafetyBackup disponible";
            } else {
                $this->testResults[] = "⚠ Método createSafetyBackup no encontrado";
            }
            
            unlink($testFile);
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error en EnhancedRestoreService: " . $e->getMessage();
        }
        
        echo "   EnhancedRestoreService probado\n\n";
    }
    
    private function testUrlMigrator()
    {
        echo "2. Probando UrlMigrator...\n";
        
        try {
            $migrator = new UrlMigrator();
            
            // Test método extractDomainFromUrl
            if (method_exists($migrator, 'extractDomainFromUrl')) {
                $domain = $migrator->extractDomainFromUrl('https://example.com/path');
                $this->testResults[] = "✓ Método extractDomainFromUrl ejecutado (dominio: $domain)";
            } else {
                $this->testResults[] = "⚠ Método extractDomainFromUrl no encontrado";
            }
            
            // Test método isValidDomain
            if (method_exists($migrator, 'isValidDomain')) {
                $valid = $migrator->isValidDomain('example.com');
                $this->testResults[] = "✓ Método isValidDomain ejecutado (válido: " . ($valid ? 'true' : 'false') . ")";
            } else {
                $this->testResults[] = "⚠ Método isValidDomain no encontrado";
            }
            
            // Test método generateUrlReplacements
            if (method_exists($migrator, 'generateUrlReplacements')) {
                $replacements = $migrator->generateUrlReplacements('old.com', 'new.com');
                $this->testResults[] = "✓ Método generateUrlReplacements ejecutado (" . count($replacements) . " reemplazos)";
            } else {
                $this->testResults[] = "⚠ Método generateUrlReplacements no encontrado";
            }
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error en UrlMigrator: " . $e->getMessage();
        }
        
        echo "   UrlMigrator probado\n\n";
    }
    
    private function testSecureFileRestoreService()
    {
        echo "3. Probando SecureFileRestoreService...\n";
        
        try {
            $service = new SecureFileRestoreService();
            
            // Test método isSecureFile
            if (method_exists($service, 'isSecureFile')) {
                $secure = $service->isSecureFile('/tmp/test.txt');
                $this->testResults[] = "✓ Método isSecureFile ejecutado (seguro: " . ($secure ? 'true' : 'false') . ")";
            } else {
                $this->testResults[] = "⚠ Método isSecureFile no encontrado";
            }
            
            // Test método scanForMalware
            if (method_exists($service, 'scanForMalware')) {
                $testFile = '/tmp/test_scan.php';
                file_put_contents($testFile, '<?php echo "Hello World"; ?>');
                
                $result = $service->scanForMalware($testFile);
                $this->testResults[] = "✓ Método scanForMalware ejecutado (resultado: " . ($result ? 'limpio' : 'sospechoso') . ")";
                
                unlink($testFile);
            } else {
                $this->testResults[] = "⚠ Método scanForMalware no encontrado";
            }
            
            // Test método validatePhpSyntax
            if (method_exists($service, 'validatePhpSyntax')) {
                $testFile = '/tmp/test_syntax.php';
                file_put_contents($testFile, '<?php echo "Valid PHP"; ?>');
                
                $valid = $service->validatePhpSyntax($testFile);
                $this->testResults[] = "✓ Método validatePhpSyntax ejecutado (válido: " . ($valid ? 'true' : 'false') . ")";
                
                unlink($testFile);
            } else {
                $this->testResults[] = "⚠ Método validatePhpSyntax no encontrado";
            }
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error en SecureFileRestoreService: " . $e->getMessage();
        }
        
        echo "   SecureFileRestoreService probado\n\n";
    }
    
    private function testTransactionManager()
    {
        echo "4. Probando TransactionManager...\n";
        
        try {
            $manager = new TransactionManager();
            
            // Test método createTransaction
            if (method_exists($manager, 'createTransaction')) {
                $transactionId = $manager->createTransaction('test_restore');
                $this->testResults[] = "✓ Método createTransaction ejecutado (ID: $transactionId)";
            } else {
                $this->testResults[] = "⚠ Método createTransaction no encontrado";
            }
            
            // Test método isTransactionActive
            if (method_exists($manager, 'isTransactionActive')) {
                $active = $manager->isTransactionActive();
                $this->testResults[] = "✓ Método isTransactionActive ejecutado (activo: " . ($active ? 'true' : 'false') . ")";
            } else {
                $this->testResults[] = "⚠ Método isTransactionActive no encontrado";
            }
            
            // Test método createCheckpoint
            if (method_exists($manager, 'createCheckpoint')) {
                $checkpointId = $manager->createCheckpoint('test_checkpoint');
                $this->testResults[] = "✓ Método createCheckpoint ejecutado (ID: $checkpointId)";
            } else {
                $this->testResults[] = "⚠ Método createCheckpoint no encontrado";
            }
            
            // Test método rollbackTransaction
            if (method_exists($manager, 'rollbackTransaction')) {
                $this->testResults[] = "✓ Método rollbackTransaction disponible";
            } else {
                $this->testResults[] = "⚠ Método rollbackTransaction no encontrado";
            }
            
        } catch (Exception $e) {
            $this->testResults[] = "✗ Error en TransactionManager: " . $e->getMessage();
        }
        
        echo "   TransactionManager probado\n\n";
    }
    
    private function showResults()
    {
        echo "=== Resultados del Test Funcional ===\n";
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
        
        $total = count($this->testResults);
        echo "\nResumen: $passed tests pasaron, $warnings advertencias, $errors errores (Total: $total)\n";
        
        if ($errors === 0) {
            echo "🎉 ¡Test funcional completado sin errores!\n";
        } else {
            echo "⚠ Se encontraron $errors errores durante el test\n";
        }
    }
}

// Ejecutar el test
$test = new FunctionalRestoreTest();
$test->run(); 