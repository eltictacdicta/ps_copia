<?php
/**
 * Script Master para ejecutar todos los tests de PS_Copia
 * Genera un reporte completo del estado de las consultas SQL y funcionalidad
 * 
 * @author AI Assistant
 * @version 1.0
 */

echo "🔍 === REPORTE COMPLETO DE AUDITORÍA SQL - PS_COPIA === 🔍\n\n";

$testResults = [];
$totalTests = 0;
$passedTests = 0;

echo "📊 EJECUTANDO TESTS DE VALIDACIÓN...\n\n";

// Test 1: Test Básico de SQL
echo "1️⃣ EJECUTANDO TEST BÁSICO DE SQL...\n";
echo str_repeat("=", 50) . "\n";

ob_start();
$basicTestExitCode = 0;
try {
    include 'tests/BasicSQLTest.php';
} catch (Exception $e) {
    echo "Error ejecutando BasicSQLTest: " . $e->getMessage() . "\n";
    $basicTestExitCode = 1;
}
$basicTestOutput = ob_get_clean();

echo $basicTestOutput;
echo str_repeat("=", 50) . "\n\n";

// Analizar resultados del test básico
preg_match('/Total de tests: (\d+)/', $basicTestOutput, $totalMatches);
preg_match('/Tests exitosos: (\d+)/', $basicTestOutput, $passedMatches);
preg_match('/Porcentaje de éxito: ([\d.]+)%/', $basicTestOutput, $percentageMatches);

$basicTotal = isset($totalMatches[1]) ? (int)$totalMatches[1] : 0;
$basicPassed = isset($passedMatches[1]) ? (int)$passedMatches[1] : 0;
$basicPercentage = isset($percentageMatches[1]) ? (float)$percentageMatches[1] : 0;

$testResults['basic_sql'] = [
    'name' => 'Test Básico SQL',
    'total' => $basicTotal,
    'passed' => $basicPassed,
    'percentage' => $basicPercentage,
    'status' => $basicPercentage >= 90 ? 'EXCELENTE' : ($basicPercentage >= 75 ? 'BUENO' : 'CRÍTICO')
];

$totalTests += $basicTotal;
$passedTests += $basicPassed;

// Test 2: Test Simple de Restauración
echo "2️⃣ EJECUTANDO TEST SIMPLE DE RESTAURACIÓN...\n";
echo str_repeat("=", 50) . "\n";

ob_start();
$simpleTestExitCode = 0;
try {
    include 'tests/SimpleRestoreTest.php';
} catch (Exception $e) {
    echo "Error ejecutando SimpleRestoreTest: " . $e->getMessage() . "\n";
    $simpleTestExitCode = 1;
}
$simpleTestOutput = ob_get_clean();

echo $simpleTestOutput;
echo str_repeat("=", 50) . "\n\n";

// Analizar resultados del test simple
preg_match('/Resumen: (\d+)\/(\d+) tests pasaron/', $simpleTestOutput, $simpleMatches);
$simpleTotal = isset($simpleMatches[2]) ? (int)$simpleMatches[2] : 0;
$simplePassed = isset($simpleMatches[1]) ? (int)$simpleMatches[1] : 0;
$simplePercentage = $simpleTotal > 0 ? round(($simplePassed / $simpleTotal) * 100, 2) : 0;

$testResults['simple_restore'] = [
    'name' => 'Test Simple Restauración',
    'total' => $simpleTotal,
    'passed' => $simplePassed,
    'percentage' => $simplePercentage,
    'status' => $simplePercentage >= 90 ? 'EXCELENTE' : ($simplePercentage >= 75 ? 'BUENO' : 'REGULAR')
];

$totalTests += $simpleTotal;
$passedTests += $simplePassed;

// Reporte de auditoría de archivos
echo "3️⃣ AUDITORÍA DE CONSULTAS EN ARCHIVOS FUENTE...\n";
echo str_repeat("=", 50) . "\n";

$sourceFiles = [
    'classes/Migration/DatabaseMigrator.php',
    'classes/Services/RestoreService.php',
    'classes/Services/EnhancedRestoreService.php',
    'classes/Migration/UrlMigrator.php',
    'classes/Services/TransactionManager.php'
];

$auditResults = [];

