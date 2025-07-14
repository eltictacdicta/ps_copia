<?php
/**
 * SQL Queries Validation Test para PS_Copia
 * Valida todas las consultas SQL del módulo para seguridad, sintaxis y funcionalidad
 * 
 * @author AI Assistant
 * @version 1.0
 */

require_once dirname(__DIR__) . '/ps_copia.php';
require_once dirname(__DIR__) . '/functions.php';

class SQLQueriesValidationTest
{
    /** @var Db */
    private $db;
    
    /** @var array */
    private $testResults = [];
    
    /** @var int */
    private $totalTests = 0;
    
    /** @var int */
    private $passedTests = 0;
    
    /** @var string */
    private $testPrefix = 'test_';
    
    /** @var array */
    private $criticalQueries = [];

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->initializeCriticalQueries();
        
        echo "=== Test de Validación de Consultas SQL - PS_Copia ===\n\n";
    }

    /**
     * Ejecutar todos los tests
     *
     * @return array
     */
    public function runAllTests(): array
    {
        try {
            // Test 1: Validación de sintaxis SQL
            $this->testSQLSyntaxValidation();
            
            // Test 2: Verificación de uso de pSQL()
            $this->testPSQLUsage();
            
            // Test 3: Test de consultas de migración de URLs
            $this->testUrlMigrationQueries();
            
            // Test 4: Test de consultas de configuración
            $this->testConfigurationQueries();
            
            // Test 5: Test de consultas de prefijos
            $this->testPrefixQueries();
            
            // Test 6: Test de transacciones
            $this->testTransactionQueries();
            
            // Test 7: Test de consultas de restauración
            $this->testRestorationQueries();
            
            // Test 8: Validación de inyección SQL
            $this->testSQLInjectionProtection();
            
            // Test 9: Test de consultas de validación
            $this->testValidationQueries();
            
            // Test 10: Test de rendimiento de consultas
            $this->testQueryPerformance();
            
        } catch (Exception $e) {
            $this->addTestResult('FATAL_ERROR', false, $e->getMessage());
        }
        
        $this->printTestSummary();
        return $this->testResults;
    }

    /**
     * Inicializar lista de consultas críticas del módulo
     */
    private function initializeCriticalQueries(): void
    {
        $this->criticalQueries = [
            'shop_url_select' => "SELECT * FROM `{prefix}shop_url` LIMIT 1",
            'shop_url_update' => "UPDATE `{prefix}shop_url` SET `domain` = '{domain}', `domain_ssl` = '{domain}' WHERE 1",
            'config_select' => "SELECT `value` FROM `{prefix}configuration` WHERE `name` = '{config_key}'",
            'config_update' => "UPDATE `{prefix}configuration` SET `value` = '{value}' WHERE `name` = '{config_key}'",
            'config_insert' => "INSERT INTO `{prefix}configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('{config_key}', '{value}', NOW(), NOW())",
            'module_disable' => "UPDATE `{prefix}module` SET `active` = 0 WHERE `name` = '{module_name}'",
            'table_exists' => "SHOW TABLES LIKE '{prefix}{table_name}'",
            'prefix_detection' => "SHOW TABLES LIKE '%shop_url'",
            'backup_validation' => "SELECT COUNT(*) FROM `{prefix}shop_url`",
            'domain_validation' => "SELECT domain FROM `{prefix}shop_url` WHERE domain != '' LIMIT 1"
        ];
    }

    /**
     * Test 1: Validación de sintaxis SQL
     */
    private function testSQLSyntaxValidation(): void
    {
        echo "1. Validando sintaxis de consultas SQL...\n";
        
        foreach ($this->criticalQueries as $queryName => $queryTemplate) {
            $this->totalTests++;
            
            try {
                // Preparar consulta con valores de test
                $query = $this->prepareMockQuery($queryTemplate);
                
                // Intentar preparar la consulta para validar sintaxis
                $stmt = $this->db->prepare($query);
                
                if ($stmt !== false) {
                    $this->passedTests++;
                    $this->addTestResult("SQL Syntax - {$queryName}", true, "Sintaxis válida");
                } else {
                    $this->addTestResult("SQL Syntax - {$queryName}", false, "Error de sintaxis SQL");
                }
                
            } catch (Exception $e) {
                $this->addTestResult("SQL Syntax - {$queryName}", false, "Error: " . $e->getMessage());
            }
        }
        
        echo "   Validación de sintaxis completada\n\n";
    }

    /**
     * Test 2: Verificación de uso de pSQL()
     */
    private function testPSQLUsage(): void
    {
        echo "2. Verificando uso correcto de pSQL()...\n";
        
        $files = [
            'classes/Migration/DatabaseMigrator.php',
            'classes/Services/RestoreService.php',
            'classes/Services/EnhancedRestoreService.php',
            'classes/Migration/UrlMigrator.php'
        ];
        
        foreach ($files as $file) {
            $this->totalTests++;
            $filePath = dirname(__DIR__) . '/' . $file;
            
            if (!file_exists($filePath)) {
                $this->addTestResult("pSQL Usage - {$file}", false, "Archivo no encontrado");
                continue;
            }
            
            $content = file_get_contents($filePath);
            
            // Buscar variables directamente concatenadas en SQL sin pSQL()
            $unsafePatterns = [
                '/\$[a-zA-Z_][a-zA-Z0-9_]*[\'"]/',  // Variables en strings sin pSQL
                '/\'\s*\.\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*\./',  // Concatenación directa
            ];
            
            $hasUnsafeUsage = false;
            foreach ($unsafePatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $hasUnsafeUsage = true;
                    break;
                }
            }
            
            if (!$hasUnsafeUsage) {
                $this->passedTests++;
                $this->addTestResult("pSQL Usage - {$file}", true, "Uso correcto de pSQL()");
            } else {
                $this->addTestResult("pSQL Usage - {$file}", false, "Posible uso inseguro detectado");
            }
        }
        
        echo "   Verificación de pSQL() completada\n\n";
    }

    /**
     * Test 3: Test de consultas de migración de URLs
     */
    private function testUrlMigrationQueries(): void
    {
        echo "3. Probando consultas de migración de URLs...\n";
        
        // Test UPDATE shop_url
        $this->totalTests++;
        try {
            $testDomain = 'test.local';
            $prefix = _DB_PREFIX_;
            
            // Verificar que la tabla shop_url existe
            $tableExists = $this->db->executeS("SHOW TABLES LIKE '{$prefix}shop_url'");
            
            if (!empty($tableExists)) {
                // Preparar consulta de test (sin ejecutar)
                $query = "UPDATE `{$prefix}shop_url` SET `domain` = '" . pSQL($testDomain) . "' WHERE 1=0";
                $stmt = $this->db->prepare($query);
                
                if ($stmt !== false) {
                    $this->passedTests++;
                    $this->addTestResult("URL Migration - shop_url update", true, "Consulta válida");
                } else {
                    $this->addTestResult("URL Migration - shop_url update", false, "Error en preparación");
                }
            } else {
                $this->addTestResult("URL Migration - shop_url update", false, "Tabla shop_url no existe");
            }
        } catch (Exception $e) {
            $this->addTestResult("URL Migration - shop_url update", false, "Error: " . $e->getMessage());
        }
        
        // Test SELECT domain from shop_url
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $query = "SELECT domain FROM `{$prefix}shop_url` LIMIT 1";
            $result = $this->db->executeS($query);
            
            $this->passedTests++;
            $this->addTestResult("URL Migration - domain select", true, "Consulta ejecutada correctamente");
        } catch (Exception $e) {
            $this->addTestResult("URL Migration - domain select", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de migración de URLs completados\n\n";
    }

    /**
     * Test 4: Test de consultas de configuración
     */
    private function testConfigurationQueries(): void
    {
        echo "4. Probando consultas de configuración...\n";
        
        // Test SELECT configuration
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $query = "SELECT `value` FROM `{$prefix}configuration` WHERE `name` = 'PS_SHOP_DOMAIN'";
            $result = $this->db->executeS($query);
            
            $this->passedTests++;
            $this->addTestResult("Configuration - PS_SHOP_DOMAIN select", true, "Consulta ejecutada");
        } catch (Exception $e) {
            $this->addTestResult("Configuration - PS_SHOP_DOMAIN select", false, "Error: " . $e->getMessage());
        }
        
        // Test UPDATE configuration (sin ejecutar realmente)
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $testValue = 'test.local';
            $query = "UPDATE `{$prefix}configuration` SET `value` = '" . pSQL($testValue) . "' WHERE `name` = 'PS_SHOP_DOMAIN' AND 1=0";
            $stmt = $this->db->prepare($query);
            
            if ($stmt !== false) {
                $this->passedTests++;
                $this->addTestResult("Configuration - UPDATE test", true, "Sintaxis válida");
            } else {
                $this->addTestResult("Configuration - UPDATE test", false, "Error de sintaxis");
            }
        } catch (Exception $e) {
            $this->addTestResult("Configuration - UPDATE test", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de configuración completados\n\n";
    }

    /**
     * Test 5: Test de consultas de prefijos
     */
    private function testPrefixQueries(): void
    {
        echo "5. Probando consultas de detección de prefijos...\n";
        
        // Test SHOW TABLES
        $this->totalTests++;
        try {
            $query = "SHOW TABLES LIKE '%shop_url'";
            $result = $this->db->executeS($query);
            
            $this->passedTests++;
            $this->addTestResult("Prefix Detection - show tables", true, "Consulta ejecutada - " . count($result) . " resultados");
        } catch (Exception $e) {
            $this->addTestResult("Prefix Detection - show tables", false, "Error: " . $e->getMessage());
        }
        
        // Test detección de prefijo actual
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $query = "SELECT COUNT(*) as count FROM `{$prefix}shop_url`";
            $result = $this->db->getValue($query);
            
            $this->passedTests++;
            $this->addTestResult("Prefix Detection - current prefix", true, "Prefix actual válido: {$prefix}");
        } catch (Exception $e) {
            $this->addTestResult("Prefix Detection - current prefix", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de prefijos completados\n\n";
    }

    /**
     * Test 6: Test de transacciones
     */
    private function testTransactionQueries(): void
    {
        echo "6. Probando consultas de transacciones...\n";
        
        // Test START TRANSACTION
        $this->totalTests++;
        try {
            $this->db->execute("START TRANSACTION");
            $this->db->execute("ROLLBACK");
            
            $this->passedTests++;
            $this->addTestResult("Transaction - START/ROLLBACK", true, "Transacciones funcionan correctamente");
        } catch (Exception $e) {
            $this->addTestResult("Transaction - START/ROLLBACK", false, "Error: " . $e->getMessage());
        }
        
        // Test COMMIT
        $this->totalTests++;
        try {
            $this->db->execute("START TRANSACTION");
            $this->db->execute("COMMIT");
            
            $this->passedTests++;
            $this->addTestResult("Transaction - COMMIT", true, "COMMIT funciona correctamente");
        } catch (Exception $e) {
            $this->addTestResult("Transaction - COMMIT", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de transacciones completados\n\n";
    }

    /**
     * Test 7: Test de consultas de restauración
     */
    private function testRestorationQueries(): void
    {
        echo "7. Probando consultas de restauración...\n";
        
        // Test DROP TABLE IF EXISTS (sin ejecutar)
        $this->totalTests++;
        try {
            $query = "DROP TABLE IF EXISTS `test_dummy_table_that_does_not_exist`";
            $stmt = $this->db->prepare($query);
            
            if ($stmt !== false) {
                $this->passedTests++;
                $this->addTestResult("Restoration - DROP TABLE syntax", true, "Sintaxis válida");
            } else {
                $this->addTestResult("Restoration - DROP TABLE syntax", false, "Error de sintaxis");
            }
        } catch (Exception $e) {
            $this->addTestResult("Restoration - DROP TABLE syntax", false, "Error: " . $e->getMessage());
        }
        
        // Test verificación de tablas esenciales
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $essentialTables = ['shop_url', 'configuration', 'module'];
            $allExist = true;
            
            foreach ($essentialTables as $table) {
                $query = "SHOW TABLES LIKE '{$prefix}{$table}'";
                $result = $this->db->executeS($query);
                if (empty($result)) {
                    $allExist = false;
                    break;
                }
            }
            
            if ($allExist) {
                $this->passedTests++;
                $this->addTestResult("Restoration - essential tables", true, "Todas las tablas esenciales existen");
            } else {
                $this->addTestResult("Restoration - essential tables", false, "Faltan tablas esenciales");
            }
        } catch (Exception $e) {
            $this->addTestResult("Restoration - essential tables", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de restauración completados\n\n";
    }

    /**
     * Test 8: Validación de protección contra inyección SQL
     */
    private function testSQLInjectionProtection(): void
    {
        echo "8. Probando protección contra inyección SQL...\n";
        
        // Test con entrada maliciosa
        $this->totalTests++;
        try {
            $maliciousInput = "'; DROP TABLE users; --";
            $safeInput = pSQL($maliciousInput);
            
            // Verificar que pSQL() escapó correctamente
            if (strpos($safeInput, 'DROP') === false || strpos($safeInput, '--') === false) {
                $this->passedTests++;
                $this->addTestResult("SQL Injection - pSQL protection", true, "pSQL() protege contra inyección");
            } else {
                $this->addTestResult("SQL Injection - pSQL protection", false, "pSQL() no escapó correctamente");
            }
        } catch (Exception $e) {
            $this->addTestResult("SQL Injection - pSQL protection", false, "Error: " . $e->getMessage());
        }
        
        // Test de escape de caracteres especiales
        $this->totalTests++;
        try {
            $specialChars = "test'\"\\;()";
            $escaped = pSQL($specialChars);
            
            if ($escaped !== $specialChars) {
                $this->passedTests++;
                $this->addTestResult("SQL Injection - character escaping", true, "Caracteres especiales escapados");
            } else {
                $this->addTestResult("SQL Injection - character escaping", false, "Caracteres no escapados");
            }
        } catch (Exception $e) {
            $this->addTestResult("SQL Injection - character escaping", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de protección SQL completados\n\n";
    }

    /**
     * Test 9: Test de consultas de validación
     */
    private function testValidationQueries(): void
    {
        echo "9. Probando consultas de validación...\n";
        
        // Test validación de dominio
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $query = "SELECT domain FROM `{$prefix}shop_url` WHERE domain != '' AND domain IS NOT NULL LIMIT 1";
            $result = $this->db->getValue($query);
            
            $this->passedTests++;
            $currentDomain = $result ?: 'No configurado';
            $this->addTestResult("Validation - domain check", true, "Dominio actual: {$currentDomain}");
        } catch (Exception $e) {
            $this->addTestResult("Validation - domain check", false, "Error: " . $e->getMessage());
        }
        
        // Test COUNT para verificación de integridad
        $this->totalTests++;
        try {
            $prefix = _DB_PREFIX_;
            $query = "SELECT COUNT(*) FROM `{$prefix}configuration` WHERE name LIKE 'PS_%'";
            $count = $this->db->getValue($query);
            
            $this->passedTests++;
            $this->addTestResult("Validation - config count", true, "Configuraciones PS_: {$count}");
        } catch (Exception $e) {
            $this->addTestResult("Validation - config count", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de validación completados\n\n";
    }

    /**
     * Test 10: Test de rendimiento de consultas
     */
    private function testQueryPerformance(): void
    {
        echo "10. Probando rendimiento de consultas...\n";
        
        // Test rendimiento SELECT básico
        $this->totalTests++;
        try {
            $startTime = microtime(true);
            $prefix = _DB_PREFIX_;
            $query = "SELECT COUNT(*) FROM `{$prefix}configuration`";
            $result = $this->db->getValue($query);
            $endTime = microtime(true);
            
            $executionTime = ($endTime - $startTime) * 1000; // ms
            
            if ($executionTime < 100) { // Menos de 100ms
                $this->passedTests++;
                $this->addTestResult("Performance - basic SELECT", true, sprintf("Ejecutado en %.2fms", $executionTime));
            } else {
                $this->addTestResult("Performance - basic SELECT", false, sprintf("Lento: %.2fms", $executionTime));
            }
        } catch (Exception $e) {
            $this->addTestResult("Performance - basic SELECT", false, "Error: " . $e->getMessage());
        }
        
        // Test rendimiento SHOW TABLES
        $this->totalTests++;
        try {
            $startTime = microtime(true);
            $query = "SHOW TABLES";
            $result = $this->db->executeS($query);
            $endTime = microtime(true);
            
            $executionTime = ($endTime - $startTime) * 1000; // ms
            
            if ($executionTime < 50) { // Menos de 50ms
                $this->passedTests++;
                $this->addTestResult("Performance - SHOW TABLES", true, sprintf("Ejecutado en %.2fms", $executionTime));
            } else {
                $this->addTestResult("Performance - SHOW TABLES", false, sprintf("Lento: %.2fms", $executionTime));
            }
        } catch (Exception $e) {
            $this->addTestResult("Performance - SHOW TABLES", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de rendimiento completados\n\n";
    }

    /**
     * Preparar consulta mock para testing
     */
    private function prepareMockQuery(string $queryTemplate): string
    {
        $replacements = [
            '{prefix}' => _DB_PREFIX_,
            '{domain}' => 'test.local',
            '{config_key}' => 'PS_SHOP_DOMAIN',
            '{value}' => 'test_value',
            '{module_name}' => 'test_module',
            '{table_name}' => 'configuration'
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $queryTemplate);
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
        
        echo "\n=== RESUMEN DE TESTS SQL ===\n";
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
        
        if ($percentage >= 90) {
            echo "✓ Excelente: Las consultas SQL están bien implementadas\n";
        } elseif ($percentage >= 80) {
            echo "⚠ Bueno: Algunas consultas necesitan revisión\n";
        } elseif ($percentage >= 70) {
            echo "⚠ Regular: Se requieren mejoras en las consultas SQL\n";
        } else {
            echo "✗ Crítico: Se requiere revisión completa de las consultas SQL\n";
        }
        
        echo "\n- Todas las consultas deben usar pSQL() para prevenir inyección SQL\n";
        echo "- Verificar que las transacciones se manejen correctamente\n";
        echo "- Optimizar consultas que tomen más de 100ms\n";
        echo "- Validar existencia de tablas antes de realizar consultas\n";
        echo "\n";
    }
}

// Ejecutar tests si se llama directamente
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new SQLQueriesValidationTest();
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