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
                    <p>Este módulo te ayuda a crear copias de seguridad y restaurar tu tienda PrestaShop. Con solo unos clics, puedes crear y restaurar copias de seguridad con confianza.</p>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="icon-download"></i>
                            Crear Copia de Seguridad
                        </h3>
                    </div>
                    <div class="panel-body text-center">
                        <p>Crea una copia de seguridad completa de tu tienda incluyendo archivos y base de datos.</p>
                        <button id="createBackupBtn" class="btn btn-lg btn-primary">
                            <i class="icon-download"></i>
                            Crear Copia de Seguridad
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="icon-upload"></i>
                            Restaurar Copia de Seguridad
                        </h3>
                    </div>
                    <div class="panel-body text-center">
                        <p>Restaura tu tienda desde una copia de seguridad previamente creada.</p>
                        <button id="restoreBackupBtn" class="btn btn-lg btn-warning">
                            <i class="icon-upload"></i>
                            Restaurar desde Copia
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
                            Archivos de Backup Existentes
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
</style>

<script>
{literal}
$(document).ready(function() {
{/literal}
    var ajaxUrl = "{$link->getAdminLink('AdminPsCopiaAjax')|escape:'html':'UTF-8'}";
{literal}
    $('#createBackupBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('i').removeClass('icon-download').addClass('icon-spinner icon-spin');
        
        $('#backup-progress').show();
        $('#backup-feedback').empty();
        updateProgress(10, 'Iniciando copia de seguridad...');

        // Simular progreso mientras se ejecuta
        var progressInterval = setInterval(function() {
            var currentProgress = parseInt($('#backup-progress-bar').attr('aria-valuenow'));
            if (currentProgress < 90) {
                updateProgress(currentProgress + 5, 'Procesando...');
            }
        }, 1000);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 300000, // 5 minutos de timeout
            data: {
                action: 'create_backup',
                ajax: true,
{/literal}
                token: "{if isset($token)}{$token|escape:'html':'UTF-8'}{else}{Tools::getAdminTokenLite('AdminPsCopiaAjax')}{/if}"
{literal}
            },
            success: function(response) {
                clearInterval(progressInterval);
                console.log('Respuesta del servidor:', response);
                
                if (response && response.success) {
                    updateProgress(100, '¡Copia de seguridad creada con éxito!');
                    $('#backup-feedback').html('<div class="alert alert-success"><i class="icon-check"></i> <strong>Éxito:</strong> ' + response.message + '</div>');
                    // Recargar la lista de backups
                    setTimeout(function() {
                        loadBackupsList();
                    }, 1000);
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
                    displayBackupsList(response.backups);
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
            $('#backups-list').html('<div class="alert alert-info"><i class="icon-info-circle"></i> No hay archivos de backup disponibles.</div>');
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-striped">';
        html += '<thead><tr>';
        html += '<th><i class="icon-file"></i> Archivo</th>';
        html += '<th><i class="icon-calendar"></i> Fecha</th>';
        html += '<th><i class="icon-hdd"></i> Tamaño</th>';
        html += '<th><i class="icon-tag"></i> Tipo</th>';
        html += '<th><i class="icon-cogs"></i> Acciones</th>';
        html += '</tr></thead><tbody>';

        backups.forEach(function(backup) {
            var typeIcon = backup.type === 'database' ? 'icon-database' : 'icon-folder-open';
            var typeLabel = backup.type === 'database' ? 'Base de Datos' : 'Archivos';
            var typeClass = backup.type === 'database' ? 'label-info' : 'label-success';
            var buttonClass = backup.type === 'database' ? 'btn-info' : 'btn-success';
            
            html += '<tr>';
            html += '<td><i class="' + typeIcon + '"></i> ' + backup.name + '</td>';
            html += '<td>' + backup.date + '</td>';
            html += '<td>' + backup.size + '</td>';
            html += '<td><span class="label ' + typeClass + '">' + typeLabel + '</span></td>';
            html += '<td>';
            html += '<button class="btn btn-xs ' + buttonClass + ' restore-backup-btn" ';
            html += 'data-backup-name="' + backup.name + '" data-backup-type="' + backup.type + '">';
            html += '<i class="icon-upload"></i> Restaurar';
            html += '</button>';
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
        // Mostrar modal de selección o directamente ir a la lista
        $('html, body').animate({
            scrollTop: $("#backups-list").offset().top - 100
        }, 500);
        
        // Resaltar la sección de backups
        $('#backups-list').closest('.panel').addClass('panel-warning').removeClass('panel-default');
        setTimeout(function() {
            $('#backups-list').closest('.panel').removeClass('panel-warning').addClass('panel-default');
        }, 3000);
    });

    // Manejar botones de restaurar individuales
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