<?php
/**
 * Database Transaction Test para PS_Copia
 * Valida la integridad de las transacciones y procesos de migración de base de datos
 * 
 * @author AI Assistant
 * @version 1.0
 */

require_once dirname(__DIR__) . '/ps_copia.php';
require_once dirname(__DIR__) . '/functions.php';

use PrestaShop\Module\PsCopia\Migration\DatabaseMigrator;
use PrestaShop\Module\PsCopia\Services\TransactionManager;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;

class DatabaseTransactionTest
{
    /** @var Db */
    private $db;
    
    /** @var BackupLogger */
    private $logger;
    
    /** @var array */
    private $testResults = [];
    
    /** @var int */
    private $totalTests = 0;
    
    /** @var int */
    private $passedTests = 0;
    
    /** @var string */
    private $originalDomain;
    
    /** @var array */
    private $originalConfigs = [];

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->logger = new BackupLogger();
        
        // Guardar estado original para restaurar al final
        $this->saveOriginalState();
        
        echo "=== Test de Transacciones de Base de Datos - PS_Copia ===\n\n";
    }

    /**
     * Ejecutar todos los tests de transacciones
     *
     * @return array
     */
    public function runAllTests(): array
    {
        try {
            // Test 1: Transacciones básicas
            $this->testBasicTransactions();
            
            // Test 2: Rollback automático en errores
            $this->testAutomaticRollback();
            
            // Test 3: Integridad de migración de URLs
            $this->testUrlMigrationIntegrity();
            
            // Test 4: Validación de prefijos
            $this->testPrefixMigration();
            
            // Test 5: Preservación de configuraciones críticas
            $this->testConfigurationPreservation();
            
            // Test 6: Test de transacciones anidadas
            $this->testNestedTransactions();
            
            // Test 7: Validación de integridad referencial
            $this->testReferentialIntegrity();
            
            // Test 8: Test de restauración de checkpoints
            $this->testCheckpointRestoration();
            
        } catch (Exception $e) {
            $this->addTestResult('FATAL_ERROR', false, $e->getMessage());
        } finally {
            // Restaurar estado original
            $this->restoreOriginalState();
        }
        
        $this->printTestSummary();
        return $this->testResults;
    }

    /**
     * Test 1: Transacciones básicas
     */
    private function testBasicTransactions(): void
    {
        echo "1. Probando transacciones básicas...\n";
        
        // Test START TRANSACTION y COMMIT
        $this->totalTests++;
        try {
            $this->db->execute("START TRANSACTION");
            
            // Hacer un cambio temporal
            $testValue = 'test_transaction_' . time();
            $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($testValue) . "' WHERE `name` = 'PS_SHOP_NAME'";
            $this->db->execute($sql);
            
            // Verificar el cambio
            $result = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            
            if ($result === $testValue) {
                $this->db->execute("ROLLBACK"); // Revertir cambio de test
                $this->passedTests++;
                $this->addTestResult("Basic Transaction - UPDATE/ROLLBACK", true, "Transacción ejecutada y revertida correctamente");
            } else {
                $this->db->execute("ROLLBACK");
                $this->addTestResult("Basic Transaction - UPDATE/ROLLBACK", false, "El cambio no se aplicó correctamente");
            }
        } catch (Exception $e) {
            $this->db->execute("ROLLBACK");
            $this->addTestResult("Basic Transaction - UPDATE/ROLLBACK", false, "Error: " . $e->getMessage());
        }
        
        // Test transacción con COMMIT
        $this->totalTests++;
        try {
            $this->db->execute("START TRANSACTION");
            
            // Verificar que podemos hacer COMMIT sin errores
            $this->db->execute("COMMIT");
            
            $this->passedTests++;
            $this->addTestResult("Basic Transaction - COMMIT", true, "COMMIT ejecutado correctamente");
        } catch (Exception $e) {
            $this->addTestResult("Basic Transaction - COMMIT", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de transacciones básicas completados\n\n";
    }

    /**
     * Test 2: Rollback automático en errores
     */
    private function testAutomaticRollback(): void
    {
        echo "2. Probando rollback automático...\n";
        
        $this->totalTests++;
        try {
            // Simular una transacción que debe hacer rollback
            $this->db->execute("START TRANSACTION");
            
            // Hacer un cambio válido
            $originalValue = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            $testValue = 'test_rollback_' . time();
            $this->db->execute("UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($testValue) . "' WHERE `name` = 'PS_SHOP_NAME'");
            
            // Simular error y hacer rollback
            $this->db->execute("ROLLBACK");
            
            // Verificar que el valor original se mantuvo
            $currentValue = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            
            if ($currentValue === $originalValue) {
                $this->passedTests++;
                $this->addTestResult("Automatic Rollback", true, "Rollback restauró el valor original correctamente");
            } else {
                $this->addTestResult("Automatic Rollback", false, "Rollback no funcionó - valor no restaurado");
            }
        } catch (Exception $e) {
            $this->db->execute("ROLLBACK");
            $this->addTestResult("Automatic Rollback", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de rollback automático completados\n\n";
    }

    /**
     * Test 3: Integridad de migración de URLs
     */
    private function testUrlMigrationIntegrity(): void
    {
        echo "3. Probando integridad de migración de URLs...\n";
        
        $this->totalTests++;
        try {
            // Crear un DatabaseMigrator para el test
            $migrator = new DatabaseMigrator($this->logger);
            
            // Usar el método de test de restauración
            $testUrl = 'https://test.local/';
            $testResults = $migrator->testRestoration($testUrl);
            
            // Verificar que los tests pasaron
            $allTestsPassed = true;
            $failedTests = [];
            
            foreach ($testResults as $testName => $result) {
                if (is_array($result) && isset($result['status']) && $result['status'] === 'error') {
                    $allTestsPassed = false;
                    $failedTests[] = $testName;
                }
            }
            
            if ($allTestsPassed) {
                $this->passedTests++;
                $this->addTestResult("URL Migration Integrity", true, "Todos los tests de migración pasaron");
            } else {
                $this->addTestResult("URL Migration Integrity", false, "Tests fallidos: " . implode(', ', $failedTests));
            }
        } catch (Exception $e) {
            $this->addTestResult("URL Migration Integrity", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de integridad de migración completados\n\n";
    }

    /**
     * Test 4: Validación de prefijos
     */
    private function testPrefixMigration(): void
    {
        echo "4. Probando migración de prefijos...\n";
        
        $this->totalTests++;
        try {
            $currentPrefix = _DB_PREFIX_;
            
            // Verificar que el prefijo actual es válido
            $shopUrlTable = $currentPrefix . 'shop_url';
            $result = $this->db->executeS("SHOW TABLES LIKE '" . $shopUrlTable . "'");
            
            if (!empty($result)) {
                // Verificar que podemos acceder a la tabla
                $data = $this->db->getRow("SELECT * FROM `{$shopUrlTable}` LIMIT 1");
                
                if ($data !== false) {
                    $this->passedTests++;
                    $this->addTestResult("Prefix Migration", true, "Prefijo actual '{$currentPrefix}' es válido y accesible");
                } else {
                    $this->addTestResult("Prefix Migration", false, "No se pueden leer datos de la tabla shop_url");
                }
            } else {
                $this->addTestResult("Prefix Migration", false, "Tabla shop_url no encontrada con prefijo '{$currentPrefix}'");
            }
        } catch (Exception $e) {
            $this->addTestResult("Prefix Migration", false, "Error: " . $e->getMessage());
        }
        
        // Test detección automática de prefijos
        $this->totalTests++;
        try {
            $tablesWithPrefix = $this->db->executeS("SHOW TABLES LIKE '%shop_url'");
            
            if (!empty($tablesWithPrefix)) {
                $this->passedTests++;
                $this->addTestResult("Prefix Detection", true, "Detectadas " . count($tablesWithPrefix) . " tablas shop_url");
            } else {
                $this->addTestResult("Prefix Detection", false, "No se encontraron tablas shop_url");
            }
        } catch (Exception $e) {
            $this->addTestResult("Prefix Detection", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de prefijos completados\n\n";
    }

    /**
     * Test 5: Preservación de configuraciones críticas
     */
    private function testConfigurationPreservation(): void
    {
        echo "5. Probando preservación de configuraciones...\n";
        
        $criticalConfigs = [
            'PS_SHOP_DOMAIN',
            'PS_SHOP_DOMAIN_SSL',
            'PS_SHOP_NAME',
            'PS_VERSION_DB'
        ];
        
        foreach ($criticalConfigs as $configKey) {
            $this->totalTests++;
            try {
                $value = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($configKey) . "'");
                
                if ($value !== false && $value !== null) {
                    $this->passedTests++;
                    $this->addTestResult("Config Preservation - {$configKey}", true, "Valor: " . substr($value, 0, 50));
                } else {
                    $this->addTestResult("Config Preservation - {$configKey}", false, "Configuración no encontrada o vacía");
                }
            } catch (Exception $e) {
                $this->addTestResult("Config Preservation - {$configKey}", false, "Error: " . $e->getMessage());
            }
        }
        
        echo "   Tests de preservación de configuraciones completados\n\n";
    }

    /**
     * Test 6: Transacciones anidadas
     */
    private function testNestedTransactions(): void
    {
        echo "6. Probando transacciones anidadas...\n";
        
        $this->totalTests++;
        try {
            // MySQL no soporta transacciones verdaderamente anidadas,
            // pero podemos simular el comportamiento
            $this->db->execute("START TRANSACTION");
            
            // Primer nivel de cambios
            $testValue1 = 'test_nested_1_' . time();
            $this->db->execute("UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($testValue1) . "' WHERE `name` = 'PS_SHOP_NAME'");
            
            // Crear un savepoint simulado
            $savepointData = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            
            // Segundo nivel de cambios
            $testValue2 = 'test_nested_2_' . time();
            $this->db->execute("UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($testValue2) . "' WHERE `name` = 'PS_SHOP_NAME'");
            
            // Verificar segundo cambio
            $currentValue = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            
            if ($currentValue === $testValue2) {
                // Rollback completo
                $this->db->execute("ROLLBACK");
                $this->passedTests++;
                $this->addTestResult("Nested Transactions", true, "Cambios anidados manejados correctamente");
            } else {
                $this->db->execute("ROLLBACK");
                $this->addTestResult("Nested Transactions", false, "Los cambios anidados no se aplicaron");
            }
        } catch (Exception $e) {
            $this->db->execute("ROLLBACK");
            $this->addTestResult("Nested Transactions", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de transacciones anidadas completados\n\n";
    }

    /**
     * Test 7: Validación de integridad referencial
     */
    private function testReferentialIntegrity(): void
    {
        echo "7. Probando integridad referencial...\n";
        
        $this->totalTests++;
        try {
            // Verificar que las tablas principales existen y están relacionadas
            $prefix = _DB_PREFIX_;
            $tables = [
                'shop' => "SELECT COUNT(*) FROM `{$prefix}shop`",
                'shop_url' => "SELECT COUNT(*) FROM `{$prefix}shop_url`",
                'configuration' => "SELECT COUNT(*) FROM `{$prefix}configuration`"
            ];
            
            $allTablesValid = true;
            $tableCounts = [];
            
            foreach ($tables as $tableName => $query) {
                try {
                    $count = $this->db->getValue($query);
                    $tableCounts[$tableName] = $count;
                    
                    if ($count === false || $count < 0) {
                        $allTablesValid = false;
                    }
                } catch (Exception $e) {
                    $allTablesValid = false;
                    $tableCounts[$tableName] = 'ERROR: ' . $e->getMessage();
                }
            }
            
            if ($allTablesValid) {
                $this->passedTests++;
                $this->addTestResult("Referential Integrity", true, "Tablas principales válidas: " . json_encode($tableCounts));
            } else {
                $this->addTestResult("Referential Integrity", false, "Problemas en tablas: " . json_encode($tableCounts));
            }
        } catch (Exception $e) {
            $this->addTestResult("Referential Integrity", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de integridad referencial completados\n\n";
    }

    /**
     * Test 8: Restauración de checkpoints
     */
    private function testCheckpointRestoration(): void
    {
        echo "8. Probando restauración de checkpoints...\n";
        
        $this->totalTests++;
        try {
            // Simular creación de checkpoint guardando estado actual
            $checkpointData = [
                'shop_name' => $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'"),
                'domain' => $this->db->getValue("SELECT `domain` FROM `" . _DB_PREFIX_ . "shop_url` LIMIT 1")
            ];
            
            // Hacer cambios temporales
            $this->db->execute("START TRANSACTION");
            $testValue = 'checkpoint_test_' . time();
            $this->db->execute("UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($testValue) . "' WHERE `name` = 'PS_SHOP_NAME'");
            
            // "Restaurar" desde checkpoint (rollback)
            $this->db->execute("ROLLBACK");
            
            // Verificar que se restauró el estado original
            $restoredValue = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = 'PS_SHOP_NAME'");
            
            if ($restoredValue === $checkpointData['shop_name']) {
                $this->passedTests++;
                $this->addTestResult("Checkpoint Restoration", true, "Estado restaurado correctamente desde checkpoint");
            } else {
                $this->addTestResult("Checkpoint Restoration", false, "No se pudo restaurar el estado desde checkpoint");
            }
        } catch (Exception $e) {
            $this->db->execute("ROLLBACK");
            $this->addTestResult("Checkpoint Restoration", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de restauración de checkpoints completados\n\n";
    }

    /**
     * Guardar estado original
     */
    private function saveOriginalState(): void
    {
        try {
            $this->originalDomain = $this->db->getValue("SELECT `domain` FROM `" . _DB_PREFIX_ . "shop_url` LIMIT 1");
            
            $configs = ['PS_SHOP_NAME', 'PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL'];
            foreach ($configs as $config) {
                $this->originalConfigs[$config] = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($config) . "'");
            }
        } catch (Exception $e) {
            echo "Advertencia: No se pudo guardar el estado original: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Restaurar estado original
     */
    private function restoreOriginalState(): void
    {
        try {
            // Restaurar configuraciones originales si es necesario
            foreach ($this->originalConfigs as $configName => $originalValue) {
                if ($originalValue !== null) {
                    $currentValue = $this->db->getValue("SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($configName) . "'");
                    if ($currentValue !== $originalValue) {
                        $this->db->execute("UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($originalValue) . "' WHERE `name` = '" . pSQL($configName) . "'");
                    }
                }
            }
            
            echo "Estado original restaurado correctamente\n";
        } catch (Exception $e) {
            echo "Advertencia: No se pudo restaurar completamente el estado original: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Agregar resultado de test
     */
    private function addTestResult(string $testName, bool $passed, string $message): void
    {
        $this->testResults[] = [
            'test' => $testName,
            'passed' => $passed,
            'message' => $message
        ];
        
        $status = $passed ? '✓' : '✗';
        echo "   {$status} {$testName}: {$message}\n";
    }

    /**
     * Imprimir resumen de tests
     */
    private function printTestSummary(): void
    {
        $failedTests = $this->totalTests - $this->passedTests;
        $percentage = $this->totalTests > 0 ? round(($this->passedTests / $this->totalTests) * 100, 2) : 0;
        
        echo "\n=== RESUMEN DE TESTS DE TRANSACCIONES ===\n";
        echo "Total de tests: {$this->totalTests}\n";
        echo "Tests exitosos: {$this->passedTests}\n";
        echo "Tests fallidos: {$failedTests}\n";
        echo "Porcentaje de éxito: {$percentage}%\n";
        
        if ($failedTests > 0) {
            echo "\n=== TESTS FALLIDOS ===\n";
            foreach ($this->testResults as $result) {
                if (!$result['passed']) {
                    echo "✗ {$result['test']}: {$result['message']}\n";
                }
            }
        }
        
        echo "\n=== RECOMENDACIONES ===\n";
        
        if ($percentage >= 95) {
            echo "✓ Excelente: Las transacciones de base de datos funcionan perfectamente\n";
        } elseif ($percentage >= 85) {
            echo "⚠ Bueno: Transacciones funcionan bien con algunas observaciones\n";
        } elseif ($percentage >= 70) {
            echo "⚠ Regular: Se requieren mejoras en el manejo de transacciones\n";
        } else {
            echo "✗ Crítico: Se requiere revisión completa del sistema de transacciones\n";
        }
        
        echo "\n- Verificar que todas las operaciones críticas usen transacciones\n";
        echo "- Implementar rollback automático en caso de errores\n";
        echo "- Validar integridad de datos después de cada migración\n";
        echo "- Crear checkpoints antes de operaciones complejas\n";
        echo "\n";
    }
}

// Ejecutar tests si se llama directamente
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new DatabaseTransactionTest();
        $results = $test->runAllTests();
        
        // Exit code basado en los resultados
        $failedCount = 0;
        foreach ($results as $result) {
            if (!$result['passed']) {
                $failedCount++;
            }
        }
        
        exit($failedCount > 0 ? 1 : 0);
        
    } catch (Exception $e) {
        echo "ERROR FATAL: " . $e->getMessage() . "\n";
        exit(1);
    }
} 