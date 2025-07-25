<?php
/**
 * PS Copias Simple Standalone Installer - AJAX Version
 * 
 * Este instalador utiliza AJAX para manejar archivos grandes
 * Compatible con ZIP de exportación estándar de PS_Copia
 * No requiere estructura de PrestaShop
 * 
 * TEMPLATE VARIABLES TO REPLACE:
 * {
    "backup_name": "test_backup",
    "created_date": "2025-07-25 14:00:49",
    "prestashop_version": "8.0.0",
    "source_url": "http://localhost/",
    "export_zip_name": "test_backup_export.zip"
} - Configuration JSON with backup information
 * 2.0-improved - Version number
 * 2025-07-25 14:00:49 - Package creation date
 */

// Suprimir warnings y notices que pueden interferir con JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuración embebida - WILL BE REPLACED BY SERVICE
$EMBEDDED_CONFIG = json_decode('{
    "backup_name": "test_backup",
    "created_date": "2025-07-25 14:00:49",
    "prestashop_version": "8.0.0",
    "source_url": "http://localhost/",
    "export_zip_name": "test_backup_export.zip"
}', true);

// Configuración del instalador
define('INSTALLER_VERSION', '2.0-improved');
define('MAX_EXECUTION_TIME', 300); // 5 minutos por chunk
define('MEMORY_LIMIT', '512M');
define('CHUNK_SIZE', 50); // Archivos por chunk

// Configurar límites
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@ini_set('memory_limit', MEMORY_LIMIT);
@set_time_limit(MAX_EXECUTION_TIME);

class PsCopiasSimpleInstaller
{
    private $currentStep;
    private $config;
    private $backupZipFile = null;
    private $errors = [];
    private $warnings = [];
    private $logFile;
    private $extractDir;
    private $tempDir;
    
    public function __construct()
    {
        global $EMBEDDED_CONFIG;
        $this->config = $EMBEDDED_CONFIG;
        $this->currentStep = $_GET['step'] ?? 'welcome';
        $this->logFile = 'installer_log_' . date('Y-m-d_H-i-s') . '.txt';
        $this->extractDir = 'extracted_backup';
        $this->tempDir = 'temp_restore_' . time();
        $this->detectBackupZip();
    }
    
