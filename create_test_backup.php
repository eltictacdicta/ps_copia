<?php
/**
 * Script para crear un backup ZIP de prueba compatible con el instalador corregido
 */

echo "=== Creando Backup ZIP de Prueba ===\n\n";

$zipPath = 'test_backup_export.zip';

// Crear el ZIP
$zip = new ZipArchive();
$result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($result !== TRUE) {
    die("Error creando ZIP: $result\n");
}

echo "1. Creando estructura de backup...\n";

// Información del backup
$backupInfo = [
    'backup_name' => 'test_backup',
    'created_date' => date('Y-m-d H:i:s'),
    'prestashop_version' => '8.0.0',
    'backup_type' => 'complete',
    'source_url' => 'http://localhost/',
    'files_count' => 3,
    'database_size' => '1KB'
];

$zip->addFromString('backup_info.json', json_encode($backupInfo, JSON_PRETTY_PRINT));
echo "   ✓ backup_info.json añadido\n";

// Base de datos de prueba
$sql = "-- Base de datos de prueba para instalador corregido\n";
$sql .= "-- Creado: " . date('Y-m-d H:i:s') . "\n\n";
$sql .= "CREATE TABLE IF NOT EXISTS test_table (\n";
$sql .= "    id INT AUTO_INCREMENT PRIMARY KEY,\n";
$sql .= "    name VARCHAR(255) NOT NULL,\n";
$sql .= "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n";
$sql .= ");\n\n";
$sql .= "INSERT INTO test_table (name) VALUES ('Test Record 1'), ('Test Record 2');\n";

$zip->addFromString('database.sql', $sql);
echo "   ✓ database.sql añadido\n";

// Archivos de prueba
$zip->addFromString('files/index.php', "<?php\necho '<h1>¡Instalación de prueba exitosa!</h1>';\necho '<p>Instalador corregido funcionando.</p>';\n");
$zip->addFromString('files/config.php', "<?php\n// Configuración de prueba\ndefine('TEST_INSTALL', true);\n");
$zip->addFromString('files/test.txt', "Archivo de prueba - " . date('Y-m-d H:i:s'));

echo "   ✓ Archivos de prueba añadidos\n";

$zip->close();

$fileSize = filesize($zipPath);
echo "\n2. ZIP creado exitosamente:\n";
echo "   Archivo: $zipPath\n";
echo "   Tamaño: " . number_format($fileSize) . " bytes\n";

// Verificar contenido del ZIP
echo "\n3. Verificando contenido del ZIP:\n";
$zip = new ZipArchive();
if ($zip->open($zipPath) === TRUE) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        echo "   ✓ $filename\n";
    }
    $zip->close();
}

echo "\n=== Backup ZIP listo para probar ===\n";
echo "Ahora puedes acceder a ps_copias_installer_fixed.php para probar la instalación.\n"; 