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

/**
 * Manually remove the legacy controller. It has been deleted from the project but remain present while upgrading the module.
 *
 * @return bool
 */
function upgrade_module_5_0_3($module)
{
    $path = __DIR__ . '/../AdminSelfUpgrade.php';
    if (file_exists($path)) {
        $result = @unlink($path);
        if ($result !== true) {
            PrestaShopLogger::addLog('Could not delete deprecated controller AdminSelfUpgrade.php. ' . $result, 3);

            return false;
        }
    }

    return true;
}
