<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

use PrestaShop\Module\PsCopia\BackupContainer;
use Symfony\Component\HttpFoundation\JsonResponse;

class AdminPsCopiaAjaxController extends ModuleAdminController
{
    /** @var Ps_copia */
    public $module;

    /** @var bool */
    private $isActualPHPVersionCompatible = true;

    /**
     * @var BackupContainer
     */
    private $backupContainer;

    public function __construct()
    {
        parent::__construct();

        // Load autoloader
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        // Verificar si el módulo está disponible y obtener el container
        if ($this->module && method_exists($this->module, 'getBackupContainer')) {
            $this->backupContainer = $this->module->getBackupContainer();
        } else {
            $this->isActualPHPVersionCompatible = false;
        }
    }

    public function postProcess()
    {
        if (!$this->isActualPHPVersionCompatible) {
            $this->ajaxDie(json_encode(['error' => 'Module not compatible with current PHP version']));
        }

        $action = Tools::getValue('action');

        switch ($action) {
            case 'create_backup':
                $this->handleCreateBackup();
                break;
            case 'restore_backup':
                $this->handleRestoreBackup();
                break;
            case 'list_backups':
                $this->handleListBackups();
                break;
            default:
                $this->ajaxDie(json_encode(['error' => 'Unknown action']));
        }

        return true;
    }

