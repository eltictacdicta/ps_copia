<?php
/**
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
 */

namespace PrestaShop\Module\PsCopia\Logger;

use PrestaShop\Module\PsCopia\BackupContainer;

/**
 * Simple logger for backup operations
 */
class BackupLogger
{
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /** @var BackupContainer */
    private $container;

    /** @var resource|null */
    private $logFile;

    /** @var string */
    private $logFilePath;

    /** @var bool */
    private $enableDebug;

    public function __construct(BackupContainer $container, bool $enableDebug = false)
    {
        $this->container = $container;
        $this->enableDebug = $enableDebug;
        $this->initLogFile();
    }

    /**
     * Initialize log file
     */
    private function initLogFile(): void
    {
        $logsPath = $this->container->getProperty(BackupContainer::LOGS_PATH);
        $this->logFilePath = $logsPath . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d') . '.log';
        
        // Ensure logs directory exists
        if (!is_dir($logsPath)) {
            mkdir($logsPath, 0755, true);
        }
    }

    /**
     * Log debug message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->enableDebug) {
            $this->log(self::LEVEL_DEBUG, $message, $context);
        }
    }

    /**
     * Log info message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
        
        // Also log to PHP error log for critical errors
        error_log("PS_Copia ERROR: " . $message);
    }

    /**
     * Core logging method
     *
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context);
        $logLine = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        // Write to file
        if (file_put_contents($this->logFilePath, $logLine, FILE_APPEND | LOCK_EX) === false) {
            // Fallback to PHP error log if file write fails
            error_log("PS_Copia Log Write Failed: " . $message);
        }
    }

    /**
     * Get recent log entries
     *
     * @param int $lines Number of lines to return
     * @return array<string>
     */
    public function getRecentLogs(int $lines = 50): array
    {
        if (!file_exists($this->logFilePath)) {
            return [];
        }

        $content = file_get_contents($this->logFilePath);
        if ($content === false) {
            return [];
        }

        $logLines = explode(PHP_EOL, trim($content));
        return array_slice($logLines, -$lines);
    }

    /**
     * Clear log file
     */
    public function clearLogs(): void
    {
        if (file_exists($this->logFilePath)) {
            file_put_contents($this->logFilePath, '');
        }
    }

    /**
     * Get log file size
     *
     * @return int
     */
    public function getLogFileSize(): int
    {
        if (!file_exists($this->logFilePath)) {
            return 0;
        }

        return filesize($this->logFilePath);
    }

    /**
     * Rotate log files (keep only last N days)
     *
     * @param int $daysToKeep
     */
    public function rotateLogs(int $daysToKeep = 7): void
    {
        $logsPath = $this->container->getProperty(BackupContainer::LOGS_PATH);
        
        if (!is_dir($logsPath)) {
            return;
        }

        $files = glob($logsPath . DIRECTORY_SEPARATOR . 'backup_*.log');
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
} 