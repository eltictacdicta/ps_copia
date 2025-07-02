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

use PrestaShop\Module\PsCopia\BackupContainer;
use PrestaShop\Module\PsCopia\VersionUtils;

class AdminPsCopiaController extends ModuleAdminController
{
    /** @var Ps_copia */
    public $module;
    public $multishop_context_group = false;
    /** @var bool */
    public $ajax = false;
    /** @var bool */
    public $standalone = true;

    /**
     * @var BackupContainer
     */
    private $backupContainer;

    /**
     * @var Db
     */
    public $db;

    /** @var string[] */
    public $_errors = [];
    /** @var bool */
    private $isActualPHPVersionCompatible = true;

    public function viewAccess($disable = false)
    {
        if ($this->ajax) {
            return true;
        } else {
            // Allow only super admin
            global $cookie;
            if ($cookie->profile == 1) {
                return true;
            }
        }

        return false;
    }

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        
        // Load required classes
        $this->loadRequiredClasses();

        // Check PHP version compatibility
        if (!VersionUtils::isActualPHPVersionCompatible()) {
            $this->isActualPHPVersionCompatible = false;
            return;
        }

        $this->init();
        $this->db = Db::getInstance();
    }

    /**
     * Load required classes manually to ensure they are available
     *
     * @return void
     */
    private function loadRequiredClasses(): void
    {
        // Try autoloader first
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        // Manually load critical classes if not available
        $classesToLoad = [
            'PrestaShop\Module\PsCopia\VersionUtils' => '/../../classes/VersionUtils.php',
            'PrestaShop\Module\PsCopia\BackupContainer' => '/../../classes/BackupContainer.php',
        ];

        foreach ($classesToLoad as $className => $filePath) {
            if (!class_exists($className)) {
                $fullPath = __DIR__ . $filePath;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }
        }
    }

    /**
     * Initialize the backup container and necessary paths
     *
     * @return void
     */
    public function init()
    {
        if (!$this->isActualPHPVersionCompatible) {
            parent::init();
            return;
        }

        if (!$this->ajax) {
            parent::init();
        }

        // Check if user is logged in
        if (!$this->context->employee->id) {
            return;
        }

        // Initialize backup container
        if (class_exists('PrestaShop\Module\PsCopia\BackupContainer')) {
            $this->backupContainer = new BackupContainer(_PS_ROOT_DIR_, _PS_ADMIN_DIR_, 'ps_copia');
            $this->backupContainer->initDirectories();
        }
    }

    public function postProcess()
    {
        if (!$this->isActualPHPVersionCompatible) {
            return true;
        }

        parent::postProcess();
        return true;
    }

    /**
     * @return string
     */
    public function initContent()
    {
        if (!$this->isActualPHPVersionCompatible) {
            $message = sprintf(
                $this->trans('The module %s requires PHP %s to work properly. Please upgrade your server configuration.'),
                $this->module->displayName,
                VersionUtils::getHumanReadableVersionOf(VersionUtils::MODULE_COMPATIBLE_PHP_VERSION)
            );
            
            $this->displayWarning($message);
            return parent::initContent();
        }

        // Assign variables for the template
        $this->context->smarty->assign([
            'module_dir' => $this->module->getPathUri(),
            'module_version' => $this->module->version,
            'admin_dir' => basename(_PS_ADMIN_DIR_),
            'token' => Tools::getAdminTokenLite('AdminPsCopiaAjax'),
        ]);

        $this->content = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/backup_dashboard.tpl');
        
        return parent::initContent();
    }

    /**
     * Get backup container instance
     */
    public function getBackupContainer(): ?BackupContainer
    {
        return $this->backupContainer;
    }
}
