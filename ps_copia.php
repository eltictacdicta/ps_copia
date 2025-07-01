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
class Ps_copia extends Module
{
    /**
     * @var int
     */
    public $multishop_context;

    /**
     * @var \PrestaShop\Module\PsCopia\BackupContainer
     */
    protected $container;

    public function __construct()
    {
        $this->name = 'ps_copia';
        $this->tab = 'administration';
        $this->author = 'PrestaShop';
        $this->version = '1.0.0';
        $this->need_instance = 1;
        $this->module_key = '926bc3e16738b7b834f37fc63d59dcf8';

        $this->bootstrap = true;
        parent::__construct();

        $this->multishop_context = Shop::CONTEXT_ALL;

        $this->displayName = 'Asistente de Copias de Seguridad';
        $this->description = 'El módulo Asistente de Copias de Seguridad te ayuda a crear copias de seguridad y restaurar tu tienda PrestaShop.';

        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    /**
     * following the Core documentation :
     * https://devdocs.prestashop-project.org/8/modules/creation/module-translation/new-system/#translating-your-module
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        // Load required classes directly
        if (!class_exists('PrestaShop\Module\PsCopia\Exceptions\UpgradeException')) {
            require_once _PS_MODULE_DIR_ . '/ps_copia/classes/Exceptions/UpgradeException.php';
        }
        if (!class_exists('PrestaShop\Module\PsCopia\VersionUtils')) {
            require_once _PS_MODULE_DIR_ . '/ps_copia/classes/VersionUtils.php';
        }
        if (!class_exists('PrestaShop\Module\PsCopia\UpgradeTools\Translator')) {
            require_once _PS_MODULE_DIR_ . '/ps_copia/classes/UpgradeTools/Translator.php';
        }
        
        if (!\PrestaShop\Module\PsCopia\VersionUtils::isActualPHPVersionCompatible()) {
            $this->_errors[] = $this->trans(
                'This module requires PHP %s to work properly. Please upgrade your server configuration.',
                [\PrestaShop\Module\PsCopia\VersionUtils::getHumanReadableVersionOf(\PrestaShop\Module\PsCopia\VersionUtils::MODULE_COMPATIBLE_PHP_VERSION)]
            );

            return false;
        }

        // If the "AdminPsCopia" tab does not exist yet, create it
        $moduleTabName = 'AdminPsCopia';
        if (!Tab::getIdFromClassName($moduleTabName)) {
            $tab = new Tab();
            $tab->class_name = $moduleTabName;
            $tab->icon = 'content_copy';
            $tab->module = 'ps_copia';

            // We use DEFAULT to add Upgrade tab as a standalone tab in the back office menu
            $tab->id_parent = (int) Tab::getIdFromClassName('CONFIGURE');

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = 'Asistente de Copias';
            }
            if (!$tab->save()) {
                return $this->_abortInstall($this->trans('Unable to create the %s tab', [$moduleTabName]));
            }
        }

        $ajaxTabName = 'AdminPsCopiaAjax';
        if (!Tab::getIdFromClassName($ajaxTabName)) {
            $ajaxTab = new Tab();
            $ajaxTab->class_name = $ajaxTabName;
            $ajaxTab->module = 'ps_copia';
            $ajaxTab->id_parent = -1;

            foreach (Language::getLanguages(false) as $lang) {
                $ajaxTab->name[(int) $lang['id_lang']] = 'Asistente de Copias';
            }
            if (!$ajaxTab->save()) {
                return $this->_abortInstall($this->trans('Unable to create the %s tab', [$ajaxTabName]));
            }
        }

        return parent::install() && $this->registerHook('displayBackOfficeHeader') && $this->registerHook('displayBackOfficeEmployeeMenu');
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Delete the module Back-office tab
        $id_tab = Tab::getIdFromClassName('AdminPsCopia');
        if ($id_tab) {
            $tab = new Tab((int) $id_tab);
            $tab->delete();
        }

        $id_ajax_tab = Tab::getIdFromClassName('AdminPsCopiaAjax');
        if ($id_ajax_tab) {
            $ajaxTab = new Tab((int) $id_ajax_tab);
            $ajaxTab->delete();
        }

        // Remove the 1-click upgrade working directory
        if (defined('_PS_ADMIN_DIR_')) {
            self::_removeDirectory(_PS_ADMIN_DIR_ . DIRECTORY_SEPARATOR . 'ps_copia');
        }

        return parent::uninstall();
    }

    /**
     * @return string
     */
    public function getContent()
    {
        // Redirigir siempre al controlador principal para la interfaz de usuario
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPsCopia'));
    }

    /**
     * Set installation errors and return false.
     *
     * @param string $error Installation abortion reason
     *
     * @return bool Always false
     */
    protected function _abortInstall($error)
    {
        $this->_errors[] = $error;

        return false;
    }

    /**
     * @param string $dir
     *
     * @return void
     */
    private static function _removeDirectory($dir)
    {
        if ($handle = @opendir($dir)) {
            while (false !== ($entry = @readdir($handle))) {
                if ($entry != '.' && $entry != '..') {
                    if (is_dir($dir . DIRECTORY_SEPARATOR . $entry) === true) {
                        self::_removeDirectory($dir . DIRECTORY_SEPARATOR . $entry);
                    } else {
                        @unlink($dir . DIRECTORY_SEPARATOR . $entry);
                    }
                }
            }

            @closedir($handle);
            @rmdir($dir);
        }
    }

    /**
     * Adapter for trans calls, existing only on PS 1.7.
     * Making them available for PS 1.6 as well.
     *
     * @param string $id
     * @param array<int|string, int|string> $parameters $parameters
     * @param string $domain
     * @param string $locale
     *
     * @return string
     */
    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        if (isset($this->container)) {
            return $this->container->getTranslator()->trans($id, $parameters);
        }
        
        // Fallback si no hay container disponible
        $translator = new \PrestaShop\Module\PsCopia\UpgradeTools\Translator(
            _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'ps_copia' . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR,
            \Context::getContext()->language->iso_code
        );

        return $translator->trans($id, $parameters);
    }

