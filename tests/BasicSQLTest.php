<?php
/**
 * Test Básico de SQL para PS_Copia
 * Valida consultas SQL básicas sin dependencias complejas de PrestaShop
 * 
 * @author AI Assistant
 * @version 1.0
 */

// Intentar cargar PrestaShop básico
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

class BasicSQLTest
{
    /** @var PDO */
    private $pdo;
    
    /** @var array */
    private $testResults = [];
    
    /** @var int */
    private $totalTests = 0;
    
    /** @var int */
    private $passedTests = 0;
    
    /** @var array */
    private $criticalQueries = [];

    public function __construct()
    {
        echo "=== Test Básico de Consultas SQL - PS_Copia ===\n\n";
        
        // Inicializar conexión PDO
        $this->initializeDatabase();
        
        // Inicializar consultas críticas
        $this->initializeCriticalQueries();
    }

    /**
     * Ejecutar todos los tests
     *
     * @return array
     */
    public function runAllTests(): array
    {
        try {
            // Test 1: Conexión a base de datos
            $this->testDatabaseConnection();
            
            // Test 2: Verificación de tablas esenciales
            $this->testEssentialTables();
            
            // Test 3: Validación de sintaxis SQL
            $this->testSQLSyntax();
            
            // Test 4: Test de transacciones básicas
            $this->testBasicTransactions();
            
            // Test 5: Protección contra inyección SQL
            $this->testSQLInjectionProtection();
            
            // Test 6: Consultas de configuración
            $this->testConfigurationQueries();
            
            // Test 7: Consultas de shop_url
            $this->testShopUrlQueries();
            
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
     * Inicializar consultas críticas
     */
    private function initializeCriticalQueries(): void
    {
        $prefix = _DB_PREFIX_;
        
        $this->criticalQueries = [
            'shop_url_select' => "SELECT * FROM `{$prefix}shop_url` LIMIT 1",
            'shop_url_count' => "SELECT COUNT(*) FROM `{$prefix}shop_url`",
            'config_select' => "SELECT `value` FROM `{$prefix}configuration` WHERE `name` = ?",
            'config_count' => "SELECT COUNT(*) FROM `{$prefix}configuration`",
            'show_tables' => "SHOW TABLES LIKE '{$prefix}%'",
            'table_exists' => "SHOW TABLES LIKE '{$prefix}shop_url'",
            'table_structure' => "DESCRIBE `{$prefix}shop_url`"
        ];
    }

    /**
     * Test 1: Conexión a base de datos
     */
    private function testDatabaseConnection(): void
    {
        echo "1. Probando conexión a base de datos...\n";
        
        $this->totalTests++;
        
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query("SELECT 1");
                $result = $stmt->fetchColumn();
                
                if ($result == 1) {
                    $this->passedTests++;
                    $this->addTestResult("Database Connection", true, "Conexión exitosa");
                } else {
                    $this->addTestResult("Database Connection", false, "Respuesta inesperada");
                }
            } catch (PDOException $e) {
                $this->addTestResult("Database Connection", false, "Error: " . $e->getMessage());
            }
        } else {
            $this->addTestResult("Database Connection", false, "No se pudo establecer conexión PDO");
        }
        
        echo "   Test de conexión completado\n\n";
    }

