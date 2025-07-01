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
use PrestaShop\Module\AutoUpgrade\Twig\PageSelectors;
use Symfony\Component\HttpFoundation\Response;

abstract class AbstractPageWithStepController extends AbstractPageController
{
    public function step(): Response
    {
        if (!$this->request->isXmlHttpRequest()) {
            return new Response('Unexpected call to a step route outside an ajax call.', 404);
        }

        // It may be tempting to move this line inside the parameters of the method
        // `getTwig()->render()`. Please refrain to do so as this makes Twig
        // called BEFORE the call to the function sent as parameters. Initiating it too early
        // can be misleading when rendering the templates as more autoloaders can be loaded
        // in the meantime (i.e the core).
        $params = $this->getParams();

        return AjaxResponseBuilder::hydrationResponse(
            PageSelectors::STEP_PARENT_ID,
            $this->getTwig()->render(
                '@ModuleAutoUpgrade/steps/' . $this->getStepTemplate() . '.html.twig',
                $params
            ),
            ['newRoute' => $this->displayRouteInUrl()]
        );
    }

    /**
     * Relative path from the templates folder of the twig file
     * to load when reaching this step.
     *
     * @see step()
     *
     * @return string
     */
    abstract protected function getStepTemplate(): string;
}
