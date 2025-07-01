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
use PrestaShop\Module\AutoUpgrade\Task\TaskType;
use PrestaShop\Module\AutoUpgrade\Twig\PageSelectors;
use Symfony\Component\HttpFoundation\JsonResponse;

class LogsController extends AbstractGlobalController
{
    public function getDownloadLogsButton(): JsonResponse
    {
        $type = TaskType::fromString(
            $this->request->request->get('download-logs-type')
        );

        return AjaxResponseBuilder::hydrationResponse(
            PageSelectors::DOWNLOAD_LOGS_PARENT_ID,
            $this->getTwig()->render(
                '@ModuleAutoUpgrade/components/download_logs.html.twig',
                $this->upgradeContainer->getLogsService()->getDownloadLogsData($type)
            )
        );
    }
}
