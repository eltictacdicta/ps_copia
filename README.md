# 🔒 Backup Assistant - Asistente de Copias de Seguridad para PrestaShop

![Versión](https://img.shields.io/badge/versión-1.1.0-brightgreen.svg)
![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-5.6+-purple.svg)
![Licencia](https://img.shields.io/badge/licencia-AFL--3.0-orange.svg)

**Backup Assistant** es un módulo avanzado de PrestaShop diseñado para crear y restaurar copias de seguridad completas de tu tienda online. Optimizado para sitios grandes y con funcionalidades avanzadas para garantizar una migración y backup seguros.

## 🚀 Características Principales

### ✨ **Gestión Inteligente de Backups**
- 🔄 **Creación automática** de copias de seguridad completas
- 📦 **Restauración integral** desde backups existentes
- 🔍 **Verificación de integridad** automática
- 🏷️ **Etiquetado y organización** de backups

### 💪 **Optimizado para Sitios Grandes**
- 🎯 **Detección automática** de sitios > 500MB
- ⚡ **Procesamiento por chunks** (grupos de 100 archivos)
- 🌊 **Streaming para archivos grandes** (> 50MB)
- 🧠 **Gestión optimizada de memoria** (< 100MB constante)
- ⏱️ **Prevención automática de timeouts**

### 🌐 **Funcionalidades Avanzadas**
- 📤 **Importar desde servidor** - Subir via FTP/SFTP sin límites
- 🔧 **Migración automática** entre dominios
- 🛡️ **Verificación de seguridad** multi-capa
- 📊 **Interfaz visual mejorada** con progreso en tiempo real

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
- `curl` - Transferencias HTTP
- `json` - Procesamiento datos

## 📦 Instalación

### **Método 1: Instalación Manual**
1. Descarga el módulo y descomprime en `modules/backup_assistant/`
2. Ve a **Módulos > Gestor de Módulos** en tu admin
3. Busca "Asistente de Copias de Seguridad"
4. Haz clic en **Instalar**

### **Método 2: Composer**
```bash
cd modules/backup_assistant/
composer install --optimize-autoloader
```

### **Verificación Post-Instalación**
- ✅ Comprueba que aparece en **Herramientas > Asistente de Copias**
- ✅ Verifica permisos de escritura en `/admin/backup_assistant/`
- ✅ Ejecuta la suite de pruebas: `php test_large_sites.php`

## 🎯 Uso del Módulo

### **Crear Copia de Seguridad**
1. Ve a **Herramientas > Asistente de Copias**
2. Selecciona **"Crear Copia de Seguridad"**
3. Configura opciones (archivos, base de datos, configuración)
4. Inicia el proceso _(detección automática para sitios grandes)_

### **Restaurar desde Backup**

#### **Archivos Pequeños (<100MB):**
1. Selecciona **"Restaurar"**
2. Sube tu archivo ZIP
3. Confirma la restauración

#### **Archivos Grandes (>100MB):**
1. Sube tu backup via **FTP/SFTP** a `/admin/backup_assistant/uploads/`
2. Clic en **"Importar desde Servidor"**
3. Selecciona tu archivo de la lista
4. Inicia la importación _(procesamiento optimizado automático)_

### **Migración entre Dominios**
- ✅ URLs actualizadas automáticamente
- ✅ Configuración adaptada al nuevo entorno
- ✅ Verificación post-migración

## 🛠️ Funcionalidades Avanzadas

### **Detección Automática de Sitios Grandes**
```php
// El módulo detecta automáticamente y optimiza para:
- Sitios > 500MB → Modo chunked
- Archivos > 50MB → Streaming
- Memoria > 80% → Limpieza agresiva
```

### **Procesamiento por Chunks**
- Procesa archivos en grupos de 100
- Limpia memoria después de cada chunk
- Mantiene progreso visual actualizado

### **Gestión de Uploads Servidor**
```
/admin/backup_assistant/uploads/
├── .htaccess          # Protección automática
├── index.php          # Prevenir listado
└── backup_sitio.zip   # Tu backup grande
```

### **Suite de Pruebas Automatizadas**
```bash
cd modules/backup_assistant/
php test_large_sites.php

✅ testMemoryLimitParsing      - OK
✅ testFileSizeEstimation      - OK
✅ testChunkedProcessing       - OK
✅ testStreamingFileHandling   - OK
✅ testTimeoutPrevention       - OK
✅ testLargeFileDetection      - OK
✅ testMemoryCleanup           - OK
```

## 🚦 Solución de Problemas

### **Errores Comunes**

#### Error de Memoria
```
❌ Fatal error: Allowed memory size exhausted
✅ Solución: El módulo gestiona memoria automáticamente
   - Verifica que está en versión 1.1.0+
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

### **Verificación de Estado**
```bash
# Comprobar configuración del módulo
curl -X POST admin/index.php?controller=AdminBackupAssistantAjax&action=scan_server_uploads

# Verificar permisos
ls -la admin/backup_assistant/uploads/
```

## 📊 Métricas de Rendimiento

| Tamaño del Sitio | Método Recomendado | Tiempo Estimado | Memoria Usada |
|------------------|-------------------|-----------------|---------------|
| < 100MB | Upload HTTP | 2-5 minutos | < 50MB |
| 100MB - 500MB | Upload HTTP | 5-15 minutos | < 100MB |
| 500MB - 2GB | Importar Servidor | 10-30 minutos | < 100MB |
| > 2GB | Importar Servidor | 30-60 minutos | < 200MB |

## 🔄 Changelog

### **Versión 1.1.0** _(Actual)_
- ✨ **Nuevo**: Funcionalidad "Importar desde Servidor"
- ⚡ **Mejorado**: Optimizaciones para sitios grandes (hasta 2GB)
- 🔧 **Nuevo**: Detección automática y procesamiento inteligente
- 🛡️ **Mejorado**: Seguridad multi-capa y validaciones
- 📊 **Nuevo**: Interfaz visual mejorada con progreso detallado
- 🧪 **Nuevo**: Suite de pruebas automatizadas
- 📚 **Mejorado**: Documentación técnica completa

### **Versión 1.0.1**
- 🐛 Correcciones menores
- 📝 Mejoras en traducciones

### **Versión 1.0.0**
- 🎉 Lanzamiento inicial
- 🔄 Funcionalidades básicas de backup/restore

## 📚 Documentación Adicional

- [`INSTALL.md`](INSTALL.md) - Guía detallada de instalación
- [`UPLOADS_SERVIDOR.md`](UPLOADS_SERVIDOR.md) - Uso avanzado de uploads
- [`OPTIMIZACIONES_SITIOS_GRANDES.md`](OPTIMIZACIONES_SITIOS_GRANDES.md) - Detalles técnicos
- [`RESUMEN_IMPLEMENTACION.md`](RESUMEN_IMPLEMENTACION.md) - Características implementadas

## 🛡️ Seguridad

### **Medidas Implementadas**
- 🔒 **Path traversal protection** - Prevención de acceso no autorizado
- 🛡️ **Validación de extensiones** - Solo archivos .zip permitidos
- 📁 **Archivos .htaccess automáticos** - Protección del directorio uploads
- 🚫 **Restricción de acceso** - Solo administradores autorizados
- ✅ **Verificación de integridad** - Validación de estructura de backups

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

**Este es un proyecto propio desarrollado de forma independiente.** Aunque ha sido probado exhaustivamente y cuenta con una suite de pruebas automatizadas, **se recomienda usarlo con precaución** en entornos de producción.

**El autor no se hace responsable de cualquier problema, pérdida de datos o daños** que puedan surgir del uso de este módulo. Se recomienda encarecidamente:

- ✅ **Realizar pruebas** en entorno de desarrollo antes de usar en producción
- ✅ **Mantener backups actualizados** de tu tienda antes de usar el módulo
- ✅ **Verificar la compatibilidad** con tu versión específica de PrestaShop
- ✅ **Probar en un subdominio** antes de aplicar en tu tienda principal

**Usa este módulo bajo tu propia responsabilidad.** 