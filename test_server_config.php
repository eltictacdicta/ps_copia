<?php
/**
 * Test de Configuración del Servidor para ps_copia
 * Verifica que el servidor tenga todo lo necesario para funcionar correctamente
 * 
 * Archivo optimizado para evitar falsos positivos de antivirus
 */

// Configuración inicial
if (!defined('_PS_ROOT_DIR_')) {
    $currentDir = dirname(__FILE__);
    $possiblePsRoot = realpath($currentDir . '/../../');
    
    if ($possiblePsRoot && file_exists($possiblePsRoot . '/config/config.inc.php')) {
        define('_PS_ROOT_DIR_', $possiblePsRoot);
        require_once $possiblePsRoot . '/config/config.inc.php';
    } else {
        echo "❌ No se pudo encontrar la configuración de PrestaShop\n";
        exit;
    }
}

echo "🔧 TEST DE CONFIGURACIÓN DEL SERVIDOR\n";
echo "====================================\n\n";

// Información del servidor
echo "📋 INFORMACIÓN DEL SERVIDOR:\n";
echo "  PHP Version: " . phpversion() . "\n";
echo "  Memory Limit: " . ini_get('memory_limit') . "\n";
echo "  Max Execution Time: " . ini_get('max_execution_time') . "s\n";
echo "  Upload Max Size: " . ini_get('upload_max_filesize') . "\n";
echo "  Post Max Size: " . ini_get('post_max_size') . "\n";

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

// Test de ZipArchive
echo "\n📦 TEST ZIPARCHIVE:\n";
try {
    $zip = new ZipArchive();
    echo "  ✅ ZipArchive disponible\n";
    
    // Test de timeout
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

// Recomendaciones finales
echo "\n📊 RECOMENDACIONES:\n";

if (getenv('DDEV_SITENAME')) {
    echo "  🐳 ENTORNO DDEV DETECTADO:\n";
    echo "     Para optimizar archivos grandes, considera aumentar:\n";
    echo "     - memory_limit a 1G\n";
    echo "     - max_execution_time a 0 (ilimitado)\n";
    echo "     - upload_max_filesize a 1G\n";
    echo "     - post_max_size a 1G\n\n";
}

$memoryBytes = parseBytes(ini_get('memory_limit'));
if ($memoryBytes !== -1 && $memoryBytes < 512 * 1024 * 1024) {
    echo "  📈 MEMORIA INSUFICIENTE:\n";
    echo "     Tu memory_limit actual es muy bajo para archivos grandes.\n";
    echo "     Aumenta a al menos 512M o 1G.\n\n";
}

echo "✅ DIAGNÓSTICO COMPLETADO\n";
echo "======================\n\n";
echo "Para más ayuda, revisa los logs del módulo.\n";

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
?> 