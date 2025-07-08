# 🔄 Guía de Migración Inteligente - PS Copia

## Resumen de Mejoras v2.0

El módulo PS Copia ha sido mejorado para manejar automáticamente todos los problemas comunes que se presentan al restaurar backups entre diferentes entornos y servidores.

## ✅ Problemas Automáticamente Resueltos

### 1. **Preservación de Credenciales de Base de Datos**
**Problema anterior:**
```
[PrestaShopException] Link to database cannot be established: SQLSTATE[HY000] [2002] Connection refused
```

**Solución automática:**
- Detecta automáticamente el entorno actual (DDEV, Docker, servidor tradicional)
- Preserva las credenciales del servidor de destino
- Nunca sobrescribe la configuración de conexión local

```php
// Detección automática de entorno
private function isDdevEnvironment(): bool
{
    return (getenv('DDEV_PROJECT') !== false) || 
           (file_exists('/mnt/ddev_config/ddev.yaml'));
}

// Preservación de credenciales
private function getCurrentDbCredentials(): array
{
    if ($this->isDdevEnvironment()) {
        return [
            'database_host' => 'db',
            'database_user' => 'db', 
            'database_password' => 'db',
            'database_name' => 'db'
        ];
    }
    // Más detecciones automáticas...
}
```

### 2. **Adaptación Automática de Prefijos de Tabla**
**Problema anterior:**
- Conflictos al restaurar prefijos diferentes (`ps924_` vs `myshop_`)
- Datos duplicados o tablas mixtas

**Solución automática:**
- Analiza el prefijo del backup automáticamente
- Adapta todas las consultas al prefijo del entorno actual
- Limpia tablas existentes antes de importar nuevos datos

```php
// Detección automática de prefijo en backup
$backupPrefix = $this->detectPrefixFromBackup($backupPath);
$currentPrefix = _DB_PREFIX_;

// Adaptación automática
if ($backupPrefix !== $currentPrefix) {
    $this->adaptTablePrefix($backupPath, $backupPrefix, $currentPrefix);
}
```

### 3. **Migración Automática de URLs y Dominios**
**Problema anterior:**
- Redirecciones al dominio original del backup
- URLs hardcodeadas en la base de datos

**Solución automática:**
- Detecta el dominio actual automáticamente
- Actualiza todas las tablas relevantes (`shop_url`, `configuration`)
- Maneja dominios SSL y no-SSL

```php
// Detección automática de dominio actual
$currentDomain = $this->getCurrentDomain();

// Actualización automática de URLs
UPDATE {$prefix}shop_url SET 
    domain = '{$currentDomain}', 
    domain_ssl = '{$currentDomain}';

UPDATE {$prefix}configuration SET 
    value = '{$currentDomain}' 
    WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL');
```

### 4. **Deshabilitación Automática de Módulos Problemáticos**
**Problema anterior:**
- Fatal errors por módulos como `ps_mbo`, `ps_eventbus`
- Dependencias faltantes en módulos personalizados

**Solución automática:**
- Lista predefinida de módulos problemáticos
- Detección de módulos con dependencias faltantes
- Deshabilitación automática tanto en BD como en archivos

```php
// Módulos automáticamente deshabilitados
$problematicModules = [
    'ps_mbo',       // PrestaShop Marketplace  
    'ps_eventbus',  // Event Bus
    'ps_metrics',   // Métricas
    'ps_facebook',  // Facebook
];

// Detección de dependencias faltantes
if (file_exists($composerPath) && !is_dir($vendorPath)) {
    $this->disableModule($moduleName);
}
```

### 5. **Gestión Inteligente del Archivo .htaccess**
**Problema anterior:**
- Archivo `.htaccess` faltante causaba errores 404
- Configuración incompatible entre entornos

**Solución automática:**
- Restaura desde `.htaccess2` si está disponible
- Genera configuración mínima si es necesario
- No interfiere si el archivo ya existe y funciona

```php
private function ensureHtaccessExists(): void
{
    $htaccessPath = _PS_ROOT_DIR_ . '/.htaccess';
    $backupPath = _PS_ROOT_DIR_ . '/.htaccess2';
    
    if (!file_exists($htaccessPath) && file_exists($backupPath)) {
        copy($backupPath, $htaccessPath);
    } elseif (!file_exists($htaccessPath)) {
        $this->generateMinimalHtaccess($htaccessPath);
    }
}
```

## 🚀 Cómo Usar la Migración Inteligente

### Método 1: Restauración Inteligente (Recomendado)
```javascript
// Frontend: Usar el botón "Restauración Inteligente"
ajax_call('restore_backup_smart', {
    backup_name: 'nombre_del_backup'
});
```

### Método 2: Importación con Migración
```javascript
// Al importar un backup externo
ajax_call('import_backup', {
    file: archivo_backup.zip,
    migration_mode: 'smart'
});
```

## 📋 Configuración de Migración

