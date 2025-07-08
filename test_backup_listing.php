<?php
/**
 * Test script para verificar listado de backups
 */

// Incluir configuración de PrestaShop
require_once dirname(__FILE__) . '/../../config/config.inc.php';

// Cargar clases necesarias
require_once dirname(__FILE__) . '/classes/BackupContainer.php';
require_once dirname(__FILE__) . '/classes/Logger/BackupLogger.php';

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;

echo "🔍 TEST: Verificando listado de backups\n";
echo "=====================================\n\n";

try {
    // Crear instancia del container
    $backupContainer = new BackupContainer(_PS_ROOT_DIR_, _PS_ADMIN_DIR_, 'ps_copia');
    $logger = new BackupLogger($backupContainer, true);
    
    // Simular la lógica de getCompleteBackups()
    $metadataFile = $backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/backup_metadata.json';
    
    echo "📁 Archivo de metadata: " . $metadataFile . "\n";
    
    if (!file_exists($metadataFile)) {
        echo "❌ Archivo de metadata no encontrado\n";
        exit;
    }
    
    $content = file_get_contents($metadataFile);
    $metadata = json_decode($content, true);
    
    if (!$metadata) {
        echo "❌ Error al decodificar metadata\n";
        exit;
    }
    
    echo "📊 Metadata encontrado:\n";
    print_r($metadata);
    echo "\n";
    
    // Aplicar la lógica de getCompleteBackups
    $completeBackups = [];
    
    // Verificar si es formato array o formato original
    $isArrayFormat = array_keys($metadata) === range(0, count($metadata) - 1);
    
    echo "🔍 Formato detectado: " . ($isArrayFormat ? "Array" : "Original (objeto con claves)") . "\n\n";
    
    if ($isArrayFormat) {
        echo "⚠️  Formato array no esperado para este test\n";
    } else {
        // Formato original: objeto con claves como nombres de backup
        foreach ($metadata as $backupName => $backupInfo) {
            echo "🔎 Procesando backup: " . $backupName . "\n";
            echo "   Tipo: " . ($backupInfo['type'] ?? 'no definido') . "\n";
            
            // Verificar si es un backup importado desde servidor que ya fue restaurado
            if (isset($backupInfo['type']) && $backupInfo['type'] === 'server_import') {
                echo "   ✅ Es server_import - agregando como restaurado\n";
                
                // Backup importado desde servidor que YA está completamente restaurado
                $completeBackups[] = [
                    'name' => $backupName,
                    'date' => $backupInfo['created_at'],
                    'size' => 0, // No aplica, ya está restaurado
                    'size_formatted' => 'Restaurado',
                    'type' => 'server_import_restored',
                    'imported_from' => $backupInfo['imported_from'] ?? 'Unknown',
                    'migration_applied' => $backupInfo['migration_applied'] ?? false,
                    'restoration_note' => 'Este backup ya fue restaurado en la tienda actual'
                ];
            } else {
                echo "   🔍 Es backup tradicional - verificando archivos\n";
                
                // Backup completo tradicional - verificar que los archivos existan
                $dbFile = $backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['database_file'];
                $filesFile = $backupContainer->getProperty(BackupContainer::BACKUP_PATH) . '/' . $backupInfo['files_file'];
                
                echo "   📁 DB File: " . $dbFile . " - " . (file_exists($dbFile) ? "Existe" : "NO EXISTE") . "\n";
                echo "   📁 Files File: " . $filesFile . " - " . (file_exists($filesFile) ? "Existe" : "NO EXISTE") . "\n";
                
                if (file_exists($dbFile) && file_exists($filesFile)) {
                    $totalSize = filesize($dbFile) + filesize($filesFile);
                    
                    $completeBackups[] = [
                        'name' => $backupName,
                        'date' => $backupInfo['created_at'],
                        'size' => $totalSize,
                        'size_formatted' => formatBytes($totalSize),
                        'type' => 'complete',
                        'database_file' => $backupInfo['database_file'],
                        'files_file' => $backupInfo['files_file']
                    ];
                    
                    echo "   ✅ Agregado como backup completo\n";
                } else {
                    echo "   ❌ Archivos no encontrados - no agregado\n";
                }
            }
            echo "\n";
        }
    }
    
    echo "📋 RESULTADO FINAL:\n";
    echo "==================\n";
    echo "Backups encontrados: " . count($completeBackups) . "\n\n";
    
    foreach ($completeBackups as $index => $backup) {
        echo "🔹 Backup #" . ($index + 1) . ":\n";
        echo "   Nombre: " . $backup['name'] . "\n";
        echo "   Tipo: " . $backup['type'] . "\n";
        echo "   Fecha: " . $backup['date'] . "\n";
        echo "   Tamaño: " . $backup['size_formatted'] . "\n";
        if (isset($backup['imported_from'])) {
            echo "   Importado desde: " . $backup['imported_from'] . "\n";
        }
        if (isset($backup['migration_applied'])) {
            echo "   Migración aplicada: " . ($backup['migration_applied'] ? 'Sí' : 'No') . "\n";
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
} 