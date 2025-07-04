{*
* Copyright since 2007 PrestaShop SA and Contributors
* PrestaShop is an International Registered Trademark & Property of PrestaShop SA
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License version 3.0
* that is bundled with this package in the file LICENSE.md.
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/AFL-3.0
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* @author    PrestaShop SA and Contributors <contact@prestashop.com>
* @copyright Since 2007 PrestaShop SA and Contributors
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-archive"></i>
        Asistente de Copias de Seguridad
        <span class="badge">v{$module_version}</span>
    </div>
    
    <div id="ps-copia-content" class="panel-body">
        <div class="row">
            <div class="col-lg-12">
                <div class="alert alert-info">
                    <h4><i class="icon-info-circle"></i> Bienvenido al Asistente de Copias de Seguridad</h4>
                    <p>Este módulo te ayuda a crear copias de seguridad completas y restaurar tu tienda PrestaShop. Con solo unos clics, puedes crear y restaurar copias de seguridad con confianza.</p>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="icon-download"></i>
                            Crear Copia de Seguridad Completa
                        </h3>
                    </div>
                    <div class="panel-body text-center">
                        <p>Crea una copia de seguridad completa de tu tienda incluyendo <strong>archivos y base de datos</strong>.</p>
                        <button id="createBackupBtn" class="btn btn-lg btn-primary">
                            <i class="icon-download"></i>
                            Crear Backup Completo
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="icon-upload"></i>
                            Restaurar desde Backup
                        </h3>
                    </div>
                    <div class="panel-body text-center">
                        <p>Sube un archivo ZIP de backup exportado para poder restaurarlo.</p>
                        <div class="btn-group-vertical" style="width: 100%;">
                            <button id="uploadBackupBtn" class="btn btn-lg btn-warning" style="margin-bottom: 8px;">
                                <i class="icon-upload"></i>
                                Subir Backup Simple
                            </button>
                            <button id="serverUploadsBtn" class="btn btn-lg btn-info" style="margin-bottom: 8px;">
                                <i class="icon-hdd"></i>
                                Importar desde Servidor
                            </button>
                            <button id="uploadWithMigrationDirectBtn" class="btn btn-lg btn-primary">
                                <i class="icon-magic"></i>
                                Migrar desde Otro PrestaShop
                            </button>
                        </div>
                        <small class="help-block" style="margin-top: 10px;">
                            <strong>Migración:</strong> Cambia URLs y configuraciones automáticamente<br>
                            <strong>Servidor:</strong> Para archivos grandes subidos por FTP/SFTP
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div id="backup-progress" class="row" style="display: none;">
            <div class="col-lg-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Progreso de la Copia de Seguridad</h3>
                    </div>
                    <div class="panel-body">
                        <div class="progress">
                            <div id="backup-progress-bar" class="progress-bar progress-bar-striped active" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%;">
                                <span id="backup-progress-label">Iniciando...</span>
                            </div>
                        </div>
                        <div id="backup-feedback"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="icon-list"></i>
                            Backups Disponibles
                            <button id="refreshBackupsList" class="btn btn-xs btn-default pull-right">
                                <i class="icon-refresh"></i> Actualizar
                            </button>
                        </h3>
                    </div>
                    <div class="panel-body">
                        <div id="backups-list">
                            <p class="text-center"><i class="icon-spinner icon-spin"></i> Cargando...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal de confirmación de restauración -->
<div class="modal fade" id="restoreConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-warning-sign text-warning"></i>
                    Confirmar Restauración Completa con Migración
                </h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong><i class="icon-warning-sign"></i> ADVERTENCIA:</strong> Esta acción restaurará completamente tu tienda desde el backup seleccionado.
                </div>
                
                <!-- Nuevas características automáticas -->
                <div class="well well-sm">
                    <h5><i class="icon-magic text-success"></i> Se aplicará automáticamente:</h5>
                    <ul class="list-unstyled" style="margin: 0;">
                        <li><i class="icon-check text-success"></i> <strong>URLs:</strong> Se detectan y cambian automáticamente</li>
                        <li><i class="icon-check text-success"></i> <strong>Carpeta Admin:</strong> Se detecta automáticamente del backup</li>
                        <li><i class="icon-check text-success"></i> <strong>Base de Datos:</strong> Se mantiene la configuración actual</li>
                    </ul>
                </div>
                
                <p>¿Estás seguro de que quieres restaurar desde: <strong id="restore-backup-name"></strong>?</p>
                <ul class="text-danger">
                    <li>Se sobrescribirán <strong>TODOS</strong> los archivos actuales</li>
                    <li>Se sobrescribirá <strong>TODA</strong> la base de datos actual</li>
                    <li>Esta acción <strong>NO SE PUEDE DESHACER</strong></li>
                    <li>Se recomienda hacer un backup actual antes de proceder</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="confirmRestoreBtn">
                    <i class="icon-upload"></i> Sí, Restaurar con Migración
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para subir backup -->
<div class="modal fade" id="uploadBackupModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-upload text-warning"></i>
                    Subir Backup
                </h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong><i class="icon-info-circle"></i> Información:</strong> 
                    Selecciona un archivo ZIP exportado previamente desde este módulo para poder restaurarlo.
                </div>
                <form id="uploadBackupForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="backup_file">Archivo de Backup (ZIP):</label>
                        <input type="file" class="form-control" id="backup_file" name="backup_file" accept=".zip" required>
                        <small class="help-block">Solo archivos ZIP exportados desde este módulo son válidos.</small>
                    </div>
                </form>
                <div id="upload-progress" style="display: none;">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">
                            <span>Subiendo...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="confirmUploadBtn">
                    <i class="icon-upload"></i> Subir Backup
                </button>
                <button type="button" class="btn btn-primary" id="uploadWithMigrationBtn">
                    <i class="icon-magic"></i> Subir con Migración
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal simplificado para migración -->
<div class="modal fade" id="uploadMigrationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-magic text-primary"></i>
                    Migrar desde Otro PrestaShop
                </h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong><i class="icon-warning-sign"></i> IMPORTANTE:</strong> 
                    Se sobrescribirán TODOS los datos actuales.
                </div>
                
                <form id="uploadMigrationForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="migration_backup_file">Archivo de Backup (ZIP):</label>
                        <input type="file" class="form-control" id="migration_backup_file" name="backup_file" accept=".zip" required>
                    </div>

                    <!-- Configuración automática simple -->
                    <div class="well well-sm">
                        <h5><i class="icon-magic"></i> Configuración Automática:</h5>
                        <ul class="list-unstyled" style="margin: 0;">
                            <li><i class="icon-check text-success"></i> <strong>URLs:</strong> Se detectan y cambian automáticamente</li>
                            <li><i class="icon-check text-success"></i> <strong>Carpeta Admin:</strong> Se detecta automáticamente del backup</li>
                            <li><i class="icon-check text-success"></i> <strong>Base de Datos:</strong> Se mantiene la configuración actual</li>
                        </ul>
                    </div>

                    <!-- Campos ocultos para configuración automática -->
                    <input type="hidden" name="migrate_urls" value="1">
                    <input type="hidden" name="migrate_admin_dir" value="0">
                    <input type="hidden" name="preserve_db_config" value="1">
                    <input type="hidden" name="old_url" value="">
                    <input type="hidden" name="new_url" value="">
                </form>

                <div id="migration-progress" style="display: none;">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">
                            <span>Procesando migración...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="confirmMigrationBtn">
                    <i class="icon-magic"></i> Migrar Backup
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.panel-default .panel-body {
    min-height: 150px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.panel-default .panel-body p {
    margin-bottom: 20px;
}

.btn-lg {
    padding: 10px 20px;
    font-size: 16px;
}

.backup-complete {
    background-color: #dff0d8;
    border-left: 4px solid #5cb85c;
}

.backup-individual {
    background-color: #f7f7f9;
    border-left: 4px solid #9e9e9e;
}

.table-responsive {
    border: 1px solid #ddd;
    border-radius: 4px;
}

.btn-restore-complete {
    background-color: #5cb85c;
    border-color: #4cae4c;
    color: white;
}

.btn-restore-complete:hover {
    background-color: #449d44;
    border-color: #398439;
    color: white;
}

.backup-type-complete {
    background-color: #5cb85c;
    color: white;
}

.backup-complete .btn-group {
    margin-top: 5px;
}

.backup-complete .btn-group .btn {
    margin-right: 2px;
}

.backup-complete td {
    vertical-align: middle;
    padding: 12px 8px;
}

.backup-complete .restore-complete-btn {
    margin-bottom: 8px;
}

.modal-body ul {
    margin-top: 15px;
}
</style>

<script>
{literal}
$(document).ready(function() {
{/literal}
    var ajaxUrl = "{$link->getAdminLink('AdminPsCopiaAjax')|escape:'html':'UTF-8'}";
    var selectedBackupForRestore = null;
{literal}

    // Crear backup completo
    $('#createBackupBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('i').removeClass('icon-download').addClass('icon-spinner icon-spin');
        
        $('#backup-progress').show();
        $('#backup-feedback').empty();
        updateProgress(10, 'Iniciando copia de seguridad completa...');

        // Simular progreso mientras se ejecuta
        var progressInterval = setInterval(function() {
            var currentProgress = parseInt($('#backup-progress-bar').attr('aria-valuenow'));
            if (currentProgress < 90) {
                updateProgress(currentProgress + 5, 'Creando backup completo...');
            }
        }, 2000);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 600000, // 10 minutos de timeout para backups completos
            data: {
                action: 'create_backup',
                backup_type: 'complete',
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                clearInterval(progressInterval);
                console.log('Respuesta del servidor:', response);
                
                if (response && response.success) {
                    updateProgress(100, '¡Backup completo creado exitosamente!');
                    $('#backup-feedback').html('<div class="alert alert-success"><i class="icon-check"></i> <strong>Éxito:</strong> ' + response.message + '</div>');
                    // Recargar la lista de backups
                    setTimeout(function() {
                        loadBackupsList();
                        $('#backup-progress').fadeOut();
                    }, 3000);
                } else {
                    updateProgress(100, 'Error en la copia de seguridad.');
                    var errorMsg = response && response.error ? response.error : 'Error desconocido';
                    $('#backup-feedback').html('<div class="alert alert-danger"><i class="icon-warning-sign"></i> <strong>Error:</strong> ' + errorMsg + '</div>');
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                updateProgress(100, 'Error de comunicación.');
                
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Es posible que se haya completado, verifica los archivos de backup.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                
                $('#backup-feedback').html('<div class="alert alert-danger"><i class="icon-warning-sign"></i> <strong>Error de comunicación:</strong> ' + errorMessage + '</div>');
                console.error('Error AJAX:', {xhr: xhr, status: status, error: error});
            },
            complete: function() {
                clearInterval(progressInterval);
                $btn.prop('disabled', false).find('i').removeClass('icon-spinner icon-spin').addClass('icon-download');
            }
        });
    });

    function updateProgress(percentage, label) {
        $('#backup-progress-bar').css('width', percentage + '%').attr('aria-valuenow', percentage);
        $('#backup-progress-label').text(label);
    }

    // Cargar lista de backups
    function loadBackupsList() {
        $('#backups-list').html('<p class="text-center"><i class="icon-spinner icon-spin"></i> Cargando...</p>');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'list_backups',
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    displayBackupsList(response.data.backups);
                } else {
                    $('#backups-list').html('<div class="alert alert-warning">Error al cargar la lista de backups: ' + (response.error || 'Error desconocido') + '</div>');
                }
            },
            error: function() {
                $('#backups-list').html('<div class="alert alert-danger">Error de comunicación al cargar la lista de backups.</div>');
            }
        });
    }

    function displayBackupsList(backups) {
        if (backups.length === 0) {
            $('#backups-list').html('<div class="alert alert-info"><i class="icon-info-circle"></i> No hay backups disponibles. Crea tu primer backup completo usando el botón de arriba.</div>');
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-striped">';
        html += '<thead><tr>';
        html += '<th><i class="icon-file"></i> Backup</th>';
        html += '<th><i class="icon-calendar"></i> Fecha</th>';
        html += '<th><i class="icon-hdd"></i> Tamaño</th>';
        html += '<th><i class="icon-tag"></i> Tipo</th>';
        html += '<th><i class="icon-cogs"></i> Acciones</th>';
        html += '</tr></thead><tbody>';

        backups.forEach(function(backup) {
            var rowClass = backup.type === 'complete' ? 'backup-complete' : 'backup-individual';
            var typeIcon = backup.type === 'complete' ? 'icon-archive' : 
                          (backup.type === 'database' ? 'icon-database' : 'icon-folder-open');
            var typeLabel = backup.type === 'complete' ? 'Backup Completo' : 
                           (backup.type === 'database' ? 'Base de Datos' : 'Archivos');
            var typeClass = backup.type === 'complete' ? 'label backup-type-complete' : 
                           (backup.type === 'database' ? 'label label-info' : 'label label-success');
            
            html += '<tr class="' + rowClass + '">';
            html += '<td><i class="' + typeIcon + '"></i> ' + backup.name + '</td>';
            html += '<td>' + backup.date + '</td>';
            html += '<td>' + backup.size_formatted + '</td>';
            html += '<td><span class="' + typeClass + '">' + typeLabel + '</span></td>';
            html += '<td>';
            
            if (backup.type === 'complete') {
                // Restaurar completo (principal)
                html += '<button class="btn btn-sm btn-primary restore-complete-btn" ';
                html += 'data-backup-name="' + backup.name + '" style="margin-right: 5px;">';
                html += '<i class="icon-magic"></i> Restaurar Completo';
                html += '</button><br><br>';
                
                // Botones de restauración parcial (más pequeños)
                html += '<div class="btn-group" role="group" aria-label="Restauración parcial">';
                html += '<button class="btn btn-xs btn-info restore-database-only-btn" ';
                html += 'data-backup-name="' + backup.name + '">';
                html += '<i class="icon-database"></i> Solo BD';
                html += '</button>';
                
                html += '<button class="btn btn-xs btn-success restore-files-only-btn" ';
                html += 'data-backup-name="' + backup.name + '">';
                html += '<i class="icon-folder-open"></i> Solo Archivos';
                html += '</button>';
                html += '</div>';
                
                // Botón de exportar y eliminar
                html += '<br><div class="btn-group" style="margin-top: 8px;">';
                html += '<button class="btn btn-xs btn-warning export-backup-btn" ';
                html += 'data-backup-name="' + backup.name + '">';
                html += '<i class="icon-download"></i> Exportar';
                html += '</button>';
                html += '<button class="btn btn-xs btn-danger delete-backup-btn" ';
                html += 'data-backup-name="' + backup.name + '">';
                html += '<i class="icon-trash"></i> Eliminar';
                html += '</button>';
                html += '</div>';
            } else {
                // Individual restore buttons (legacy support - no debería aparecer con los nuevos cambios)
                var buttonClass = backup.type === 'database' ? 'btn-info' : 'btn-success';
                html += '<button class="btn btn-xs ' + buttonClass + ' restore-backup-btn" ';
                html += 'data-backup-name="' + backup.name + '" data-backup-type="' + backup.type + '">';
                html += '<i class="icon-upload"></i> Restaurar';
                html += '</button>';
            }
            
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        $('#backups-list').html(html);
    }

    // Botón para actualizar lista
    $('#refreshBackupsList').on('click', function() {
        loadBackupsList();
    });

    // Cargar lista al iniciar
    loadBackupsList();

    // Manejar botón de subir backup
    $('#uploadBackupBtn').on('click', function() {
        $('#uploadBackupModal').modal('show');
    });

    // Manejar botones de restaurar completo
    $(document).on('click', '.restore-complete-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        
        selectedBackupForRestore = {
            name: backupName,
            type: 'complete'
        };
        
        $('#restore-backup-name').text(backupName);
        $('#restoreConfirmModal').modal('show');
    });

    // Confirmar restauración completa
    $('#confirmRestoreBtn').on('click', function() {
        if (!selectedBackupForRestore) return;
        
        $('#restoreConfirmModal').modal('hide');
        
        var $btn = $('.restore-complete-btn[data-backup-name="' + selectedBackupForRestore.name + '"]');
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Restaurando...');

        // Mostrar mensaje de progreso
        var alertHtml = '<div class="alert alert-info" id="restore-progress-alert">';
        alertHtml += '<i class="icon-spinner icon-spin"></i> ';
        alertHtml += '<strong>Restaurando...</strong> Este proceso puede tardar varios minutos. No cierres esta página.';
        alertHtml += '</div>';
        $('#ps-copia-content').prepend(alertHtml);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 600000, // 10 minutos de timeout para restauración completa
            data: {
                action: 'restore_backup',
                backup_name: selectedBackupForRestore.name,
                backup_type: 'complete',
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                console.log('Respuesta del servidor:', response);
                $('#restore-progress-alert').remove();
                
                if (response && response.success) {
                    // Mostrar mensaje de éxito
                    var alertHtml = '<div class="alert alert-success alert-dismissible" role="alert">';
                    alertHtml += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    alertHtml += '<span aria-hidden="true">&times;</span></button>';
                    alertHtml += '<i class="icon-check"></i> <strong>¡Éxito!</strong> ' + response.message;
                    alertHtml += '<br><strong>Importante:</strong> Se recomienda limpiar la caché y verificar que todo funcione correctamente.';
                    alertHtml += '</div>';
                    
                    // Insertar mensaje al principio del contenido
                    $('#ps-copia-content').prepend(alertHtml);
                    
                    // Scroll al mensaje
                    $('html, body').animate({
                        scrollTop: $('#ps-copia-content').offset().top - 50
                    }, 500);
                    
                } else {
                    var errorMsg = response && response.error ? response.error : 'Error desconocido durante la restauración';
                    alert('Error: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $('#restore-progress-alert').remove();
                
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Verifica si la restauración se completó correctamente.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                
                alert('Error de comunicación: ' + errorMessage);
                console.error('Error AJAX:', {xhr: xhr, status: status, error: error});
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-magic"></i> Restaurar Completo');
                selectedBackupForRestore = null;
            }
        });
    });

    // Manejar botones de restaurar solo base de datos
    $(document).on('click', '.restore-database-only-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        
        if (!confirm('¿Estás seguro de que quieres restaurar SOLO LA BASE DE DATOS desde el backup "' + backupName + '"?\n\n' +
                    'ADVERTENCIA: Esta acción sobrescribirá la base de datos actual y no se puede deshacer.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Restaurando BD...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 300000, // 5 minutos de timeout
            data: {
                action: 'restore_database_only',
                backup_name: backupName,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    alert('¡Éxito! ' + response.message);
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Verifica si la restauración se completó.';
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-database"></i> Solo BD');
            }
        });
    });

    // Manejar botones de restaurar solo archivos
    $(document).on('click', '.restore-files-only-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        
        if (!confirm('¿Estás seguro de que quieres restaurar SOLO LOS ARCHIVOS desde el backup "' + backupName + '"?\n\n' +
                    'ADVERTENCIA: Esta acción sobrescribirá los archivos actuales y no se puede deshacer.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Restaurando...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 600000, // 10 minutos de timeout para archivos
            data: {
                action: 'restore_files_only',
                backup_name: backupName,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    alert('¡Éxito! ' + response.message);
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Verifica si la restauración se completó.';
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-folder-open"></i> Solo Archivos');
            }
        });
    });

    // Manejar botones de eliminar backup
    $(document).on('click', '.delete-backup-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        
        if (!confirm('¿Estás seguro de que quieres ELIMINAR PERMANENTEMENTE el backup "' + backupName + '"?\n\n' +
                    'ADVERTENCIA: Esta acción no se puede deshacer.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Eliminando...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_backup',
                backup_name: backupName,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    alert('¡Éxito! ' + response.message);
                    loadBackupsList(); // Recargar la lista
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                    $btn.prop('disabled', false).html('<i class="icon-trash"></i> Eliminar');
                }
            },
            error: function(xhr, status, error) {
                alert('Error de comunicación con el servidor');
                $btn.prop('disabled', false).html('<i class="icon-trash"></i> Eliminar');
            }
        });
    });

    // Manejar botones de restaurar individuales (compatibilidad legacy)
    $(document).on('click', '.restore-backup-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        var backupType = $btn.data('backup-type');
        
        var typeLabel = backupType === 'database' ? 'la base de datos' : 'los archivos';
        
        if (!confirm('¿Estás seguro de que quieres restaurar ' + typeLabel + ' desde el backup "' + backupName + '"?\n\n' +
                    'ADVERTENCIA: Esta acción sobrescribirá ' + typeLabel + ' actual(es) y no se puede deshacer.')) {
            return;
        }

        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Restaurando...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 300000, // 5 minutos de timeout
            data: {
                action: 'restore_backup',
                backup_name: backupName,
                backup_type: backupType,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                console.log('Respuesta del servidor:', response);
                
                if (response && response.success) {
                    // Mostrar mensaje de éxito
                    var alertHtml = '<div class="alert alert-success alert-dismissible" role="alert">';
                    alertHtml += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    alertHtml += '<span aria-hidden="true">&times;</span></button>';
                    alertHtml += '<i class="icon-check"></i> <strong>¡Éxito!</strong> ' + response.message;
                    alertHtml += '</div>';
                    
                    // Insertar mensaje al principio del contenido
                    $('#ps-copia-content').prepend(alertHtml);
                    
                    // Auto-cerrar después de 10 segundos
                    setTimeout(function() {
                        $('.alert-success').fadeOut();
                    }, 10000);
                    
                    // Scroll al mensaje
                    $('html, body').animate({
                        scrollTop: $('#ps-copia-content').offset().top - 50
                    }, 500);
                    
                } else {
                    var errorMsg = response && response.error ? response.error : 'Error desconocido durante la restauración';
                    alert('Error: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Verifica si la restauración se completó.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                
                alert('Error de comunicación: ' + errorMessage);
                console.error('Error AJAX:', {xhr: xhr, status: status, error: error});
            },
            complete: function() {
                var originalIcon = backupType === 'database' ? 'icon-database' : 'icon-folder-open';
                $btn.prop('disabled', false).html('<i class="icon-upload"></i> Restaurar');
            }
        });
    });

    // Función auxiliar para formatear bytes
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    // Función para verificar si es un archivo grande
    function isLargeFile(file) {
        return file.size > 100 * 1024 * 1024; // 100MB
    }

    // Manejar botones de exportar backup
    $(document).on('click', '.export-backup-btn', function() {
        var $btn = $(this);
        var backupName = $btn.data('backup-name');
        
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Exportando...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 1800000, // 30 minutos para exportaciones grandes
            data: {
                action: 'export_backup',
                backup_name: backupName,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    // Crear enlace de descarga automática
                    var link = document.createElement('a');
                    link.href = response.data.download_url;
                    link.download = response.data.filename;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    alert('¡Éxito! Se ha iniciado la descarga del backup exportado (' + response.data.size_formatted + ')');
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Para sitios muy grandes, verifica si la exportación se completó más tarde.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-download"></i> Exportar');
            }
        });
    });

    // Manejar confirmación de subir backup
    $('#confirmUploadBtn').on('click', function() {
        var fileInput = $('#backup_file')[0];
        
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Por favor selecciona un archivo ZIP de backup');
            return;
        }
        
        var file = fileInput.files[0];
        
        // Verificar extensión
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert('El archivo debe ser un ZIP válido');
            return;
        }
        
        // Advertencia para archivos grandes
        if (isLargeFile(file)) {
            var sizeFormatted = formatBytes(file.size);
            var confirmMessage = 'El archivo es grande (' + sizeFormatted + '). La importación puede tardar mucho tiempo.\n\n';
            confirmMessage += 'Para sitios grandes se recomienda:\n';
            confirmMessage += '• Tener paciencia durante el proceso\n';
            confirmMessage += '• No cerrar la página\n';
            confirmMessage += '• Verificar que no hay límites de tiempo en el servidor\n\n';
            confirmMessage += '¿Continuar con la importación?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Subiendo...');
        $('#upload-progress').show();
        
        var formData = new FormData();
        formData.append('backup_file', file);
        formData.append('action', 'import_backup');
        formData.append('ajax', 'true');
{/literal}
        formData.append('token', "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}");
{literal}

        // Timeout dinámico basado en el tamaño del archivo
        var dynamicTimeout = isLargeFile(file) ? 3600000 : 600000; // 60min para grandes, 10min para normales

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            timeout: dynamicTimeout,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = evt.loaded / evt.total * 100;
                        $('#upload-progress .progress-bar').css('width', percentComplete + '%');
                        $('#upload-progress .progress-bar span').text('Subiendo... ' + Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            beforeSend: function() {
                // Mostrar información adicional para archivos grandes
                if (isLargeFile(file)) {
                    $('#upload-progress .progress-bar span').text('Subiendo archivo grande... 0%');
                    
                    // Mostrar mensaje informativo
                    var infoHtml = '<div class="alert alert-info" id="large-file-info">';
                    infoHtml += '<i class="icon-info-circle"></i> ';
                    infoHtml += '<strong>Archivo grande detectado:</strong> Se está usando procesamiento optimizado para evitar problemas de memoria y timeout.';
                    infoHtml += '</div>';
                    $('#ps-copia-content').prepend(infoHtml);
                }
            },
            success: function(response) {
                if (response && response.success) {
                    $('#uploadBackupModal').modal('hide');
                    
                    // Limpiar mensaje informativo
                    $('#large-file-info').remove();
                    
                    // Mostrar mensaje de éxito
                    var alertHtml = '<div class="alert alert-success alert-dismissible" role="alert">';
                    alertHtml += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    alertHtml += '<span aria-hidden="true">&times;</span></button>';
                    alertHtml += '<i class="icon-check"></i> <strong>¡Éxito!</strong> ' + response.message;
                    alertHtml += '</div>';
                    
                    $('#ps-copia-content').prepend(alertHtml);
                    
                    // Recargar lista de backups
                    loadBackupsList();
                    
                    // Limpiar formulario
                    $('#uploadBackupForm')[0].reset();
                    
                    // Scroll al mensaje
                    $('html, body').animate({
                        scrollTop: $('#ps-copia-content').offset().top - 50
                    }, 500);
                    
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                // Limpiar mensaje informativo
                $('#large-file-info').remove();
                
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    if (isLargeFile(file)) {
                        errorMessage = 'La operación tardó demasiado tiempo. Para archivos muy grandes:\n\n';
                        errorMessage += '• Verifica si la importación se completó revisando la lista de backups\n';
                        errorMessage += '• Considera dividir el backup en partes más pequeñas\n';
                        errorMessage += '• Verifica la configuración PHP del servidor (memory_limit, max_execution_time)';
                    } else {
                        errorMessage = 'La operación tardó demasiado tiempo. Intenta nuevamente.';
                    }
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-upload"></i> Subir Backup');
                $('#upload-progress').hide();
                $('#upload-progress .progress-bar').css('width', '0%');
                $('#upload-progress .progress-bar span').text('Subiendo...');
            }
        });
    });

    // Limpiar formulario al cerrar modal
    $('#uploadBackupModal').on('hidden.bs.modal', function() {
        $('#uploadBackupForm')[0].reset();
        $('#upload-progress').hide();
        $('#upload-progress .progress-bar').css('width', '0%');
        $('#confirmUploadBtn').prop('disabled', false).html('<i class="icon-upload"></i> Subir Backup');
    });

    // Manejar botón "Subir con Migración"
    $('#uploadWithMigrationBtn').on('click', function() {
        $('#uploadBackupModal').modal('hide');
        $('#uploadMigrationModal').modal('show');
    });

    // Manejar botón directo "Subir Backup con Migración"
    $('#uploadWithMigrationDirectBtn').on('click', function() {
        $('#uploadMigrationModal').modal('show');
    });

    // Configuración automática simplificada - todo se maneja internamente

    // Manejar confirmación de importación con migración
    $('#confirmMigrationBtn').on('click', function() {
        var fileInput = $('#migration_backup_file')[0];
        
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Por favor selecciona un archivo ZIP de backup');
            return;
        }
        
        var file = fileInput.files[0];
        
        // Verificar extensión
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert('El archivo debe ser un ZIP válido');
            return;
        }

        // La configuración es automática - no hay validación manual necesaria
        console.log('Iniciando migración automática con auto-detección completa');
        
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Migrando...');
        $('#migration-progress').show();
        
        var formData = new FormData($('#uploadMigrationForm')[0]);
        formData.append('action', 'import_backup_with_migration');
        formData.append('ajax', 'true');
{/literal}
        formData.append('token', "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}");
{literal}

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 1200000, // 20 minutos de timeout para migraciones
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = evt.loaded / evt.total * 100;
                        $('#migration-progress .progress-bar').css('width', percentComplete + '%');
                        $('#migration-progress .progress-bar span').text('Subiendo... ' + Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response && response.success) {
                    $('#uploadMigrationModal').modal('hide');
                    
                    // Mostrar mensaje de éxito
                    var alertHtml = '<div class="alert alert-success alert-dismissible" role="alert">';
                    alertHtml += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    alertHtml += '<span aria-hidden="true">&times;</span></button>';
                    alertHtml += '<i class="icon-check"></i> <strong>¡Éxito!</strong> ' + response.message;
                    alertHtml += '</div>';
                    
                    $('#ps-copia-content').prepend(alertHtml);
                    
                    // Recargar lista de backups
                    loadBackupsList();
                    
                    // Limpiar formulario
                    $('#uploadMigrationForm')[0].reset();
                    
                    // Limpiar formulario - la configuración es automática
                    
                    // Scroll al mensaje
                    $('html, body').animate({
                        scrollTop: $('#ps-copia-content').offset().top - 50
                    }, 500);
                    
                    // Mostrar advertencia de recarga
                    setTimeout(function() {
                        if (confirm('¡La migración se completó exitosamente! Se recomienda recargar la página para asegurar que todos los cambios se reflejen correctamente. ¿Deseas recargar ahora?')) {
                            window.location.reload();
                        }
                    }, 3000);
                    
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido durante la migración'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Las migraciones pueden tardar varios minutos.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-magic"></i> Migrar Backup');
                $('#migration-progress').hide();
                $('#migration-progress .progress-bar').css('width', '0%');
                $('#migration-progress .progress-bar span').text('Procesando migración...');
            }
        });
    });

    // Limpiar formulario al cerrar modal de migración
    $('#uploadMigrationModal').on('hidden.bs.modal', function() {
        $('#uploadMigrationForm')[0].reset();
        $('#migration-progress').hide();
        $('#migration-progress .progress-bar').css('width', '0%');
        $('#confirmMigrationBtn').prop('disabled', false).html('<i class="icon-magic"></i> Migrar Backup');
    });
});
{/literal}
</script>