    /**
     * Detecta automáticamente el archivo ZIP de backup
     */
    private function detectBackupZip()
    {
        $currentDir = dirname(__FILE__);
        
        // Buscar el ZIP especificado en la configuración
        if (isset($this->config['export_zip_name'])) {
            $expectedZip = $currentDir . '/' . $this->config['export_zip_name'];
            if (file_exists($expectedZip)) {
                $this->backupZipFile = $expectedZip;
                return;
            }
        }
        
        // Buscar ZIP con el nombre del backup
        if (isset($this->config['backup_name'])) {
            $patterns = [
                $currentDir . '/' . $this->config['backup_name'] . '_export.zip',
                $currentDir . '/' . $this->config['backup_name'] . '.zip'
            ];
            
            foreach ($patterns as $pattern) {
                if (file_exists($pattern)) {
                    $this->backupZipFile = $pattern;
                    return;
                }
            }
        }
        
        // Buscar cualquier ZIP que parezca un backup
        $zipFiles = glob($currentDir . '/*.zip');
        foreach ($zipFiles as $zipFile) {
            if ($this->isValidBackupZip($zipFile)) {
                $this->backupZipFile = $zipFile;
                break;
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
        $this->logMessage("=== PS Copias Simple Installer AJAX Started ===");
        $this->logMessage("Version: " . INSTALLER_VERSION);
        $this->logMessage("Step: " . $this->currentStep);
        $this->logMessage("Backup: " . ($this->config['backup_name'] ?? 'Unknown'));
        
        // Manejar peticiones AJAX
        if (isset($_GET['ajax'])) {
            $this->handleAjaxRequest();
            return;
        }
        
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
                $this->showExtractionStep();
                break;
            case 'install':
                $this->showInstallationStep();
                break;
            case 'complete':
                $this->showCompleteStep();
                break;
            default:
                $this->showWelcomeStep();
        }
    }
    
    /**
     * Manejar peticiones AJAX
     */
    private function handleAjaxRequest()
    {
        // Limpiar cualquier output previo que pueda interferir con JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json');
        
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'extract_backup':
                    $this->ajaxExtractBackup();
                    break;
                case 'extract_files':
                    $this->ajaxExtractFiles();
                    break;
                case 'extract_files_chunk':
                    $this->ajaxExtractFilesChunk();
                    break;
                case 'restore_database':
                    $this->ajaxRestoreDatabase();
                    break;
                case 'configure_system':
                    $this->ajaxConfigureSystem();
                    break;
                case 'get_progress':
                    $this->ajaxGetProgress();
                    break;
                default:
                    throw new Exception('Acción AJAX no válida');
            }
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }
    }
    
    /**
     * AJAX: Extraer backup principal
     */
    private function ajaxExtractBackup()
    {
        if (!$this->backupZipFile || !file_exists($this->backupZipFile)) {
            throw new Exception('Archivo ZIP de backup no encontrado');
        }
        
        $this->logMessage("Starting backup extraction via AJAX");
        
        $zip = new ZipArchive();
        $result = $zip->open($this->backupZipFile);
        
        if ($result !== TRUE) {
            throw new Exception('No se pudo abrir el ZIP: ' . $this->getZipError($result));
        }
        
        // Crear directorio de extracción
        $extractPath = dirname(__FILE__) . '/' . $this->extractDir;
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }
        
        // Extraer el backup principal
        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new Exception('Error extrayendo el backup');
        }
        
        $zip->close();
        
        // Verificar que se extrajo correctamente
        $backupInfoPath = $extractPath . '/backup_info.json';
        if (!file_exists($backupInfoPath)) {
            throw new Exception('Información del backup no encontrada después de la extracción');
        }
        
        // Leer información del backup
        $backupInfo = json_decode(file_get_contents($backupInfoPath), true);
        if (!$backupInfo) {
            throw new Exception('No se pudo leer la información del backup');
        }
        
        $this->saveProgress('extract_backup', 100, 'Backup extraído correctamente');
        
        $this->ajaxSuccess([
            'message' => 'Backup extraído correctamente',
            'backup_info' => $backupInfo,
            'next_step' => 'extract_files'
        ]);
    }
    
    /**
     * AJAX: Iniciar extracción de archivos
     */
    private function ajaxExtractFiles()
    {
        $extractPath = dirname(__FILE__) . '/' . $this->extractDir;
        
        // Buscar archivo ZIP de archivos
        $filesZipPath = null;
        $possiblePaths = [
            $extractPath . '/files.zip',
            $extractPath . '/files/' . $this->config['backup_name'] . '_files.zip'
        ];
        
        // Buscar en subdirectorios
        if (is_dir($extractPath . '/files')) {
            $zipFiles = glob($extractPath . '/files/*.zip');
            if (!empty($zipFiles)) {
                $filesZipPath = $zipFiles[0];
            }
        }
        
        if (!$filesZipPath) {
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $filesZipPath = $path;
                    break;
                }
            }
        }
        
        if (!$filesZipPath || !file_exists($filesZipPath)) {
            throw new Exception('Archivo ZIP de archivos no encontrado en: ' . $extractPath);
        }
        
        $this->logMessage("Found files ZIP: " . basename($filesZipPath));
        
        // Abrir ZIP para contar archivos
        $zip = new ZipArchive();
        $result = $zip->open($filesZipPath);
        
        if ($result !== TRUE) {
            throw new Exception('No se pudo abrir el ZIP de archivos: ' . $this->getZipError($result));
        }
        
        $totalFiles = $zip->numFiles;
        $zip->close();
        
        $this->logMessage("Total files to extract: " . $totalFiles);
        
        // Inicializar progreso
        $this->saveProgress('extract_files', 0, 'Iniciando extracción de archivos...');
        
        $this->ajaxSuccess([
            'message' => 'Extracción de archivos iniciada',
            'total_files' => $totalFiles,
            'chunks_needed' => ceil($totalFiles / CHUNK_SIZE),
            'files_zip_path' => $filesZipPath
        ]);
    }
    
    /**
     * AJAX: Extraer chunk de archivos
     */
    private function ajaxExtractFilesChunk()
    {
        $chunkIndex = intval($_GET['chunk'] ?? 0);
        $filesZipPath = $_GET['files_zip_path'] ?? '';
        
        if (!file_exists($filesZipPath)) {
            throw new Exception('Archivo ZIP de archivos no encontrado');
        }
        
        $this->logMessage("Extracting files chunk: " . $chunkIndex);
        
        $zip = new ZipArchive();
        $result = $zip->open($filesZipPath);
        
        if ($result !== TRUE) {
            throw new Exception('No se pudo abrir el ZIP: ' . $this->getZipError($result));
        }
        
        $targetDir = dirname(__FILE__);
        $tempExtractDir = $targetDir . '/' . $this->tempDir;
        
        // Crear directorio temporal si no existe
        if (!is_dir($tempExtractDir)) {
            mkdir($tempExtractDir, 0755, true);
        }
        
        $startIndex = $chunkIndex * CHUNK_SIZE;
        $endIndex = min($startIndex + CHUNK_SIZE, $zip->numFiles);
        $extractedCount = 0;
        
        // Extraer archivos del chunk actual
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $fileName = $zip->getNameIndex($i);
            
            if ($fileName === false) {
                continue;
            }
            
            // Extraer archivo individual
            $fileContent = $zip->getFromIndex($i);
            if ($fileContent === false) {
                $this->logMessage("Warning: Could not extract file at index $i");
                continue;
            }
            
            $targetPath = $tempExtractDir . '/' . $fileName;
            $targetDir = dirname($targetPath);
            
            // Crear directorio si no existe
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            // Escribir archivo
            if (file_put_contents($targetPath, $fileContent) !== false) {
                $extractedCount++;
            } else {
                $this->logMessage("Warning: Could not write file: " . $fileName);
            }
        }
        
        $zip->close();
        
        $progress = (($chunkIndex + 1) * CHUNK_SIZE / $zip->numFiles) * 100;
        $progress = min($progress, 100);
        
        $this->saveProgress('extract_files', $progress, "Extrayendo archivos... chunk " . ($chunkIndex + 1));
        
        $isLastChunk = $endIndex >= $zip->numFiles;
        
        // Si es el último chunk, mover archivos a posición final
        if ($isLastChunk) {
            $this->moveExtractedFilesToFinalLocation();
            $this->saveProgress('extract_files', 100, 'Archivos extraídos correctamente');
        }
        
        $this->ajaxSuccess([
            'chunk' => $chunkIndex,
            'extracted_count' => $extractedCount,
            'progress' => $progress,
            'is_last_chunk' => $isLastChunk,
            'message' => $isLastChunk ? 'Extracción completada' : 'Chunk extraído correctamente'
        ]);
    }
    
    /**
     * Mover archivos extraídos a la ubicación final
     */
    private function moveExtractedFilesToFinalLocation()
    {
        $tempDir = dirname(__FILE__) . '/' . $this->tempDir;
        $targetDir = dirname(__FILE__);
        
        if (!is_dir($tempDir)) {
            return false;
        }
        
        $this->logMessage("Moving extracted files to final location");
        
        // Archivos a excluir
        $excludeFiles = [
            basename(__FILE__),
            'installer_log_*.txt',
            $this->extractDir,
            $this->tempDir
        ];
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            $target = $targetDir . '/' . $relativePath;
            
            // Verificar si debe excluirse
            if ($this->shouldExcludeFile($relativePath, $excludeFiles)) {
                continue;
            }
            
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                // Crear directorio padre si no existe
                $targetDirPath = dirname($target);
                if (!is_dir($targetDirPath)) {
                    mkdir($targetDirPath, 0755, true);
                }
                
                copy($item, $target);
            }
        }
        
        // Limpiar directorio temporal
        $this->removeDirectory($tempDir);
        
        return true;
    }
    
    /**
     * AJAX: Restaurar base de datos
     */
    private function ajaxRestoreDatabase()
    {
        $dbConfig = $this->loadDbConfig();
        if (!$dbConfig) {
            throw new Exception('Configuración de base de datos no encontrada');
        }
        
        $extractPath = dirname(__FILE__) . '/' . $this->extractDir;
        
        // Buscar archivo SQL
        $sqlFile = null;
        $possiblePaths = [
            $extractPath . '/database.sql',
            $extractPath . '/database.sql.gz',
            $extractPath . '/database/' . $this->config['backup_name'] . '_database.sql',
            $extractPath . '/database/' . $this->config['backup_name'] . '_database.sql.gz'
        ];
        
        // Buscar en subdirectorio database
        if (is_dir($extractPath . '/database')) {
            $sqlFiles = array_merge(
                glob($extractPath . '/database/*.sql'),
                glob($extractPath . '/database/*.sql.gz')
            );
            if (!empty($sqlFiles)) {
                $sqlFile = $sqlFiles[0];
            }
        }
        
        if (!$sqlFile) {
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $sqlFile = $path;
                    break;
                }
            }
        }
        
        if (!$sqlFile || !file_exists($sqlFile)) {
            throw new Exception('Archivo SQL no encontrado');
        }
        
        $this->logMessage("Restoring database from: " . basename($sqlFile));
        $this->saveProgress('restore_database', 0, 'Iniciando restauración de base de datos...');
        
        $result = $this->restoreDatabase($sqlFile, $dbConfig);
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        $this->saveProgress('restore_database', 100, 'Base de datos restaurada correctamente');
        
        $this->ajaxSuccess([
            'message' => 'Base de datos restaurada correctamente'
        ]);
    }
    
    /**
     * AJAX: Configurar sistema
     */
    private function ajaxConfigureSystem()
    {
        $dbConfig = $this->loadDbConfig();
        if (!$dbConfig) {
            throw new Exception('Configuración de base de datos no encontrada');
        }
        
        $this->logMessage("Configuring system");
        $this->saveProgress('configure_system', 0, 'Configurando sistema...');
        
        $result = $this->configureSystem($dbConfig);
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        $this->saveProgress('configure_system', 100, 'Sistema configurado correctamente');
        
        $this->ajaxSuccess([
            'message' => 'Sistema configurado correctamente'
        ]);
    }
    
    /**
     * AJAX: Obtener progreso
     */
    private function ajaxGetProgress()
    {
        $task = $_GET['task'] ?? '';
        $progress = $this->getProgress($task);
        
        $this->ajaxSuccess($progress);
    }
    
    /**
     * Paso 1: Bienvenida
     */
    private function showWelcomeStep()
    {
        $this->renderHeader("Bienvenido al Instalador Simple");
        
        echo '<div class="step-content">';
        echo '<h2>🚀 Instalador Simple de PS Copias</h2>';
        echo '<p>Este instalador restaurará tu tienda PrestaShop desde un backup de PS_Copia.</p>';
        
        if (!$this->backupZipFile) {
            echo '<div class="error-box">';
            echo '<h3>⚠ Archivo ZIP de Backup No Encontrado</h3>';
            echo '<p>No se encontró un archivo ZIP de backup válido en este directorio.</p>';
            echo '<p>Asegúrate de que el archivo ZIP del backup esté en el mismo directorio que este instalador.</p>';
            echo '</div>';
            echo '</div>';
            $this->renderFooter();
            return;
        }
        
        echo '<div class="info-box">';
        echo '<h3>📦 Backup Detectado</h3>';
        echo '<p><strong>Archivo:</strong> ' . basename($this->backupZipFile) . '</p>';
        echo '<p><strong>Tamaño:</strong> ' . $this->formatBytes(filesize($this->backupZipFile)) . '</p>';
        echo '<p><strong>Backup:</strong> ' . ($this->config['backup_name'] ?? 'Desconocido') . '</p>';
        echo '</div>';
        
        echo '<div class="warning-box">';
        echo '<h3>⚠ Importante</h3>';
        echo '<ul>';
        echo '<li>Este proceso sobrescribirá todos los archivos del directorio actual</li>';
        echo '<li>Asegúrate de tener una base de datos vacía o de respaldo</li>';
        echo '<li>El proceso puede tardar varios minutos según el tamaño del backup</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="navigation">';
        echo '<a href="?step=requirements" class="btn btn-primary">Continuar →</a>';
        echo '</div>';
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 2: Verificación de requisitos
     */
    private function showRequirementsStep()
    {
        $this->renderHeader("Verificación de Requisitos");
        
        echo '<div class="step-content">';
        echo '<h2>🔍 Verificación de Requisitos del Sistema</h2>';
        
        $requirements = $this->checkSystemRequirements();
        $allRequirementsMet = true;
        
        echo '<table class="requirements-table">';
        echo '<thead><tr><th>Requisito</th><th>Estado</th><th>Valor</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($requirements as $req) {
            $statusClass = $req['status'] ? 'status-ok' : 'status-error';
            $statusIcon = $req['status'] ? '✓' : '✗';
            
            if (!$req['status']) {
                $allRequirementsMet = false;
            }
            
            echo "<tr>";
            echo "<td>{$req['name']}</td>";
            echo "<td class='{$statusClass}'>{$statusIcon}</td>";
            echo "<td>{$req['value']}</td>";
            echo "</tr>";
        }
        
        echo '</tbody></table>';
        
        if ($allRequirementsMet) {
            echo '<div class="success-box">';
            echo '<h3>✓ Todos los Requisitos Cumplidos</h3>';
            echo '<p>Tu servidor cumple con todos los requisitos para la instalación.</p>';
            echo '</div>';
        } else {
            echo '<div class="error-box">';
            echo '<h3>✗ Requisitos No Cumplidos</h3>';
            echo '<p>Tu servidor no cumple con algunos requisitos. Por favor, contacta con tu proveedor de hosting.</p>';
            echo '</div>';
        }
        
        echo '<div class="navigation">';
        echo '<a href="?step=welcome" class="btn btn-secondary">← Atrás</a>';
        if ($allRequirementsMet) {
            echo '<a href="?step=database" class="btn btn-primary">Continuar →</a>';
        }
        echo '</div>';
        
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
        echo '<h2>🗄 Configuración de la Base de Datos</h2>';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processDatabaseConfig();
        }
        
        $savedConfig = $this->loadDbConfig();
        
        echo '<form method="post" class="db-config-form">';
        echo '<div class="form-group">';
        echo '<label for="host">Servidor de Base de Datos:</label>';
        echo '<input type="text" id="host" name="host" value="' . ($savedConfig['host'] ?? 'localhost') . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="user">Usuario:</label>';
        echo '<input type="text" id="user" name="user" value="' . ($savedConfig['user'] ?? '') . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="password">Contraseña:</label>';
        echo '<input type="password" id="password" name="password" value="' . ($savedConfig['password'] ?? '') . '">';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="name">Nombre de la Base de Datos:</label>';
        echo '<input type="text" id="name" name="name" value="' . ($savedConfig['name'] ?? '') . '" required>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="prefix">Prefijo de Tablas (opcional):</label>';
        echo '<input type="text" id="prefix" name="prefix" value="' . ($savedConfig['prefix'] ?? 'ps_') . '">';
        echo '</div>';
        
        echo '<button type="submit" name="test_connection" class="btn btn-secondary">Probar Conexión</button>';
        echo '<button type="submit" name="save_config" class="btn btn-primary">Guardar y Continuar</button>';
        echo '</form>';
        
        echo '<div class="navigation">';
        echo '<a href="?step=requirements" class="btn btn-secondary">← Atrás</a>';
        if ($savedConfig) {
            echo '<a href="?step=extract" class="btn btn-primary">Continuar →</a>';
        }
        echo '</div>';
        
        echo '</div>';
        $this->renderFooter();
    }
    
    /**
     * Paso 4: Extracción (AJAX)
     */
    private function showExtractionStep()
    {
        $this->renderHeader("Extrayendo Backup");
        
        echo '<div class="step-content">';
        echo '<h2>📦 Extrayendo Backup</h2>';
        
        echo '<div id="extraction-progress">';
        echo '<div class="progress-step" id="step-extract-backup">Extrayendo backup principal...</div>';
        echo '<div class="progress-step" id="step-extract-files">Extrayendo archivos...</div>';
        echo '<div class="progress-step" id="step-complete">Extracción completada</div>';
        echo '</div>';
        
        echo '<div class="progress-bar-container">';
        echo '<div class="progress-bar" id="progress-bar"></div>';
        echo '</div>';
        
        echo '<div id="current-status">Iniciando...</div>';
        echo '<div id="extraction-log"></div>';
        
        echo '</div>';
        
        // JavaScript para manejar la extracción AJAX
        echo '<script>
        let currentStep = 0;
        let totalFiles = 0;
        let currentChunk = 0;
        let totalChunks = 0;
        
        function updateProgress(percentage, message) {
            const progressBar = document.getElementById("progress-bar");
            const statusDiv = document.getElementById("current-status");
            
            progressBar.style.width = percentage + "%";
            statusDiv.textContent = message;
        }
        
        function addLogMessage(message) {
            const logDiv = document.getElementById("extraction-log");
            const logEntry = document.createElement("div");
            logEntry.className = "log-entry";
            logEntry.textContent = new Date().toLocaleTimeString() + ": " + message;
            logDiv.appendChild(logEntry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        function markStepComplete(stepId) {
            const step = document.getElementById(stepId);
            if (step) {
                step.classList.add("completed");
            }
        }
        
        async function startExtraction() {
            try {
                // Paso 1: Extraer backup principal
                addLogMessage("Iniciando extracción del backup principal...");
                const backupResult = await fetch("?ajax=1&action=extract_backup");
                const backupData = await backupResult.json();
                
                if (!backupData.success) {
                    throw new Error(backupData.error);
                }
                
                markStepComplete("step-extract-backup");
                addLogMessage("Backup principal extraído correctamente");
                updateProgress(33, "Iniciando extracción de archivos...");
                
                // Paso 2: Iniciar extracción de archivos
                const filesResult = await fetch("?ajax=1&action=extract_files");
                const filesData = await filesResult.json();
                
                if (!filesData.success) {
                    throw new Error(filesData.error);
                }
                
                totalFiles = filesData.data.total_files;
                totalChunks = filesData.data.chunks_needed;
                const filesZipPath = encodeURIComponent(filesData.data.files_zip_path);
                
                addLogMessage(`Extrayendo ${totalFiles} archivos en ${totalChunks} chunks...`);
                
                // Paso 3: Extraer archivos por chunks
                for (let chunk = 0; chunk < totalChunks; chunk++) {
                    const chunkResult = await fetch(`?ajax=1&action=extract_files_chunk&chunk=${chunk}&files_zip_path=${filesZipPath}`);
                    const chunkData = await chunkResult.json();
                    
                    if (!chunkData.success) {
                        throw new Error(chunkData.error);
                    }
                    
                    const progress = 33 + (chunkData.data.progress * 0.67);
                    updateProgress(progress, `Extrayendo chunk ${chunk + 1} de ${totalChunks}...`);
                    addLogMessage(`Chunk ${chunk + 1}/${totalChunks} completado (${chunkData.data.extracted_count} archivos)`);
                }
                
                markStepComplete("step-extract-files");
                markStepComplete("step-complete");
                updateProgress(100, "Extracción completada");
                addLogMessage("¡Extracción completada exitosamente!");
                
                // Redirigir al siguiente paso
                setTimeout(() => {
                    window.location.href = "?step=install";
                }, 2000);
                
            } catch (error) {
                addLogMessage("ERROR: " + error.message);
                updateProgress(0, "Error en la extracción");
                console.error("Extraction error:", error);
            }
        }
        
        // Iniciar automáticamente
        document.addEventListener("DOMContentLoaded", startExtraction);
        </script>';
        
        $this->renderFooter();
    }
    
    /**
     * Paso 5: Instalación (AJAX)
     */
    private function showInstallationStep()
    {
        $this->renderHeader("Instalando PrestaShop");
        
        echo '<div class="step-content">';
        echo '<h2>⚙️ Restaurando PrestaShop</h2>';
        
        echo '<div id="installation-progress">';
        echo '<div class="progress-step" id="step-restore-db">Restaurando base de datos...</div>';
        echo '<div class="progress-step" id="step-configure">Configurando sistema...</div>';
        echo '<div class="progress-step" id="step-complete-install">Instalación completada</div>';
        echo '</div>';
        
        echo '<div class="progress-bar-container">';
        echo '<div class="progress-bar" id="progress-bar"></div>';
        echo '</div>';
        
        echo '<div id="current-status">Iniciando...</div>';
        echo '<div id="installation-log"></div>';
        
        echo '</div>';
        
        // JavaScript para manejar la instalación AJAX
        echo '<script>
        function updateProgress(percentage, message) {
            const progressBar = document.getElementById("progress-bar");
            const statusDiv = document.getElementById("current-status");
            
            progressBar.style.width = percentage + "%";
            statusDiv.textContent = message;
        }
        
        function addLogMessage(message) {
            const logDiv = document.getElementById("installation-log");
            const logEntry = document.createElement("div");
            logEntry.className = "log-entry";
            logEntry.textContent = new Date().toLocaleTimeString() + ": " + message;
            logDiv.appendChild(logEntry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        function markStepComplete(stepId) {
            const step = document.getElementById(stepId);
            if (step) {
                step.classList.add("completed");
            }
        }
        
        async function startInstallation() {
            try {
                // Paso 1: Restaurar base de datos
                addLogMessage("Iniciando restauración de base de datos...");
                updateProgress(10, "Restaurando base de datos...");
                
                const dbResult = await fetch("?ajax=1&action=restore_database");
                const dbData = await dbResult.json();
                
                if (!dbData.success) {
                    throw new Error(dbData.error);
                }
                
                markStepComplete("step-restore-db");
                addLogMessage("Base de datos restaurada correctamente");
                updateProgress(70, "Configurando sistema...");
                
                // Paso 2: Configurar sistema
                const configResult = await fetch("?ajax=1&action=configure_system");
                const configData = await configResult.json();
                
                if (!configData.success) {
                    throw new Error(configData.error);
                }
                
                markStepComplete("step-configure");
                markStepComplete("step-complete-install");
                updateProgress(100, "¡Instalación completada!");
                addLogMessage("¡Sistema configurado correctamente!");
                
                // Redirigir al paso final
                setTimeout(() => {
                    window.location.href = "?step=complete";
                }, 2000);
                
            } catch (error) {
                addLogMessage("ERROR: " + error.message);
                updateProgress(0, "Error en la instalación");
                console.error("Installation error:", error);
            }
        }
        
        // Iniciar automáticamente
        document.addEventListener("DOMContentLoaded", startInstallation);
        </script>';
        
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
        echo '<p>Tu tienda PrestaShop ha sido restaurada exitosamente usando AJAX.</p>';
        
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
        echo '<li>Elimina el archivo ZIP de backup del directorio web</li>';
        echo '<li>Elimina el directorio extracted_backup</li>';
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

    // === MÉTODOS AUXILIARES ===
    
    private function processDatabaseConfig()
    {
        if (isset($_POST['test_connection'])) {
            $this->testDatabaseConnection();
        } elseif (isset($_POST['save_config'])) {
            $this->saveDatabaseConfig();
        }
    }
    
    private function testDatabaseConnection()
    {
        $config = [
            'host' => $_POST['host'],
            'user' => $_POST['user'],
            'password' => $_POST['password'],
            'name' => $_POST['name'],
            'prefix' => $_POST['prefix'] ?? 'ps_'
        ];
        
        try {
            $connection = new mysqli($config['host'], $config['user'], $config['password'], $config['name']);
            
            if ($connection->connect_error) {
                echo '<div class="error-box">';
                echo '<h3>Error de Conexión</h3>';
                echo '<p>' . htmlspecialchars($connection->connect_error) . '</p>';
                echo '</div>';
            } else {
                echo '<div class="success-box">';
                echo '<h3>✓ Conexión Exitosa</h3>';
                echo '<p>La conexión a la base de datos fue exitosa.</p>';
                echo '</div>';
                $connection->close();
            }
        } catch (Exception $e) {
            echo '<div class="error-box">';
            echo '<h3>Error</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
    }
    
    private function saveDatabaseConfig()
    {
        $config = [
            'host' => $_POST['host'],
            'user' => $_POST['user'],
            'password' => $_POST['password'],
            'name' => $_POST['name'],
            'prefix' => $_POST['prefix'] ?? 'ps_'
        ];
        
        file_put_contents('installer_db_config.json', json_encode($config));
        
        echo '<div class="success-box">';
        echo '<h3>✓ Configuración Guardada</h3>';
        echo '<p>La configuración de base de datos ha sido guardada.</p>';
        echo '</div>';
    }
    
    private function loadDbConfig()
    {
        $configFile = 'installer_db_config.json';
        if (file_exists($configFile)) {
            return json_decode(file_get_contents($configFile), true);
        }
        return null;
    }
    
    private function restoreDatabase($sqlFile, $dbConfig)
    {
        $this->logMessage("Starting database restoration from: " . basename($sqlFile));
        
        try {
            $connection = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['password'], $dbConfig['name']);
            
            if ($connection->connect_error) {
                return [
                    'success' => false,
                    'error' => 'Error de conexión: ' . $connection->connect_error
                ];
            }
            
            $isGzipped = pathinfo($sqlFile, PATHINFO_EXTENSION) === 'gz';
            
            // Para archivos grandes, usar comando directo del sistema
            $fileSize = filesize($sqlFile);
            if ($fileSize > 5 * 1024 * 1024) { // Mayor a 5MB
                $this->logMessage("Using system command for large file");
                return $this->restoreDatabaseViaCommand($sqlFile, $dbConfig, $isGzipped);
            }
            
            // Para archivos pequeños, usar PHP
            $this->logMessage("Processing via PHP for small file");
            
            if ($isGzipped) {
                $handle = gzopen($sqlFile, 'r');
                $sql = '';
                while (!gzeof($handle)) {
                    $sql .= gzread($handle, 8192);
                }
                gzclose($handle);
            } else {
                $sql = file_get_contents($sqlFile);
            }
            
            if (empty($sql)) {
                return [
                    'success' => false,
                    'error' => 'El archivo SQL está vacío'
                ];
            }
            
            // Dividir y ejecutar statements
            $statements = $this->splitSqlStatements($sql);
            $executedCount = 0;
            $errorCount = 0;
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement)) continue;
                
                if ($connection->query($statement)) {
                    $executedCount++;
                } else {
                    $errorCount++;
                    $this->logMessage("SQL Warning: " . $connection->error);
                }
            }
            
            $connection->close();
            
            $this->logMessage("Database restoration completed. Executed: $executedCount, Errors: $errorCount");
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error ejecutando SQL: ' . $e->getMessage()
            ];
        }
    }
    
    private function restoreDatabaseViaCommand($sqlFile, $dbConfig, $isGzipped)
    {
        $this->logMessage("Restoring database via system command");
        
        // Verificar que mysql esté disponible
        $mysqlTest = shell_exec('mysql --version 2>&1');
        if (strpos($mysqlTest, 'mysql') === false) {
            return [
                'success' => false,
                'error' => 'Comando MySQL no disponible'
            ];
        }
        
        // Construir comando
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
        
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $errorOutput = implode("\n", $output);
            $this->logMessage("MySQL command failed: " . $errorOutput);
            
            return [
                'success' => false,
                'error' => 'Error ejecutando MySQL: ' . $errorOutput
            ];
        }
        
        $this->logMessage("Database restored successfully via command");
        return ['success' => true];
    }
    
    private function splitSqlStatements($sql)
    {
        $statements = [];
        $currentStatement = '';
        $inQuotes = false;
        $quoteChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (!$inQuotes && $char === ';') {
                $statements[] = $currentStatement;
                $currentStatement = '';
                continue;
            }
            
            $currentStatement .= $char;
        }
        
        if (!empty(trim($currentStatement))) {
            $statements[] = $currentStatement;
        }
        
        return $statements;
    }
    
    private function configureSystem($dbConfig)
    {
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
            
            $this->logMessage("Configuring for domain: " . $domain);
            
            // Actualizar configuración de dominio
            $queries = [
                "UPDATE `{$prefix}shop_url` SET `domain` = '{$domain}', `domain_ssl` = '{$domain}'",
                "UPDATE `{$prefix}configuration` SET `value` = '{$domain}' WHERE `name` = 'PS_SHOP_DOMAIN'",
                "UPDATE `{$prefix}configuration` SET `value` = '{$domain}' WHERE `name` = 'PS_SHOP_DOMAIN_SSL'"
            ];
            
            foreach ($queries as $query) {
                $result = $connection->query($query);
                if (!$result) {
                    $this->logMessage("Config query failed: " . $connection->error);
                }
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
    
    private function saveProgress($task, $percentage, $message)
    {
        $progress = [
            'task' => $task,
            'percentage' => $percentage,
            'message' => $message,
            'timestamp' => time()
        ];
        
        file_put_contents("progress_{$task}.json", json_encode($progress));
    }
    
    private function getProgress($task)
    {
        $file = "progress_{$task}.json";
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        
        return [
            'task' => $task,
            'percentage' => 0,
            'message' => 'No iniciado',
            'timestamp' => time()
        ];
    }
    
    private function ajaxSuccess($data)
    {
        // Limpiar cualquier output previo que pueda interferir con JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        
        // Asegurar que la respuesta se envía
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
        
        exit;
    }
    
    private function ajaxError($message)
    {
        // Limpiar cualquier output previo que pueda interferir con JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        
        // Asegurar que la respuesta se envía
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
        
        exit;
    }
    
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
        
        $bytes = $this->parseMemoryLimit($memoryLimit);
        return $bytes >= 256 * 1024 * 1024; // 256MB
    }
    
    private function parseMemoryLimit($memoryLimit)
    {
        $unit = strtolower(substr($memoryLimit, -1));
        $value = intval(substr($memoryLimit, 0, -1));
        
        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
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
        echo '<title>' . htmlspecialchars($title) . '</title>';
        echo '<style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
            .container { max-width: 800px; margin: 0 auto; padding: 20px; }
            .installer-card { background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden; }
            .header { background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { font-size: 24px; margin-bottom: 8px; }
            .header .subtitle { opacity: 0.9; font-size: 14px; }
            .step-content { padding: 40px; }
            .info-box, .error-box, .warning-box, .success-box { padding: 20px; border-radius: 8px; margin: 20px 0; }
            .info-box { background: #ebf8ff; border-left: 4px solid #3182ce; color: #2a4365; }
            .error-box { background: #fed7d7; border-left: 4px solid #e53e3e; color: #742a2a; }
            .warning-box { background: #fefcbf; border-left: 4px solid #d69e2e; color: #744210; }
            .success-box { background: #f0fff4; border-left: 4px solid #38a169; color: #22543d; }
            .btn { display: inline-block; padding: 12px 24px; background: #4299e1; color: white; text-decoration: none; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; transition: all 0.2s; }
            .btn:hover { background: #3182ce; transform: translateY(-1px); }
            .btn-secondary { background: #718096; }
            .btn-secondary:hover { background: #4a5568; }
            .btn-primary { background: #4299e1; }
            .btn-primary:hover { background: #3182ce; }
            .navigation { margin-top: 30px; display: flex; gap: 10px; justify-content: space-between; }
            .requirements-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .requirements-table th, .requirements-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
            .requirements-table th { background: #f7fafc; font-weight: 600; }
            .status-ok { color: #38a169; font-weight: bold; }
            .status-error { color: #e53e3e; font-weight: bold; }
            .form-group { margin-bottom: 20px; }
            .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #2d3748; }
            .form-group input { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 14px; }
            .db-config-form { max-width: 500px; }
            .progress-step { padding: 10px; margin: 5px 0; background: #f7fafc; border-left: 4px solid #e2e8f0; border-radius: 4px; transition: all 0.3s; }
            .progress-step.active { background: #ebf8ff; border-left-color: #3182ce; }
            .progress-step.completed { background: #f0fff4; border-left-color: #38a169; }
            .progress-bar-container { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; margin: 20px 0; overflow: hidden; }
            .progress-bar { height: 100%; background: linear-gradient(90deg, #4299e1, #3182ce); transition: width 0.3s ease; border-radius: 4px; }
            #current-status { text-align: center; margin: 20px 0; font-weight: 600; color: #2d3748; }
            .log-entry { font-family: "Courier New", monospace; font-size: 12px; color: #4a5568; padding: 2px 0; border-bottom: 1px solid #f1f1f1; }
            #extraction-log, #installation-log { max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 15px; border-radius: 4px; margin-top: 20px; }
        </style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="container">';
        echo '<div class="installer-card">';
        echo '<div class="header">';
        echo '<h1>PS Copias - Instalador Simple AJAX</h1>';
        echo '<div class="subtitle">Versión ' . INSTALLER_VERSION . ' | Instalación Completada</div>';
        echo '</div>';
    }
    
    private function renderFooter()
    {
        echo '</div></div>';
        echo '<div style="text-align: center; padding: 20px; color: rgba(255,255,255,0.8); font-size: 12px;">
            PS Copias Simple Installer v' . INSTALLER_VERSION . '<br>
            Compatible con ZIP de exportación estándar de PS_Copia
        </div>';
        echo '</body></html>';
    }
}

// Ejecutar instalador
try {
    $installer = new PsCopiasSimpleInstaller();
    $installer->run();
} catch (Exception $e) {
    echo "ERROR FATAL: " . htmlspecialchars($e->getMessage());
} 