# Funcionalidades de Exportar e Importar Backups

## Nuevas Funcionalidades Implementadas

Se han añadido nuevas funcionalidades al módulo **ps_copia** para permitir exportar e importar copias de seguridad mediante archivos ZIP:

### 1. **Exportar Backup**
- **Botón**: "Exportar" (naranja) en la lista de backups completos
- **Funcionalidad**: Crea un archivo ZIP que contiene:
  - Archivo de base de datos (.sql.gz)
  - Archivo de archivos (.zip)
  - Metadata del backup (backup_info.json)
- **Descarga automática**: Al hacer clic se inicia la descarga automáticamente
- **Estructura del ZIP exportado**:
  ```
  backup_export.zip
  ├── database/
  │   └── backup_db_YYYY-MM-DD_HH-mm-ss.sql.gz
  ├── files/
  │   └── backup_files_YYYY-MM-DD_HH-mm-ss.zip
  └── backup_info.json
  ```

### 2. **Importar Backup**
- **Botón**: "Subir Backup" (reemplaza "Seleccionar Backup")
- **Funcionalidad**: 
  - Abre un modal para seleccionar archivo ZIP
  - Valida la estructura del archivo
  - Extrae y procesa los archivos de backup
  - Crea un nuevo backup importado con nombre único
- **Validaciones**:
  - Solo archivos ZIP
  - Estructura correcta (database/, files/, backup_info.json)
  - Archivos de backup válidos

### 3. **Flujo de Trabajo**

#### Exportar:
1. Crea un backup completo
2. En la lista de backups, hacer clic en "Exportar"
3. Se descarga un archivo ZIP con todo el backup

#### Importar:
1. Hacer clic en "Subir Backup"
2. Seleccionar el archivo ZIP exportado
3. Hacer clic en "Subir Backup" en el modal
4. El backup se procesa y aparece en la lista como "imported_backup_YYYY-MM-DD_HH-mm-ss"

### 4. **Cambios en la Interfaz**

- **Botón principal cambiado**: "Seleccionar Backup" → "Subir Backup"
- **Nuevo botón**: "Exportar" en cada backup completo
- **Modal añadido**: Para subir archivos ZIP
- **Progreso de subida**: Barra de progreso durante la importación

### 5. **Archivos Modificados**

1. **controllers/admin/AdminPsCopiaAjaxController.php**:
   - Nuevos métodos: `handleExportBackup()`, `handleImportBackup()`, `handleDownloadExport()`
   - Casos añadidos en el switch: 'export_backup', 'import_backup', 'download_export'

2. **views/templates/admin/backup_dashboard.tpl**:
   - Cambio de texto del botón principal
   - Nuevo modal para subir backups
   - Botón de exportar en la lista de backups
   - Manejadores JavaScript para las nuevas funcionalidades

### 6. **Características de Seguridad**

- **Validación de archivos**: Solo acepta ZIPs con estructura correcta
- **Nombres únicos**: Los backups importados reciben nombres únicos para evitar conflictos
- **Verificación de ruta**: Los archivos descargados se verifican para estar dentro del directorio de backups
- **Limpieza automática**: Los archivos temporales se eliminan automáticamente

### 7. **Gestión de Errores**

- Validación de tamaño de archivo (limits de PHP)
- Timeouts apropiados para operaciones largas
- Mensajes de error descriptivos
- Limpieza automática en caso de fallo

### 8. **Compatibilidad**

- Mantiene compatibilidad con backups existentes
- Los backups exportados pueden importarse en cualquier instalación con el módulo
- Funciona con la funcionalidad de restauración existente

## Uso Recomendado

1. **Para migrar entre servidores**: Exportar backup en servidor origen, importar en servidor destino
2. **Para copias de seguridad externas**: Exportar y guardar los ZIP en sistemas externos
3. **Para compartir backups**: Los ZIP exportados contienen todo lo necesario para restaurar

## Notas Técnicas

- Los archivos ZIP exportados se eliminan automáticamente después de la descarga
- El proceso de importación valida la integridad de los archivos
- Se mantiene toda la metadata original del backup en el archivo JSON 