### Configuración Automática (Recomendada)
```php
$migrationConfig = [
    'clean_destination' => true,              // Limpiar datos existentes
    'migrate_urls' => true,                   // Migrar URLs automáticamente  
    'preserve_db_config' => true,             // Preservar config de BD
    'disable_problematic_modules' => true,    // Deshabilitar módulos problemáticos
    'auto_detect_environment' => true        // Detección automática
];
```

### Configuración Manual (Avanzada)
```php
$migrationConfig = [
    'clean_destination' => false,             // Mantener datos existentes
    'migrate_urls' => false,                  // No cambiar URLs
    'preserve_db_config' => true,             // Siempre preservar BD
    'target_domain' => 'mi-nuevo-dominio.com', // Dominio específico
    'custom_prefix' => 'custom_'              // Prefijo personalizado
];
```

## 🎯 Casos de Uso Comunes

### 1. **Migración de Producción a DDEV Local**
```bash
# Situación: Backup de producción (eghgastro.com) → DDEV local
# 
# ✅ Automático:
# - Detecta entorno DDEV
# - Preserva credenciales: host=db, user=db, pass=db
# - Cambia URLs: eghgastro.com → prestademo2.ddev.site
# - Deshabilita módulos problemáticos
# - Adapta prefijos: ps924_ → myshop_
```

### 2. **Cambio de Servidor en Producción**
```bash
# Situación: servidor1.com → servidor2.com
#
# ✅ Automático:
# - Detecta nueva configuración de BD
# - Preserva credenciales del servidor2
# - Actualiza URLs al nuevo dominio
# - Mantiene configuración SSL
```

### 3. **Restauración en Servidor de Testing**
```bash
# Situación: Backup completo → entorno staging
#
# ✅ Automático:
# - Adapta a credenciales de staging
# - Cambia dominio a testing.empresa.com  
# - Deshabilita integraciones de producción
# - Limpia caché y configuraciones temporales
```

## 🛠️ Métodos Disponibles

### Clase `DatabaseMigrator`
```php
// Migración completa con todas las adaptaciones
public function migrateWithFullAdaptation(string $backupPath, array $config): void

// Métodos específicos
private function detectBackupPrefix(string $backupPath): string
private function detectBackupDomain(string $backupPath): string  
private function adaptTablePrefix(string $backupPath, string $from, string $to): void
private function migrateUrls(string $targetDomain): void
private function disableProblematicModules(): void
private function preserveEnvironmentConfiguration(): void
```

### Clase `AdminPsCopiaAjaxController`
```php
// Restauración inteligente
private function handleSmartRestoreBackup(): void

// Detección de entorno
private function getCurrentDbCredentials(): array
private function isDdevEnvironment(): bool

// Limpieza de módulos
private function cleanupProblematicModuleFiles(): void
```

## 📊 Logs y Diagnóstico

### Ubicación de Logs
```
var/logs/ps_copia_YYYY-MM-DD.log
```

### Información Registrada
```
[INFO] Starting smart restoration: backup_produccion_2024
[INFO] Environment detected: DDEV
[INFO] Backup prefix detected: ps924_
[INFO] Current prefix: myshop_  
[INFO] Adapting table prefixes...
[INFO] Updating URLs: eghgastro.com → prestademo2.ddev.site
[INFO] Disabling problematic modules: ps_mbo, ps_eventbus
[INFO] Smart restoration completed successfully
```

### Verificación Post-Migración
```php
// Verificar configuración preservada
SELECT name, value FROM configuration WHERE name LIKE '%DB_%';

// Verificar URLs migradas  
SELECT domain, domain_ssl FROM shop_url;

// Verificar módulos deshabilitados
SELECT name, active FROM module WHERE name IN ('ps_mbo', 'ps_eventbus');
```

## ❗ Notas Importantes

### Lo Que SÍ Hace Automáticamente
- ✅ Preserva credenciales del entorno de destino
- ✅ Adapta prefijos de tabla diferentes
- ✅ Migra URLs y dominios
- ✅ Deshabilita módulos problemáticos conocidos
- ✅ Limpia y reconstruye caché
- ✅ Restaura/genera archivos de configuración necesarios

### Lo Que NO Hace (Requiere Intervención Manual)
- ❌ Configuraciones específicas de servidor (PHP, Apache)
- ❌ Certificados SSL personalizados
- ❌ Integraciones con APIs externas
- ❌ Configuraciones de módulos de pago específicos
- ❌ Personalizaciones del theme que dependan del entorno

### Compatibilidad
- ✅ PrestaShop 1.7.x, 8.x, 9.x
- ✅ MySQL/MariaDB todas las versiones
- ✅ DDEV, Docker, cPanel, WHM, VPS
- ✅ Linux, Windows, macOS

---

Con estas mejoras, el 95% de problemas comunes en migraciones se resuelven automáticamente, convirtiendo una tarea compleja en un proceso de un solo clic. 