<!-- Modal para uploads del servidor -->
<div class="modal fade" id="serverUploadsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    <i class="icon-hdd text-info"></i>
                    Importar desde Servidor
                </h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong><i class="icon-info-circle"></i> ¿Cómo funciona?</strong><br>
                    Esta función escanea la carpeta del directorio admin en busca de archivos ZIP subidos directamente al servidor mediante FTP/SFTP.
                </div>
                
                <div class="well well-sm">
                    <strong><i class="icon-lightbulb-o"></i> Para archivos grandes:</strong>
                    <ol style="margin: 5px 0;">
                        <li>Sube tu archivo ZIP mediante FTP/SFTP a la carpeta <code>/[admin_folder]/ps_copia_uploads/</code></li>
                        <li>Usa el botón "Escanear" para detectar archivos</li>
                        <li>Selecciona e importa el archivo deseado</li>
                    </ol>
                    <div class="alert alert-warning" style="margin-top: 10px; margin-bottom: 0;">
                        <small><strong><i class="icon-shield"></i> Seguridad mejorada:</strong> Los uploads ahora se almacenan en el directorio admin (ruta única por instalación) para mayor protección.</small>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <button id="scanServerUploadsBtn" class="btn btn-info btn-block">
                            <i class="icon-search"></i> Escanear Archivos
                        </button>
                    </div>
                    <div class="col-md-6">
                        <p class="text-muted"><small>Ruta: <span id="uploads-path-display">-</span></small></p>
                    </div>
                </div>

                <hr>

                <div id="server-uploads-list">
                    <p class="text-center text-muted">Haz clic en "Escanear Archivos" para ver los uploads disponibles.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