foreach ($sourceFiles as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Contar consultas SQL
        $sqlPatterns = [
            'SELECT' => '/SELECT\s+.*?\s+FROM/i',
            'UPDATE' => '/UPDATE\s+.*?\s+SET/i',
            'INSERT' => '/INSERT\s+INTO/i',
            'DELETE' => '/DELETE\s+FROM/i',
            'CREATE' => '/CREATE\s+TABLE/i',
            'DROP' => '/DROP\s+TABLE/i'
        ];
        
        $sqlCounts = [];
        $totalSqlQueries = 0;
        
        foreach ($sqlPatterns as $type => $pattern) {
            preg_match_all($pattern, $content, $matches);
            $count = count($matches[0]);
            $sqlCounts[$type] = $count;
            $totalSqlQueries += $count;
        }
        
        // Verificar uso de pSQL()
        $pSqlUsage = substr_count($content, 'pSQL(');
        $unsafeConcatenations = preg_match_all('/\'\s*\.\s*\$[a-zA-Z_]/', $content);
        
        // Verificar transacciones
        $transactions = substr_count($content, 'START TRANSACTION') + 
                       substr_count($content, 'BEGIN') + 
                       substr_count($content, 'COMMIT') + 
                       substr_count($content, 'ROLLBACK');
        
        $auditResults[$file] = [
            'total_queries' => $totalSqlQueries,
            'query_types' => $sqlCounts,
            'psql_usage' => $pSqlUsage,
            'unsafe_concatenations' => $unsafeConcatenations,
            'transactions' => $transactions,
            'file_size' => strlen($content),
            'lines' => substr_count($content, "\n") + 1
        ];
        
        echo "📄 " . basename($file) . ":\n";
        echo "   • Total consultas SQL: {$totalSqlQueries}\n";
        echo "   • Uso de pSQL(): {$pSqlUsage}\n";
        echo "   • Transacciones: {$transactions}\n";
        echo "   • Líneas de código: " . $auditResults[$file]['lines'] . "\n";
        
        // Mostrar distribución de consultas
        foreach ($sqlCounts as $type => $count) {
            if ($count > 0) {
                echo "   • {$type}: {$count}\n";
            }
        }
        echo "\n";
    } else {
        echo "❌ Archivo no encontrado: {$file}\n\n";
    }
}

echo str_repeat("=", 50) . "\n\n";

// Generar resumen ejecutivo
echo "📋 === RESUMEN EJECUTIVO === 📋\n\n";

$overallPercentage = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0;

echo "🎯 RESULTADOS GENERALES:\n";
echo "   • Total de tests ejecutados: {$totalTests}\n";
echo "   • Tests exitosos: {$passedTests}\n";
echo "   • Tests fallidos: " . ($totalTests - $passedTests) . "\n";
echo "   • Porcentaje de éxito general: {$overallPercentage}%\n\n";

echo "📊 DESGLOSE POR CATEGORÍA:\n";
foreach ($testResults as $category => $result) {
    $status = $result['status'];
    $emoji = $status === 'EXCELENTE' ? '✅' : ($status === 'BUENO' ? '⚠️' : '❌');
    echo "   {$emoji} {$result['name']}: {$result['passed']}/{$result['total']} ({$result['percentage']}%) - {$status}\n";
}

echo "\n🔍 ANÁLISIS DE CÓDIGO:\n";
$totalQueries = 0;
$totalPsqlUsage = 0;
$totalTransactions = 0;

foreach ($auditResults as $file => $audit) {
    $totalQueries += $audit['total_queries'];
    $totalPsqlUsage += $audit['psql_usage'];
    $totalTransactions += $audit['transactions'];
}

echo "   • Total consultas SQL encontradas: {$totalQueries}\n";
echo "   • Total uso de pSQL(): {$totalPsqlUsage}\n";
echo "   • Total manejo de transacciones: {$totalTransactions}\n";

// Calcular nivel de seguridad
$securityRatio = $totalQueries > 0 ? round(($totalPsqlUsage / $totalQueries) * 100, 2) : 100;
echo "   • Ratio de seguridad SQL: {$securityRatio}%\n\n";

// Recomendaciones
echo "💡 === RECOMENDACIONES === 💡\n\n";

if ($overallPercentage >= 95) {
    echo "🌟 ESTADO: EXCELENTE\n";
    echo "   El módulo tiene un excelente nivel de calidad en sus consultas SQL.\n";
    echo "   Todas las pruebas pasan satisfactoriamente.\n\n";
} elseif ($overallPercentage >= 85) {
    echo "✅ ESTADO: BUENO\n";
    echo "   El módulo tiene un buen nivel de calidad con algunas áreas de mejora.\n\n";
} elseif ($overallPercentage >= 70) {
    echo "⚠️ ESTADO: REGULAR\n";
    echo "   Se requieren mejoras en las consultas SQL del módulo.\n\n";
} else {
    echo "❌ ESTADO: CRÍTICO\n";
    echo "   Se requiere revisión completa de las consultas SQL del módulo.\n\n";
}

echo "🔧 ACCIONES RECOMENDADAS:\n";

if ($securityRatio < 80) {
    echo "   • 🔒 SEGURIDAD: Incrementar el uso de pSQL() en todas las consultas\n";
}

if ($totalTransactions < 5) {
    echo "   • 🔄 TRANSACCIONES: Implementar más manejo de transacciones para operaciones críticas\n";
}

if ($overallPercentage < 90) {
    echo "   • 🧪 TESTING: Mejorar cobertura de tests para consultas SQL\n";
}

echo "   • 📝 DOCUMENTACIÓN: Mantener documentación actualizada de cambios en BD\n";
echo "   • 🔍 MONITOREO: Implementar logging para consultas SQL en producción\n";
echo "   • 🚀 RENDIMIENTO: Optimizar consultas que tomen más de 100ms\n";

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ AUDITORÍA COMPLETA FINALIZADA\n";
echo "📅 Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "🏆 Resultado final: " . ($overallPercentage >= 90 ? "APROBADO" : "REQUIERE ATENCIÓN") . "\n";
echo str_repeat("=", 60) . "\n\n";

// Exit code basado en resultados
if ($overallPercentage >= 90) {
    exit(0); // Todo bien
} elseif ($overallPercentage >= 75) {
    exit(1); // Atención requerida
} else {
    exit(2); // Crítico
} 