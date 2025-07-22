<?php
/**
 * PS Copias Standalone Installer Template - Estilo Duplicator de WordPress
 * 
 * Este instalador funciona independientemente del paquete ZIP
 * y detecta automáticamente los archivos necesarios.
 * 
 * TEMPLATE VARIABLES TO REPLACE:
 * {EMBEDDED_CONFIG} - Configuration JSON with package information
 * {INSTALLER_VERSION} - Version number
 * {CREATION_DATE} - Package creation date
 */

// Configuración embebida - WILL BE REPLACED BY SERVICE
$EMBEDDED_CONFIG = '{EMBEDDED_CONFIG}';

// Configuración del instalador
define('INSTALLER_VERSION', '{INSTALLER_VERSION}');
define('MAX_EXECUTION_TIME', 3600);
define('MEMORY_LIMIT', '1024M');

// Configurar límites
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@ini_set('memory_limit', MEMORY_LIMIT);
@set_time_limit(MAX_EXECUTION_TIME);

class PsCopiasStandaloneInstaller
{
    private $currentStep;
    private $embeddedConfig;
    private $packageFiles = [];
    private $errors = [];
    private $warnings = [];
    private $logFile;
    
    public function __construct()
    {
        global $EMBEDDED_CONFIG;
        $this->embeddedConfig = $EMBEDDED_CONFIG;
        $this->currentStep = $_GET['step'] ?? 'welcome';
        $this->logFile = 'installer_log_' . date('Y-m-d_H-i-s') . '.txt';
        $this->detectPackageFiles();
    }
    
    /**
     * Detecta automáticamente los archivos del paquete
     */
    private function detectPackageFiles()
    {
        $currentDir = __DIR__;
        
        // Buscar paquete ZIP
        $packageFiles = glob($currentDir . '/' . $this->embeddedConfig['package_pattern']);
        if (!empty($packageFiles)) {
            $this->packageFiles['package'] = $packageFiles[0];
        }
        
        // Buscar archivos extraídos si el ZIP ya fue descomprimido
        $archiveFiles = glob($currentDir . '/' . $this->embeddedConfig['archive_pattern']);
        if (!empty($archiveFiles)) {
            $this->packageFiles['archive'] = $archiveFiles[0];
        }
        
        $dbFiles = glob($currentDir . '/' . $this->embeddedConfig['database_pattern']);
        if (!empty($dbFiles)) {
            $this->packageFiles['database'] = $dbFiles[0];
        }
        
        $configFiles = glob($currentDir . '/site_config.json');
        if (!empty($configFiles)) {
            $this->packageFiles['config'] = $configFiles[0];
        }
    }
    