    /**
     * Hook called after the backoffice content is rendered.
     * For ps_copia module, we don't need update notifications.
     *
     * @return string
     */
    public function hookDisplayBackOfficeHeader()
    {
        // No necesitamos notificaciones de actualización en el módulo ps_copia
        // Solo manejamos copias de seguridad y restauración
        return '';
    }

    /**
     * Only available from PS8.
     *
     * @param array{links: \PrestaShop\PrestaShop\Core\Action\ActionsBarButtonsCollection} $params
     *
     * @return void
     */
    public function hookDisplayBackOfficeEmployeeMenu(array $params)
    {
        if (!$this->initAutoloaderIfCompliant()) {
            return;
        }

        // TODO: Crear la clase DisplayBackOfficeEmployeeMenu si es necesaria
        // (new \PrestaShop\Module\PsCopia\Hooks\DisplayBackOfficeEmployeeMenu($this->getBackupContainer(), $params, $this->context))
        //     ->run();
    }

    /**
     * @return bool
     */
    public function initAutoloaderIfCompliant()
    {
        if (!isset($this->container)) {
            if (file_exists(_PS_MODULE_DIR_ . '/ps_copia/vendor/autoload.php')) {
                require_once _PS_MODULE_DIR_ . '/ps_copia/vendor/autoload.php';
                $this->getBackupContainer();
            }
        }
        // During install, container is not set
        if (!isset($this->container)) {
            return false;
        }

        return true;
    }

    /**
     * @return \PrestaShop\Module\PsCopia\BackupContainer
     */
    public function getBackupContainer()
    {
        if (!isset($this->container)) {
            $this->container = new \PrestaShop\Module\PsCopia\BackupContainer(_PS_ROOT_DIR_, _PS_ADMIN_DIR_, 'ps_copia');
            $this->container->initDirectories();
        }

        return $this->container;
    }
}