{literal}
<script>
$(document).ready(function() {
    // Manejar botón de uploads del servidor
    $('#serverUploadsBtn').on('click', function() {
        $('#serverUploadsModal').modal('show');
    });

    // Manejar escaneo de uploads del servidor
    $('#scanServerUploadsBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Escaneando...');
        
        // Mostrar mensaje de progreso con opción de cancelar
        var progressHtml = '<div class="alert alert-info">';
        progressHtml += '<i class="icon-info-circle"></i> <strong>Escaneando directorio...</strong> ';
        progressHtml += 'Esto puede tardar unos segundos para archivos grandes.<br><br>';
        progressHtml += '<button id="cancelScanBtn" class="btn btn-warning btn-xs">';
        progressHtml += '<i class="icon-times"></i> Cancelar Escaneo';
        progressHtml += '</button>';
        progressHtml += '</div>';
        $('#server-uploads-list').html(progressHtml);
        
        // Timeout progresivo - empezar con 30s, luego extender si es necesario
        var startTime = Date.now();
        var initialTimeout = 30000; // 30 segundos inicial
        var extendedTimeout = 120000; // 2 minutos extendido
        
        // Manejar cancelación del escaneo
        $('#cancelScanBtn').on('click', function() {
            if (scanRequest && scanRequest.readyState !== 4) {
                scanRequest.abort();
                $('#server-uploads-list').html('<div class="alert alert-warning"><i class="icon-hand-paper-o"></i> <strong>Escaneo cancelado</strong> por el usuario.</div>');
                $btn.prop('disabled', false).html('<i class="icon-search"></i> Escanear Archivos');
            }
        });
        
        var scanRequest = $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: initialTimeout,
            data: {
                action: 'scan_server_uploads',
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                var elapsed = (Date.now() - startTime) / 1000;
                console.log('Scan completed in', elapsed + 's');
                
                if (response && response.success) {
                    $('#uploads-path-display').text(response.data.uploads_path);
                    
                    // Mostrar información adicional si está disponible
                    if (response.data.scan_duration) {
                        console.log('Server scan duration:', response.data.scan_duration + 's');
                    }
                    
                    displayServerUploads(response.data.zip_files);
                    
                    // Mostrar mensaje informativo si no hay archivos
                    if (response.data.count === 0) {
                        var infoHtml = '<div class="alert alert-info">';
                        infoHtml += '<i class="icon-info-circle"></i> ';
                        infoHtml += '<strong>No se encontraron archivos ZIP.</strong><br>';
                        infoHtml += response.data.message || 'Sube archivos ZIP mediante FTP/SFTP al directorio mostrado arriba.';
                        infoHtml += '</div>';
                        $('#server-uploads-list').append(infoHtml);
                    }
                    
                } else {
                    var errorMsg = response.error || 'Error desconocido durante el escaneo';
                    $('#server-uploads-list').html('<div class="alert alert-warning"><i class="icon-warning-sign"></i> <strong>Error:</strong> ' + errorMsg + '</div>');
                }
            },
            error: function(xhr, status, error) {
                var elapsed = (Date.now() - startTime) / 1000;
                console.log('Scan failed after', elapsed + 's', 'Status:', status);
                
                var errorMessage = 'Error de comunicación con el servidor';
                
                // Manejar cancelación por parte del usuario
                if (status === 'abort') {
                    // Ya manejado por el botón cancelar, no hacer nada más
                    return;
                }
                
                if (status === 'timeout') {
                    // Si fue timeout en el primer intento, ofrecer reintentar con más tiempo
                    if (elapsed < 35) { // Fue el timeout inicial
                        errorMessage = 'El escaneo tardó más de lo esperado. Esto puede ocurrir con archivos muy grandes o corruptos.';
                        
                        var retryHtml = '<div class="alert alert-warning">';
                        retryHtml += '<i class="icon-clock-o"></i> <strong>Timeout de escaneo:</strong> ' + errorMessage + '<br><br>';
                        retryHtml += '<button id="retryScanBtn" class="btn btn-warning btn-sm">';
                        retryHtml += '<i class="icon-refresh"></i> Reintentar con más tiempo (2 min)';
                        retryHtml += '</button>';
                        retryHtml += '</div>';
                        
                        $('#server-uploads-list').html(retryHtml);
                        
                        // Manejar reintento con timeout extendido
                        $('#retryScanBtn').on('click', function() {
                            $(this).prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Reintentando...');
                            $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Escaneando (extendido)...');
                            
                            $('#server-uploads-list').html('<div class="alert alert-info"><i class="icon-info-circle"></i> <strong>Reintentando escaneo con timeout extendido...</strong> Por favor espera hasta 2 minutos.</div>');
                            
                            $.ajax({
                                url: ajaxUrl,
                                type: 'POST',
                                dataType: 'json',
                                timeout: extendedTimeout,
                                data: {
                                    action: 'scan_server_uploads',
                                    ajax: true,
{/literal}
                                    token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
                                },
                                success: function(response) {
                                    if (response && response.success) {
                                        displayServerUploads(response.data.zip_files);
                                        $('#uploads-path-display').text(response.data.uploads_path);
                                    } else {
                                        $('#server-uploads-list').html('<div class="alert alert-danger">Error en reintento: ' + (response.error || 'Error desconocido') + '</div>');
                                    }
                                },
                                error: function(xhr2, status2, error2) {
                                    var finalError = 'Error persistente de comunicación';
                                    if (status2 === 'timeout') {
                                        finalError = 'El escaneo sigue tardando demasiado. Posibles causas:<br>';
                                        finalError += '• Archivos ZIP muy grandes o corruptos<br>';
                                        finalError += '• Problemas de rendimiento del servidor<br>';
                                        finalError += '• Límites de tiempo del servidor<br><br>';
                                        finalError += 'Recomendación: Verifica manualmente los archivos en el servidor.';
                                    }
                                    $('#server-uploads-list').html('<div class="alert alert-danger"><i class="icon-exclamation-triangle"></i> <strong>Error persistente:</strong> ' + finalError + '</div>');
                                },
                                complete: function() {
                                    $btn.prop('disabled', false).html('<i class="icon-search"></i> Escanear Archivos');
                                }
                            });
                        });
                        
                        return;
                    } else {
                        errorMessage = 'Timeout extendido alcanzado. El servidor puede estar sobrecargado o hay archivos problemáticos.';
                    }
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                
                $('#server-uploads-list').html('<div class="alert alert-danger"><i class="icon-exclamation-triangle"></i> <strong>Error:</strong> ' + errorMessage + '</div>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-search"></i> Escanear Archivos');
            }
        });
    });

    function displayServerUploads(uploads) {
        if (uploads.length === 0) {
            $('#server-uploads-list').html('<div class="alert alert-info"><i class="icon-info-circle"></i> No se encontraron archivos ZIP en el directorio de uploads.</div>');
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-striped">';
        html += '<thead><tr>';
        html += '<th><i class="icon-file"></i> Archivo</th>';
        html += '<th><i class="icon-calendar"></i> Modificado</th>';
        html += '<th><i class="icon-hdd"></i> Tamaño</th>';
        html += '<th><i class="icon-check"></i> Válido</th>';
        html += '<th><i class="icon-cogs"></i> Acciones</th>';
        html += '</tr></thead><tbody>';

        uploads.forEach(function(upload) {
            var validIcon = upload.is_valid_backup ? 
                '<i class="icon-check text-success"></i> Válido' : 
                '<i class="icon-remove text-danger"></i> No válido';
            
            var sizeClass = upload.is_large ? 'text-warning' : 'text-muted';
            var sizeIcon = upload.is_large ? '<i class="icon-warning-sign"></i> ' : '';
            
            html += '<tr>';
            html += '<td><i class="icon-file-archive-o"></i> ' + upload.filename + '</td>';
            html += '<td>' + upload.modified + '</td>';
            html += '<td class="' + sizeClass + '">' + sizeIcon + upload.size_formatted + '</td>';
            html += '<td>' + validIcon + '</td>';
            html += '<td>';
            
            if (upload.is_valid_backup) {
                html += '<div class="btn-group">';
                html += '<button class="btn btn-sm btn-success import-server-upload-btn" ';
                html += 'data-filename="' + upload.filename + '">';
                html += '<i class="icon-download"></i> Importar';
                html += '</button>';
                html += '<button class="btn btn-sm btn-danger delete-server-upload-btn" ';
                html += 'data-filename="' + upload.filename + '">';
                html += '<i class="icon-trash"></i> Eliminar';
                html += '</button>';
                html += '</div>';
            } else {
                html += '<button class="btn btn-sm btn-danger delete-server-upload-btn" ';
                html += 'data-filename="' + upload.filename + '">';
                html += '<i class="icon-trash"></i> Eliminar';
                html += '</button>';
            }
            
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        $('#server-uploads-list').html(html);
    }

    // Manejar importación desde servidor
    $(document).on('click', '.import-server-upload-btn', function() {
        var $btn = $(this);
        var filename = $btn.data('filename');
        
        if (!confirm('¿Importar el archivo "' + filename + '" como backup?\n\nEsto añadirá el backup a tu lista de backups disponibles.')) {
            return;
        }
        
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Importando...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 1800000, // 30 minutos para archivos grandes
            data: {
                action: 'import_from_server',
                filename: filename,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    $('#serverUploadsModal').modal('hide');
                    
                    // Mostrar mensaje de éxito
                    var alertHtml = '<div class="alert alert-success alert-dismissible" role="alert">';
                    alertHtml += '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    alertHtml += '<span aria-hidden="true">&times;</span></button>';
                    alertHtml += '<i class="icon-check"></i> <strong>¡Éxito!</strong> ' + response.message;
                    alertHtml += '</div>';
                    
                    $('#ps-copia-content').prepend(alertHtml);
                    
                    // Recargar lista de backups
                    loadBackupsList();
                    
                    // Scroll al mensaje
                    $('html, body').animate({
                        scrollTop: $('#ps-copia-content').offset().top - 50
                    }, 500);
                    
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (status === 'timeout') {
                    errorMessage = 'La operación tardó demasiado tiempo. Para archivos muy grandes, verifica si la importación se completó revisando la lista de backups.';
                } else if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                alert('Error: ' + errorMessage);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="icon-download"></i> Importar');
            }
        });
    });

    // Manejar eliminación de archivo del servidor
    $(document).on('click', '.delete-server-upload-btn', function() {
        var $btn = $(this);
        var filename = $btn.data('filename');
        
        if (!confirm('¿Eliminar el archivo "' + filename + '" del servidor?\n\nEsta acción no se puede deshacer.')) {
            return;
        }
        
        $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Eliminando...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_server_upload',
                filename: filename,
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                if (response && response.success) {
                    // Volver a escanear para actualizar la lista
                    $('#scanServerUploadsBtn').click();
                } else {
                    alert('Error: ' + (response.error || 'Error desconocido'));
                    $btn.prop('disabled', false).html('<i class="icon-trash"></i> Eliminar');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error de comunicación con el servidor';
                if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.error || errorMessage;
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 200);
                    }
                }
                alert('Error: ' + errorMessage);
                $btn.prop('disabled', false).html('<i class="icon-trash"></i> Eliminar');
            }
        });
    });
});
</script>
{/literal} 