    private function handleCreateBackup()
    {
        try {
            // Verificar permisos de escritura
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            if (!is_writable($backupDir)) {
                throw new \Exception("El directorio de backup no tiene permisos de escritura: " . $backupDir);
            }

            // Obtener configuración de la base de datos desde PrestaShop
            $dbConfig = [
                'host' => _DB_SERVER_,
                'user' => _DB_USER_,
                'password' => _DB_PASSWD_,
                'database' => _DB_NAME_
            ];

            // Crear backup de la base de datos
            $backupFile = $this->backupContainer->getBackupFilename(true);
            
            // Comando mysqldump sin usar variables de shell
            $command = sprintf(
                'mysqldump --single-transaction --routines --triggers --host=%s --user=%s --password=%s %s | gzip > %s',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['user']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupFile)
            );

            // Ejecutar el comando
            $output = [];
            $return_var = 0;
            exec($command . ' 2>&1', $output, $return_var);

            if ($return_var !== 0) {
                throw new \Exception("Error al crear la copia de la base de datos. Código: " . $return_var . ". Salida: " . implode("\n", $output));
            }

            // Verificar que el archivo fue creado
            if (!file_exists($backupFile) || filesize($backupFile) === 0) {
                throw new \Exception("El archivo de backup de la base de datos no se creó correctamente o está vacío.");
            }
            
            // Backup de archivos
            $filesBackupFile = $this->backupContainer->getBackupFilename(false);
            $this->zipFiles(_PS_ROOT_DIR_, $filesBackupFile);

            // Verificar que el archivo de archivos fue creado
            if (!file_exists($filesBackupFile) || filesize($filesBackupFile) === 0) {
                throw new \Exception("El archivo de backup de archivos no se creó correctamente o está vacío.");
            }

            $this->ajaxDie(json_encode([
                'success' => true,
                'message' => sprintf(
                    'Copia de seguridad completada. Base de datos: %s (%.2f MB), Archivos: %s (%.2f MB)',
                    basename($backupFile),
                    filesize($backupFile) / (1024 * 1024),
                    basename($filesBackupFile),
                    filesize($filesBackupFile) / (1024 * 1024)
                )
            ]));

        } catch (\Exception $e) {
            // Log del error
            error_log("PS_Copia Error: " . $e->getMessage());
            
            $this->ajaxDie(json_encode([
                'error' => $e->getMessage(),
                'success' => false
            ]));
        }
    }

    private function handleRestoreBackup()
    {
        try {
            $backupName = Tools::getValue('backup_name');
            $backupType = Tools::getValue('backup_type');
            
            if (empty($backupName) || empty($backupType)) {
                throw new \Exception("Nombre de backup y tipo son requeridos");
            }

            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
            
            if (!file_exists($backupFile)) {
                throw new \Exception("El archivo de backup no existe: " . $backupName);
            }

            if ($backupType === 'database') {
                $this->restoreDatabase($backupFile);
                $message = 'Base de datos restaurada exitosamente desde: ' . $backupName;
            } elseif ($backupType === 'files') {
                $this->restoreFiles($backupFile);
                $message = 'Archivos restaurados exitosamente desde: ' . $backupName;
            } else {
                throw new \Exception("Tipo de backup no válido: " . $backupType);
            }

            $this->ajaxDie(json_encode([
                'success' => true,
                'message' => $message
            ]));

        } catch (\Exception $e) {
            error_log("PS_Copia Restore Error: " . $e->getMessage());
            
            $this->ajaxDie(json_encode([
                'error' => $e->getMessage(),
                'success' => false
            ]));
        }
    }

    private function handleListBackups()
    {
        try {
            $backupDir = $this->backupContainer->getProperty(BackupContainer::BACKUP_PATH);
            $backups = [];
            
            if (is_dir($backupDir)) {
                $files = scandir($backupDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $filePath = $backupDir . DIRECTORY_SEPARATOR . $file;
                        if (is_file($filePath)) {
                            $backups[] = [
                                'name' => $file,
                                'size' => $this->formatBytes(filesize($filePath)),
                                'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                                'type' => strpos($file, 'db_backup_') === 0 ? 'database' : 'files'
                            ];
                        }
                    }
                }
                
                // Ordenar por fecha (más recientes primero)
                usort($backups, function($a, $b) {
                    return strtotime($b['date']) - strtotime($a['date']);
                });
            }
            
            $this->ajaxDie(json_encode([
                'success' => true,
                'backups' => $backups
            ]));
            
        } catch (\Exception $e) {
            $this->ajaxDie(json_encode([
                'error' => $e->getMessage(),
                'success' => false
            ]));
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Zip files recursively.
     *
     * @param string $source
     * @param string $destination
     */
    private function zipFiles(string $source, string $destination): void
    {
        if (!extension_loaded('zip')) {
            throw new \Exception('La extensión ZIP de PHP no está instalada.');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new \Exception('No se pudo crear el archivo zip. Error: ' . $this->getZipError($result));
        }

        $source = realpath($source);
        
        if (!$source) {
            throw new \Exception('El directorio fuente no existe: ' . $source);
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        // Directorios y archivos a excluir
        $exclude = [
            realpath($this->backupContainer->getProperty(BackupContainer::BACKUP_PATH)),
            realpath(sys_get_temp_dir()),
            realpath(_PS_ROOT_DIR_ . '/var/cache'),
            realpath(_PS_ROOT_DIR_ . '/var/logs'),
            realpath(_PS_ROOT_DIR_ . '/cache'),
            realpath(_PS_ROOT_DIR_ . '/log'),
        ];

        // Filtrar valores null
        $exclude = array_filter($exclude);

        $fileCount = 0;
        foreach ($files as $name => $file) {
            if (!$file->isDir() && $file->isReadable()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source) + 1);
                
                // Excluir directorios y archivos específicos
                $isExcluded = false;
                foreach ($exclude as $excludePath) {
                    if ($excludePath && strpos($filePath, $excludePath) === 0) {
                        $isExcluded = true;
                        break;
                    }
                }

                // Excluir archivos temporales y de log
                if (!$isExcluded && !preg_match('/\.(log|tmp|temp|cache)$/i', $filePath)) {
                    $zip->addFile($filePath, $relativePath);
                    $fileCount++;
                    
                    // Limitar el número de archivos para evitar timeout
                    if ($fileCount % 1000 === 0) {
                        // Permitir que el script continúe sin timeout
                        if (function_exists('set_time_limit')) {
                            set_time_limit(300); // 5 minutos más
                        }
                    }
                }
            }
        }

        $result = $zip->close();
        if (!$result) {
            throw new \Exception('Error al cerrar el archivo zip.');
        }

        if ($fileCount === 0) {
            throw new \Exception('No se agregaron archivos al zip.');
        }
    }

    /**
     * Get human readable ZIP error message
     */
    private function getZipError(int $code): string
    {
        switch($code) {
            case \ZipArchive::ER_OK: return 'No error';
            case \ZipArchive::ER_MULTIDISK: return 'Multi-disk zip archives not supported';
            case \ZipArchive::ER_RENAME: return 'Renaming temporary file failed';
            case \ZipArchive::ER_CLOSE: return 'Closing zip archive failed';
            case \ZipArchive::ER_SEEK: return 'Seek error';
            case \ZipArchive::ER_READ: return 'Read error';
            case \ZipArchive::ER_WRITE: return 'Write error';
            case \ZipArchive::ER_CRC: return 'CRC error';
            case \ZipArchive::ER_ZIPCLOSED: return 'Containing zip archive was closed';
            case \ZipArchive::ER_NOENT: return 'No such file';
            case \ZipArchive::ER_EXISTS: return 'File already exists';
            case \ZipArchive::ER_OPEN: return 'Can\'t open file';
            case \ZipArchive::ER_TMPOPEN: return 'Failure to create temporary file';
            case \ZipArchive::ER_ZLIB: return 'Zlib error';
            case \ZipArchive::ER_MEMORY: return 'Memory allocation failure';
            case \ZipArchive::ER_CHANGED: return 'Entry has been changed';
            case \ZipArchive::ER_COMPNOTSUPP: return 'Compression method not supported';
            case \ZipArchive::ER_EOF: return 'Premature EOF';
            case \ZipArchive::ER_INVAL: return 'Invalid argument';
            case \ZipArchive::ER_NOZIP: return 'Not a zip archive';
            case \ZipArchive::ER_INTERNAL: return 'Internal error';
            case \ZipArchive::ER_INCONS: return 'Zip archive inconsistent';
            case \ZipArchive::ER_REMOVE: return 'Can\'t remove file';
            case \ZipArchive::ER_DELETED: return 'Entry has been deleted';
            default: return 'Unknown error code: ' . $code;
        }
    }

    /**
     * Restore database from backup file
     */
    private function restoreDatabase(string $backupFile): void
    {
        // Verificar que el archivo existe
        if (!file_exists($backupFile)) {
            throw new \Exception("El archivo de backup de la base de datos no existe: " . $backupFile);
        }

        // Verificar que mysqldump está disponible
        $mysqlCommand = 'mysql';
        $output = [];
        $return_var = 0;
        exec('which ' . $mysqlCommand . ' 2>/dev/null', $output, $return_var);
        if ($return_var !== 0) {
            throw new \Exception("El comando mysql no está disponible en el sistema");
        }

        // Configuración de la base de datos
        $dbConfig = [
            'host' => _DB_SERVER_,
            'user' => _DB_USER_,
            'password' => _DB_PASSWD_,
            'database' => _DB_NAME_
        ];

        // Determinar si el archivo está comprimido
        $isGzipped = pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz';
        
        if ($isGzipped) {
            // Para archivos .gz usar zcat
            $command = sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s',
                escapeshellarg($backupFile),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['user']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database'])
            );
        } else {
            // Para archivos .sql normales
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['user']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupFile)
            );
        }

        // Ejecutar la restauración
        $output = [];
        $return_var = 0;
        exec($command . ' 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            throw new \Exception("Error al restaurar la base de datos. Código: " . $return_var . ". Salida: " . implode("\n", $output));
        }
    }

    /**
     * Restore files from backup zip
     */
    private function restoreFiles(string $backupFile): void
    {
        if (!extension_loaded('zip')) {
            throw new \Exception('La extensión ZIP de PHP no está instalada.');
        }

        // Verificar que el archivo existe
        if (!file_exists($backupFile)) {
            throw new \Exception("El archivo de backup de archivos no existe: " . $backupFile);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($backupFile);
        
        if ($result !== TRUE) {
            throw new \Exception('No se pudo abrir el archivo zip. Error: ' . $this->getZipError($result));
        }

        $extractPath = _PS_ROOT_DIR_;
        
        // Crear directorio temporal para verificar contenido
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_copia_temp_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \Exception('No se pudo crear el directorio temporal: ' . $tempDir);
        }

        try {
            // Extraer a directorio temporal primero para verificación
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception('Error al extraer el archivo zip al directorio temporal');
            }
            
            $zip->close();

            // Mover archivos del directorio temporal al directorio real
            // Esto nos permite hacer verificaciones antes de sobrescribir
            $this->copyDirectoryRecursively($tempDir, $extractPath);
            
        } finally {
            // Limpiar directorio temporal
            $this->removeDirectoryRecursively($tempDir);
        }
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectoryRecursively(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }
            } else {
                // Verificar si podemos escribir en el directorio destino
                $destinationDir = dirname($destinationPath);
                if (!is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                
                if (!copy($item->getPathname(), $destinationPath)) {
                    throw new \Exception('Error al copiar archivo: ' . $relativePath);
                }
            }
        }
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectoryRecursively(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($directory);
    }
}
