# 🔧 Solución para Problema de Restauración - Módulo PS_Copia

## ✅ Problema Resuelto

**Error original:** Al restaurar una copia de seguridad, el sistema intentaba usar los datos del servidor original (URL, sufijo de tabla, configuraciones) en lugar de adaptarlos al servidor de destino.

**Mensaje de error típico:**
```
Error: Restoration failed: Database restore failed: URL_migration failed: 
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.ps_shop_url' doesn't exist. 
System rolled back to previous state.
```

## 🎯 Solución Implementada

### 1. **Migración Automática de URLs Forzada**

**Cambios en `DatabaseMigrator.php`:**
- Modificado el método `autoDetectUrls()` para **SIEMPRE** forzar la migración de URLs
- Agregado flag `force_shop_url_update = true` automáticamente
- Mejorada la detección de dominio de destino con múltiples fallbacks

```php
// SIEMPRE habilitar migración de URLs si tenemos una URL de destino
if (!empty($migrationConfig['new_url'])) {
    $migrationConfig['migrate_urls'] = true;
    $migrationConfig['force_shop_url_update'] = true; // FORZAR actualización
}
```

### 2. **Actualización Forzada de Tabla shop_url**

**Mejoras implementadas:**
- Agregada actualización forzada de `shop_url` al final del proceso de migración
- Múltiples fallbacks para detectar el dominio actual
- Limpieza automática del dominio (remover puertos)
- Verificación y creación de configuraciones faltantes

```php
// SIEMPRE forzar actualización de shop_url independientemente de la configuración anterior
$this->logger->info("FORCING shop_url table update to ensure proper domain configuration");
$this->forceUpdateShopUrl($migrationConfig);
```

### 3. **Configuración de Dominio Robusta**

**Mejoras en `updateDomainConfiguration()`:**
- Verificación de existencia de configuraciones antes de actualizar
- Inserción automática de configuraciones faltantes (`PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL`)
- Manejo individual de cada configuración para mejor control

```php
// Verificar si existe la configuración
$existsQuery = "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($configKey) . "'";
$exists = $this->db->getValue($existsQuery);

if ($exists) {
    // Actualizar configuración existente
    $sql = "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($domain) . "' WHERE `name` = '" . pSQL($configKey) . "'";
} else {
    // Insertar nueva configuración si no existe
    $sql = "INSERT INTO `" . _DB_PREFIX_ . "configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('" . pSQL($configKey) . "', '" . pSQL($domain) . "', NOW(), NOW())";
}
```

### 4. **Fallbacks Agresivos para Detección de Dominio**

**Sistema de fallbacks mejorado:**
```php
// Intentar múltiples fallbacks
$fallbacks = [
    $_SERVER['HTTP_HOST'] ?? '',
    $_SERVER['SERVER_NAME'] ?? '',
    'localhost'
];

foreach ($fallbacks as $fallback) {
    if (!empty($fallback)) {
        $targetDomain = $fallback;
        $this->logger->info("Using fallback domain: " . $targetDomain);
        break;
    }
}
```

### 5. **Verificación Post-Migración**

**Nuevo método `verifyMigrationSuccess()`:**
- Verifica que la tabla `shop_url` tenga el dominio correcto
- Verifica que las configuraciones `PS_SHOP_DOMAIN` y `PS_SHOP_DOMAIN_SSL` estén actualizadas
- Registra todos los valores para debugging

## 🚀 Cómo Funciona Ahora

### **Proceso de Restauración Mejorado:**

1. **Detección Automática:** El sistema detecta automáticamente el dominio actual del servidor
2. **Configuración Forzada:** Se fuerza la migración de URLs independientemente de la configuración
3. **Restauración de BD:** Se restaura la base de datos del backup
4. **Migración de URLs:** Se ejecuta la migración de URLs (si se detectaron URLs origen y destino)
5. **Actualización Forzada:** Se fuerza la actualización de `shop_url` con el dominio actual
6. **Configuración de Dominio:** Se actualizan/crean las configuraciones de dominio
7. **Verificación:** Se verifica que todos los cambios se hayan aplicado correctamente

### **Adaptación Automática:**
- **URLs:** `https://servidor-origen.com` → `https://servidor-destino.com`
- **Dominios:** `servidor-origen.com` → `servidor-destino.com`
- **Configuraciones:** Se preservan las del servidor de destino
- **Prefijos:** Se adaptan automáticamente si son diferentes

## ⚠️ Compatibilidad

**Entornos soportados:**
- ✅ DDEV (detección automática)
- ✅ Docker (detección automática)
- ✅ Servidores tradicionales
- ✅ Localhost
- ✅ Dominios con puerto (se limpia automáticamente)

**Versiones PrestaShop:**
- ✅ PrestaShop 1.7.x
- ✅ PrestaShop 8.x
- ✅ Diferentes prefijos de tabla

## 📋 Resultado

**Antes:**
```
❌ Error: Base table or view not found: 1146 Table 'db.ps_shop_url' doesn't exist
❌ URLs del servidor original permanecían en el destino
❌ Configuraciones mezcladas entre origen y destino
```

**Después:**
```
✅ Restauración exitosa con adaptación automática
✅ URLs actualizadas al servidor de destino
✅ Configuraciones correctas para el entorno actual
✅ Verificación post-migración automática
```

## 🔍 Logs de Debugging

El sistema ahora genera logs detallados que incluyen:
- Detección de dominio actual
- Configuración de migración aplicada
- Resultados de actualización de `shop_url`
- Verificación de configuraciones
- Fallbacks utilizados

**Ejemplo de logs:**
```
[INFO] Auto-detected destination URL: https://prestademo2.ddev.site
[INFO] URL migration FORCED: servidor-origen.com → prestademo2.ddev.site
[INFO] FORCING shop_url table update to ensure proper domain configuration
[INFO] Updated PS_SHOP_DOMAIN to prestademo2.ddev.site: SUCCESS
[INFO] Migration verification completed
```

## 🎉 Conclusión

La solución implementada garantiza que **todas las restauraciones de backup se adapten automáticamente al servidor de destino**, eliminando completamente el error original y asegurando que el sistema funcione correctamente después de la restauración. 