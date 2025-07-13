<?php
/**
 * Response Helper for AJAX responses
 * Centralizes response formatting and error handling
 */

namespace PrestaShop\Module\PsCopia\Services;

class ResponseHelper
{
    /**
     * Send AJAX success response
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    public static function ajaxSuccess(string $message, array $data = []): void
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Send AJAX error response
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    public static function ajaxError(string $message, array $data = []): void
    {
        $response = [
            'success' => false,
            'error' => $message,
            'data' => $data
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get upload error message
     *
     * @param int $errorCode
     * @return string
     */
    public static function getUploadError(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'El archivo es demasiado grande (excede upload_max_filesize)';
            case UPLOAD_ERR_FORM_SIZE:
                return 'El archivo es demasiado grande (excede MAX_FILE_SIZE)';
            case UPLOAD_ERR_PARTIAL:
                return 'El archivo se subió parcialmente';
            case UPLOAD_ERR_NO_FILE:
                return 'No se seleccionó ningún archivo';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Falta el directorio temporal';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Error al escribir el archivo en el disco';
            case UPLOAD_ERR_EXTENSION:
                return 'Una extensión de PHP detuvo la subida del archivo';
            default:
                return 'Error desconocido al subir el archivo: ' . $errorCode;
        }
    }

    /**
     * Get ZIP error message
     *
     * @param int $code
     * @return string
     */
    public static function getZipError(int $code): string
    {
        switch ($code) {
            case ZipArchive::ER_OK: return 'No error';
            case ZipArchive::ER_MULTIDISK: return 'Multi-disk zip archives not supported';
            case ZipArchive::ER_RENAME: return 'Renaming temporary file failed';
            case ZipArchive::ER_CLOSE: return 'Closing zip archive failed';
            case ZipArchive::ER_SEEK: return 'Seek error';
            case ZipArchive::ER_READ: return 'Read error';
            case ZipArchive::ER_WRITE: return 'Write error';
            case ZipArchive::ER_CRC: return 'CRC error';
            case ZipArchive::ER_ZIPCLOSED: return 'Containing zip archive was closed';
            case ZipArchive::ER_NOENT: return 'No such file';
            case ZipArchive::ER_EXISTS: return 'File already exists';
            case ZipArchive::ER_OPEN: return 'Can\'t open file';
            case ZipArchive::ER_TMPOPEN: return 'Failure to create temporary file';
            case ZipArchive::ER_ZLIB: return 'Zlib error';
            case ZipArchive::ER_MEMORY: return 'Memory allocation failure';
            case ZipArchive::ER_CHANGED: return 'Entry has been changed';
            case ZipArchive::ER_COMPNOTSUPP: return 'Compression method not supported';
            case ZipArchive::ER_EOF: return 'Premature EOF';
            case ZipArchive::ER_INVAL: return 'Invalid argument';
            case ZipArchive::ER_NOZIP: return 'Not a zip archive';
            case ZipArchive::ER_INTERNAL: return 'Internal error';
            case ZipArchive::ER_INCONS: return 'Zip archive inconsistent';
            case ZipArchive::ER_REMOVE: return 'Can\'t remove file';
            case ZipArchive::ER_DELETED: return 'Entry has been deleted';
            default: return "Unknown error code: $code";
        }
    }
} 