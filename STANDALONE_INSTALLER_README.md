# PS Copias - Instalador Standalone (Estilo Duplicator)

## 🚀 Nueva Funcionalidad Implementada

Se ha agregado una nueva funcionalidad al módulo PS Copias que permite crear un **instalador standalone estilo Duplicator** para PrestaShop. Esta funcionalidad te permite crear un paquete completo que puede ser instalado en cualquier servidor sin necesidad de tener PrestaShop preinstalado.

## ✨ Características Principales

### 📦 Paquete Todo-en-Uno
- **Instalador PHP automático** basado en la interfaz de Duplicator de WordPress
- **Archivos completos** del sitio PrestaShop
- **Base de datos completa** con todos los datos
- **Configuración automática** de URLs y rutas
- **Verificación de requisitos** del sistema
- **Instrucciones paso a paso** integradas

### 🎯 Casos de Uso Ideales
- **Migración de servidores** sin downtime
- **Copias para desarrollo** en nuevos entornos
- **Backup portable** que no requiere el módulo instalado
- **Distribución de tiendas** preconfiguradas
- **Recuperación de emergencia** en servidores limpios

## 🔧 Archivos Modificados

### 1. Controlador AJAX (`controllers/admin/AdminPsCopiaAjaxController.php`)
```php
// Nueva acción agregada al switch
case 'export_standalone_installer':
    $this->handleExportStandaloneInstaller();
    break;

// Nuevo método handler
private function handleExportStandaloneInstaller(): void
```

### 2. Servicio de Importación/Exportación (`classes/Services/ImportExportService.php`)
```php
// Nuevo método principal
public function exportStandaloneInstaller(string $backupName): array

// Métodos auxiliares agregados:
private function generateInstallerConfig(array $backupData, string $packageId): array
private function generateInstallerPHP(array $config): string
private function generateSiteConfig(array $backupData): array
private function generateReadmeContent(string $packageId, array $config): string
private function getModulesInfo(): array
private function getMySQLVersion(): string
```

### 3. Plantilla del Instalador (`installer_templates/ps_copias_installer_template.php`)
- Instalador completo basado en el archivo `ps_copias_installer.php` existente
- Sistema de plantillas con variables reemplazables
- Interfaz web moderna y responsive
- Verificación automática de requisitos
- Proceso paso a paso guiado

### 4. Interfaz de Usuario (`views/templates/admin/backup_dashboard.tpl`)
```javascript
// Nuevo botón en la lista de backups
html += '<button class="btn btn-xs btn-primary export-standalone-installer-btn" ';
html += 'data-backup-name="' + backup.name + '" title="Exportar con Instalador Estilo Duplicator">';
html += '<i class="icon-magic"></i> Instalador';

// Nuevo manejador JavaScript
$(document).on('click', '.export-standalone-installer-btn', function() {
```

## 📋 Cómo Usar la Nueva Funcionalidad

### Paso 1: Crear un Backup
1. Accede al panel de administración de PrestaShop
2. Ve a **Configuración > Módulos > PS Copias**
3. Haz clic en **"Crear Backup Completo"**
4. Espera a que se complete el proceso

### Paso 2: Exportar con Instalador
1. En la lista de backups disponibles, busca tu backup
2. Haz clic en el botón **"Instalador"** (icono de varita mágica)
3. Confirma la creación del instalador standalone
4. Espera a que se genere el paquete
5. El archivo ZIP se descargará automáticamente

### Paso 3: Instalar en Nuevo Servidor
1. **Extrae el ZIP** descargado en tu computadora
2. **Sube todos los archivos** al directorio raíz de tu nuevo servidor web
3. **Accede al instalador** navegando a: `http://tu-nuevo-dominio.com/ps_copias_installer.php`
4. **Sigue el asistente** paso a paso:
   - ✅ Bienvenida y verificación de archivos
   - 📦 Extracción de paquete (si es necesario)
   - 🔧 Verificación de requisitos del sistema
   - 🗄️ Configuración de base de datos
   - 🚀 Proceso de instalación automático
   - 🎉 ¡Instalación completada!

