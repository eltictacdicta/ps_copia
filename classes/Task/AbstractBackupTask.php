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

namespace PrestaShop\Module\PsCopia\Task;

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\Logger\BackupLogger;
use PrestaShop\Module\PsCopia\UpgradeTools\Translator;
use Exception;

/**
 * Abstract base class for backup tasks
 * Inspired by autoupgrade's task system but simplified for backup operations
 */
abstract class AbstractBackupTask
{
    /** @var BackupContainer */
    protected $container;

    /** @var BackupLogger */
    protected $logger;

    /** @var Translator */
    protected $translator;

    /** @var bool */
    protected $stepDone = false;

    /** @var string */
    protected $status = '';

    /** @var string */
    protected $next = '';

    /** @var bool */
    protected $errorFlag = false;

    public function __construct(BackupContainer $container)
    {
        $this->container = $container;
        $this->logger = new BackupLogger($container, true);
        $this->translator = $container->getTranslator();
    }

    /**
     * Initialize task (called before run)
     */
    public function init(): void
    {
        // Override in child classes if needed
    }

    /**
     * Execute the task
     *
     * @return int Exit code (0 = success, 1 = error)
     * @throws Exception
     */
    abstract public function run(): int;

    /**
     * Check if step is done
     *
     * @return bool
     */
    public function isStepDone(): bool
    {
        return $this->stepDone;
    }

    /**
     * Get current status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get next task name
     *
     * @return string
     */
    public function getNext(): string
    {
        return $this->next;
    }

    /**
     * Check if error flag is set
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->errorFlag;
    }

    /**
     * Set error flag
     */
    protected function setErrorFlag(): void
    {
        $this->errorFlag = true;
    }

    /**
     * Get task type
     *
     * @return string
     */
    public function getTaskType(): string
    {
        return 'backup';
    }

    /**
     * Get current container
     *
     * @return BackupContainer
     */
    protected function getContainer(): BackupContainer
    {
        return $this->container;
    }

    /**
     * Get logger instance
     *
     * @return BackupLogger
     */
    protected function getLogger(): BackupLogger
    {
        return $this->logger;
    }

    /**
     * Get translator instance
     *
     * @return Translator
     */
    protected function getTranslator(): Translator
    {
        return $this->translator;
    }

    /**
     * Check if remaining time is enough to continue
     * Similar to autoupgrade's time management
     *
     * @param int $elapsedTime
     * @param int $maxTime
     * @return bool
     */
    protected function isRemainingTimeEnough(int $elapsedTime, int $maxTime = 25): bool
    {
        return $elapsedTime < $maxTime;
    }

    /**
     * Format time for logging
     *
     * @param int $seconds
     * @return string
     */
    protected function formatTime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        return $minutes . 'm ' . $remainingSeconds . 's';
    }

    /**
     * Format bytes for logging
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
} 