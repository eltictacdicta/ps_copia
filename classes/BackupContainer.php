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

namespace PrestaShop\Module\PsCopia;

use Exception;
use PrestaShop\Module\PsCopia\UpgradeTools\Translator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class responsible of the management of backup and restore operations
 * Simplified version without upgrade functionality
 */
class BackupContainer
{
    const WORKSPACE_PATH = 'workspace';
    const BACKUP_PATH = 'backup';
    const LOGS_PATH = 'logs';
    const PS_ROOT_PATH = 'ps_root';
    const PS_ADMIN_PATH = 'ps_admin';

    /** @var Filesystem */
    private $fileSystem;

    /** @var Translator */
    private $translator;

    /**
     * @var string Absolute path to ps root folder of PS
     */
    private $psRootDir;

    /**
     * @var string Absolute path to the admin folder
     */
    private $adminDir;

    /**
     * @var string Path to the backup working directory
     */
    private $backupWorkDir;

    public function __construct(string $psRootDir, string $adminDir, string $moduleSubDir = 'ps_copia')
    {
        $this->psRootDir = $psRootDir;
        $this->adminDir = $adminDir;
        $this->backupWorkDir = $adminDir . DIRECTORY_SEPARATOR . $moduleSubDir;
    }

    /**
     * Get various paths used by the module
     */
    public function getProperty(string $property): ?string
    {
        switch ($property) {
            case self::PS_ROOT_PATH:
                return $this->psRootDir;
            case self::PS_ADMIN_PATH:
                return $this->adminDir;
            case self::WORKSPACE_PATH:
                return $this->backupWorkDir;
            case self::BACKUP_PATH:
                return $this->backupWorkDir . DIRECTORY_SEPARATOR . 'backup';
            case self::LOGS_PATH:
                return $this->backupWorkDir . DIRECTORY_SEPARATOR . 'logs';
            default:
                return '';
        }
    }

    /**
     * Get filesystem instance
     */
    public function getFileSystem(): Filesystem
    {
        if (null === $this->fileSystem) {
            $this->fileSystem = new Filesystem();
        }

        return $this->fileSystem;
    }

    /**
     * Get translator instance
     */
    public function getTranslator(): Translator
    {
        if (null === $this->translator) {
            $this->translator = new Translator(
                $this->psRootDir . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'ps_copia' . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR,
                \Context::getContext()->language->iso_code
            );
        }

        return $this->translator;
    }

    /**
     * Get database instance
     */
    public function getDb(): \Db
    {
        return \Db::getInstance();
    }

    /**
     * Get a unique filename for the backup.
     *
     * @param bool $isDatabase
     *
     * @return string
     */
    public function getBackupFilename(bool $isDatabase = true): string
    {
        $prefix = $isDatabase ? 'db_backup_' : 'files_backup_';
        $timestamp = date('Y-m-d_H-i-s');
        $hash = substr(md5(uniqid()), 0, 8);
        $extension = $isDatabase ? '.sql.gz' : '.zip';

        return $this->getProperty(self::BACKUP_PATH) . DIRECTORY_SEPARATOR . $prefix . $timestamp . '_' . $hash . $extension;
    }

    /**
     * Create necessary directories for backup operations
     */
    public function initDirectories(): void
    {
        $directories = [
            $this->getProperty(self::WORKSPACE_PATH),
            $this->getProperty(self::BACKUP_PATH),
            $this->getProperty(self::LOGS_PATH),
        ];

        foreach ($directories as $directory) {
            if (!$this->getFileSystem()->exists($directory)) {
                $this->getFileSystem()->mkdir($directory, 0755);
            }
        }
    }
} 