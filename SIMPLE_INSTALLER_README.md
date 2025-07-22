# PS Copias - Instalador Simple AJAX

## Descripción

El **Instalador Simple AJAX** es una versión mejorada del instalador independiente de PS_Copia que utiliza tecnología AJAX para manejar archivos grandes y evitar problemas de timeout durante la instalación.

## Características Principales

### ✨ Nuevas Características AJAX

- **Extracción por chunks**: Los archivos se procesan en lotes de 50 archivos para evitar timeouts
- **Progreso en tiempo real**: Barras de progreso y logs detallados durante todo el proceso
- **Manejo de archivos grandes**: Optimizado para backups de cualquier tamaño
- **Recuperación de errores**: Mejor manejo de errores con logs detallados
- **Interfaz moderna**: UI responsive con feedback visual

### 🔧 Funcionalidades

1. **Detección automática de backup**: Encuentra automáticamente el ZIP de backup en el directorio
2. **Verificación de requisitos**: Comprueba PHP, extensiones y permisos
3. **Configuración de base de datos**: Interfaz amigable para configurar MySQL
4. **Extracción AJAX**: Procesa archivos paso a paso sin bloquear el navegador
5. **Restauración de base de datos**: Maneja archivos SQL y SQL.GZ con comandos optimizados
6. **Configuración automática**: Actualiza dominios y URLs automáticamente

## Instalación

### Prerrequisitos

- PHP 5.6 o superior
- Extensión ZIP de PHP
- Extensión MySQLi de PHP
- MySQL/MariaDB
- Permisos de escritura en el directorio

### Proceso de Instalación

1. **Preparación**:
   ```bash
   # Subir ambos archivos al directorio raíz del servidor
   - ps_copias_installer_simple.php
   - nombre_backup_export.zip
   ```

2. **Ejecutar instalador**:
   ```
   http://tu-dominio.com/ps_copias_installer_simple.php
   ```

3. **Seguir los pasos**:
   - Verificación de requisitos
   - Configuración de base de datos
   - Extracción automática (AJAX)
   - Instalación automática (AJAX)
   - Finalización

## Flujo del Proceso AJAX

### Paso 1: Extracción del Backup Principal
```javascript
GET /?ajax=1&action=extract_backup
```
- Extrae el ZIP principal al directorio `extracted_backup`
- Verifica la estructura del backup
- Lee la información del backup

### Paso 2: Extracción de Archivos por Chunks
```javascript
GET /?ajax=1&action=extract_files
GET /?ajax=1&action=extract_files_chunk&chunk=0&files_zip_path=...
```
- Cuenta los archivos totales en el ZIP
- Extrae archivos en chunks de 50 archivos
- Muestra progreso en tiempo real
- Mueve archivos a ubicación final

### Paso 3: Restauración de Base de Datos
```javascript
GET /?ajax=1&action=restore_database
```
- Detecta archivos SQL/SQL.GZ automáticamente
- Usa comandos MySQL optimizados para archivos grandes
- Fallback a PHP para archivos pequeños

### Paso 4: Configuración del Sistema
```javascript
GET /?ajax=1&action=configure_system
```
- Actualiza URLs y dominios automáticamente
- Configura la tienda para el nuevo entorno

## Archivos Generados

Durante la instalación se crean estos archivos temporales:

- `installer_db_config.json` - Configuración de base de datos
- `installer_log_YYYY-MM-DD_HH-MM-SS.txt` - Log detallado
- `extracted_backup/` - Directorio temporal de extracción
- `temp_restore_*/` - Directorio temporal para archivos
- `progress_*.json` - Archivos de progreso AJAX

## Configuración

### Configuración de Base de Datos

```json
{
    "host": "localhost",
    "user": "usuario_db",
    "password": "contraseña_db",
    "name": "nombre_db",
    "prefix": "ps_"
}
```

### Configuración AJAX

```php
define('MAX_EXECUTION_TIME', 300); // 5 minutos por chunk
define('MEMORY_LIMIT', '512M');    // Límite de memoria
define('CHUNK_SIZE', 50);          // Archivos por chunk
```

## Seguridad

### Archivos a Eliminar Después de la Instalación

```bash
# Archivos del instalador
rm ps_copias_installer_simple.php
rm installer_db_config.json
rm installer_log_*.txt
rm progress_*.json

# Directorio temporal
rm -rf extracted_backup/

# ZIP de backup
rm nombre_backup_export.zip
```

