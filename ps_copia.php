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

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_copia extends Module
{
    /**
     * @var int
     */
    public $multishop_context;

    /**
     * @var \PrestaShop\Module\PsCopia\BackupContainer|null
     */
    protected $container;

    public function __construct()
    {
        $this->name = 'ps_copia';
        $this->tab = 'administration';
        $this->author = 'PrestaShop';
        $this->version = '1.0.1';
        $this->need_instance = 1;
        $this->module_key = '926bc3e16738b7b834f37fc63d59dcf8';

        $this->bootstrap = true;
        parent::__construct();

        $this->multishop_context = Shop::CONTEXT_ALL;

        // Load autoloader before using module classes
        $this->initAutoloaderIfCompliant();

        $this->displayName = $this->trans('Backup Assistant', [], 'Modules.Pscopia.Admin');
        $this->description = $this->trans('The Backup Assistant module helps you create backups and restore your PrestaShop store. With just a few clicks, you can create and restore backups with confidence.', [], 'Modules.Pscopia.Admin');

        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    /**
     * Following the Core documentation for new translation system
     * https://devdocs.prestashop-project.org/8/modules/creation/module-translation/new-system/#translating-your-module
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install(): bool
    {
        if (!$this->checkRequirements()) {
            return false;
        }

        if (!$this->createTabs()) {
            return false;
        }

        return parent::install() 
            && $this->registerHook('displayBackOfficeHeader') 
            && $this->registerHook('displayBackOfficeEmployeeMenu');
    }

    /**
     * Check system requirements
     *
     * @return bool
     */
    private function checkRequirements(): bool
    {
        // Load required classes directly for installation
        $this->loadRequiredClasses();
        
        if (!\PrestaShop\Module\PsCopia\VersionUtils::isActualPHPVersionCompatible()) {
            $this->_errors[] = $this->trans(
                'This module requires PHP %s to work properly. Please upgrade your server configuration.',
                [\PrestaShop\Module\PsCopia\VersionUtils::getHumanReadableVersionOf(\PrestaShop\Module\PsCopia\VersionUtils::MODULE_COMPATIBLE_PHP_VERSION)],
                'Modules.Pscopia.Admin'
            );
            return false;
        }

        // Check required PHP extensions
        $requiredExtensions = ['zip', 'mysqli'];
        $missingExtensions = [];
        
        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }
        
        if (!empty($missingExtensions)) {
            $this->_errors[] = $this->trans(
                'The following PHP extensions are required: %s',
                [implode(', ', $missingExtensions)],
                'Modules.Pscopia.Admin'
            );
            return false;
        }

        return true;
    }

    /**
     * Load required classes for installation
     *
     * @return void
     */
    private function loadRequiredClasses(): void
    {
        $classesToLoad = [
            'PrestaShop\Module\PsCopia\Exceptions\UpgradeException' => '/classes/Exceptions/UpgradeException.php',
            'PrestaShop\Module\PsCopia\VersionUtils' => '/classes/VersionUtils.php',
            'PrestaShop\Module\PsCopia\UpgradeTools\Translator' => '/classes/UpgradeTools/Translator.php',
        ];

        foreach ($classesToLoad as $className => $filePath) {
            if (!class_exists($className)) {
                require_once _PS_MODULE_DIR_ . '/ps_copia' . $filePath;
            }
        }
    }

    /**
     * Create module tabs
     *
     * @return bool
     */
    private function createTabs(): bool
    {
        // Main tab
        $moduleTabName = 'AdminPsCopia';
        if (!Tab::getIdFromClassName($moduleTabName)) {
            $tab = new Tab();
            $tab->class_name = $moduleTabName;
            $tab->icon = 'content_copy';
            $tab->module = 'ps_copia';
            $tab->id_parent = (int) Tab::getIdFromClassName('CONFIGURE');

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = $this->trans('Backup Assistant', [], 'Modules.Pscopia.Admin');
            }
            
            if (!$tab->save()) {
                return $this->_abortInstall($this->trans('Unable to create the %s tab', [$moduleTabName], 'Modules.Pscopia.Admin'));
            }
        }

        // Ajax tab
        $ajaxTabName = 'AdminPsCopiaAjax';
        if (!Tab::getIdFromClassName($ajaxTabName)) {
            $ajaxTab = new Tab();
            $ajaxTab->class_name = $ajaxTabName;
            $ajaxTab->module = 'ps_copia';
            $ajaxTab->id_parent = -1;

            foreach (Language::getLanguages(false) as $lang) {
                $ajaxTab->name[(int) $lang['id_lang']] = $this->trans('Backup Assistant Ajax', [], 'Modules.Pscopia.Admin');
            }
            
            if (!$ajaxTab->save()) {
                return $this->_abortInstall($this->trans('Unable to create the %s tab', [$ajaxTabName], 'Modules.Pscopia.Admin'));
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall(): bool
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

        // Remove the working directory
        if (defined('_PS_ADMIN_DIR_')) {
            self::_removeDirectory(_PS_ADMIN_DIR_ . DIRECTORY_SEPARATOR . 'ps_copia');
        }

        return parent::uninstall();
    }

    /**
     * Redirect to module configuration
     *
     * @return void
     */
    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPsCopia'));
    }

    /**
     * Set installation errors and return false.
     *
     * @param string $error Installation abortion reason
     *
     * @return bool Always false
     */
    protected function _abortInstall(string $error): bool
    {
        $this->_errors[] = $error;
        return false;
    }

    /**
     * Remove directory recursively
     *
     * @param string $dir
     *
     * @return void
     */
    private static function _removeDirectory(string $dir): void
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
     * Translation adapter compatible with PS 1.7+
     *
     * @param string $id
     * @param array<int|string, int|string> $parameters
     * @param string|null $domain
     * @param string|null $locale
     *
     * @return string
     */
    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        // For PrestaShop 8+ use the Translator service if available
        if (class_exists('\Symfony\Component\Translation\TranslatorInterface') && $this->get && method_exists($this, 'get')) {
            try {
                $translator = $this->get('translator');
                if ($translator && method_exists($translator, 'trans')) {
                    return $translator->trans($id, $parameters, $domain, $locale);
                }
            } catch (Exception $e) {
                // Continue to fallback if service is not available
            }
        }

        // Try to use PrestaShop's legacy translation system
        if (class_exists('Translate') && method_exists('Translate', 'getModuleTranslation')) {
            try {
                $translated = Translate::getModuleTranslation('ps_copia', $id, 'ps_copia');
                if ($translated !== $id) {
                    return $this->applyTranslationParameters($translated, $parameters);
                }
            } catch (Exception $e) {
                // Continue to fallback
            }
        }

        // Fallback to our custom translator
        if (isset($this->container) && $this->container) {
            return $this->container->getTranslator()->trans($id, $parameters);
        }
        
        // Try to create translator with safe defaults
        try {
            $languageCode = 'en';
            if (class_exists('Context') && Context::getContext() && Context::getContext()->language) {
                $languageCode = Context::getContext()->language->iso_code;
            }
            
            $translationsPath = _PS_MODULE_DIR_ . '/ps_copia/translations/';
            if (class_exists('\PrestaShop\Module\PsCopia\UpgradeTools\Translator')) {
                $translator = new \PrestaShop\Module\PsCopia\UpgradeTools\Translator($translationsPath, $languageCode);
                return $translator->trans($id, $parameters);
            }
        } catch (Exception $e) {
            // Last resort: return the original string with parameters applied
        }
        
        // Last resort fallback: return original string with parameters
        return $this->applyTranslationParameters($id, $parameters);
    }

    /**
     * Apply parameters to a translation string
     *
     * @param string $text
     * @param array $parameters
     * @return string
     */
    private function applyTranslationParameters($text, array $parameters = [])
    {
        if (empty($parameters)) {
            return $text;
        }

        // Replace placeholders for non-numeric keys
        foreach ($parameters as $placeholder => $value) {
            if (is_int($placeholder)) {
                continue;
            }
            $text = str_replace($placeholder, $value, $text);
            unset($parameters[$placeholder]);
        }

        if (!count($parameters)) {
            return $text;
        }

        return call_user_func_array('sprintf', array_merge([$text], $parameters));
    }

    /**
     * Hook called after the backoffice content is rendered.
     * For ps_copia module, we don't need update notifications.
     *
     * @return string
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        // Add CSS/JS if needed for the backup module interface
        return '';
    }

    /**
     * Only available from PS8.
     * Add action button to employee menu if available
     *
     * @param array{links: \PrestaShop\PrestaShop\Core\Action\ActionsBarButtonsCollection} $params
     *
     * @return void
     */
    public function hookDisplayBackOfficeEmployeeMenu(array $params): void
    {
        if (!$this->initAutoloaderIfCompliant()) {
            return;
        }

        // Check if PS8 classes are available
        if (!class_exists('\PrestaShop\PrestaShop\Core\Action\ActionsBarButtonsCollection') 
            || !class_exists('\PrestaShop\PrestaShop\Core\Action\ActionsBarButton')
            || !($params['links'] instanceof \PrestaShop\PrestaShop\Core\Action\ActionsBarButtonsCollection)) {
            return;
        }

        $params['links']->add(
            new \PrestaShop\PrestaShop\Core\Action\ActionsBarButton(
                __CLASS__,
                [
                    'link' => $this->context->link->getAdminLink('AdminPsCopia'),
                    'icon' => 'content_copy',
                    'isExternalLink' => false,
                ],
                $this->trans('Backup Assistant', [], 'Modules.Pscopia.Admin')
            )
        );
    }

    /**
     * Initialize autoloader if system is compatible
     *
     * @return bool
     */
    public function initAutoloaderIfCompliant(): bool
    {
        if (!isset($this->container)) {
            $autoloadPath = _PS_MODULE_DIR_ . '/ps_copia/vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
                
                // Only initialize container if admin dir is available
                if (defined('_PS_ADMIN_DIR_') && defined('_PS_ROOT_DIR_')) {
                    $this->getBackupContainer();
                }
            }
        }
        
        // Return true if autoloader was loaded successfully
        return class_exists('\PrestaShop\Module\PsCopia\UpgradeTools\Translator');
    }

    /**
     * Get backup container instance
     *
     * @return \PrestaShop\Module\PsCopia\BackupContainer|null
     */
    public function getBackupContainer(): ?\PrestaShop\Module\PsCopia\BackupContainer
    {
        if (!isset($this->container) && defined('_PS_ROOT_DIR_') && defined('_PS_ADMIN_DIR_')) {
            $this->container = new \PrestaShop\Module\PsCopia\BackupContainer(
                _PS_ROOT_DIR_, 
                _PS_ADMIN_DIR_, 
                'ps_copia'
            );
            $this->container->initDirectories();
        }

        return $this->container ?? null;
    }
}
