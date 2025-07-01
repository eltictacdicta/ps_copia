# Instalación del Módulo PS_Copia

## Pasos de Instalación

### 1. Preparación
- Asegúrate de tener una copia de seguridad de tu tienda PrestaShop
- Verifica que tengas permisos de administrador

### 2. Instalación del Módulo
1. Sube la carpeta `ps_copia` al directorio `modules/` de tu instalación de PrestaShop
2. Ve al panel de administración de PrestaShop
3. Navega a **Módulos > Gestor de Módulos**
4. Busca "Asistente de Copias de Seguridad" o "ps_copia"
5. Haz clic en **Instalar**

### 3. Verificación
- El módulo debería aparecer en el menú de administración como "Asistente de Copias"
- Deberías ver dos opciones disponibles:
  - Crear copia de seguridad
  - Restaurar desde una copia de seguridad

### 4. Configuración de Permisos
Asegúrate de que los siguientes directorios tengan permisos de escritura:
```
/admin/ps_copia/
/config/xml/
```

### 5. Primer Uso
1. Ve a **Herramientas > Asistente de Copias** en el menú de administración
2. Selecciona "Crear copia de seguridad" para hacer tu primera copia de seguridad
3. Configura las opciones según tus necesidades
4. Inicia el proceso

## Solución de Problemas

### Error de Autoloader
Si encuentras errores relacionados con el autoloader de Composer:
1. Ve al directorio del módulo: `cd modules/ps_copia/`
2. Ejecuta: `composer dump-autoload --optimize`

### Permisos de Archivo
Si tienes problemas de permisos:
```bash
chmod -R 755 modules/ps_copia/
chmod -R 777 admin/ps_copia/
```

### Cache
Si los cambios no se reflejan:
1. Ve a **Parámetros Avanzados > Rendimiento**
2. Haz clic en **Limpiar cache**

## Contacto
Para soporte técnico, contacta al administrador del sistema. 