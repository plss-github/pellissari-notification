<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;

use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

final class Access
{
    public static function isAdmin(): bool
    {
        // Rights, not a hard-coded profile ID/name. Root scope prevents a tenant admin changing global rules.
        return (int) Session::getLoginUserID() > 0
            && Session::getCurrentInterface() === 'central'
            && Session::haveRight('config', UPDATE)
            && Session::haveRight('profile', UPDATE)
            && Session::haveAccessToEntity(0);
    }
    public static function user(): int
    {
        $id = (int) Session::getLoginUserID();
        if ($id <= 0 || Session::getCurrentInterface() !== 'central') {
            throw new AccessDeniedHttpException('Sessão técnica necessária.');
        }
        return $id;
    }
    public static function admin(): int
    {
        $id = self::user();
        if (!self::isAdmin()) {
            throw new AccessDeniedHttpException('Somente Super-Admin ou perfil equivalente, com acesso a entidade raiz.');
        }
        return $id;
    }
    public static function csrf(): string
    {
        if (empty($_SESSION['pellissarinotification_csrf'])) {
            $_SESSION['pellissarinotification_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['pellissarinotification_csrf'];
    }
    public static function post(Request $request): void
    {
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST']);
        }
        // GLPI's native listener checks _glpi_csrf_token first. Do NOT consume that token twice.
        // This independent plugin token is a second check and also protects compatibility paths.
        $provided = $request->request->get('_pellissarinotification_csrf');
        $expected = $_SESSION['pellissarinotification_csrf'] ?? '';
        if (!is_string($provided) || $expected === '' || !hash_equals($expected, $provided)) {
            throw new AccessDeniedHttpException('Token de segurança inválido. Atualize a página.');
        }
    }
    public static function base(): string
    {
        global $CFG_GLPI;
        // GLPI 11 canonical plugin routes work for both plugins/ and marketplace/ installations.
        return rtrim($CFG_GLPI['root_doc'] ?? '', '/') . '/plugins/pellissarinotification';
    }
}
