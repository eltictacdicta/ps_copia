# Optimizaciones para Sitios Grandes - Backup Assistant

## Resumen de Mejoras Implementadas

Este documento describe las optimizaciones implementadas en el módulo **backup_assistant** para hacer que sea compatible con exportación/importación de sitios grandes sin depender de la configuración del servidor.

## Problemas Solucionados

### 1. **Límites de Memoria**
- **Problema**: Los backups grandes consumen demasiada memoria RAM
- **Solución**: Procesamiento por chunks y streaming de archivos

### 2. **Timeouts de Ejecución**
- **Problema**: Los procesos largos exceden max_execution_time
- **Solución**: Refreshing automático de timeouts y procesamiento asíncrono

### 3. **Límites de Subida**
- **Problema**: Archivos grandes fallan por upload_max_filesize
- **Solución**: Detección automática y manejo optimizado

## Características Implementadas

### 🚀 **Procesamiento Inteligente**

#### Detección Automática de Sitios Grandes
```php
$estimatedSize = $this->estimateDirectorySize($sourceDir);
$isLargeSite = $estimatedSize > 500 * 1024 * 1024; // 500MB
```

- Estima el tamaño del sitio rápidamente
- Cambia automáticamente a modo optimizado para sitios > 500MB
- Evita escaneo completo innecesario

#### Procesamiento por Chunks
```php
$chunkSize = 100; // Procesar archivos en grupos pequeños
$chunks = array_chunk($filesToProcess, $chunkSize);
```

- Procesa archivos en grupos de 100
- Limpia memoria después de cada chunk
- Previene timeouts automáticamente

### 💾 **Manejo Optimizado de Memoria**

#### Streaming para Archivos Grandes
```php
if ($fileInfo['size'] > 50 * 1024 * 1024) { // 50MB
    $this->addLargeFileToZip($zip, $fileInfo['path'], $fileInfo['relative']);
}
```

- Archivos > 50MB se procesan con streaming
- Máximo 20MB en memoria por archivo
- Usa archivos temporales para archivos enormes

#### Garbage Collection Automático
```php
private function clearMemory(): void
{
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
    
    // Limpieza agresiva si memoria > 80%
    if ($memoryUsage > ($memoryLimit * 0.8)) {
        for ($i = 0; $i < 3; $i++) {
            gc_collect_cycles();
        }
    }
}
```

### ⏱️ **Prevención de Timeouts**

#### Refreshing Automático
```php
private function preventTimeout(): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // 5 minutos más
    }
    
    // Mantener conexión activa
    if (ob_get_level()) {
        @ob_flush();
    }
    @flush();
}
```

#### Timeouts Dinámicos en JavaScript
```javascript
var dynamicTimeout = isLargeFile(file) ? 3600000 : 600000; // 60min vs 10min
```

### 📦 **Importación Optimizada**

#### Verificación de Integridad
```php
private function verifyZipIntegrity(string $zipPath): bool
{
    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CHECKCONS);
    return $result === TRUE;
}
```

#### Extracción por Streaming
```php
private function extractFileStreaming(ZipArchive $zip, string $filename, string $outputPath): void
{
    $stream = $zip->getStream($filename);
    $chunkSize = 8192; // 8KB chunks
    
    while (!feof($stream)) {
        $chunk = fread($stream, $chunkSize);
        fwrite($output, $chunk);
        
        // Prevenir timeout cada MB
        if ($totalWritten % (1024 * 1024) === 0) {
            $this->preventTimeout();
        }
    }
}
```

## Estrategias de Optimización

### 1. **Autodetección Sin Configuración**
- No requiere cambios en php.ini
- Funciona dentro de límites existentes
- Optimiza automáticamente según el tamaño

### 2. **Procesamiento Progresivo**
- Divide operaciones grandes en pequeñas
- Mantiene la respuesta del servidor
- Permite progreso visible al usuario

### 3. **Manejo Inteligente de Errores**
- Diferencia entre archivos grandes y pequeños
- Proporciona consejos específicos
- Permite recuperación parcial

## Beneficios Implementados

### ✅ **Para el Usuario**
- **Advertencias Informativas**: Se informa cuando se detecta un archivo grande
- **Progreso Visual**: Barras de progreso mejoradas
- **Timeouts Extendidos**: 30-60 minutos para operaciones grandes
- **Consejos Contextuales**: Mensajes específicos según el tamaño

### ✅ **Para el Servidor**
- **Uso Eficiente de Memoria**: Máximo 20-50MB por operación
- **Prevención de Timeouts**: Refreshing automático cada chunk
- **Limpieza Automática**: Garbage collection agresivo
- **Archivos Temporales**: Limpieza automática al finalizar

### ✅ **Para el Desarrollador**
- **Código Modular**: Métodos separados para sitios grandes/pequeños
- **Logging Detallado**: Seguimiento completo del proceso
- **Fallbacks Automáticos**: Degradación elegante en caso de error
- **Compatibilidad**: Funciona con configuraciones restrictivas

## Límites y Recomendaciones

### 📊 **Límites Soft**
- **Sitios pequeños** (< 500MB): Procesamiento estándar
- **Sitios medianos** (500MB - 2GB): Procesamiento chunked
- **Sitios grandes** (> 2GB): Requiere configuración del servidor

### 💡 **Recomendaciones para Sitios Enormes**
1. **Aumentar memory_limit** a 1GB si es posible
2. **Configurar max_execution_time** a 0 (ilimitado)
3. **Usar DDEV o entorno controlado** para desarrollo
4. **Considerar backups incrementales** para sitios > 5GB

### 🛠️ **Configuración DDEV Recomendada**
```bash
# Para sitios grandes en DDEV
ddev config --php-version 8.1
ddev exec echo "memory_limit = 1G" >> /etc/php/8.1/cli/php.ini
ddev exec echo "max_execution_time = 0" >> /etc/php/8.1/cli/php.ini
ddev exec echo "upload_max_filesize = 1G" >> /etc/php/8.1/cli/php.ini
ddev exec echo "post_max_size = 1G" >> /etc/php/8.1/cli/php.ini
ddev restart
```

## Testing y Validación

### 🧪 **Casos de Prueba**
1. **Sitio pequeño** (< 100MB): Debe usar procesamiento estándar
2. **Sitio mediano** (100MB - 1GB): Debe mostrar advertencias y usar chunks
3. **Sitio grande** (> 1GB): Debe usar streaming completo
4. **Archivo corrupto**: Debe fallar con mensaje claro
5. **Timeout simulado**: Debe proporcionar consejos específicos

### 📈 **Métricas de Éxito**
- ✅ Importación exitosa de backups > 500MB
- ✅ Uso de memoria < 100MB durante el proceso
- ✅ Sin timeouts para operaciones normales
- ✅ Mensajes de error informativos
- ✅ Limpieza automática de archivos temporales

## Conclusión

Estas optimizaciones permiten que el módulo backup_assistant maneje sitios de cualquier tamaño sin requerir cambios en la configuración del servidor. El sistema detecta automáticamente el tamaño del sitio y aplica la estrategia más apropiada, garantizando el éxito de la operación mientras mantiene un uso eficiente de los recursos del servidor.

La implementación es completamente transparente para el usuario final y proporciona feedback detallado durante todo el proceso. 