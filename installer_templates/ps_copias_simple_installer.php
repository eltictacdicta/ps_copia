<?php
/**
 * PS Copias Simple Standalone Installer - Estilo Duplicator
 * 
 * Este instalador funciona con los ZIP de exportación normales de PS_Copia
 * Lee automáticamente el ZIP de backup y lo restaura en el nuevo servidor
 * 
 * TEMPLATE VARIABLES TO REPLACE:
 * {BACKUP_NAME} - Nombre del backup a buscar
 * {CREATION_DATE} - Fecha de creación del paquete
 * {PRESTASHOP_VERSION} - Versión de PrestaShop
 * {SOURCE_URL} - URL original del sitio
 */

// Configuración del instalador
define('INSTALLER_VERSION', '2.0-Simple');
define('MAX_EXECUTION_TIME', 3600);
define('MEMORY_LIMIT', '1024M');
define('BACKUP_NAME', '{BACKUP_NAME}');
define('CREATION_DATE', '{CREATION_DATE}');
define('PRESTASHOP_VERSION', '{PRESTASHOP_VERSION}');
define('SOURCE_URL', '{SOURCE_URL}');

// Configurar límites
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@ini_set('memory_limit', MEMORY_LIMIT);
@set_time_limit(MAX_EXECUTION_TIME);

class PsCopiasSimpleInstaller
{
    private $currentStep;
    private $backupZipFile = null;
    private $errors = [];
    private $warnings = [];
    private $logFile;
    
    public function __construct()
    {
        $this->currentStep = $_GET['step'] ?? 'welcome';
        $this->logFile = 'installer_log_' . date('Y-m-d_H-i-s') . '.txt';
        $this->detectBackupZip();
    }
    
    /**
     * Detecta automáticamente el archivo ZIP de backup
     */
    private function detectBackupZip()
    {
        $currentDir = dirname(__FILE__);
        
        // Buscar ZIP de exportación con el nombre del backup
        $patterns = [
            $currentDir . '/' . BACKUP_NAME . '_export.zip',
            $currentDir . '/' . BACKUP_NAME . '.zip',
            $currentDir . '/backup_*.zip',
            $currentDir . '/*_export.zip'
        ];
        
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (!empty($files)) {
                $this->backupZipFile = $files[0];
                break;
            }
        }
        
