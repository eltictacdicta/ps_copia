# Resolución de Problemas - Exportación de Instalador Standalone

## Problema: ZIP generado demasiado pequeño

### Síntomas
- El archivo ZIP del instalador standalone es muy pequeño (menos de 1MB)
- Al intentar descargar, el archivo está corrupto o vacío
- Error durante el proceso de exportación

### Causas Comunes

#### 1. Archivos de backup originales corruptos
**Diagnóstico:**
```bash
# Verificar tamaños de archivos de backup
ls -lh /ruta/a/admin/ps_copia/backups/
```

**Solución:**
- Crear un nuevo backup completo antes de exportar
- Verificar que los archivos `.sql` y `.zip` tengan tamaños razonables

#### 2. Template del instalador faltante o corrupto
**Diagnóstico:**
```bash
# Verificar que existe el template
ls -la modules/ps_copia/installer_templates/ps_copias_installer_template.php
```

**Solución:**
- Asegurar que el archivo template tiene más de 40KB
- Reinstalar el módulo si el template está corrupto

#### 3. Problemas de permisos
**Diagnóstico:**
```bash
# Verificar permisos del directorio de backups
ls -la /admin/ps_copia/backups/
```

**Solución:**
```bash
# Dar permisos de escritura
chmod 755 admin/ps_copia/
chmod 644 admin/ps_copia/backups/*
```

#### 4. Límites de PHP insuficientes
**Diagnóstico:**
- Verificar memory_limit en php.ini
- Verificar max_execution_time

**Solución:**
```ini
; En php.ini
memory_limit = 1024M
max_execution_time = 3600
```

### Proceso de Diagnóstico Detallado

#### Paso 1: Ejecutar script de diagnóstico
```bash
cd modules/ps_copia/
php debug_export.php
```

#### Paso 2: Verificar logs del módulo
```bash
# Buscar archivos de log
find admin/ps_copia/ -name "*.log" -type f
tail -f admin/ps_copia/logs/backup.log
```

#### Paso 3: Verificar backup existente manualmente
```bash
# Listar backups disponibles
ls -la admin/ps_copia/backups/

# Verificar contenido de un backup específico
unzip -l admin/ps_copia/backups/backup_files_YYYY-MM-DD.zip | head -20
```

### Mejoras Implementadas en v1.2.1

#### Validaciones añadidas:
1. **Verificación de tamaño de archivos** antes del procesamiento
2. **Validación de integridad ZIP** para archivos de backup
3. **Logs detallados** en cada paso del proceso
4. **Manejo de errores mejorado** con limpieza automática de archivos temporales
5. **Verificación de template** antes de generar el instalador

#### Logs mejorados:
```php
// Ejemplo de logs que ahora se generan:
[INFO] Backup files validation: database_size=50.2MB, files_size=150.8MB
[INFO] Generated installer config: package_id=backup_abc123
[INFO] Template loaded successfully: template_size=45459 bytes
[INFO] Nested ZIP created successfully: nested_zip_size=200.5MB
[INFO] Standalone installer export created successfully: final_size=201.2MB
```

### Soluciones Específicas por Error

#### Error: "Database backup file is too small"
```bash
# Crear nuevo backup de base de datos
cd admin/ps_copia/
# Desde la interfaz de PS_Copia, crear backup solo de DB
```

#### Error: "Files backup file is too small"
```bash
# Crear nuevo backup de archivos
# Desde la interfaz de PS_Copia, crear backup solo de archivos
```

#### Error: "Installer template not found"
```bash
# Verificar y restaurar template
cp modules/ps_copia/installer_templates/ps_copias_installer_template.php.backup \
   modules/ps_copia/installer_templates/ps_copias_installer_template.php
```

#### Error: "Cannot create nested package ZIP"
```bash
# Verificar espacio en disco temporal
df -h /tmp/
# Limpiar archivos temporales si es necesario
rm -f /tmp/ps_copia_*
```

### Pasos de Verificación Post-Exportación

#### 1. Verificar tamaño del archivo generado
```bash
ls -lh admin/ps_copia/backups/*_standalone_installer.zip
```

#### 2. Verificar contenido del ZIP
```bash
unzip -l backup_XXXX_standalone_installer.zip
```

**Contenido esperado:**
- `ps_copias_installer.php` (40-50KB)
- `ps_copias_package_*.zip` (tamaño del backup completo)
- `README.txt` (2-3KB)

#### 3. Probar el instalador generado
```bash
# Extraer en directorio de prueba
mkdir test_installer/
cd test_installer/
unzip ../backup_XXXX_standalone_installer.zip

# Verificar que el installer PHP se ve bien
head -50 ps_copias_installer.php
```

### Contacto y Soporte

Si después de seguir estos pasos el problema persiste:

1. **Recopilar información:**
   - Ejecutar `php debug_export.php`
   - Copiar logs de error completos
   - Versión de PHP y PrestaShop
   - Tamaño de la tienda (número de productos, archivos)

2. **Información del entorno:**
   - Sistema operativo del servidor
   - Versión de MySQL
   - Configuración de PHP relevante

3. **Crear issue con:**
   - Descripción detallada del problema
   - Logs completos del error
   - Información del entorno
   - Pasos para reproducir el problema 