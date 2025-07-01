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

use PrestaShop\Module\AutoUpgrade\UpgradeContainer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig_Environment;

abstract class AbstractGlobalController
{
    /** @var UpgradeContainer */
    protected $upgradeContainer;

    /** @var Request */
    protected $request;

    public function __construct(UpgradeContainer $upgradeContainer, Request $request)
    {
        $this->upgradeContainer = $upgradeContainer;
        $this->request = $request;
    }

    /**
     * @return Twig_Environment|Environment
     */
    protected function getTwig()
    {
        return $this->upgradeContainer->getTwig();
    }

    protected function redirectTo(string $destinationRoute): RedirectResponse
    {
        return new RedirectResponse($this->upgradeContainer->getUrlGenerator()->getUrlToRoute($this->request, $destinationRoute));
    }
}
