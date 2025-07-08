<?php

/**
 * Script de Diagnóstico para PS_Copia - Problemas con Archivos Grandes
 * 
 * Este script ayuda a identificar problemas de configuración que pueden
 * causar errores de comunicación con archivos grandes.
 */

echo "🔍 DIAGNÓSTICO PS_COPIA - CONFIGURACIÓN SERVIDOR\n";
echo "================================================\n\n";

// Verificar entorno
echo "📋 INFORMACIÓN DEL ENTORNO:\n";
echo "- PHP Version: " . PHP_VERSION . "\n";
echo "- Sistema: " . PHP_OS . "\n";
echo "- SAPI: " . php_sapi_name() . "\n";
echo "- Detectar DDEV: " . (getenv('DDEV_SITENAME') ? 'SÍ (' . getenv('DDEV_SITENAME') . ')' : 'NO') . "\n\n";

// Configuración crítica de PHP
echo "⚙️  CONFIGURACIÓN PHP CRÍTICA:\n";
$criticalSettings = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_time' => ini_get('max_input_time'),
    'default_socket_timeout' => ini_get('default_socket_timeout')
];

foreach ($criticalSettings as $setting => $value) {
    $status = '✅';
    $recommendation = '';
    
    switch ($setting) {
        case 'memory_limit':
            if ($value !== '-1' && parseBytes($value) < 256 * 1024 * 1024) {
                $status = '⚠️';
                $recommendation = ' (Recomendado: 512M+)';
            }
            break;
        case 'max_execution_time':
            if ($value != '0' && $value < 300) {
                $status = '⚠️';
                $recommendation = ' (Recomendado: 0 o 600+)';
            }
            break;
        case 'upload_max_filesize':
            if (parseBytes($value) < 500 * 1024 * 1024) {
                $status = '⚠️';
                $recommendation = ' (Para archivos >100MB usar FTP)';
            }
            break;
    }
    
    echo "  {$status} {$setting}: {$value}{$recommendation}\n";
}

// Extensiones requeridas
echo "\n🔧 EXTENSIONES PHP:\n";
$requiredExtensions = ['zip', 'mysqli', 'json', 'curl'];
foreach ($requiredExtensions as $ext) {
    $status = extension_loaded($ext) ? '✅' : '❌';
    echo "  {$status} {$ext}\n";
}

// Funciones críticas
echo "\n🛠️  FUNCIONES CRÍTICAS:\n";
$criticalFunctions = ['set_time_limit', 'ini_set', 'gc_collect_cycles', 'realpath'];
foreach ($criticalFunctions as $func) {
    $status = function_exists($func) ? '✅' : '❌';
    echo "  {$status} {$func}\n";
}

// Permisos de directorio
echo "\n📁 PERMISOS DE DIRECTORIOS:\n";
$directories = [
    _PS_ROOT_DIR_ => 'PrestaShop Root',
    _PS_ROOT_DIR_ . '/modules/ps_copia' => 'Módulo PS_Copia'
];

// Detectar directorio admin
$adminDir = null;
$adminFolders = glob(_PS_ROOT_DIR_ . '/admin*', GLOB_ONLYDIR);
if (!empty($adminFolders)) {
    $adminDir = $adminFolders[0];
    $directories[$adminDir . '/ps_copia'] = 'Admin PS_Copia';
    $directories[$adminDir . '/ps_copia/uploads'] = 'Uploads Directory';
}

foreach ($directories as $dir => $name) {
    if (is_dir($dir)) {
        $readable = is_readable($dir) ? '✅' : '❌';
        $writable = is_writable($dir) ? '✅' : '❌';
        echo "  {$name}: R:{$readable} W:{$writable} ({$dir})\n";
    } else {
        echo "  {$name}: ❌ NO EXISTE ({$dir})\n";
    }
}

// Test de ZipArchive con timeout
echo "\n📦 TEST ZIPARCHIVE:\n";
try {
    $zip = new ZipArchive();
    echo "  ✅ ZipArchive disponible\n";
    
    // Test de timeout con set_time_limit
    $oldLimit = ini_get('max_execution_time');
    if (function_exists('set_time_limit')) {
        set_time_limit(5);
        echo "  ✅ set_time_limit funcionando\n";
        set_time_limit((int)$oldLimit);
    } else {
        echo "  ❌ set_time_limit NO disponible\n";
    }
    
} catch (Exception $e) {
    echo "  ❌ Error con ZipArchive: " . $e->getMessage() . "\n";
}

