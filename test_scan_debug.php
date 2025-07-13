<?php
/**
 * Script de Diagnóstico para el Problema de Escaneo
 * ps_copia Module - Scan Debug Test
 * 
 * Este script ha sido optimizado para evitar falsos positivos de antivirus
 */

// Incluir configuración de PrestaShop
if (!defined("_PS_ADMIN_DIR_")) {
    $adminDir = null;
    $currentDir = dirname(__FILE__);
    
    // Buscar el directorio admin
    $possibleAdminDirs = glob($currentDir . '/../../admin*');
    foreach ($possibleAdminDirs as $dir) {
        if (is_dir($dir) && file_exists($dir . '/index.php')) {
            $adminDir = $dir;
            break;
        }
    }
    
    if ($adminDir) {
        define("_PS_ADMIN_DIR_", $adminDir . "/");
    } else {
        echo "❌ No se pudo encontrar el directorio admin\n";
        exit;
    }
}

require_once dirname(__FILE__) . "/../../config/config.inc.php";

echo "🔍 DIAGNÓSTICO DEL PROBLEMA DE ESCANEO\n";
echo "======================================\n\n";

// Función para mostrar estado
function showStatus($message, $status = 'info') {
    $icons = [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️'
    ];
    
    echo $icons[$status] . ' ' . $message . "\n";
}

// 1. Verificar configuración básica
showStatus("Verificando configuración básica...");

if (!defined('_PS_ROOT_DIR_')) {
    showStatus("_PS_ROOT_DIR_ no está definido", 'error');
    exit;
}

showStatus("PrestaShop Root: " . _PS_ROOT_DIR_);

// 2. Verificar la clase BackupContainer
showStatus("Verificando clase BackupContainer...");

$backupContainerPath = dirname(__FILE__) . "/classes/BackupContainer.php";
if (!file_exists($backupContainerPath)) {
    showStatus("BackupContainer.php no encontrado en: " . $backupContainerPath, 'error');
    exit;
}

require_once $backupContainerPath;

if (!class_exists('BackupContainer')) {
    showStatus("Clase BackupContainer no se pudo cargar", 'error');
    exit;
}

showStatus("BackupContainer cargado correctamente");

// 3. Verificar funcionalidad básica
showStatus("Verificando funcionalidad básica...");

try {
    $container = new BackupContainer();
    showStatus("BackupContainer inicializado correctamente");
} catch (Exception $e) {
    showStatus("Error al inicializar BackupContainer: " . $e->getMessage(), 'error');
    exit;
}

// 4. Verificar permisos
showStatus("Verificando permisos de directorio...");

$backupPath = $container->getProperty('backup_path');
if (is_writable($backupPath)) {
    showStatus("Directorio de backup es escribible: " . $backupPath);
} else {
    showStatus("Directorio de backup NO es escribible: " . $backupPath, 'warning');
}

echo "\n✅ DIAGNÓSTICO COMPLETADO\n";
echo "========================\n";
echo "El módulo parece estar funcionando correctamente.\n";
echo "Si tienes problemas específicos, consulta los logs en:\n";
echo "- " . dirname(__FILE__) . "/logs/\n";
echo "- Error log del sistema\n";
?> 