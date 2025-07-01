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

namespace PrestaShop\Module\AutoUpgrade\Controller;

use PrestaShop\Module\AutoUpgrade\AjaxResponseBuilder;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeFileNames;
use PrestaShop\Module\AutoUpgrade\Router\Routes;
use Symfony\Component\HttpFoundation\JsonResponse;

class HomePageController extends AbstractPageController
{
    const FORM_FIELDS = [
        'route_choice' => 'route_choice',
    ];
    const FORM_OPTIONS = [
        'backup_value' => 'backup',
        'restore_value' => 'restore',
    ];

    protected function getPageTemplate(): string
    {
        return 'home';
    }

    protected function displayRouteInUrl(): ?string
    {
        return Routes::HOME_PAGE;
    }

    public function submit(): JsonResponse
    {
        $routeChoice = $this->request->request->get(self::FORM_FIELDS['route_choice']);

        if ($routeChoice === self::FORM_OPTIONS['backup_value']) {
            // Redirigir a la página de opciones de backup
            return AjaxResponseBuilder::nextRouteResponse(Routes::BACKUP_PAGE_BACKUP_OPTIONS);
        }

        // if is not backup is restore
        if ($this->getParams()['empty_backup']) {
            return AjaxResponseBuilder::errorResponse('No puedes acceder a esta ruta porque no tienes copias de seguridad.', 401);
        }

        $this->upgradeContainer->getFileStorage()->clean(UpgradeFileNames::RESTORE_CONFIG_FILENAME);

        return AjaxResponseBuilder::nextRouteResponse(Routes::RESTORE_PAGE_BACKUP_SELECTION);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    protected function getParams(): array
    {
        $backupFinder = $this->upgradeContainer->getBackupFinder();

        return [
            'empty_backup' => empty($backupFinder->getAvailableBackups()),
            'form_route_to_submit' => Routes::HOME_PAGE_SUBMIT_FORM,
            'form_fields' => self::FORM_FIELDS,
            'form_options' => self::FORM_OPTIONS,
        ];
    }
}