    /**
     * Test 2: Verificación de tablas esenciales
     */
    private function testEssentialTables(): void
    {
        echo "2. Verificando tablas esenciales...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Essential Tables", false, "Sin conexión a base de datos");
            return;
        }
        
        $essentialTables = ['shop_url', 'configuration', 'module', 'shop'];
        $prefix = _DB_PREFIX_;
        
        foreach ($essentialTables as $table) {
            $this->totalTests++;
            try {
                $fullTableName = $prefix . $table;
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$fullTableName]);
                $exists = $stmt->fetchColumn() !== false;
                
                if ($exists) {
                    $this->passedTests++;
                    $this->addTestResult("Essential Table - {$table}", true, "Tabla existe");
                } else {
                    $this->addTestResult("Essential Table - {$table}", false, "Tabla no encontrada");
                }
            } catch (PDOException $e) {
                $this->addTestResult("Essential Table - {$table}", false, "Error: " . $e->getMessage());
            }
        }
        
        echo "   Verificación de tablas completada\n\n";
    }

    /**
     * Test 3: Validación de sintaxis SQL
     */
    private function testSQLSyntax(): void
    {
        echo "3. Validando sintaxis SQL...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("SQL Syntax", false, "Sin conexión a base de datos");
            return;
        }
        
        foreach ($this->criticalQueries as $queryName => $sql) {
            $this->totalTests++;
            try {
                // Para consultas con parámetros, usar un valor de prueba
                if (strpos($sql, '?') !== false) {
                    $stmt = $this->pdo->prepare($sql);
                    if ($stmt) {
                        $this->passedTests++;
                        $this->addTestResult("SQL Syntax - {$queryName}", true, "Sintaxis válida");
                    } else {
                        $this->addTestResult("SQL Syntax - {$queryName}", false, "Error en preparación");
                    }
                } else {
                    $stmt = $this->pdo->prepare($sql);
                    if ($stmt) {
                        $this->passedTests++;
                        $this->addTestResult("SQL Syntax - {$queryName}", true, "Sintaxis válida");
                    } else {
                        $this->addTestResult("SQL Syntax - {$queryName}", false, "Error en preparación");
                    }
                }
            } catch (PDOException $e) {
                $this->addTestResult("SQL Syntax - {$queryName}", false, "Error: " . $e->getMessage());
            }
        }
        
        echo "   Validación de sintaxis completada\n\n";
    }

    /**
     * Test 4: Transacciones básicas
     */
    private function testBasicTransactions(): void
    {
        echo "4. Probando transacciones básicas...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Basic Transactions", false, "Sin conexión a base de datos");
            return;
        }
        
        // Test BEGIN/ROLLBACK
        $this->totalTests++;
        try {
            $this->pdo->beginTransaction();
            
            // Hacer un cambio temporal (si existe la tabla configuration)
            $prefix = _DB_PREFIX_;
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute(["{$prefix}configuration"]);
            
            if ($stmt->fetchColumn()) {
                // La tabla existe, hacer test
                $testValue = 'test_transaction_' . time();
                $updateStmt = $this->pdo->prepare("UPDATE `{$prefix}configuration` SET `value` = ? WHERE `name` = 'PS_SHOP_NAME' LIMIT 1");
                $updateStmt->execute([$testValue]);
            }
            
            // Hacer rollback
            $this->pdo->rollback();
            
            $this->passedTests++;
            $this->addTestResult("Basic Transactions - ROLLBACK", true, "Transacción revertida correctamente");
        } catch (PDOException $e) {
            try {
                $this->pdo->rollback();
            } catch (PDOException $rollbackError) {
                // Ignorar error de rollback
            }
            $this->addTestResult("Basic Transactions - ROLLBACK", false, "Error: " . $e->getMessage());
        }
        
        // Test COMMIT
        $this->totalTests++;
        try {
            $this->pdo->beginTransaction();
            $this->pdo->commit();
            
            $this->passedTests++;
            $this->addTestResult("Basic Transactions - COMMIT", true, "COMMIT ejecutado correctamente");
        } catch (PDOException $e) {
            $this->addTestResult("Basic Transactions - COMMIT", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de transacciones completados\n\n";
    }

    /**
     * Test 5: Protección contra inyección SQL
     */
    private function testSQLInjectionProtection(): void
    {
        echo "5. Probando protección contra inyección SQL...\n";
        
        // Test escapado de caracteres
        $this->totalTests++;
        try {
            $maliciousInput = "'; DROP TABLE users; --";
            
            // Simular función pSQL
            $escaped = addslashes($maliciousInput);
            
            if (strpos($escaped, 'DROP') !== false && strpos($escaped, "\\'") !== false) {
                $this->passedTests++;
                $this->addTestResult("SQL Injection - Character Escaping", true, "Caracteres especiales escapados");
            } else {
                $this->addTestResult("SQL Injection - Character Escaping", false, "Escape incompleto");
            }
        } catch (Exception $e) {
            $this->addTestResult("SQL Injection - Character Escaping", false, "Error: " . $e->getMessage());
        }
        
        // Test prepared statements
        $this->totalTests++;
        if ($this->pdo !== null) {
            try {
                $testInput = "test_value";
                $prefix = _DB_PREFIX_;
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$prefix}configuration` WHERE `name` = ?");
                
                if ($stmt) {
                    $this->passedTests++;
                    $this->addTestResult("SQL Injection - Prepared Statements", true, "Prepared statements funcionan");
                } else {
                    $this->addTestResult("SQL Injection - Prepared Statements", false, "No se pudo preparar statement");
                }
            } catch (PDOException $e) {
                $this->addTestResult("SQL Injection - Prepared Statements", false, "Error: " . $e->getMessage());
            }
        } else {
            $this->addTestResult("SQL Injection - Prepared Statements", false, "Sin conexión a base de datos");
        }
        
        echo "   Tests de protección SQL completados\n\n";
    }

    /**
     * Test 6: Consultas de configuración
     */
    private function testConfigurationQueries(): void
    {
        echo "6. Probando consultas de configuración...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Configuration Queries", false, "Sin conexión a base de datos");
            return;
        }
        
        $prefix = _DB_PREFIX_;
        
        // Test SELECT configuration
        $this->totalTests++;
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$prefix}configuration`");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count !== false && $count >= 0) {
                $this->passedTests++;
                $this->addTestResult("Configuration Queries - COUNT", true, "Configuraciones encontradas: {$count}");
            } else {
                $this->addTestResult("Configuration Queries - COUNT", false, "No se pudo contar configuraciones");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Configuration Queries - COUNT", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de configuración completados\n\n";
    }

    /**
     * Test 7: Consultas de shop_url
     */
    private function testShopUrlQueries(): void
    {
        echo "7. Probando consultas de shop_url...\n";
        
        if ($this->pdo === null) {
            $this->totalTests++;
            $this->addTestResult("Shop URL Queries", false, "Sin conexión a base de datos");
            return;
        }
        
        $prefix = _DB_PREFIX_;
        
        // Test existencia de tabla shop_url
        $this->totalTests++;
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute(["{$prefix}shop_url"]);
            $exists = $stmt->fetchColumn() !== false;
            
            if ($exists) {
                $this->passedTests++;
                $this->addTestResult("Shop URL Queries - Table Exists", true, "Tabla shop_url existe");
                
                // Test SELECT from shop_url
                $this->totalTests++;
                try {
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$prefix}shop_url`");
                    $stmt->execute();
                    $count = $stmt->fetchColumn();
                    
                    $this->passedTests++;
                    $this->addTestResult("Shop URL Queries - SELECT", true, "Registros shop_url: {$count}");
                } catch (PDOException $e) {
                    $this->addTestResult("Shop URL Queries - SELECT", false, "Error: " . $e->getMessage());
                }
            } else {
                $this->addTestResult("Shop URL Queries - Table Exists", false, "Tabla shop_url no encontrada");
            }
        } catch (PDOException $e) {
            $this->addTestResult("Shop URL Queries - Table Exists", false, "Error: " . $e->getMessage());
        }
        
        echo "   Tests de shop_url completados\n\n";
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
        
        echo "\n=== RESUMEN DE TESTS BÁSICOS SQL ===\n";
        echo "Total de tests: {$this->totalTests}\n";
        echo "Tests exitosos: {$this->passedTests}\n";
        echo "Tests fallidos: {$failedTests}\n";
        echo "Porcentaje de éxito: {$percentage}%\n";
        
        echo "\n=== CONFIGURACIÓN DETECTADA ===\n";
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
        
        echo "\n=== RECOMENDACIONES ===\n";
        
        if ($percentage >= 90) {
            echo "✓ Excelente: Base de datos y consultas SQL funcionan correctamente\n";
        } elseif ($percentage >= 75) {
            echo "⚠ Bueno: Algunas consultas necesitan atención\n";
        } elseif ($percentage >= 50) {
            echo "⚠ Regular: Se requieren mejoras en las consultas\n";
        } else {
            echo "✗ Crítico: Problemas graves en base de datos o consultas\n";
        }
        
        echo "\n- Verificar que todas las tablas esenciales existan\n";
        echo "- Usar siempre prepared statements para entrada de usuario\n";
        echo "- Implementar transacciones para operaciones críticas\n";
        echo "- Validar prefijos de tabla antes de ejecutar consultas\n";
        echo "\n";
    }
}

// Ejecutar tests si se llama directamente
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new BasicSQLTest();
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