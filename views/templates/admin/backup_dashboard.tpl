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
                        <p>Restaura tu tienda completamente desde una copia de seguridad previamente creada.</p>
                        <button id="restoreBackupBtn" class="btn btn-lg btn-warning">
                            <i class="icon-upload"></i>
                            Seleccionar Backup
                        </button>
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
                    Confirmar Restauración
                </h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong><i class="icon-warning-sign"></i> ADVERTENCIA:</strong> Esta acción restaurará completamente tu tienda desde el backup seleccionado.
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
                    <i class="icon-upload"></i> Sí, Restaurar Ahora
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
                html += '<button class="btn btn-sm btn-restore-complete restore-complete-btn" ';
                html += 'data-backup-name="' + backup.name + '">';
                html += '<i class="icon-upload"></i> Restaurar Completo';
                html += '</button>';
            } else {
                // Individual restore buttons (legacy support)
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

    // Manejar botón principal de restaurar
    $('#restoreBackupBtn').on('click', function() {
        // Mostrar y resaltar la sección de backups
        $('html, body').animate({
            scrollTop: $("#backups-list").offset().top - 100
        }, 500);
        
        // Resaltar la sección de backups
        $('#backups-list').closest('.panel').addClass('panel-warning').removeClass('panel-default');
        setTimeout(function() {
            $('#backups-list').closest('.panel').removeClass('panel-warning').addClass('panel-default');
        }, 3000);
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
                $btn.prop('disabled', false).html('<i class="icon-upload"></i> Restaurar Completo');
                selectedBackupForRestore = null;
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
});
{/literal}
</script> 