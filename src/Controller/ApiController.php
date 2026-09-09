<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Techbell\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Techbell\Access;
use GlpiPlugin\Techbell\Settings;
use GlpiPlugin\Techbell\Store;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ApiController extends AbstractController
{
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
    #[Route('/Feed', name: 'techbell_feed', methods: ['GET'])]
    public function feed(): JsonResponse
    {
        $user = Access::user();
        $store = new Store(); $cfg = Settings::get();
        $context = hash('sha256', json_encode([$user, $_SESSION['glpiactiveprofile']['id'] ?? 0, $_SESSION['glpiactiveentities'] ?? []]));
        return $this->json([
            'user_id' => $user, 'context' => $context, 'admin' => Access::isAdmin(),
            'settings_url' => Access::base() . '/Config', 'enabled' => (bool) $cfg['enabled'],
            'poll_interval' => (int) $cfg['poll_interval'], 'toast_duration' => (int) $cfg['toast_duration'],
            'unread' => $store->unreadStats(), 'pending' => $store->pending(),
        ]);
    }
    #[Route('/History', name: 'techbell_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        Access::user();
        try {
            return $this->json((new Store())->history(max(0, $request->query->getInt('before')),
                $request->query->getString('type'), $request->query->getBoolean('unread')));
        } catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 400); }
    }
    #[Route('/Token', name: 'techbell_token', methods: ['GET'])]
    public function token(): JsonResponse
    {
        Access::user();
        return $this->json(['csrf' => Session::getNewCSRFToken(true), 'plugin_csrf' => Access::csrf()]);
    }
    // GET is intentionally listed for the GLPI <11.0.7 route-matching bug.
    // Access::post() rejects every non-POST request BEFORE any mutation.
    #[Route('/Action', name: 'techbell_action', methods: ['GET', 'POST'])]
    public function action(Request $request): JsonResponse
    {
        Access::user(); Access::post($request);
        $store = new Store();
        try {
            switch ($request->request->getString('action')) {
                case 'claim':
                    $ids = explode(',', $request->request->getString('ids'));
                    return $this->json(['items' => $store->claim($ids)]);
                case 'read':
                    $store->markRead($request->request->getInt('id'));
                    return $this->json(['ok' => true]);
                case 'read_all':
                    return $this->json($store->markAllRead($request->request->getInt('after'), $request->request->getInt('upto')));
                case 'test':
                    Access::admin();
                    if (time() < (int) ($_SESSION['techbell_next_test'] ?? 0)) {
                        return $this->json(['error' => 'Aguarde três segundos entre os testes.'], 429);
                    }
                    $item = $store->createTest($request->request->getString('type'), $request->request->getString('color'));
                    $_SESSION['techbell_next_test'] = time() + 3;
                    return $this->json(['item' => $item]);
                case 'user_rules':
                    Access::admin();
                    Settings::saveOverrides($request->request->getInt('user_id'), $request->request->all());
                    return $this->json(['ok' => true]);
                default:
                    return $this->json(['error' => 'Ação inválida.'], 400);
            }
        } catch (\InvalidArgumentException $e) { return $this->json(['error' => $e->getMessage()], 400); }
    }
    #[Route('/Users', name: 'techbell_users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        global $DB;
        Access::admin();
        $q = trim($request->query->getString('q'));
        if (strlen($q) < 2 || strlen($q) > 80) { return $this->json(['items' => []]); }
        $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $where = ['is_deleted' => 0, 'is_active' => 1, 'OR' => [
            ['name' => ['LIKE', $pattern]], ['realname' => ['LIKE', $pattern]], ['firstname' => ['LIKE', $pattern]],
        ]];
        if (ctype_digit($q)) { $where['OR'][] = ['id' => (int) $q]; }
        $items = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users',
            'WHERE' => $where, 'ORDERBY' => ['name ASC'], 'LIMIT' => 20]) as $row) {
            $full = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            $items[] = ['id' => (int) $row['id'], 'label' => ($full !== '' ? $full . ' - ' : '') . $row['name'] . ' (#' . $row['id'] . ')'];
        }
        return $this->json(['items' => $items]);
    }
    #[Route('/UserRules', name: 'techbell_user_rules', methods: ['GET'])]
    public function userRules(Request $request): JsonResponse
    {
        Access::admin();
        return $this->json(['rules' => Settings::overrides(max(0, $request->query->getInt('user_id')))]);
    }
}
