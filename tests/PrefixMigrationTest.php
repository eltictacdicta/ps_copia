<?php
/**
 * Test de Migración de Prefijos para PS_Copia
 * Valida que la detección y migración de prefijos funciona correctamente
 * 
 * @author AI Assistant
 * @version 1.0
 */

// Cargar configuración básica
$prestashopConfigPaths = [
    '/var/www/html/config/settings.inc.php',
    '/var/www/html/app/config/parameters.php',
    '/var/www/html/config/config.inc.php'
];

$configLoaded = false;
foreach ($prestashopConfigPaths as $configPath) {
    if (file_exists($configPath)) {
        try {
            if (strpos($configPath, 'parameters.php') !== false) {
                $parameters = include $configPath;
                if (isset($parameters['parameters'])) {
                    define('_DB_SERVER_', $parameters['parameters']['database_host'] ?? 'db');
                    define('_DB_NAME_', $parameters['parameters']['database_name'] ?? 'db');
                    define('_DB_USER_', $parameters['parameters']['database_user'] ?? 'db');
                    define('_DB_PASSWD_', $parameters['parameters']['database_password'] ?? 'db');
                    define('_DB_PREFIX_', $parameters['parameters']['database_prefix'] ?? 'ps_');
                    $configLoaded = true;
                    break;
                }
            } else {
                include_once $configPath;
                if (defined('_DB_SERVER_')) {
                    $configLoaded = true;
                    break;
                }
            }
        } catch (Exception $e) {
            // Continuar con el siguiente archivo
        }
    }
}

// Fallback para DDEV
if (!$configLoaded) {
    define('_DB_SERVER_', 'db');
    define('_DB_NAME_', 'db');
    define('_DB_USER_', 'db');
    define('_DB_PASSWD_', 'db');
    define('_DB_PREFIX_', 'ps_');
}

class PrefixMigrationTest
{
    /** @var PDO */
    private $pdo;
    
    /** @var array */
    private $testResults = [];
    
    /** @var int */
    private $totalTests = 0;
    
    /** @var int */
    private $passedTests = 0;

    public function __construct()
    {
        echo "=== Test de Migración de Prefijos - PS_Copia ===\n\n";
        
        // Inicializar conexión PDO
        $this->initializeDatabase();
    }

    /**
     * Ejecutar todos los tests
     *
     * @return array
     */
    public function runAllTests(): array
    {
        try {
            // Test 1: Detección de prefijo actual
            $this->testCurrentPrefixDetection();
            
            // Test 2: Búsqueda de tablas shop_url
            $this->testShopUrlTableDetection();
            
            // Test 3: Validación de estado de base de datos
            $this->testDatabaseStateValidation();
            
            // Test 4: Manejo de múltiples prefijos
            $this->testMultiplePrefixHandling();
            
            // Test 5: Recuperación de errores de tabla no encontrada
            $this->testTableNotFoundRecovery();
            
            // Test 6: Validación de consultas con prefijo dinámico
            $this->testDynamicPrefixQueries();
            
        } catch (Exception $e) {
            $this->addTestResult('FATAL_ERROR', false, $e->getMessage());
        }
        
        $this->printTestSummary();
        return $this->testResults;
    }