    /**
     * Punto de entrada principal
     */
    public function run()
    {
        $this->logMessage("=== PS Copias Standalone Installer Started ===");
        $this->logMessage("Step: " . $this->currentStep);
        
        switch ($this->currentStep) {
            case 'welcome':
                $this->showWelcomeStep();
                break;
            case 'extract':
                $this->showExtractStep();
                break;
            case 'requirements':
                $this->showRequirementsStep();
                break;
            case 'database':
                $this->showDatabaseStep();
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
     * Paso 1: Bienvenida y detección de archivos
     */
    private function showWelcomeStep()
    {
        $this->showHeader('Bienvenido al Instalador PS Copias');
        
        echo '<div class="step-content">';
        echo '<h3>🚀 Instalador Estilo Duplicator para PrestaShop</h3>';
        
        // Mostrar información del paquete original
        echo '<div class="info-box">';
        echo '<h4>📋 Información del Paquete Original</h4>';
        echo '<p><strong>Fecha de creación:</strong> ' . htmlspecialchars($this->embeddedConfig['created_date']) . '</p>';
        echo '<p><strong>PrestaShop versión:</strong> ' . htmlspecialchars($this->embeddedConfig['prestashop_version']) . '</p>';
        echo '<p><strong>URL original:</strong> ' . htmlspecialchars($this->embeddedConfig['source_url']) . '</p>';
        echo '</div>';
        
        // Detectar nueva ubicación
        $newUrl = $this->detectNewUrl();
        $newPath = $this->detectNewPath();
        
        echo '<div class="info-box">';
        echo '<h4>📍 Nueva Ubicación Detectada</h4>';
        echo '<p><strong>Nueva URL:</strong> ' . htmlspecialchars($newUrl) . '</p>';
        echo '<p><strong>Nueva ruta:</strong> ' . htmlspecialchars($newPath) . '</p>';
        echo '</div>';
        
        // Verificar archivos del paquete
        echo '<div class="files-status">';
        echo '<h4>📦 Estado de Archivos del Paquete</h4>';
        
        $hasPackage = isset($this->packageFiles['package']);
        $hasExtracted = isset($this->packageFiles['archive']) && isset($this->packageFiles['database']);
        
        if ($hasPackage && !$hasExtracted) {
            echo '<p class="status-info">✅ Paquete ZIP detectado: ' . basename($this->packageFiles['package']) . '</p>';
            echo '<p class="status-warning">⚠️ El paquete necesita ser extraído primero.</p>';
            $nextStep = 'extract';
            $nextLabel = 'Extraer Paquete';
        } elseif ($hasExtracted) {
            echo '<p class="status-success">✅ Archivo de archivos: ' . basename($this->packageFiles['archive']) . '</p>';
            echo '<p class="status-success">✅ Base de datos: ' . basename($this->packageFiles['database']) . '</p>';
            if (isset($this->packageFiles['config'])) {
                echo '<p class="status-success">✅ Configuración: site_config.json</p>';
            }
            $nextStep = 'requirements';
            $nextLabel = 'Verificar Requisitos';
        } else {
            echo '<p class="status-error">❌ No se encontraron archivos del paquete.</p>';
            echo '<p class="help-text">Asegúrese de que el archivo ZIP del paquete esté en el mismo directorio que este instalador.</p>';
            $nextStep = null;
            $nextLabel = null;
        }
        
        echo '</div>';
        echo '</div>';
        
        if ($nextStep) {
            $this->showNextButton($nextStep, $nextLabel);
        }
        
        $this->showFooter();
    }
    
    /**
     * Paso 2: Extraer paquete
     */
    private function showExtractStep()
    {
        if ($_POST['action'] ?? '' === 'extract') {
            $this->performExtraction();
            return;
        }
        
        $this->showHeader('Extraer Paquete');
        
        echo '<div class="step-content">';
        echo '<h3>📦 Extrayendo Archivos del Paquete</h3>';
        echo '<p>El paquete ZIP será extraído para obtener los archivos necesarios para la instalación.</p>';
        
        if (isset($this->packageFiles['package'])) {
            echo '<div class="info-box">';
            echo '<p><strong>Paquete a extraer:</strong> ' . basename($this->packageFiles['package']) . '</p>';
            echo '<p><strong>Tamaño:</strong> ' . $this->formatBytes(filesize($this->packageFiles['package'])) . '</p>';
            echo '</div>';
            
            echo '<form method="post">';
            echo '<input type="hidden" name="action" value="extract">';
            echo '<button type="submit" class="btn-primary">🚀 Extraer Paquete</button>';
            echo '</form>';
        } else {
            echo '<p class="status-error">❌ No se encontró el paquete ZIP.</p>';
        }
        
        echo '</div>';
        $this->showFooter();
    }
    
    /**
     * Realiza la extracción del paquete
     */
    private function performExtraction()
    {
        try {
            $this->logMessage("Iniciando extracción del paquete...");
            
            if (!isset($this->packageFiles['package'])) {
                throw new Exception('No se encontró el paquete ZIP');
            }
            
            $packageFile = $this->packageFiles['package'];
            $extractDir = __DIR__;
            
            $zip = new ZipArchive();
            $result = $zip->open($packageFile);
            
            if ($result !== TRUE) {
                throw new Exception("No se puede abrir el paquete ZIP: $packageFile");
            }
            
            // Extraer todos los archivos
            $zip->extractTo($extractDir);
            $zip->close();
            
            $this->logMessage("Paquete extraído exitosamente");
            
            // Redirigir a verificación de requisitos
            header('Location: ?step=requirements');
            exit;
            
        } catch (Exception $e) {
            $this->showHeader('Error en Extracción');
            echo '<div class="step-content">';
            echo '<div class="error-box">';
            echo '<h3>❌ Error al extraer el paquete</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
            echo '<a href="?step=welcome" class="btn-secondary">← Volver al Inicio</a>';
            echo '</div>';
            $this->showFooter();
        }
    }
    
    /**
     * Paso 3: Verificación de requisitos
     */
    private function showRequirementsStep()
    {
        $this->showHeader('Verificación de Requisitos');
        
        $requirements = $this->checkSystemRequirements();
        $allPassed = true;
        
        echo '<div class="step-content">';
        echo '<h3>🔧 Verificando Requisitos del Sistema</h3>';
        
        echo '<div class="requirements-list">';
        foreach ($requirements as $req) {
            $status = $req['status'] ? 'success' : 'error';
            $icon = $req['status'] ? '✅' : '❌';
            
            echo '<div class="requirement-item status-' . $status . '">';
            echo '<span class="icon">' . $icon . '</span>';
            echo '<span class="name">' . htmlspecialchars($req['name']) . '</span>';
            echo '<span class="value">(' . htmlspecialchars($req['current']) . ')</span>';
            
            if (!$req['status']) {
                echo '<div class="requirement-help">Requerido: ' . htmlspecialchars($req['required']) . '</div>';
                $allPassed = false;
            }
            echo '</div>';
        }
        echo '</div>';
        
        if ($allPassed) {
            echo '<div class="success-box">';
            echo '<p>✅ Todos los requisitos están cumplidos. Puede continuar con la instalación.</p>';
            echo '</div>';
            $this->showNextButton('database', 'Configurar Base de Datos');
        } else {
            echo '<div class="error-box">';
            echo '<p>❌ Algunos requisitos no están cumplidos. Por favor, corrija los errores antes de continuar.</p>';
            echo '</div>';
        }
        
        echo '</div>';
        $this->showFooter();
    }
    
    /**
     * Paso 4: Configuración de Base de Datos
     */
    private function showDatabaseStep()
    {
        $this->showHeader('Configuración de Base de Datos');
        
        echo '<div class="step-content">';
        echo '<h3>🗄️ Configuración de Base de Datos</h3>';
        
        echo '<form method="post" action="?step=install" class="database-form">';
        echo '<div class="form-section">';
        echo '<h4>Información del Servidor MySQL</h4>';
        
        echo '<div class="form-group">';
        echo '<label for="db_host">Servidor de Base de Datos:</label>';
        echo '<input type="text" id="db_host" name="db_host" value="localhost" required>';
        echo '<small>Generalmente "localhost" o la IP del servidor MySQL</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_port">Puerto:</label>';
        echo '<input type="number" id="db_port" name="db_port" value="3306">';
        echo '<small>Puerto del servidor MySQL (por defecto 3306)</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_name">Nombre de la Base de Datos:</label>';
        echo '<input type="text" id="db_name" name="db_name" required>';
        echo '<small>Base de datos donde se importarán los datos</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_user">Usuario de Base de Datos:</label>';
        echo '<input type="text" id="db_user" name="db_user" required>';
        echo '<small>Usuario con permisos para crear y modificar tablas</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_password">Contraseña:</label>';
        echo '<input type="password" id="db_password" name="db_password">';
        echo '<small>Contraseña del usuario de base de datos</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="db_prefix">Prefijo de Tablas:</label>';
        echo '<input type="text" id="db_prefix" name="db_prefix" value="ps_">';
        echo '<small>Prefijo para las tablas de PrestaShop</small>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="form-section">';
        echo '<h4>Configuración del Sitio</h4>';
        
        echo '<div class="form-group">';
        echo '<label for="site_url">URL del Sitio:</label>';
        echo '<input type="url" id="site_url" name="site_url" value="' . htmlspecialchars($this->detectNewUrl()) . '" required>';
        echo '<small>URL completa donde se accederá al sitio</small>';
        echo '</div>';
        
        echo '<div class="form-group">';
        echo '<label for="site_path">Ruta del Sitio:</label>';
        echo '<input type="text" id="site_path" name="site_path" value="' . htmlspecialchars($this->detectNewPath()) . '" required>';
        echo '<small>Ruta física en el servidor donde están los archivos</small>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="form-section">';
        echo '<h4>Opciones Avanzadas</h4>';
        
        echo '<div class="form-group checkbox-group">';
        echo '<label><input type="checkbox" name="clear_cache" value="1" checked> Limpiar cache después de la instalación</label>';
        echo '</div>';
        
        echo '<div class="form-group checkbox-group">';
        echo '<label><input type="checkbox" name="update_htaccess" value="1" checked> Actualizar archivo .htaccess automáticamente</label>';
        echo '</div>';
        
        echo '<div class="form-group checkbox-group">';
        echo '<label><input type="checkbox" name="backup_existing" value="1" checked> Crear backup de archivos existentes (si los hay)</label>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="action-buttons">';
        echo '<a href="?step=requirements" class="btn-secondary">⬅️ Anterior</a>';
        echo '<button type="button" class="btn-info" onclick="testDatabaseConnection()">🔬 Probar Conexión</button>';
        echo '<button type="submit" class="btn-primary">🚀 Iniciar Instalación</button>';
        echo '</div>';
        echo '</form>';
        
        echo '<div id="connection-test-result" class="alert" style="display: none;"></div>';
        echo '</div>';
        
        echo '<script>';
        echo 'function testDatabaseConnection() {';
        echo '    const formData = new FormData();';
        echo '    formData.append("action", "test_connection");';
        echo '    formData.append("db_host", document.getElementById("db_host").value);';
        echo '    formData.append("db_port", document.getElementById("db_port").value);';
        echo '    formData.append("db_name", document.getElementById("db_name").value);';
        echo '    formData.append("db_user", document.getElementById("db_user").value);';
        echo '    formData.append("db_password", document.getElementById("db_password").value);';
        echo '    ';
        echo '    const resultDiv = document.getElementById("connection-test-result");';
        echo '    resultDiv.style.display = "block";';
        echo '    resultDiv.className = "alert alert-info";';
        echo '    resultDiv.innerHTML = "🔄 Probando conexión...";';
        echo '    ';
        echo '    fetch(window.location.href, {';
        echo '        method: "POST",';
        echo '        body: formData';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        resultDiv.className = "alert " + (data.success ? "alert-success" : "alert-error");';
        echo '        resultDiv.innerHTML = data.success ? "✅ " + data.message : "❌ " + data.message;';
        echo '    })';
        echo '    .catch(error => {';
        echo '        resultDiv.className = "alert alert-error";';
        echo '        resultDiv.innerHTML = "❌ Error al probar la conexión: " + error.message;';
        echo '    });';
        echo '}';
        echo '</script>';
        
        $this->showFooter();
    }
    
    /**
     * Paso 5: Realizar instalación
     */
    private function performInstallation()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?step=database');
            exit;
        }
        
        $this->showHeader('Instalación en Progreso');
        
        echo '<div class="step-content">';
        echo '<h3>🚀 Instalación en Progreso</h3>';
        
        echo '<div class="progress-container">';
        echo '<div class="progress-bar">';
        echo '<div class="progress-fill" id="progress-fill"></div>';
        echo '</div>';
        echo '<div class="progress-text" id="progress-text">Iniciando instalación...</div>';
        echo '</div>';
        
        echo '<div class="installation-log" id="installation-log">';
        echo '<h4>📋 Log de Instalación</h4>';
        echo '<div class="log-content" id="log-content"></div>';
        echo '</div>';
        echo '</div>';
        
        echo '<script>';
        echo 'function performInstallation() {';
        echo '    const formData = new FormData();';
        echo '    formData.append("action", "install");';
        
        foreach ($_POST as $key => $value) {
            echo '    formData.append("' . htmlspecialchars($key) . '", "' . htmlspecialchars($value) . '");';
        }
        
        echo '    const progressFill = document.getElementById("progress-fill");';
        echo '    const progressText = document.getElementById("progress-text");';
        echo '    const logContent = document.getElementById("log-content");';
        echo '    ';
        echo '    function updateProgress(percentage, message) {';
        echo '        progressFill.style.width = percentage + "%";';
        echo '        progressText.textContent = message;';
        echo '        ';
        echo '        const logEntry = document.createElement("div");';
        echo '        logEntry.className = "log-entry";';
        echo '        logEntry.innerHTML = "<span class=\"log-time\">" + new Date().toLocaleTimeString() + "</span> " + message;';
        echo '        logContent.appendChild(logEntry);';
        echo '        logContent.scrollTop = logContent.scrollHeight;';
        echo '    }';
        echo '    ';
        echo '    updateProgress(10, "🔄 Iniciando instalación...");';
        echo '    ';
        echo '    fetch(window.location.href, {';
        echo '        method: "POST",';
        echo '        body: formData';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        if (data.success) {';
        echo '            if (data.steps && data.steps.length > 0) {';
        echo '                let stepIndex = 0;';
        echo '                const showNextStep = () => {';
        echo '                    if (stepIndex < data.steps.length) {';
        echo '                        const step = data.steps[stepIndex];';
        echo '                        const percentage = Math.round(((stepIndex + 1) / data.steps.length) * 100);';
        echo '                        updateProgress(percentage, step);';
        echo '                        stepIndex++;';
        echo '                        setTimeout(showNextStep, 500);';
        echo '                    } else {';
        echo '                        updateProgress(100, "🎉 ¡Instalación completada exitosamente!");';
        echo '                        setTimeout(() => {';
        echo '                            window.location.href = "?step=complete";';
        echo '                        }, 2000);';
        echo '                    }';
        echo '                };';
        echo '                showNextStep();';
        echo '            } else {';
        echo '                updateProgress(100, "✅ Instalación completada exitosamente");';
        echo '                setTimeout(() => {';
        echo '                    window.location.href = "?step=complete";';
        echo '                }, 2000);';
        echo '            }';
        echo '        } else {';
        echo '            updateProgress(0, "❌ Error: " + data.message);';
        echo '            if (data.steps && data.steps.length > 0) {';
        echo '                data.steps.forEach(step => {';
        echo '                    updateProgress(0, step);';
        echo '                });';
        echo '            }';
        echo '        }';
        echo '    })';
        echo '    .catch(error => {';
        echo '        updateProgress(0, "❌ Error de conexión: " + error.message);';
        echo '    });';
        echo '}';
        echo '';
        echo 'performInstallation();';
        echo '</script>';
        
        $this->showFooter();
    }
    
    /**
     * Paso 6: Instalación completada
     */
    private function showCompleteStep()
    {
        $this->showHeader('Instalación Completada');
        
        echo '<div class="step-content">';
        echo '<h3>🎉 ¡Instalación Completada Exitosamente!</h3>';
        
        echo '<div class="success-box">';
        echo '<p>Su tienda PrestaShop ha sido instalada correctamente en la nueva ubicación.</p>';
        echo '</div>';
        
        echo '<div class="info-box">';
        echo '<h4>📋 Información de Acceso</h4>';
        echo '<p><strong>URL del sitio:</strong> <a href="' . htmlspecialchars($this->detectNewUrl()) . '" target="_blank">' . htmlspecialchars($this->detectNewUrl()) . '</a></p>';
        echo '<p><strong>Panel de administración:</strong> <a href="' . htmlspecialchars($this->detectNewUrl()) . 'admin/" target="_blank">' . htmlspecialchars($this->detectNewUrl()) . 'admin/</a></p>';
        echo '</div>';
        
        echo '<div class="warning-box">';
        echo '<h4>⚠️ Tareas Importantes</h4>';
        echo '<ul>';
        echo '<li>Elimine este instalador y los archivos del paquete por seguridad</li>';
        echo '<li>Verifique que todos los módulos funcionen correctamente</li>';
        echo '<li>Actualice las configuraciones específicas del nuevo dominio</li>';
        echo '<li>Configure los certificados SSL si es necesario</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<div class="action-buttons">';
        echo '<a href="' . htmlspecialchars($this->detectNewUrl()) . '" class="btn-primary" target="_blank">🏪 Ver Tienda</a>';
        echo '<a href="' . htmlspecialchars($this->detectNewUrl()) . 'admin/" class="btn-secondary" target="_blank">⚙️ Panel Admin</a>';
        echo '</div>';
        echo '</div>';
        
        $this->showFooter();
    }
    
    /**
     * Detecta la nueva URL
     */
    private function detectNewUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['REQUEST_URI'] ?? '/');
        
