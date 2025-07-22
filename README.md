# 🔒 PS_Copia - Asistente de Copias de Seguridad para PrestaShop

![Versión](https://img.shields.io/badge/versión-1.3.0-brightgreen.svg)
![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-5.6+-purple.svg)
![Licencia](https://img.shields.io/badge/licencia-AFL--3.0-orange.svg)

**PS_Copia** es un módulo avanzado de PrestaShop diseñado para crear y restaurar copias de seguridad completas de tu tienda online. Refactorizado completamente en la versión 1.2.1 con arquitectura de servicios y ahora en la v1.3.0 incluye el revolucionario **Instalador Simple AJAX** para migraciones sin dependencias de PrestaShop.

## 🚀 Características Principales

### ✨ **Arquitectura de Servicios (v1.2.1+)**
- 🏗️ **Refactorización completa** con arquitectura de servicios especializados
- 🔧 **BackupService**: Gestión de copias de seguridad
- 🔄 **RestoreService**: Gestión avanzada de restauración con migración automática
- 📤 **ImportExportService**: Operaciones de importación/exportación
- 📁 **FileManagerService**: Gestión de archivos y uploads al servidor
- ✅ **ValidationService**: Validaciones del sistema
- 📊 **ResponseHelper**: Gestión optimizada de respuestas AJAX

### 🚀 **Instalador Simple AJAX (v1.3.0)**
- 🌐 **Instalador independiente** sin dependencias de PrestaShop
- ⚡ **Extracción por chunks** para archivos de cualquier tamaño
- 📊 **Progreso en tiempo real** con barras visuales y logs
- 🔄 **Tecnología AJAX** para evitar timeouts y bloqueos
- 🛠️ **Instalación guiada** paso a paso con interfaz moderna
- 📁 **Extracción inteligente** que corrige el problema de archivos no extraídos

### 💪 **Gestión Inteligente de Backups**
- 🔄 **Creación automática** de copias de seguridad completas
- 📦 **Restauración integral** desde backups existentes
- 🧠 **Restauración inteligente** con adaptación automática del entorno
- 🔍 **Verificación de integridad** automática
- 🏷️ **Etiquetado y organización** de backups

### 🌐 **Funcionalidades Avanzadas**
- 📤 **Importar desde servidor** - Subir via FTP/SFTP sin límites
- 🔧 **Migración automática** entre dominios y prefijos de tabla
- 🛡️ **Verificación de seguridad** multi-capa
- 📊 **Interfaz visual mejorada** con progreso en tiempo real
- 🔄 **Restauración selectiva** (solo base de datos o solo archivos)
- 📥 **Exportación de backups** para migración externa
- 🎯 **Instalador Simple AJAX** - Migración independiente estilo Duplicator
- ⚡ **Manejo de archivos grandes** sin limitaciones de servidor
- 🔧 **Configuración automática** de dominios y URLs

### 🏗️ **Compatibilidad Técnica**
- ✅ PrestaShop 1.7.0 y superior
- ✅ PHP 5.6 a 8.x
- ✅ MySQL/MariaDB
- ✅ Multishop compatible
- ✅ Multiidioma completo

## 📋 Requisitos del Sistema

### **Mínimos:**
- PHP 5.6 o superior
- Extensiones: `zip`, `mysqli`
- PrestaShop 1.7.0+
- 128MB RAM (recomendado 256MB+)

### **Para Sitios Grandes (>500MB):**
- PHP 7.2+ recomendado
- 512MB RAM o superior
- `max_execution_time` flexible
- Acceso FTP/SFTP para uploads grandes

### **Extensiones PHP Requeridas:**
- `zip` - Compresión de archivos
- `mysqli` - Conexión base de datos
- `curl` - Transferencias HTTP (opcional)
- `json` - Procesamiento datos

## 📦 Instalación

### **Método 1: Instalación Manual**
1. Descarga el módulo y descomprime en `modules/ps_copia/`
2. Ve a **Módulos > Gestor de Módulos** en tu admin
3. Busca "Asistente de Copias de Seguridad"
4. Haz clic en **Instalar**

### **Método 2: Composer**
```bash
cd modules/ps_copia/
composer install --optimize-autoloader
```

### **Verificación Post-Instalación**
- ✅ Comprueba que aparece en **Herramientas > Asistente de Copias**
- ✅ Verifica permisos de escritura en `/admin/ps_copia/`
- ✅ Verifica que se crearon las pestañas del módulo correctamente

## 🎯 Uso del Módulo

### **Crear Copia de Seguridad**
1. Ve a **Herramientas > Asistente de Copias**
2. Selecciona **"Crear Copia de Seguridad"**
3. Configura opciones (completa, solo DB, solo archivos)
4. Inicia el proceso con detección automática de optimizaciones

### **Restaurar desde Backup**

#### **Archivos Pequeños (<100MB):**
1. Selecciona **"Restaurar"**
2. Sube tu archivo ZIP
3. Confirma la restauración

#### **Archivos Grandes (>100MB):**
1. Sube tu backup via **FTP/SFTP** a `/admin/ps_copia/uploads/`
2. Clic en **"Importar desde Servidor"**
3. Selecciona tu archivo de la lista
4. Inicia la importación con procesamiento optimizado automático

### **Restauración Inteligente**
- ✅ **Migración automática** de URLs y configuración
- ✅ **Adaptación de prefijos** de tabla automática
- ✅ **Verificación post-migración** completa
- ✅ **Corrección automática** de problemas comunes

### **Restauración Selectiva**
- 🗄️ **Solo Base de Datos**: Restaura únicamente la BD desde backup completo
- 📁 **Solo Archivos**: Restaura únicamente archivos desde backup completo
- 🎯 **Personalizada**: Combina opciones según necesidades

### **Instalador Simple AJAX (¡NUEVO!)**
#### **Para Migraciones y Nuevas Instalaciones**
1. Ve a **Backups Disponibles** en tu tienda actual
2. Selecciona un backup y haz clic en **"Instalador"** 📋
3. Descarga **2 archivos**:
   - `ps_copias_installer_simple.php` (instalador AJAX)
   - `backup_XXXX_export.zip` (backup estándar)
4. **En el servidor destino**:
   - Sube ambos archivos al directorio raíz
   - Accede a `http://tu-dominio.com/ps_copias_installer_simple.php`
   - Sigue el proceso guiado con **AJAX en tiempo real**

#### **Ventajas del Instalador AJAX:**
- ✅ **Sin dependencias** de PrestaShop en servidor destino
- ✅ **Manejo de archivos grandes** con extracción por chunks
- ✅ **Progreso visual** en tiempo real con logs detallados  
- ✅ **Recuperación de errores** automática
- ✅ **Estilo Duplicator** familiar y confiable
- ✅ **Configuración automática** de URLs y dominios

## 🛠️ Funcionalidades Avanzadas

### **Arquitectura de Servicios (v1.2.1)**
```
Controllers/
├── AdminPsCopiaController.php      # Interfaz principal
└── AdminPsCopiaAjaxController.php  # API AJAX refactorizada

Services/
├── BackupService.php               # Creación de backups
├── RestoreService.php              # Restauración avanzada
├── ImportExportService.php         # Import/Export
├── FileManagerService.php          # Gestión de archivos
├── ValidationService.php           # Validaciones
└── ResponseHelper.php              # Respuestas AJAX
```

### **Gestión de Uploads Servidor**
```
/admin/ps_copia/uploads/
├── .htaccess          # Protección automática
├── index.php          # Prevenir listado
└── backup_sitio.zip   # Tu backup grande
```

### **Operaciones Disponibles via AJAX**

#### **En el Módulo Principal:**
- `create_backup` - Crear backup
- `restore_backup` - Restauración estándar
- `restore_backup_smart` - Restauración inteligente
- `restore_database_only` - Solo BD
- `restore_files_only` - Solo archivos
- `export_backup` - Exportar backup
- `import_backup` - Importar backup
- `scan_server_uploads` - Escanear uploads servidor
- `import_from_server` - Importar desde servidor
- `validate_backup` - Validar integridad
- `export_standalone_installer` - **¡NUEVO!** Generar instalador AJAX

#### **En el Instalador Simple AJAX:**
- `extract_backup` - Extraer backup principal
- `extract_files` - Iniciar extracción de archivos  
- `extract_files_chunk` - Procesar chunk de archivos
- `restore_database` - Restaurar base de datos
- `configure_system` - Configurar sistema
- `get_progress` - Obtener progreso en tiempo real

## 🚦 Solución de Problemas

### **Errores Comunes**

#### Error de Memoria
```
❌ Fatal error: Allowed memory size exhausted
✅ Solución: El módulo gestiona memoria automáticamente
   - Verifica que está en versión 1.2.1+
   - Para sitios >2GB: aumentar memory_limit a 1GB
```

#### Timeouts
```
❌ Maximum execution time exceeded
✅ Solución: Usa "Importar desde Servidor" para archivos grandes
   - Sube via FTP primero
   - El módulo gestiona timeouts automáticamente
```

#### Problemas de Upload
```
❌ File too large / upload_max_filesize
✅ Solución: Función "Importar desde Servidor"
   - Sin límites de tamaño
   - Upload independiente de PHP
```

#### Problemas de Migración
```
❌ URLs o prefijos incorrectos después de restaurar
✅ Solución: Usa "Restauración Inteligente" o "Instalador Simple AJAX"
   - Migración automática de URLs
   - Adaptación automática de prefijos
   - Verificación post-restauración
   - Instalador independiente para nuevos servidores
```

#### Problemas con Archivos Grandes en Instalador
```
❌ Archivos no se extraen o timeouts durante instalación
✅ Solución: Usar el nuevo "Instalador Simple AJAX" (v1.3.0)
   - Extracción por chunks de 50 archivos
   - Progreso en tiempo real
   - Sin dependencias de límites PHP
   - Recuperación automática de errores
```

### **Verificación de Estado**
```bash
# Comprobar uploads al servidor
curl -X POST admin/index.php?controller=AdminPsCopiaAjax&action=scan_server_uploads

# Verificar permisos
ls -la admin/ps_copia/uploads/
```

## 📊 Métricas de Rendimiento

| Tamaño del Sitio | Método Recomendado | Tiempo Estimado | Memoria Usada |
|------------------|-------------------|-----------------|---------------|
| < 100MB | Upload HTTP o Instalador AJAX | 2-5 minutos | < 50MB |
| 100MB - 500MB | Upload HTTP o Instalador AJAX | 5-15 minutos | < 100MB |
| 500MB - 2GB | Importar Servidor o **Instalador AJAX** | 10-30 minutos | < 100MB |
| > 2GB | Importar Servidor o **Instalador AJAX** | 30-60 minutos | < 200MB |

### **Nuevo: Rendimiento Instalador AJAX**
| Característica | Instalador Original | Instalador AJAX v1.3.0 |
|---------------|-------------------|-------------------------|
| **Archivos grandes** | ❌ Timeouts frecuentes | ✅ Chunks de 50 archivos |
| **Progreso visual** | ❌ Sin feedback | ✅ Barras + logs tiempo real |
| **Recuperación errores** | ❌ Reinicio manual | ✅ Automática con logs |
| **Dependencias** | ❌ Necesita PrestaShop | ✅ Solo PHP básico |
| **Hosting compatibilidad** | ❌ Limitado | ✅ Universal |

## 🔄 Changelog

### **Versión 1.3.0** _(Actual)_ 🎉
- 🚀 **NUEVO**: **Instalador Simple AJAX** - Migración independiente sin PrestaShop
- ⚡ **NUEVO**: **Extracción por chunks** - Maneja archivos de cualquier tamaño
- 📊 **NUEVO**: **Progreso en tiempo real** - Barras visuales y logs detallados
- 🔧 **CORREGIDO**: **Extracción de archivos** - Resuelto problema de archivos no extraídos
- 🎯 **NUEVO**: **Interfaz moderna AJAX** - Estilo Duplicator con tecnología web actual
- 🛠️ **MEJORADO**: **Manejo de errores** - Recuperación automática y logs detallados
- 📋 **NUEVO**: **Generador de instalador** - Botón directo desde backups disponibles
- 🌐 **MEJORADO**: **Compatibilidad hosting** - Funciona en cualquier servidor con PHP básico
- 📁 **CORREGIDO**: **Lógica de archivos** - Extracción paso a paso sin timeouts
- 🔐 **MEJORADO**: **Seguridad instalador** - Exclusiones automáticas y limpieza

### **Versión 1.2.1**
- 🏗️ **Nuevo**: Refactorización completa con arquitectura de servicios
- 🧠 **Nuevo**: Restauración inteligente con migración automática
- 🔧 **Mejorado**: Manejo robusto de prefijos de tabla y URLs
- 📤 **Mejorado**: Sistema de uploads al servidor optimizado
- 🛡️ **Mejorado**: Validaciones de seguridad multi-capa
- 📊 **Mejorado**: Interfaz con mejor feedback y progreso
- 🔄 **Nuevo**: Restauración selectiva (solo BD o solo archivos)
- 📥 **Nuevo**: Exportación de backups para migración externa
- 🧪 **Mejorado**: Suite de tests ampliada y robusta

### **Versión 1.2.0**
- 🔧 Refactorización inicial del controlador
- 📚 Mejoras en documentación técnica

### **Versión 1.1.0**
- ✨ Funcionalidad "Importar desde Servidor"
- ⚡ Optimizaciones para sitios grandes
- 🔧 Detección automática y procesamiento inteligente

### **Versión 1.0.0**
- 🎉 Lanzamiento inicial
- 🔄 Funcionalidades básicas de backup/restore

## 📚 Documentación Adicional

Los siguientes documentos están disponibles para referencia técnica:
- `LICENSE.md` - Licencia del módulo
- `INSTALL.md` - Guía detallada de instalación
- `CHANGELOG_v1.2.md` - Detalles de versiones anteriores
- `SIMPLE_INSTALLER_README.md` - **¡NUEVO!** Guía completa del Instalador Simple AJAX
- `TROUBLESHOOTING_EXPORT.md` - Solución de problemas de exportación
- `STANDALONE_INSTALLER_README.md` - Documentación técnica del instalador

## 🛡️ Seguridad

### **Medidas Implementadas**
- 🔒 **Path traversal protection** - Prevención de acceso no autorizado
- 🛡️ **Validación de extensiones** - Solo archivos .zip permitidos
- 📁 **Archivos .htaccess automáticos** - Protección del directorio uploads
- 🚫 **Restricción de acceso** - Solo administradores autorizados
- ✅ **Verificación de integridad** - Validación de estructura de backups
- 🔐 **Validación de servicios** - Arquitectura de servicios con validaciones

### **Recomendaciones**
- Usar conexiones HTTPS para admin
- Cambiar nombre del directorio admin regularmente
- Mantener backups en ubicación segura externa
- Verificar permisos de archivos periódicamente

## 🤝 Contribución

Este módulo está en desarrollo activo. Para contribuir:

1. 🍴 Fork del repositorio
2. 🔧 Crea tu feature branch (`git checkout -b feature/AmazingFeature`)
3. ✅ Commit tus cambios (`git commit -m 'Add AmazingFeature'`)
4. 📤 Push al branch (`git push origin feature/AmazingFeature`)
5. 📝 Abre un Pull Request

## 📄 Licencia

Este proyecto está licenciado bajo la [Academic Free License 3.0](LICENSE.md).

## 👨‍💻 Soporte

Para soporte técnico y consultas:
- 📧 Contacta al administrador del sistema
- 🐛 Reporta bugs en el sistema de issues
- 📖 Consulta la documentación incluida

---

## ⚠️ **IMPORTANTE - Disclaimer**

**Este es un proyecto propio desarrollado de forma independiente.** Aunque ha sido probado exhaustivamente y cuenta con una arquitectura robusta de servicios, **se recomienda usarlo con precaución** en entornos de producción.

**El autor no se hace responsable de cualquier problema, pérdida de datos o daños** que puedan surgir del uso de este módulo. Se recomienda encarecidamente:

- ✅ **Realizar pruebas** en entorno de desarrollo antes de usar en producción
- ✅ **Mantener backups actualizados** de tu tienda antes de usar el módulo
- ✅ **Verificar la compatibilidad** con tu versión específica de PrestaShop
- ✅ **Probar en un subdominio** antes de aplicar en tu tienda principal

**Usa este módulo bajo tu propia responsabilidad.** 