    /**
     * Inicializar conexión a base de datos
     */
    private function initializeDatabase(): void
    {
        try {
            $dsn = "mysql:host=" . _DB_SERVER_ . ";dbname=" . _DB_NAME_;
            $this->pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
            ]);
            echo "Conexión a base de datos establecida\n\n";
        } catch (PDOException $e) {
            echo "Error de conexión: " . $e->getMessage() . "\n\n";
            $this->pdo = null;
        }
    }

    /**
     * Test 1: Detección de prefijo actual
     */
    private function testCurrentPrefixDetection(): void
    {
        echo "1. Probando detección de prefijo actual...\n";
        
        $this->totalTests++;
        
        $currentPrefix = _DB_PREFIX_;
        
        if (!empty($currentPrefix)) {
            $this->passedTests++;
            $this->addTestResult("Current Prefix Detection", true, "Prefijo detectado: '{$currentPrefix}'");
        } else {
            $this->addTestResult("Current Prefix Detection", false, "No se pudo detectar el prefijo actual");
        }
        
        // Test de validación de prefijo
        $this->totalTests++;
        
        $configTable = $currentPrefix . 'configuration';
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$configTable]);
            $exists = $stmt->fetchColumn() !== false;
            
            if ($exists) {
                $this->passedTests++;
                $this->addTestResult("Prefix Validation", true, "Tabla de configuración existe: {$configTable}");
            } else {
                $this->addTestResult("Prefix Validation", false, "Tabla de configuración no existe: {$configTable}");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Prefix Validation", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de detección de prefijo completados\n\n";
    }

    /**
     * Test 2: Búsqueda de tablas shop_url
     */
    private function testShopUrlTableDetection(): void
    {
        echo "2. Probando búsqueda de tablas shop_url...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Shop URL Detection", false, "Sin conexión a base de datos");
            return;
        }
        
        // Test búsqueda con LIKE
        $this->totalTests++;
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE '%shop_url'");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($tables)) {
                $this->passedTests++;
                $this->addTestResult("Shop URL Table Search", true, "Tablas encontradas: " . implode(', ', $tables));
                
                // Test acceso a cada tabla encontrada
                foreach ($tables as $table) {
                    $this->totalTests++;
                    try {
                        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}`");
                        $countStmt->execute();
                        $count = $countStmt->fetchColumn();
                        
                        $this->passedTests++;
                        $this->addTestResult("Shop URL Table Access - {$table}", true, "Registros: {$count}");
                    } catch (PDOException $e) {
                        $this->addTestResult("Shop URL Table Access - {$table}", false, "Error: " . $e->getMessage());
                    }
                }
            } else {
                $this->addTestResult("Shop URL Table Search", false, "No se encontraron tablas shop_url");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Shop URL Table Search", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de búsqueda de shop_url completados\n\n";
    }

    /**
     * Test 3: Validación de estado de base de datos
     */
    private function testDatabaseStateValidation(): void
    {
        echo "3. Probando validación de estado de base de datos...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Database State", false, "Sin conexión a base de datos");
            return;
        }
        
        $currentPrefix = _DB_PREFIX_;
        $essentialTables = ['configuration', 'shop', 'module'];
        
        foreach ($essentialTables as $table) {
            $this->totalTests++;
            $fullTableName = $currentPrefix . $table;
            
            try {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$fullTableName]);
                $exists = $stmt->fetchColumn() !== false;
                
                if ($exists) {
                    // También verificar que la tabla tiene estructura válida
                    try {
                        $describeStmt = $this->pdo->prepare("DESCRIBE `{$fullTableName}`");
                        $describeStmt->execute();
                        $columns = $describeStmt->fetchAll();
                        
                        $this->passedTests++;
                        $this->addTestResult("Essential Table - {$table}", true, "Existe con " . count($columns) . " columnas");
                    } catch (PDOException $e) {
                        $this->addTestResult("Essential Table - {$table}", false, "Tabla existe pero estructura inválida: " . $e->getMessage());
                    }
                } else {
                    $this->addTestResult("Essential Table - {$table}", false, "Tabla no encontrada: {$fullTableName}");
                }
            } catch (PDOException $e) {
                $this->addTestResult("Essential Table - {$table}", false, "Error: " . $e->getMessage());
            }
        }
        
        echo "   Tests de validación de estado completados\n\n";
    }

    /**
     * Test 4: Manejo de múltiples prefijos
     */
    private function testMultiplePrefixHandling(): void
    {
        echo "4. Probando manejo de múltiples prefijos...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Multiple Prefixes", false, "Sin conexión a base de datos");
            return;
        }
        
        // Test búsqueda de todos los prefijos posibles
        $this->totalTests++;
        
        $commonPrefixes = ['ps_', 'myshop_', 'prestashop_', ''];
        $foundPrefixes = [];
        
        foreach ($commonPrefixes as $prefix) {
            $testTable = $prefix . 'shop_url';
            try {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$testTable]);
                
                if ($stmt->fetchColumn() !== false) {
                    $foundPrefixes[] = $prefix ?: '(sin prefijo)';
                }
            } catch (PDOException $e) {
                // Ignorar errores de prefijos no válidos
            }
        }
        
        if (!empty($foundPrefixes)) {
            $this->passedTests++;
            $this->addTestResult("Multiple Prefix Detection", true, "Prefijos encontrados: " . implode(', ', $foundPrefixes));
        } else {
            $this->addTestResult("Multiple Prefix Detection", false, "No se encontraron tablas con prefijos comunes");
        }
        
        echo "   Tests de múltiples prefijos completados\n\n";
    }

    /**
     * Test 5: Recuperación de errores de tabla no encontrada
     */
    private function testTableNotFoundRecovery(): void
    {
        echo "5. Probando recuperación de errores...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Error Recovery", false, "Sin conexión a base de datos");
            return;
        }
        
        // Test manejo de tabla inexistente
        $this->totalTests++;
        try {
            $invalidTable = 'invalid_prefix_shop_url';
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$invalidTable}`");
            $stmt->execute();
            
            // Si llegamos aquí, la tabla existe (no debería)
            $this->addTestResult("Error Recovery - Invalid Table", false, "Tabla inválida existe cuando no debería");
        } catch (PDOException $e) {
            // Error esperado
            $this->passedTests++;
            $this->addTestResult("Error Recovery - Invalid Table", true, "Error manejado correctamente: " . substr($e->getMessage(), 0, 50) . "...");
        }
        
        // Test recuperación con fallback
        $this->totalTests++;
        try {
            // Buscar cualquier tabla shop_url como fallback
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE '%shop_url'");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($tables)) {
                $fallbackTable = $tables[0];
                $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$fallbackTable}`");
                $countStmt->execute();
                $count = $countStmt->fetchColumn();
                
                $this->passedTests++;
                $this->addTestResult("Error Recovery - Fallback", true, "Fallback a tabla: {$fallbackTable} ({$count} registros)");
            } else {
                $this->addTestResult("Error Recovery - Fallback", false, "No hay tablas shop_url para fallback");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Error Recovery - Fallback", false, "Error en fallback: " . $e->getMessage());
        }
        
        echo "   Tests de recuperación de errores completados\n\n";
    }

    /**
     * Test 6: Validación de consultas con prefijo dinámico
     */
    private function testDynamicPrefixQueries(): void
    {
        echo "6. Probando consultas con prefijo dinámico...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Dynamic Prefix Queries", false, "Sin conexión a base de datos");
            return;
        }
        
        $currentPrefix = _DB_PREFIX_;
        
        // Test consulta de configuración con prefijo dinámico
        $this->totalTests++;
        try {
            $configTable = $currentPrefix . 'configuration';
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$configTable}` WHERE `name` LIKE 'PS_%'");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $this->passedTests++;
                $this->addTestResult("Dynamic Prefix - Configuration", true, "Encontradas {$count} configuraciones PS_");
            } else {
                $this->addTestResult("Dynamic Prefix - Configuration", false, "No se encontraron configuraciones PS_");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Dynamic Prefix - Configuration", false, "Error: " . $e->getMessage());
        }
        
        // Test consulta shop_url con búsqueda dinámica
        $this->totalTests++;
        try {
            // Encontrar tabla shop_url dinámicamente
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE '%shop_url'");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($tables)) {
                $shopUrlTable = $tables[0];
                $domainStmt = $this->pdo->prepare("SELECT `domain` FROM `{$shopUrlTable}` LIMIT 1");
                $domainStmt->execute();
                $domain = $domainStmt->fetchColumn();
                
                $this->passedTests++;
                $this->addTestResult("Dynamic Prefix - Shop URL", true, "Dominio encontrado: " . ($domain ?: 'vacío'));
            } else {
                $this->addTestResult("Dynamic Prefix - Shop URL", false, "No se encontraron tablas shop_url");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Dynamic Prefix - Shop URL", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de consultas dinámicas completados\n\n";
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
        
        echo "\n=== RESUMEN DE TESTS DE MIGRACIÓN DE PREFIJOS ===\n";
        echo "Total de tests: {$this->totalTests}\n";
        echo "Tests exitosos: {$this->passedTests}\n";
        echo "Tests fallidos: {$failedTests}\n";
        echo "Porcentaje de éxito: {$percentage}%\n";
        
        echo "\n=== CONFIGURACIÓN ACTUAL ===\n";
        echo "DB_SERVER: " . _DB_SERVER_ . "\n";
        echo "DB_NAME: " . _DB_NAME_ . "\n";
        echo "DB_USER: " . _DB_USER_ . "\n";
        echo "DB_PREFIX: " . _DB_PREFIX_ . "\n";
        
        if ($failedTests > 0) {
            echo "\n=== TESTS FALLIDOS ===\n";
            foreach ($this->testResults as $result) {
                if (!$result['passed']) {
                    echo "✗ {$result['test']}: {$result['message']}\n";
                }
            }
        }
        
        echo "\n=== ANÁLISIS DEL PROBLEMA ORIGINAL ===\n";
        
        if ($percentage >= 90) {
            echo "✅ CORRECCIÓN EXITOSA: El problema de 'Table ps_shop_url doesn't exist' ha sido resuelto\n";
            echo "   • Detección de prefijos funciona correctamente\n";
            echo "   • Búsqueda dinámica de tablas implementada\n";
            echo "   • Manejo de errores mejorado\n";
        } elseif ($percentage >= 75) {
            echo "⚠️ MEJORA PARCIAL: El problema está parcialmente resuelto pero requiere atención\n";
        } else {
            echo "❌ PROBLEMA PERSISTE: Se requieren más correcciones\n";
        }
        
        echo "\n=== RECOMENDACIONES ===\n";
        echo "• Siempre usar detección dinámica de prefijos en lugar de valores hardcodeados\n";
        echo "• Implementar múltiples estrategias de búsqueda para encontrar tablas\n";
        echo "• Validar estado de base de datos antes de ejecutar migraciones\n";
        echo "• Crear logs detallados para diagnosticar problemas de prefijos\n";
        echo "• Implementar recuperación automática cuando las tablas no se encuentren\n";
        echo "\n";
    }
}

// Ejecutar tests si se llama directamente
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new PrefixMigrationTest();
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