<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;
final class Menu extends \CommonGLPI
{
    public static function getTypeName($nb = 0): string { return 'Pellissari Notification'; }
    public static function getMenuName(): string { return 'Pellissari Notification'; }
    public static function getMenuContent(): array|false
    {
        if (!Access::isAdmin()) { return false; }
        return ['title' => 'Pellissari Notification', 'page' => '/plugins/pellissarinotification/Config', 'icon' => 'ti ti-bell'];
    }
}
