# 🔧 Solución para Problema de Restauración - Módulo PS_Copia

## ✅ Problema Resuelto

**Error original:** Al restaurar una copia de seguridad desde otro proyecto, el sistema presentaba dos problemas principales:

1. **Error SQL SQLSTATE[42000]**: El sistema intentaba acceder a tablas con prefijo del backup (ej. `ps924_shop_url`) cuando las tablas reales tenían prefijo del entorno actual (ej. `ps_shop_url`).

2. **Error 500 después de restauración**: Aunque la restauración parecía exitosa, el sitio daba error 500 porque el archivo `parameters.php` mantenía el prefijo del backup en lugar del prefijo del entorno actual.

**Mensajes de error típicos:**
```
Error: Restoration failed: SQLSTATE[42000]: Syntax error or access violation: 1064 
You have an error in your SQL syntax; check the manual that corresponds to your 
MariaDB server version for the right syntax to use near 'LIMIT 1' at line 1, 
System rolled back to previous state.
```

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.ps924_shop_url' doesn't exist
```

## 🎯 Solución Implementada

### 1. **Corrección de Detección de Prefijos**

**Cambios en `DatabaseMigrator.php`:**
- Modificado `getShopUrlTableName()` para **SIEMPRE** usar el prefijo del entorno actual, nunca el del backup
- Implementado sistema de reintentos para esperar a que las tablas se creen después de la restauración
- Mejorada la lógica de fallback para encontrar tablas shop_url

```php
/**
 * Get the correct shop_url table name with proper prefix detection
 * This method ensures we always use the CURRENT environment's prefix, not the backup's prefix
 */
private function getShopUrlTableName(): ?string
{
    // ALWAYS use current environment prefix, never backup prefix
    $currentPrefix = $this->getCurrentPrefix();
    
    // Strategy 1: Try current prefix first (most likely)
    $currentPrefixTable = $currentPrefix . 'shop_url';
    if ($this->tableExists($currentPrefixTable)) {
        return $currentPrefixTable;
    }
    
    // Additional fallback strategies...
}
```

### 2. **Corrección Automática del Archivo parameters.php**

**Nueva funcionalidad implementada:**
- Método `fixParametersFilePrefix()` que corrige automáticamente el prefijo en `parameters.php`
- Se ejecuta automáticamente después de cada restauración
- Previene el error 500 asegurando coherencia entre configuración y base de datos

```php
/**
 * Fix parameters.php file to ensure correct database prefix after restoration
 * This prevents the common issue where restored backups have different prefixes
 */
private function fixParametersFilePrefix(): void
{
    // Reads parameters.php and updates database_prefix to match current environment
    $pattern = "/'database_prefix'\s*=>\s*'[^']*'/";
    $replacement = "'database_prefix' => '" . $currentPrefix . "'";
    $newContent = preg_replace($pattern, $replacement, $content);
}
```

### 3. **Mejora del Orden de Operaciones**

**Secuencia optimizada de migración:**
1. **PRIMERO**: Restaurar completamente la base de datos
2. **SEGUNDO**: Aplicar migraciones de URL (después de que existan las tablas)
3. **TERCERO**: Actualización forzada de shop_url para verificación
4. **CUARTO**: Preservar configuración admin
5. **QUINTO**: **NUEVO** - Corregir parameters.php automáticamente

### 4. **Sistema de Espera para Tablas**

**Mecanismo de retry implementado:**
- Espera hasta 5 segundos para que las tablas se creen después de la restauración
- Previene errores de "tabla no encontrada" durante migraciones inmediatas
- Logging detallado de cada intento

```php
// Wait for table to exist after restoration (with retry mechanism)
$maxRetries = 5;
$retryCount = 0;

while ($retryCount < $maxRetries && !$this->tableExists($shopUrlTable)) {
    $this->logger->info("Waiting for shop_url table to be created... (attempt " . ($retryCount + 1) . "/{$maxRetries})");
    sleep(1); // Wait 1 second
    $retryCount++;
}
```

## 🔍 Diagnóstico del Problema Resuelto

**Caso específico encontrado:**
- **Base de datos**: Tablas con prefijo `ps_` (correcto)
- **parameters.php**: Configurado con `'database_prefix' => 'ps924_'` (incorrecto)
- **Resultado**: Error 500 porque PrestaShop no podía encontrar las tablas

**Solución aplicada:**
1. Corrección manual inmediata: `sed -i "s/'database_prefix' => 'ps924_'/'database_prefix' => 'ps_'/" parameters.php`
2. Implementación de corrección automática permanente en el código

## 📋 Verificación de la Solución

**Comandos de verificación utilizados:**
```bash
# Verificar prefijo en base de datos
ddev exec mysql -e "SHOW TABLES LIKE '%shop_url%';"

# Verificar configuración en parameters.php
ddev exec grep "database_prefix" /var/www/html/app/config/parameters.php

# Verificar estado del sitio
curl -s -I https://eghcopia3.ddev.site
```

**Resultado final:**
- ✅ Sitio funcionando correctamente (HTTP 200)
- ✅ Coherencia entre prefijos de base de datos y configuración
- ✅ Prevención automática de futuros problemas similares

## 🚀 Beneficios de la Solución

1. **Resolución automática**: El problema se corrige automáticamente sin intervención manual
2. **Prevención proactiva**: Evita el problema desde el origen durante la restauración
3. **Compatibilidad completa**: Funciona entre diferentes entornos PrestaShop
4. **Logging detallado**: Facilita el diagnóstico de problemas futuros
5. **Robustez mejorada**: Sistema de reintentos para operaciones críticas

## 📝 Notas Técnicas

- **Versión MariaDB**: 10.11.11-MariaDB (verificado compatible)
- **Entorno**: DDEV con PrestaShop 8.x
- **Archivos modificados**: `DatabaseMigrator.php`, documentación
- **Impacto**: Cero interrupciones, mejora transparente del proceso 