// Simulación de archivo grande
echo "\n🧪 SIMULACIÓN PROCESAMIENTO:\n";
try {
    $startTime = microtime(true);
    $iterations = 1000000;
    
    // Simular procesamiento de chunks
    for ($i = 0; $i < $iterations; $i++) {
        if ($i % 100000 === 0) {
            $elapsed = microtime(true) - $startTime;
            echo "  ⏱️  Chunk " . ($i / 100000) . ": {$elapsed}s\n";
            
            // Simular preventTimeout()
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            
            // Simular clearMemory()
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }
    
    $totalTime = microtime(true) - $startTime;
    echo "  ✅ Simulación completa en {$totalTime}s\n";
    
} catch (Exception $e) {
    echo "  ❌ Error en simulación: " . $e->getMessage() . "\n";
}

// Verificar logs de error
echo "\n📋 LOGS DE ERROR:\n";
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo "  📄 Log de errores: {$errorLog}\n";
    $recentErrors = tail($errorLog, 5);
    if (!empty($recentErrors)) {
        echo "  📝 Últimos errores:\n";
        foreach ($recentErrors as $error) {
            if (strpos($error, 'ps_copia') !== false || strpos($error, 'ZIP') !== false) {
                echo "    ⚠️  " . trim($error) . "\n";
            }
        }
    }
} else {
    echo "  ℹ️  No se encontró log de errores\n";
}

// Recomendaciones específicas
echo "\n💡 RECOMENDACIONES ESPECÍFICAS:\n";

if (getenv('DDEV_SITENAME')) {
    echo "  🐳 ENTORNO DDEV DETECTADO:\n";
    echo "     Para optimizar archivos grandes:\n";
    echo "     \n";
    echo "     ddev exec 'echo \"memory_limit = 1G\" >> /etc/php/*/cli/php.ini'\n";
    echo "     ddev exec 'echo \"max_execution_time = 0\" >> /etc/php/*/cli/php.ini'\n";
    echo "     ddev exec 'echo \"upload_max_filesize = 1G\" >> /etc/php/*/fpm/php.ini'\n";
    echo "     ddev exec 'echo \"post_max_size = 1G\" >> /etc/php/*/fpm/php.ini'\n";
    echo "     ddev restart\n\n";
}

$memoryBytes = parseBytes(ini_get('memory_limit'));
if ($memoryBytes !== -1 && $memoryBytes < 512 * 1024 * 1024) {
    echo "  📈 MEMORIA INSUFICIENTE:\n";
    echo "     Tu memory_limit actual es muy bajo para archivos grandes.\n";
    echo "     Aumenta a al menos 512M o 1G.\n\n";
}

$maxExecTime = ini_get('max_execution_time');
if ($maxExecTime != '0' && $maxExecTime < 600) {
    echo "  ⏰ TIMEOUT CORTO:\n";
    echo "     Tu max_execution_time es muy corto para archivos grandes.\n";
    echo "     Configura a 0 (ilimitado) o al menos 600 segundos.\n\n";
}

echo "✅ DIAGNÓSTICO COMPLETADO\n";
echo "======================\n\n";
echo "Para más ayuda, revisa los logs del módulo en:\n";
echo "- {$adminDir}/ps_copia/logs/\n";
echo "- Error log del sistema: {$errorLog}\n\n";

/**
 * Helper functions
 */
function parseBytes($size) {
    if ($size === '-1') return -1;
    
    $size = trim($size);
    $unit = strtolower(substr($size, -1));
    $value = (int) $size;
    
    switch($unit) {
        case 'g': $value *= 1024;
        case 'm': $value *= 1024;
        case 'k': $value *= 1024;
    }
    
    return $value;
}

function tail($file, $lines = 10) {
    if (!file_exists($file)) return [];
    
    $content = file($file);
    return array_slice($content, -$lines);
}

// Definir constantes si no existen (para testing fuera de PrestaShop)
if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', dirname(__FILE__));
} 