### Exclusiones Automáticas

El instalador excluye automáticamente:
- El archivo instalador mismo
- Logs del instalador
- Directorios temporales
- Archivos de configuración del instalador

## Solución de Problemas

### Error: "Archivo ZIP de archivos no encontrado"

**Problema**: No se encuentra el ZIP de archivos dentro del backup.

**Solución**:
1. Verificar que el backup incluye archivos de la tienda
2. Revisar la estructura del ZIP exportado
3. Comprobar logs del instalador

### Error: "Comando MySQL no disponible"

**Problema**: El comando `mysql` no está disponible en el servidor.

**Solución**:
1. Usar archivos SQL más pequeños (< 5MB)
2. Contactar al proveedor de hosting
3. El instalador automáticamente usará PHP como fallback

### Error: "Timeout durante la extracción"

**Problema**: El proceso AJAX se detiene.

**Solución**:
1. Recargar la página e intentar nuevamente
2. Verificar conectividad de red
3. Comprobar logs del servidor

### Error: "Permisos insuficientes"

**Problema**: No se pueden crear directorios o archivos.

**Solución**:
```bash
# Dar permisos de escritura
chmod 755 directorio_instalacion
chown www-data:www-data directorio_instalacion
```

## Logs y Depuración

### Archivo de Log

```
[2024-01-15 10:30:15] === PS Copias Simple Installer AJAX Started ===
[2024-01-15 10:30:15] Version: 2.0
[2024-01-15 10:30:15] Step: extract
[2024-01-15 10:30:15] Backup: backup_2024-01-15
[2024-01-15 10:30:16] Starting backup extraction via AJAX
[2024-01-15 10:30:17] Found files ZIP: backup_2024-01-15_files.zip
[2024-01-15 10:30:17] Total files to extract: 1250
[2024-01-15 10:30:18] Extracting files chunk: 0
[2024-01-15 10:30:19] Extracting files chunk: 1
...
```

### Progreso AJAX

```json
{
    "task": "extract_files",
    "percentage": 75,
    "message": "Extrayendo chunk 15 de 20...",
    "timestamp": 1642234567
}
```

## Diferencias con el Instalador Original

| Característica | Instalador Original | Instalador AJAX |
|---|---|---|
| **Extracción** | Síncrona (todo de una vez) | Asíncrona por chunks |
| **Archivos grandes** | Problemas de timeout | Optimizado |
| **Progreso** | Sin feedback visual | Barras de progreso en tiempo real |
| **Logs** | Básicos | Detallados con timestamps |
| **Interfaz** | Estática | Dinámica con actualizaciones |
| **Recuperación de errores** | Limitada | Mejorada con reintentos |

## Compatibilidad

- **PrestaShop**: 1.6.x, 1.7.x, 8.x
- **PHP**: 5.6+, 7.x, 8.x
- **MySQL**: 5.6+, MariaDB 10.x
- **Navegadores**: Modernos con soporte AJAX
- **Hosting**: Compartido, VPS, Dedicado

## Notas Técnicas

### Optimizaciones de Rendimiento

1. **Chunks pequeños**: 50 archivos por chunk para evitar timeouts
2. **Memoria controlada**: Liberación de memoria cada 1MB
3. **Timeouts ajustables**: 5 minutos por operación AJAX
4. **Fallbacks inteligentes**: PHP cuando MySQL no está disponible

### Estructura de Directorios

```
directorio_instalacion/
├── ps_copias_installer_simple.php
├── backup_export.zip
├── extracted_backup/           # Temporal
│   ├── backup_info.json
│   ├── database/
│   │   └── backup.sql.gz
│   └── files/
│       └── backup_files.zip
├── temp_restore_*/             # Temporal
├── installer_log_*.txt         # Log
└── progress_*.json            # Progreso AJAX
```

## Contacto y Soporte

Para problemas específicos del instalador AJAX:

1. Revisar logs detallados
2. Verificar requisitos del sistema
3. Comprobar permisos de archivos
4. Consultar documentación del servidor

---

**Versión**: 2.0 AJAX  
**Compatible con**: ZIP de exportación estándar de PS_Copia  
**Última actualización**: 2024-01-15 