        // Si no encuentra por patrón, buscar cualquier ZIP que parezca un backup
        if (!$this->backupZipFile) {
            $zipFiles = glob($currentDir . '/*.zip');
            foreach ($zipFiles as $zipFile) {
                if ($this->isValidBackupZip($zipFile)) {
                    $this->backupZipFile = $zipFile;
                    break;
                }
            }
        }
    }
    
    /**
     * Verifica si un ZIP es un backup válido de PS_Copia
     */
    private function isValidBackupZip($zipPath)
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== TRUE) {
            return false;
        }
        
        // Buscar archivos característicos de un backup de PS_Copia
        $hasBackupInfo = $zip->locateName('backup_info.json') !== false;
        $hasDatabaseDir = false;
        $hasFilesDir = false;
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileName = $zip->getNameIndex($i);
            if (strpos($fileName, 'database/') === 0) {
                $hasDatabaseDir = true;
            }
            if (strpos($fileName, 'files/') === 0) {
                $hasFilesDir = true;
            }
        }
        
        $zip->close();
        
        return $hasBackupInfo && ($hasDatabaseDir || $hasFilesDir);
    }
    
    /**
     * Punto de entrada principal
     */
    public function run()
    {
        $this->logMessage("=== PS Copias Simple Installer Started ===");
        $this->logMessage("Version: " . INSTALLER_VERSION);
        $this->logMessage("Step: " . $this->currentStep);
        $this->logMessage("Backup Name: " . BACKUP_NAME);
        
        switch ($this->currentStep) {
            case 'welcome':
                $this->showWelcomeStep();
                break;
            case 'requirements':
                $this->showRequirementsStep();
                break;
            case 'database':
                $this->showDatabaseStep();
                break;
            case 'extract':
                $this->performExtraction();
                break;
            case 'install':
                $this->performInstallation();
                break;
            case 'complete':
                $this->showCompleteStep();
                break;
            default:
                $this->showWelcomeStep();
        }
    }
    
    /**
     * Paso 1: Bienvenida y verificación de archivos
     */
    private function showWelcomeStep()
    {
        $this->renderHeader("Bienvenido al Instalador PS Copias");
        
        echo '<div class="step-content">';
        echo '<h2>Instalador Simple - Estilo Duplicator</h2>';
        echo '<p>Este instalador restaurará tu tienda PrestaShop desde un backup exportado.</p>';
        
        echo '<div class="info-box">';
        echo '<h3>Información del Paquete</h3>';
        echo '<ul>';
        echo '<li><strong>Backup:</strong> ' . BACKUP_NAME . '</li>';
        echo '<li><strong>Fecha:</strong> ' . CREATION_DATE . '</li>';
        echo '<li><strong>PrestaShop:</strong> ' . PRESTASHOP_VERSION . '</li>';
        echo '<li><strong>URL Original:</strong> ' . SOURCE_URL . '</li>';
        echo '</ul>';
        echo '</div>';
        
        if ($this->backupZipFile) {
            echo '<div class="success-box">';
            echo '<h3>✓ Archivo de Backup Detectado</h3>';
            echo '<p>Archivo encontrado: <code>' . basename($this->backupZipFile) . '</code></p>';
            echo '<p>Tamaño: ' . $this->formatBytes(filesize($this->backupZipFile)) . '</p>';
            echo '</div>';
            
            echo '<div class="navigation">';
            echo '<a href="?step=requirements" class="btn btn-primary">Continuar →</a>';
            echo '</div>';
        } else {
            echo '<div class="error-box">';
            echo '<h3>⚠ Archivo de Backup No Encontrado</h3>';
            echo '<p>No se encontró el archivo ZIP de backup en este directorio.</p>';
            echo '<p>Asegúrate de subir el archivo <code>' . BACKUP_NAME . '_export.zip</code> al mismo directorio que este instalador.</p>';
            echo '</div>';
            
            echo '<div class="navigation">';
            echo '<a href="javascript:location.reload()" class="btn btn-primary">Recargar Página</a>';
            echo '</div>';
        }
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 2: Verificación de requisitos
     */
    private function showRequirementsStep()
    {
        $this->renderHeader("Verificación de Requisitos");
        
        $requirements = $this->checkSystemRequirements();
        $allPassed = true;
        
        echo '<div class="step-content">';
        echo '<h2>Verificación del Sistema</h2>';
        
        echo '<table class="requirements-table">';
        echo '<thead><tr><th>Requisito</th><th>Estado</th><th>Valor</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($requirements as $req) {
            $statusClass = $req['status'] ? 'success' : 'error';
            $statusText = $req['status'] ? '✓ OK' : '✗ Fallo';
            
            if (!$req['status']) {
                $allPassed = false;
            }
            
            echo "<tr class='{$statusClass}'>";
            echo "<td>{$req['name']}</td>";
            echo "<td>{$statusText}</td>";
            echo "<td>{$req['value']}</td>";
            echo "</tr>";
        }
        
        echo '</tbody></table>';
        
        if ($allPassed) {
            echo '<div class="success-box">';
            echo '<h3>✓ Todos los Requisitos Cumplidos</h3>';
            echo '<p>El sistema cumple con todos los requisitos necesarios para la instalación.</p>';
            echo '</div>';
            
            echo '<div class="navigation">';
            echo '<a href="?step=welcome" class="btn btn-secondary">← Atrás</a>';
            echo '<a href="?step=database" class="btn btn-primary">Continuar →</a>';
            echo '</div>';
        } else {
            echo '<div class="error-box">';
            echo '<h3>⚠ Requisitos No Cumplidos</h3>';
            echo '<p>Algunos requisitos del sistema no se cumplen. Por favor, corrige estos problemas antes de continuar.</p>';
            echo '</div>';
            
            echo '<div class="navigation">';
            echo '<a href="?step=welcome" class="btn btn-secondary">← Atrás</a>';
            echo '<a href="javascript:location.reload()" class="btn btn-primary">Verificar Nuevamente</a>';
            echo '</div>';
        }
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 3: Configuración de base de datos
     */
    private function showDatabaseStep()
    {
        $this->renderHeader("Configuración de Base de Datos");
        
        echo '<div class="step-content">';
        echo '<h2>Configuración de MySQL</h2>';
        echo '<p>Introduce los datos de conexión a la base de datos MySQL donde se restaurará el backup.</p>';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $dbConfig = [
                'host' => $_POST['db_host'] ?? '',
                'name' => $_POST['db_name'] ?? '',
                'user' => $_POST['db_user'] ?? '',
                'password' => $_POST['db_password'] ?? '',
                'prefix' => $_POST['db_prefix'] ?? 'ps_'
            ];
            
            $connectionTest = $this->testDatabaseConnection($dbConfig);
            
            if ($connectionTest['success']) {
                // Guardar configuración en sesión o archivo temporal
                $this->saveDbConfig($dbConfig);
                
                echo '<div class="success-box">';
                echo '<h3>✓ Conexión Exitosa</h3>';
                echo '<p>La conexión a la base de datos fue exitosa.</p>';
                echo '</div>';
                
                echo '<div class="navigation">';
                echo '<a href="?step=requirements" class="btn btn-secondary">← Atrás</a>';
                echo '<a href="?step=extract" class="btn btn-primary">Iniciar Instalación →</a>';
                echo '</div>';
            } else {
                echo '<div class="error-box">';
                echo '<h3>⚠ Error de Conexión</h3>';
                echo '<p>' . htmlspecialchars($connectionTest['error']) . '</p>';
                echo '</div>';
                $this->showDatabaseForm($dbConfig);
            }
        } else {
            $this->showDatabaseForm();
        }
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Formulario de configuración de base de datos
     */
    private function showDatabaseForm($config = [])
    {
        $host = $config['host'] ?? 'localhost';
        $name = $config['name'] ?? '';
        $user = $config['user'] ?? '';
        $password = $config['password'] ?? '';
        $prefix = $config['prefix'] ?? 'ps_';
        
        echo '<form method="post" class="db-form">';
        echo '<div class="form-group">';
        echo '<label for="db_host">Servidor MySQL:</label>';
        echo '<input type="text" id="db_host" name="db_host" value="' . htmlspecialchars($host) . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_name">Nombre de Base de Datos:</label>';
        echo '<input type="text" id="db_name" name="db_name" value="' . htmlspecialchars($name) . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_user">Usuario:</label>';
        echo '<input type="text" id="db_user" name="db_user" value="' . htmlspecialchars($user) . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_password">Contraseña:</label>';
        echo '<input type="password" id="db_password" name="db_password" value="' . htmlspecialchars($password) . '">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_prefix">Prefijo de Tablas:</label>';
        echo '<input type="text" id="db_prefix" name="db_prefix" value="' . htmlspecialchars($prefix) . '" required>';
        echo '</div>';
        
        echo '<div class="form-actions">';
        echo '<a href="?step=requirements" class="btn btn-secondary">← Atrás</a>';
        echo '<button type="submit" class="btn btn-primary">Probar Conexión</button>';
        echo '</div>';
        
        echo '</form>';
    }
    
    /**
     * Paso 4: Extracción del backup
     */
    private function performExtraction()
    {
        $this->renderHeader("Extrayendo Backup");
        
        echo '<div class="step-content">';
        echo '<h2>Extracción y Preparación</h2>';
        
        if (!$this->backupZipFile) {
            echo '<div class="error-box">';
            echo '<h3>Error: Archivo de backup no encontrado</h3>';
            echo '</div>';
            echo '<div class="navigation">';
            echo '<a href="?step=welcome" class="btn btn-primary">← Volver al Inicio</a>';
            echo '</div>';
            echo '</div>';
            $this->renderFooter();
            return;
        }
        
        echo '<div class="progress-container">';
        echo '<div class="progress-step active">Extrayendo archivos...</div>';
        echo '</div>';
        
        try {
            $extractResult = $this->extractBackupZip();
            
            if ($extractResult['success']) {
                echo '<div class="success-box">';
                echo '<h3>✓ Extracción Completada</h3>';
                echo '<p>Archivos extraídos: ' . $extractResult['files_count'] . '</p>';
                echo '<p>Base de datos encontrada: ' . ($extractResult['has_database'] ? 'Sí' : 'No') . '</p>';
                echo '<p>Archivos de tienda encontrados: ' . ($extractResult['has_files'] ? 'Sí' : 'No') . '</p>';
                echo '</div>';
                
                // Auto-redirect to installation
                echo '<script>';
                echo 'setTimeout(function() { window.location.href = "?step=install"; }, 2000);';
                echo '</script>';
                
                echo '<div class="navigation">';
                echo '<a href="?step=install" class="btn btn-primary">Continuar con Instalación →</a>';
                echo '</div>';
            } else {
                echo '<div class="error-box">';
                echo '<h3>Error en la Extracción</h3>';
                echo '<p>' . htmlspecialchars($extractResult['error']) . '</p>';
                echo '</div>';
                
                echo '<div class="navigation">';
                echo '<a href="?step=database" class="btn btn-secondary">← Atrás</a>';
                echo '<a href="?step=extract" class="btn btn-primary">Reintentar</a>';
                echo '</div>';
            }
        } catch (Exception $e) {
            echo '<div class="error-box">';
            echo '<h3>Error Fatal</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 5: Instalación
     */
    private function performInstallation()
    {
        $this->renderHeader("Instalando PrestaShop");
        
        echo '<div class="step-content">';
        echo '<h2>Proceso de Instalación</h2>';
        
        echo '<div class="progress-container">';
        echo '<div class="progress-step active">Restaurando base de datos...</div>';
        echo '<div class="progress-step">Restaurando archivos...</div>';
        echo '<div class="progress-step">Configurando sistema...</div>';
        echo '<div class="progress-step">Finalizando...</div>';
        echo '</div>';
        
        // Obtener configuración de DB
        $dbConfig = $this->loadDbConfig();
        if (!$dbConfig) {
            echo '<div class="error-box">';
            echo '<h3>Error: Configuración de base de datos no encontrada</h3>';
            echo '</div>';
            echo '<div class="navigation">';
            echo '<a href="?step=database" class="btn btn-primary">← Configurar Base de Datos</a>';
            echo '</div>';
            echo '</div>';
            $this->renderFooter();
            return;
        }
        
        try {
            // Simular proceso de instalación con PS_Copia
            $installResult = $this->performPsCopiaRestore($dbConfig);
            
            if ($installResult['success']) {
                echo '<script>';
                echo 'document.querySelectorAll(".progress-step").forEach(step => step.classList.add("active"));';
                echo 'setTimeout(function() { window.location.href = "?step=complete"; }, 3000);';
                echo '</script>';
                
                echo '<div class="success-box">';
                echo '<h3>✓ Instalación Completada</h3>';
                echo '<p>La restauración se ha completado exitosamente.</p>';
                echo '</div>';
                
                echo '<div class="navigation">';
                echo '<a href="?step=complete" class="btn btn-primary">Finalizar →</a>';
                echo '</div>';
            } else {
                echo '<div class="error-box">';
                echo '<h3>Error en la Instalación</h3>';
                echo '<p>' . htmlspecialchars($installResult['error']) . '</p>';
                echo '</div>';
                
                echo '<div class="navigation">';
                echo '<a href="?step=extract" class="btn btn-secondary">← Atrás</a>';
                echo '<a href="?step=install" class="btn btn-primary">Reintentar</a>';
                echo '</div>';
            }
        } catch (Exception $e) {
            echo '<div class="error-box">';
            echo '<h3>Error Fatal</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 6: Completado
     */
    private function showCompleteStep()
    {
        $this->renderHeader("Instalación Completada");
        
        echo '<div class="step-content">';
        echo '<h2>🎉 ¡Instalación Exitosa!</h2>';
        echo '<p>Tu tienda PrestaShop ha sido restaurada exitosamente.</p>';
        
        $currentUrl = $this->getCurrentUrl();
        $shopUrl = dirname($currentUrl);
        
        echo '<div class="success-box">';
        echo '<h3>Acceso a tu Tienda</h3>';
        echo '<ul>';
        echo '<li><strong>Frontend:</strong> <a href="' . $shopUrl . '" target="_blank">' . $shopUrl . '</a></li>';
        echo '<li><strong>Admin:</strong> <a href="' . $shopUrl . '/admin" target="_blank">' . $shopUrl . '/admin</a></li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="warning-box">';
        echo '<h3>⚠ Importante - Seguridad</h3>';
        echo '<ul>';
        echo '<li>Elimina este archivo instalador: <code>' . basename(__FILE__) . '</code></li>';
        echo '<li>Elimina cualquier archivo ZIP de backup del directorio web</li>';
        echo '<li>Cambia las contraseñas de administrador</li>';
        echo '<li>Verifica la configuración de URLs en el admin</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="navigation">';
        echo '<a href="' . $shopUrl . '" class="btn btn-primary" target="_blank">Visitar Tienda →</a>';
        echo '<a href="' . $shopUrl . '/admin" class="btn btn-secondary" target="_blank">Ir al Admin</a>';
        echo '</div>';
        
        echo '</div>';
        $this->renderFooter();
    }
    
    // ... [Métodos auxiliares continuarán en la siguiente parte]
    
    private function logMessage($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    private function getCurrentUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }
    
    private function checkSystemRequirements()
    {
        return [
            [
                'name' => 'PHP Version',
                'status' => version_compare(PHP_VERSION, '5.6.0', '>='),
                'value' => PHP_VERSION
            ],
            [
                'name' => 'Memory Limit',
                'status' => $this->checkMemoryLimit(),
                'value' => ini_get('memory_limit')
            ],
            [
                'name' => 'ZIP Extension',
                'status' => extension_loaded('zip'),
                'value' => extension_loaded('zip') ? 'Disponible' : 'No disponible'
            ],
            [
                'name' => 'MySQLi Extension',
                'status' => extension_loaded('mysqli'),
                'value' => extension_loaded('mysqli') ? 'Disponible' : 'No disponible'
            ],
            [
                'name' => 'Write Permissions',
                'status' => is_writable(dirname(__FILE__)),
                'value' => is_writable(dirname(__FILE__)) ? 'Escribible' : 'Solo lectura'
            ]
        ];
    }
    
    private function checkMemoryLimit()
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit == -1) return true;
        
        $memoryBytes = $this->convertToBytes($memoryLimit);
        return $memoryBytes >= 512 * 1024 * 1024; // 512MB minimum
    }
    
    private function convertToBytes($value)
    {
        $unit = strtolower(substr($value, -1, 1));
        $value = (int)$value;
        
        switch ($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }
    
    private function testDatabaseConnection($config)
    {
        try {
            $connection = new mysqli($config['host'], $config['user'], $config['password'], $config['name']);
            
            if ($connection->connect_error) {
                return [
                    'success' => false,
                    'error' => 'Error de conexión: ' . $connection->connect_error
                ];
            }
            
            $connection->close();
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    private function saveDbConfig($config)
    {
        $configFile = dirname(__FILE__) . '/installer_db_config.json';
        file_put_contents($configFile, json_encode($config));
    }
    
    private function loadDbConfig()
    {
        $configFile = dirname(__FILE__) . '/installer_db_config.json';
        if (file_exists($configFile)) {
            return json_decode(file_get_contents($configFile), true);
        }
        return null;
    }
    
    private function extractBackupZip()
    {
        $extractDir = dirname(__FILE__) . '/extracted_backup';
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($this->backupZipFile);
        
        if ($result !== TRUE) {
            return [
                'success' => false,
                'error' => 'No se pudo abrir el archivo ZIP: ' . $this->getZipError($result)
            ];
        }
        
        $extractResult = $zip->extractTo($extractDir);
        $filesCount = $zip->numFiles;
        
        // Verificar contenido extraído
        $hasDatabase = is_dir($extractDir . '/database') || !empty(glob($extractDir . '/*.sql'));
        $hasFiles = is_dir($extractDir . '/files') || !empty(glob($extractDir . '/*.zip'));
        
        $zip->close();
        
        if (!$extractResult) {
            return [
                'success' => false,
                'error' => 'Error al extraer archivos del ZIP'
            ];
        }
        
        return [
            'success' => true,
            'files_count' => $filesCount,
            'has_database' => $hasDatabase,
            'has_files' => $hasFiles,
            'extract_dir' => $extractDir
        ];
    }
    
    private function performPsCopiaRestore($dbConfig)
    {
        // Simular el proceso de restauración usando las clases de PS_Copia
        // Este método implementaría la lógica de restauración similar a ImportExportService
        
        $extractDir = dirname(__FILE__) . '/extracted_backup';
        
        if (!is_dir($extractDir)) {
            return [
                'success' => false,
                'error' => 'Directorio de extracción no encontrado'
            ];
        }
        
        try {
            // 1. Restaurar base de datos
            $dbRestoreResult = $this->restoreDatabase($extractDir, $dbConfig);
            if (!$dbRestoreResult['success']) {
                return $dbRestoreResult;
            }
            
            // 2. Restaurar archivos
            $filesRestoreResult = $this->restoreFiles($extractDir);
            if (!$filesRestoreResult['success']) {
                return $filesRestoreResult;
            }
            
            // 3. Configurar sistema
            $configResult = $this->configureSystem($dbConfig);
            if (!$configResult['success']) {
                return $configResult;
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error durante la restauración: ' . $e->getMessage()
            ];
        }
    }
    
    private function restoreDatabase($extractDir, $dbConfig)
    {
        // Buscar archivos SQL (incluyendo .gz)
        $sqlFiles = array_merge(
            glob($extractDir . '/database/*.sql'),
            glob($extractDir . '/database/*.sql.gz'),
            glob($extractDir . '/*.sql'),
            glob($extractDir . '/*.sql.gz')
        );
        
        if (empty($sqlFiles)) {
            $this->logMessage("No SQL files found. Checking directory contents:");
            $this->logMessage("Database dir exists: " . (is_dir($extractDir . '/database') ? 'YES' : 'NO'));
            if (is_dir($extractDir . '/database')) {
                $dbFiles = scandir($extractDir . '/database');
                $this->logMessage("Database dir contents: " . implode(', ', $dbFiles));
            }
            
            return [
                'success' => false,
                'error' => 'No se encontró archivo de base de datos SQL'
            ];
        }
        
        $sqlFile = $sqlFiles[0];
        $this->logMessage("Found SQL file: " . basename($sqlFile));
        
        // Detectar si es archivo comprimido
        $isGzipped = pathinfo($sqlFile, PATHINFO_EXTENSION) === 'gz';
        $this->logMessage("SQL file is gzipped: " . ($isGzipped ? 'YES' : 'NO'));
        
        try {
            $connection = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['password'], $dbConfig['name']);
            
            if ($connection->connect_error) {
                return [
                    'success' => false,
                    'error' => 'Error de conexión a la base de datos'
                ];
            }
            
            // Leer archivo SQL usando la lógica de RestoreService
            if ($isGzipped) {
                $this->logMessage("Reading gzipped SQL file");
                $handle = gzopen($sqlFile, 'r');
                if (!$handle) {
                    return [
                        'success' => false,
                        'error' => 'No se pudo abrir el archivo SQL comprimido'
                    ];
                }
                
                $sql = '';
                while (!gzeof($handle)) {
                    $sql .= gzread($handle, 8192);
                }
                gzclose($handle);
            } else {
                $this->logMessage("Reading regular SQL file");
                $sql = file_get_contents($sqlFile);
            }
            
            if ($sql === false || empty($sql)) {
                return [
                    'success' => false,
                    'error' => 'El archivo SQL está vacío o no se pudo leer'
                ];
            }
            
            // Para la mayoría de archivos, usar comando mysql directo (más robustos para caracteres especiales)
            $fileSize = filesize($sqlFile);
            if ($fileSize > 1 * 1024 * 1024) { // Si es mayor a 1MB (reducido de 50MB)
                $this->logMessage("Using mysql command for better compatibility with special characters");
                return $this->restoreDatabaseViaCommand($sqlFile, $dbConfig, $isGzipped);
            }
            
            // Para archivos muy pequeños, ejecutar SQL via multi_query
            $this->logMessage("Processing small SQL file via PHP multi_query");
            
            // Ejecutar SQL en lotes para manejar archivos grandes
            $result = $connection->multi_query($sql);
            
            // Procesar todos los resultados
            do {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            } while ($connection->more_results() && $connection->next_result());
            
            $connection->close();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error ejecutando SQL: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Restaurar base de datos usando comandos del sistema (más eficiente para archivos grandes)
     */
    private function restoreDatabaseViaCommand($sqlFile, $dbConfig, $isGzipped)
    {
        $this->logMessage("Restoring database via system command");
        
        // Verificar que mysql esté disponible
        $mysqlTest = shell_exec('mysql --version 2>&1');
        if (strpos($mysqlTest, 'mysql') === false) {
            $this->logMessage("MySQL command not available, falling back to PHP method");
            return [
                'success' => false,
                'error' => 'Comando MySQL no disponible, use archivos SQL más pequeños'
            ];
        }
        
        $this->logMessage("MySQL command available: " . trim($mysqlTest));
        
        // Construir comando según si el archivo está comprimido o no
        if ($isGzipped) {
            $command = sprintf(
                'zcat %s | mysql --host=%s --user=%s --password=%s %s 2>&1',
                escapeshellarg($sqlFile),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['user']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['name'])
            );
        } else {
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['user']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['name']),
                escapeshellarg($sqlFile)
            );
        }
        
        // Log command (sin password por seguridad)
        $safeCommand = str_replace($dbConfig['password'], '***', $command);
        $this->logMessage("Executing command: " . $safeCommand);
        
        // Ejecutar comando
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $errorOutput = implode("\n", $output);
            $this->logMessage("MySQL command failed with code $returnVar: " . $errorOutput);
            
            // Extraer información específica del error SQL
            $sqlError = $this->extractSqlErrorInfo($errorOutput);
            if ($sqlError) {
                $this->logMessage("SQL Error detected - Line: " . ($sqlError['line'] ?? 'unknown') . ", Message: " . $sqlError['message']);
                
                // Intentar analizar la línea problemática
                if (isset($sqlError['line']) && is_numeric($sqlError['line'])) {
                    $this->analyzeSqlLine($sqlFile, $sqlError['line'], $isGzipped);
                }
            }
            
            return [
                'success' => false,
                'error' => 'Error ejecutando restauración MySQL: ' . $errorOutput
            ];
        }
        
        $this->logMessage("Database restored successfully via command");
        return ['success' => true];
    }
    
    /**
     * Extraer información específica de errores SQL del output de MySQL
     */
    private function extractSqlErrorInfo($errorOutput)
    {
        // Patrón para extraer errores SQL de MySQL/MariaDB
        if (preg_match('/ERROR \d+ \(\d+\) at line (\d+): (.+)/', $errorOutput, $matches)) {
            return [
                'line' => intval($matches[1]),
                'message' => trim($matches[2])
            ];
        }
        
        // Patrón alternativo
        if (preg_match('/at line (\d+)/', $errorOutput, $matches)) {
            return [
                'line' => intval($matches[1]),
                'message' => $errorOutput
            ];
        }
        
        return null;
    }
    
    /**
     * Analizar una línea específica del archivo SQL para diagnosticar problemas
     */
    private function analyzeSqlLine($sqlFile, $targetLine, $isGzipped)
    {
        $this->logMessage("Analyzing SQL line $targetLine for issues");
        
        try {
            if ($isGzipped) {
                $handle = gzopen($sqlFile, 'r');
                $readFunction = 'gzgets';
            } else {
                $handle = fopen($sqlFile, 'r');
                $readFunction = 'fgets';
            }
            
            if (!$handle) {
                $this->logMessage("Could not open SQL file for analysis");
                return;
            }
            
            $currentLine = 0;
            $contextRange = 2; // Líneas antes y después
            $contextLines = [];
            
            while (($line = $readFunction($handle)) !== false) {
                $currentLine++;
                
                // Recoger contexto alrededor de la línea problemática
                if ($currentLine >= ($targetLine - $contextRange) && $currentLine <= ($targetLine + $contextRange)) {
                    $contextLines[] = [
                        'line_number' => $currentLine,
                        'content' => rtrim($line),
                        'is_target' => $currentLine == $targetLine
                    ];
                }
                
                if ($currentLine > ($targetLine + $contextRange)) {
                    break;
                }
            }
            
            if ($isGzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            
            // Log del contexto
            foreach ($contextLines as $contextLine) {
                $marker = $contextLine['is_target'] ? ' >>> ' : '     ';
                $this->logMessage("Line {$contextLine['line_number']}:{$marker}" . substr($contextLine['content'], 0, 200));
                
                if ($contextLine['is_target']) {
                    $this->checkLineForIssues($contextLine['content'], $contextLine['line_number']);
                }
            }
            
        } catch (Exception $e) {
            $this->logMessage("Error analyzing SQL line: " . $e->getMessage());
        }
    }
    
    /**
     * Verificar una línea SQL por problemas comunes
     */
    private function checkLineForIssues($line, $lineNumber)
    {
        $issues = [];
        
        // Verificar caracteres problemáticos
        if (preg_match('/[^\x20-\x7E\x09\x0A\x0D]/', $line)) {
            $issues[] = "Contains non-ASCII characters";
        }
        
        // Verificar comillas no balanceadas
        $singleQuotes = substr_count($line, "'") - substr_count($line, "\\'");
        if ($singleQuotes % 2 !== 0) {
            $issues[] = "Unmatched single quotes";
        }
        
        // Verificar paréntesis no balanceados
        $openParens = substr_count($line, '(');
        $closeParens = substr_count($line, ')');
        if ($openParens !== $closeParens) {
            $issues[] = "Unmatched parentheses";
        }
        
        // Verificar longitud extrema
        if (strlen($line) > 2000) {
            $issues[] = "Very long line (" . strlen($line) . " chars)";
        }
        
        if (!empty($issues)) {
            $this->logMessage("Line $lineNumber issues: " . implode(", ", $issues));
        }
    }
    
    private function restoreFiles($extractDir)
    {
        // Buscar archivo ZIP de archivos
        $fileZips = array_merge(
            glob($extractDir . '/files/*.zip'),
            glob($extractDir . '/*_files.zip')
        );
        
        if (empty($fileZips)) {
            // Puede que los archivos ya estén extraídos en files/
            if (is_dir($extractDir . '/files')) {
                return $this->copyExtractedFiles($extractDir . '/files');
            }
            
            return [
                'success' => false,
                'error' => 'No se encontraron archivos de la tienda para restaurar'
            ];
        }
        
        $filesZip = $fileZips[0];
        $this->logMessage("Extracting files from: " . basename($filesZip));
        
        // NUEVA ESTRATEGIA: Extraer a directorio temporal primero
        $tempDir = sys_get_temp_dir() . '/ps_copia_files_restore_' . time();
        if (!mkdir($tempDir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'No se pudo crear directorio temporal'
            ];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($filesZip);
        
        if ($result !== TRUE) {
            $this->removeDirectory($tempDir);
            return [
                'success' => false,
                'error' => 'No se pudo abrir el ZIP de archivos: ' . $this->getZipError($result)
            ];
        }
        
        // Extraer a directorio temporal
        $extractResult = $zip->extractTo($tempDir);
        $zip->close();
        
        if (!$extractResult) {
            $this->removeDirectory($tempDir);
            return [
                'success' => false,
                'error' => 'Error al extraer archivos al directorio temporal'
            ];
        }
        
        // Ahora copiar de forma segura al destino final
        $targetDir = dirname(dirname(__FILE__)); // Directorio padre del instalador
        $result = $this->copyFilesSecurely($tempDir, $targetDir);
        
        // Limpiar directorio temporal
        $this->removeDirectory($tempDir);
        
        return $result;
    }
    
    private function copyFilesSecurely($sourceDir, $destinationDir)
    {
        if (!is_dir($sourceDir)) {
            return ['success' => false, 'error' => 'Directorio fuente no existe'];
        }
        
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }
        
        $this->logMessage("Copying files securely from temp to destination");
        
        // Archivos a excluir para evitar conflictos
        $excludeFiles = [
            'ps_copias_installer.php',  // El instalador mismo
            basename(__FILE__),         // El archivo actual del instalador
            'installer_log_*.txt'       // Logs del instalador
        ];
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $copiedFiles = 0;
        $skippedFiles = 0;
        
        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            $target = $destinationDir . DIRECTORY_SEPARATOR . $relativePath;
            
            // Verificar si debe excluirse
            if ($this->shouldExcludeFile($relativePath, $excludeFiles)) {
                $this->logMessage("Skipping excluded file: " . $relativePath);
                $skippedFiles++;
                continue;
            }
            
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    if (!mkdir($target, 0755, true)) {
                        return [
                            'success' => false, 
                            'error' => 'Error creando directorio: ' . $relativePath
                        ];
                    }
                }
            } else {
                // Crear directorio padre si no existe
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                if (!copy($item, $target)) {
                    return [
                        'success' => false, 
                        'error' => 'Error copiando archivo: ' . $relativePath
                    ];
                }
                $copiedFiles++;
            }
        }
        
        $this->logMessage("Files copied successfully: $copiedFiles files, $skippedFiles skipped");
        return ['success' => true];
    }
    
    private function shouldExcludeFile($relativePath, $excludeFiles)
    {
        $fileName = basename($relativePath);
        
        foreach ($excludeFiles as $excludePattern) {
            if (fnmatch($excludePattern, $fileName)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function copyExtractedFiles($sourceDir)
    {
        $targetDir = dirname(dirname(__FILE__));
        
        $this->logMessage("Copying files from: " . $sourceDir);
        
        // Usar la nueva función segura de copia
        return $this->copyFilesSecurely($sourceDir, $targetDir);
    }
    
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDirectory("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
    
    private function configureSystem($dbConfig)
    {
        // Configurar PrestaShop para el nuevo entorno
        $currentUrl = $this->getCurrentUrl();
        $baseUrl = dirname($currentUrl);
        
        try {
            $connection = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['password'], $dbConfig['name']);
            
            if ($connection->connect_error) {
                return [
                    'success' => false,
                    'error' => 'Error conectando para configuración'
                ];
            }
            
            $domain = parse_url($baseUrl, PHP_URL_HOST);
            $prefix = $dbConfig['prefix'];
            
            // Actualizar configuración de dominio
            $queries = [
                "UPDATE `{$prefix}shop_url` SET `domain` = '{$domain}', `domain_ssl` = '{$domain}'",
                "UPDATE `{$prefix}configuration` SET `value` = '{$domain}' WHERE `name` = 'PS_SHOP_DOMAIN'",
                "UPDATE `{$prefix}configuration` SET `value` = '{$domain}' WHERE `name` = 'PS_SHOP_DOMAIN_SSL'"
            ];
            
            foreach ($queries as $query) {
                $connection->query($query);
            }
            
            $connection->close();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error en configuración: ' . $e->getMessage()
            ];
        }
    }
    
    private function getZipError($code)
    {
        switch($code) {
            case ZipArchive::ER_OK: return 'No error';
            case ZipArchive::ER_MULTIDISK: return 'Multi-disk zip archives not supported';
            case ZipArchive::ER_RENAME: return 'Renaming temporary file failed';
            case ZipArchive::ER_CLOSE: return 'Closing zip archive failed';
            case ZipArchive::ER_SEEK: return 'Seek error';
            case ZipArchive::ER_READ: return 'Read error';
            case ZipArchive::ER_WRITE: return 'Write error';
            case ZipArchive::ER_CRC: return 'CRC error';
            case ZipArchive::ER_ZIPCLOSED: return 'Containing zip archive was closed';
            case ZipArchive::ER_NOENT: return 'No such file';
            case ZipArchive::ER_EXISTS: return 'File already exists';
            case ZipArchive::ER_OPEN: return 'Can\'t open file';
            case ZipArchive::ER_TMPOPEN: return 'Failure to create temporary file';
            case ZipArchive::ER_ZLIB: return 'Zlib error';
            case ZipArchive::ER_MEMORY: return 'Memory allocation failure';
            case ZipArchive::ER_CHANGED: return 'Entry has been changed';
            case ZipArchive::ER_COMPNOTSUPP: return 'Compression method not supported';
            case ZipArchive::ER_EOF: return 'Premature EOF';
            case ZipArchive::ER_INVAL: return 'Invalid argument';
            case ZipArchive::ER_NOZIP: return 'Not a zip archive';
            case ZipArchive::ER_INTERNAL: return 'Internal error';
            case ZipArchive::ER_INCONS: return 'Zip archive inconsistent';
            case ZipArchive::ER_REMOVE: return 'Can\'t remove file';
            case ZipArchive::ER_DELETED: return 'Entry has been deleted';
            default: return 'Unknown error code: ' . $code;
        }
    }
    
    private function renderHeader($title)
    {
        echo '<!DOCTYPE html>';
        echo '<html lang="es">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . htmlspecialchars($title) . ' - PS Copias Installer</title>';
        echo '<style>';
        echo $this->getCSS();
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="container">';
        echo '<header>';
        echo '<h1>PS Copias - Instalador Simple</h1>';
        echo '<p>Versión ' . INSTALLER_VERSION . ' | ' . htmlspecialchars($title) . '</p>';
        echo '</header>';
    }
    
    private function renderFooter()
    {
        echo '<footer>';
        echo '<p>PS Copias Simple Installer v' . INSTALLER_VERSION . ' | ';
        echo 'Creado: ' . CREATION_DATE . '</p>';
        echo '</footer>';
        echo '</div>';
        echo '</body>';
        echo '</html>';
    }
    
    private function getCSS()
    {
        return '
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        header {
            background: #2c3e50;
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        header h1 { font-size: 2.5em; margin-bottom: 10px; }
        header p { opacity: 0.8; font-size: 1.1em; }
        
        .step-content { padding: 40px; }
        
        .info-box, .success-box, .error-box, .warning-box {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .info-box { background: #e8f4fd; border: 1px solid #bee5eb; }
        .success-box { background: #d4edda; border: 1px solid #c3e6cb; }
        .error-box { background: #f8d7da; border: 1px solid #f5c6cb; }
        .warning-box { background: #fff3cd; border: 1px solid #ffeaa7; }
        
        .requirements-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .requirements-table th, .requirements-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .requirements-table th { background: #f8f9fa; }
        .requirements-table tr.success { background: #d4edda; }
        .requirements-table tr.error { background: #f8d7da; }
        
        .db-form { max-width: 500px; margin: 20px 0; }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .progress-container {
            margin: 30px 0;
        }
        
        .progress-step {
            padding: 15px;
            background: #f8f9fa;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .progress-step.active {
            background: #d4edda;
            border-left-color: #28a745;
        }
        
        .navigation {
            margin: 40px 0 20px;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 0 10px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        footer {
            background: #34495e;
            color: white;
            padding: 20px;
            text-align: center;
            font-size: 0.9em;
        }
        
        h2 { color: #2c3e50; margin-bottom: 20px; }
        h3 { color: #34495e; margin-bottom: 15px; }
        
        ul { margin: 15px 0; padding-left: 20px; }
        li { margin: 8px 0; }
        
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: "Monaco", "Consolas", monospace;
        }
        
        a { color: #667eea; text-decoration: none; }
        a:hover { text-decoration: underline; }
        ';
    }
}

// Ejecutar instalador
try {
    $installer = new PsCopiasSimpleInstaller();
    $installer->run();
} catch (Exception $e) {
    echo '<h1>Error Fatal</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
}
?> 