## 🗂️ Estructura del Paquete Generado

```
nombre_backup_12345_standalone_installer.zip
├── ps_copias_installer.php          # Instalador principal
├── ps_copias_package_12345.zip      # Paquete con backup completo
│   ├── ps_copias_archive_12345.zip  # Archivos del sitio
│   ├── ps_copias_database_12345.sql # Base de datos
│   └── site_config.json             # Configuración del sitio
└── README.txt                       # Instrucciones detalladas
```

## ⚙️ Configuración Automática

El instalador maneja automáticamente:

### 🔄 Migración de URLs
- Detección automática del nuevo dominio
- Actualización de configuración de PrestaShop
- Modificación de URLs en la base de datos
- Actualización del archivo `.htaccess`

### 🗄️ Base de Datos
- Creación y limpieza de tablas
- Importación de datos
- Actualización de credenciales
- Verificación de integridad

### 📁 Archivos
- Extracción completa del sitio
- Preservación de permisos
- Limpieza de cache
- Backup de archivos existentes (opcional)

## 🔒 Requisitos del Sistema

### Servidor de Origen (donde se crea el backup)
- PrestaShop 1.7.0+ o 8.x
- Módulo PS Copias instalado
- PHP 7.2+
- Acceso de administrador

### Servidor de Destino (donde se instala)
- **PHP 7.2 o superior**
- **MySQL 5.6 o superior**
- **Extensiones PHP requeridas:**
  - ZIP
  - MySQLi o PDO_MySQL
- **Memoria mínima:** 512MB
- **Espacio en disco:** Suficiente para el backup + 20%

## 🛡️ Características de Seguridad

### Durante la Creación
- Validación de permisos de administrador
- Verificación de integridad de backups
- Generación de IDs únicos para paquetes
- Limpieza automática de archivos temporales

### Durante la Instalación
- Verificación de requisitos del sistema
- Validación de archivos de paquete
- Conexión segura a base de datos
- Limpieza de archivos temporales al completar

## 🔍 Solución de Problemas

### Error: "Installer template not found"
```bash
# Verificar que existe el directorio y archivo
ls -la installer_templates/ps_copias_installer_template.php
```

### Error: "Cannot create ZIP file"
- Verificar permisos de escritura en directorio de backups
- Comprobar espacio disponible en disco
- Revisar límites de memoria PHP

### Error durante la instalación
- Verificar requisitos del sistema
- Comprobar credenciales de base de datos
- Revisar permisos de archivos en servidor destino

## 📊 Ventajas vs. Exportación Normal

| Característica | Exportación Normal | Instalador Standalone |
|----------------|-------------------|----------------------|
| **Requiere PS preinstalado** | ✅ Sí | ❌ No |
| **Configuración manual** | ✅ Requerida | ❌ Automática |
| **Interfaz de instalación** | ❌ No | ✅ Sí |
| **Verificación de requisitos** | ❌ Manual | ✅ Automática |
| **Migración de URLs** | ❌ Manual | ✅ Automática |
| **Instrucciones incluidas** | ❌ No | ✅ Sí |
| **Tamaño del paquete** | Menor | Mayor (+instalador) |

## 🎯 Próximas Mejoras Sugeridas

1. **Interfaz más avanzada** con progress bars en tiempo real
2. **Verificación de módulos** compatibility check
3. **Migración de certificados SSL** automática
4. **Configuración de CDN** preservation
5. **Multi-idioma** para el instalador
6. **Logs detallados** durante la instalación
7. **Rollback automático** en caso de error

## 📞 Soporte

Para reportar issues o solicitar mejoras, contacta al desarrollador del módulo PS Copias.

---

**Desarrollado por:** Javier Trujillo  
**Versión:** 1.2.1+  
**Fecha:** $(date +'%Y-%m-%d')  
**Inspirado en:** Duplicator de WordPress 