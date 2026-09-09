<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Pellissarinotification\Access;
use GlpiPlugin\Pellissarinotification\Settings;
use GlpiPlugin\Pellissarinotification\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/Config', name: 'pellissarinotification_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        Access::admin();
        $error = '';
        if ($request->isMethod('POST')) {
            Access::post($request);
            try {
                Settings::save($request->request->all());
                return new RedirectResponse(Access::base() . '/Config?saved=1', 303);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        ob_start();
        try {
            \Html::header('Pellissari Notification', $request->getPathInfo(), 'config', \GlpiPlugin\Pellissarinotification\Menu::class);
            View::admin(Settings::get(), $error, $request->query->getInt('saved') === 1);
            \Html::footer();
            $html = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean(); throw $e;
        }
        return new Response($html, $error === '' ? 200 : 400, ['Cache-Control' => 'no-store, private']);
    }
}
