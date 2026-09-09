<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Techbell;
final class Menu extends \CommonGLPI
{
    public static function getTypeName($nb = 0): string { return 'TechBell'; }
    public static function getMenuName(): string { return 'TechBell'; }
    public static function getMenuContent(): array|false
    {
        if (!Access::isAdmin()) { return false; }
        return ['title' => 'TechBell', 'page' => '/plugins/techbell/Config', 'icon' => 'ti ti-bell'];
    }
}