        return $protocol . $host . rtrim($path, '/') . '/';
    }
    
    /**
     * Detecta la nueva ruta
     */
    private function detectNewPath(): string
    {
        return dirname(__FILE__) . '/';
    }
    
    /**
     * Verifica requisitos del sistema
     */
    private function checkSystemRequirements(): array
    {
        return [
            [
                'name' => 'Versión de PHP',
                'current' => phpversion(),
                'required' => '7.2.0',
                'status' => version_compare(phpversion(), '7.2.0', '>=')
            ],
            [
                'name' => 'Extensión ZIP',
                'current' => extension_loaded('zip') ? 'Disponible' : 'No disponible',
                'required' => 'Requerida',
                'status' => extension_loaded('zip')
            ],
            [
                'name' => 'Extensión MySQL',
                'current' => (extension_loaded('mysqli') || extension_loaded('pdo_mysql')) ? 'Disponible' : 'No disponible',
                'required' => 'Requerida',
                'status' => extension_loaded('mysqli') || extension_loaded('pdo_mysql')
            ],
            [
                'name' => 'Memoria PHP',
                'current' => ini_get('memory_limit'),
                'required' => '512M',
                'status' => $this->checkMemoryLimit()
            ]
        ];
    }
    
    private function checkMemoryLimit(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit == -1) return true;
        
        $bytes = $this->convertToBytes($limit);
        return $bytes >= 536870912; // 512MB
    }
    
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }
    
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Muestra la cabecera HTML
     */
    private function showHeader(string $title)
    {
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - PS Copias Installer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; background: white; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; text-align: center; }
        .header h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        .header p { opacity: 0.9; }
        .step-content { padding: 2rem; }
        .info-box, .success-box, .error-box, .warning-box { padding: 1rem; margin: 1rem 0; border-radius: 8px; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; }
        .success-box { background: #e8f5e8; border-left: 4px solid #4caf50; }
        .error-box { background: #ffebee; border-left: 4px solid #f44336; }
        .warning-box { background: #fff3e0; border-left: 4px solid #ff9800; }
        .btn-primary, .btn-secondary { display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; margin: 0.5rem 0.5rem 0.5rem 0; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a6fd8; }
        .btn-secondary { background: #6c757d; color: white; }
        .requirements-list { margin: 1rem 0; }
        .requirement-item { display: flex; align-items: center; padding: 0.75rem; margin: 0.5rem 0; border-radius: 6px; }
        .requirement-item.status-success { background: #e8f5e8; }
        .requirement-item.status-error { background: #ffebee; }
        .requirement-item .icon { margin-right: 1rem; font-size: 1.2rem; }
        .requirement-item .name { flex: 1; font-weight: 500; }
        .requirement-item .value { color: #666; }
        .requirement-help { width: 100%; margin-top: 0.5rem; color: #d32f2f; font-size: 0.9rem; }
        .footer { background: #f8f9fa; padding: 1rem 2rem; border-top: 1px solid #dee2e6; color: #6c757d; text-align: center; }
        .progress-container { margin: 2rem 0; }
        .progress-bar { width: 100%; height: 20px; background: #e0e0e0; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #4caf50, #45a049); width: 0%; transition: width 0.3s ease; }
        .progress-text { text-align: center; margin-top: 1rem; font-weight: 500; }
        .installation-log { margin-top: 2rem; border: 1px solid #ddd; border-radius: 8px; }
        .installation-log h4 { background: #f8f9fa; padding: 1rem; margin: 0; border-bottom: 1px solid #ddd; }
        .log-content { max-height: 300px; overflow-y: auto; padding: 1rem; background: #fafafa; }
        .log-entry { margin: 0.5rem 0; padding: 0.5rem; background: white; border-radius: 4px; border-left: 3px solid #2196f3; }
        .log-time { color: #666; font-size: 0.9rem; margin-right: 1rem; }
        .form-group { margin: 1rem 0; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        .form-group input { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; }
        .form-group small { color: #666; font-size: 0.9rem; }
        .form-section { margin: 2rem 0; padding: 1.5rem; border: 1px solid #ddd; border-radius: 8px; }
        .form-section h4 { margin-top: 0; color: #333; }
        .checkbox-group label { display: flex; align-items: center; }
        .checkbox-group input { width: auto; margin-right: 0.5rem; }
        .action-buttons { margin-top: 2rem; text-align: center; }
        .btn-info { background: #17a2b8; color: white; display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; margin: 0.5rem; }
        .btn-info:hover { background: #138496; }
        .alert { padding: 1rem; margin: 1rem 0; border-radius: 6px; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p>PS Copias - Instalador Estilo Duplicator v<?php echo INSTALLER_VERSION; ?></p>
        </div>
        <?php
    }
    
    /**
     * Muestra el pie de página HTML
     */
    private function showFooter()
    {
        ?>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> PS Copias - Instalador Estilo Duplicator para PrestaShop</p>
        </div>
    </div>
</body>
</html>
        <?php
    }
    
    /**
     * Muestra botón de siguiente paso
     */
    private function showNextButton(string $step, string $label)
    {
        echo '<div style="margin-top: 2rem;">';
        echo '<a href="?step=' . urlencode($step) . '" class="btn-primary">' . htmlspecialchars($label) . ' →</a>';
        echo '</div>';
    }
    
    /**
     * Registra mensaje en el log
     */
    private function logMessage(string $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

// Manejo de peticiones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'test_connection':
                $result = testDatabaseConnection($_POST);
                echo json_encode($result);
                exit;
                
            case 'install':
                $result = performFullInstallation($_POST);
                echo json_encode($result);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Acción no válida']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * Prueba la conexión a la base de datos
 */
function testDatabaseConnection(array $data): array
{
    try {
        $host = $data['db_host'] ?? 'localhost';
        $port = $data['db_port'] ?? 3306;
        $dbname = $data['db_name'] ?? '';
        $username = $data['db_user'] ?? '';
        $password = $data['db_password'] ?? '';
        
        if (empty($dbname) || empty($username)) {
            throw new Exception('Faltan datos de conexión requeridos');
        }
        
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10
        ]);
        
        return [
            'success' => true,
            'message' => 'Conexión exitosa. Base de datos accesible.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error de conexión: ' . $e->getMessage()
        ];
    }
}

/**
 * Realiza la instalación completa
 */
function performFullInstallation(array $data): array
{
    $steps = [];
    $currentDir = __DIR__;
    
    try {
        // Validar datos requeridos
        $required = ['db_host', 'db_name', 'db_user', 'site_url', 'site_path'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Campo requerido faltante: $field");
            }
        }
        
        $steps[] = "✅ Datos de configuración validados";
        
        // Paso 1: Conectar a la base de datos
        $host = $data['db_host'];
        $port = $data['db_port'] ?? 3306;
        $dbname = $data['db_name'];
        $username = $data['db_user'];
        $password = $data['db_password'] ?? '';
        $prefix = $data['db_prefix'] ?? 'ps_';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $steps[] = "✅ Conexión a base de datos establecida";
        
        // Paso 2: Buscar y extraer archivos si es necesario
        $archiveFile = null;
        $databaseFile = null;
        
        // Buscar archivo de archivos
        $archiveFiles = glob($currentDir . '/ps_copias_archive_*.zip');
        if (!empty($archiveFiles)) {
            $archiveFile = $archiveFiles[0];
        }
        
        // Buscar archivo de base de datos
        $dbFiles = glob($currentDir . '/ps_copias_database_*.sql');
        if (!empty($dbFiles)) {
            $databaseFile = $dbFiles[0];
        }
        
        // Si no hay archivos extraídos, buscar paquete ZIP
        if (!$archiveFile || !$databaseFile) {
            $packageFiles = glob($currentDir . '/ps_copias_package_*.zip');
            if (!empty($packageFiles)) {
                $packageFile = $packageFiles[0];
                
                // Extraer paquete
                $zip = new ZipArchive();
                if ($zip->open($packageFile) === TRUE) {
                    $zip->extractTo($currentDir);
                    $zip->close();
                    
                    // Buscar archivos extraídos
                    $archiveFiles = glob($currentDir . '/ps_copias_archive_*.zip');
                    $dbFiles = glob($currentDir . '/ps_copias_database_*.sql');
                    
                    if (!empty($archiveFiles)) $archiveFile = $archiveFiles[0];
                    if (!empty($dbFiles)) $databaseFile = $dbFiles[0];
                    
                    $steps[] = "✅ Paquete extraído correctamente";
                } else {
                    throw new Exception("No se pudo extraer el paquete ZIP");
                }
            }
        }
        
        if (!$archiveFile) {
            throw new Exception("No se encontró el archivo de archivos (ps_copias_archive_*.zip)");
        }
        
        if (!$databaseFile) {
            throw new Exception("No se encontró el archivo de base de datos (ps_copias_database_*.sql)");
        }
        
        // Paso 3: Extraer archivos del sitio
        $siteDir = rtrim($data['site_path'], '/');
        
        // Crear backup de archivos existentes si se solicita
        if (!empty($data['backup_existing'])) {
            $backupDir = $currentDir . '/backup_existing_' . date('Y-m-d_H-i-s');
            if (is_dir($siteDir) && count(scandir($siteDir)) > 2) {
                mkdir($backupDir, 0755, true);
                copyDirectory($siteDir, $backupDir);
                $steps[] = "✅ Backup de archivos existentes creado";
            }
        }
        
        // Extraer archivos del sitio
        $zip = new ZipArchive();
        if ($zip->open($archiveFile) === TRUE) {
            // Limpiar directorio de destino (excepto este instalador)
            if (is_dir($siteDir)) {
                $files = glob($siteDir . '/*');
                foreach ($files as $file) {
                    if (basename($file) !== basename(__FILE__) && 
                        !strpos(basename($file), 'ps_copias_installer') &&
                        !strpos(basename($file), 'ps_copias_package') &&
                        !strpos(basename($file), 'ps_copias_archive') &&
                        !strpos(basename($file), 'ps_copias_database')) {
                        if (is_dir($file)) {
                            removeDirectory($file);
                        } else {
                            unlink($file);
                        }
                    }
                }
            } else {
                mkdir($siteDir, 0755, true);
            }
            
            $zip->extractTo($siteDir);
            $zip->close();
            $steps[] = "✅ Archivos del sitio extraídos";
        } else {
            throw new Exception("No se pudo extraer el archivo de archivos");
        }
        
        // Paso 4: Importar base de datos
        $sqlContent = file_get_contents($databaseFile);
        if ($sqlContent === false) {
            throw new Exception("No se pudo leer el archivo de base de datos");
        }
        
        // Limpiar base de datos existente
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        
        // Ejecutar SQL en lotes
        $statements = explode(';', $sqlContent);
        $importedStatements = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                    $importedStatements++;
                } catch (PDOException $e) {
                    // Ignorar errores menores como tablas que ya existen
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        error_log("Error SQL: " . $e->getMessage() . " - Statement: " . substr($statement, 0, 100));
                    }
                }
            }
        }
        
        $steps[] = "✅ Base de datos importada ($importedStatements declaraciones ejecutadas)";
        
        // Paso 5: Actualizar configuraciones
        $siteUrl = rtrim($data['site_url'], '/') . '/';
        
        // Actualizar configuración de PrestaShop
        $configFile = $siteDir . '/app/config/parameters.php';
        if (file_exists($configFile)) {
            $configContent = file_get_contents($configFile);
            
            // Actualizar parámetros de base de datos
            $configContent = preg_replace(
                "/'database_host'\s*=>\s*'[^']*'/",
                "'database_host' => '$host'",
                $configContent
            );
            $configContent = preg_replace(
                "/'database_name'\s*=>\s*'[^']*'/",
                "'database_name' => '$dbname'",
                $configContent
            );
            $configContent = preg_replace(
                "/'database_user'\s*=>\s*'[^']*'/",
                "'database_user' => '$username'",
                $configContent
            );
            $configContent = preg_replace(
                "/'database_password'\s*=>\s*'[^']*'/",
                "'database_password' => '$password'",
                $configContent
            );
            $configContent = preg_replace(
                "/'database_prefix'\s*=>\s*'[^']*'/",
                "'database_prefix' => '$prefix'",
                $configContent
            );
            
            file_put_contents($configFile, $configContent);
        }
        
        // Actualizar URLs en la base de datos
        $oldUrlPattern = '%://%.%';
        $pdo->prepare("UPDATE {$prefix}configuration SET value = ? WHERE name = 'PS_SHOP_DOMAIN'")->execute([$_SERVER['HTTP_HOST'] ?? parse_url($siteUrl, PHP_URL_HOST)]);
        $pdo->prepare("UPDATE {$prefix}configuration SET value = ? WHERE name = 'PS_SHOP_DOMAIN_SSL'")->execute([$_SERVER['HTTP_HOST'] ?? parse_url($siteUrl, PHP_URL_HOST)]);
        $pdo->prepare("UPDATE {$prefix}shop_url SET domain = ?, domain_ssl = ? WHERE main = 1")->execute([
            $_SERVER['HTTP_HOST'] ?? parse_url($siteUrl, PHP_URL_HOST),
            $_SERVER['HTTP_HOST'] ?? parse_url($siteUrl, PHP_URL_HOST)
        ]);
        
        $steps[] = "✅ Configuraciones actualizadas";
        
        // Paso 6: Tareas de finalización
        if (!empty($data['clear_cache'])) {
            $cacheDir = $siteDir . '/var/cache';
            if (is_dir($cacheDir)) {
                removeDirectory($cacheDir);
                mkdir($cacheDir, 0755, true);
                $steps[] = "✅ Cache limpiado";
            }
        }
        
        if (!empty($data['update_htaccess'])) {
            $htaccessFile = $siteDir . '/.htaccess';
            if (file_exists($htaccessFile)) {
                $htaccessContent = file_get_contents($htaccessFile);
                $newPath = parse_url($siteUrl, PHP_URL_PATH) ?: '/';
                $htaccessContent = preg_replace('/RewriteBase\s+\/[^\n]*/', 'RewriteBase ' . $newPath, $htaccessContent);
                file_put_contents($htaccessFile, $htaccessContent);
                $steps[] = "✅ Archivo .htaccess actualizado";
            }
        }
        
        $steps[] = "🎉 Instalación completada exitosamente";
        
        return [
            'success' => true,
            'message' => 'Instalación completada exitosamente',
            'steps' => $steps
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error durante la instalación: ' . $e->getMessage(),
            'steps' => $steps
        ];
    }
}

/**
 * Copia un directorio recursivamente
 */
function copyDirectory($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            if (is_dir($src . '/' . $file)) {
                copyDirectory($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

/**
 * Elimina un directorio recursivamente
 */
function removeDirectory($dir) {
    if (!is_dir($dir)) return false;
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}

// Ejecutar el instalador
try {
    $installer = new PsCopiasStandaloneInstaller();
    $installer->run();
} catch (Exception $e) {
    echo '<div style="padding: 2rem; background: #ffebee; color: #d32f2f; border-radius: 8px; margin: 2rem;">';
    echo '<h2>❌ Error Fatal</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
    echo '</div>';
}
?> 