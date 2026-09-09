<?php
// SPDX-License-Identifier: MIT
use GlpiPlugin\Techbell\Access;
use GlpiPlugin\Techbell\Events;
use GlpiPlugin\Techbell\Menu;

require_once __DIR__ . '/autoload.php';
define('PLUGIN_TECHBELL_VERSION', '0.1.1');

function plugin_init_techbell(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['techbell'] = true;
    // Hooks MUST be registered even for API, collector, rules and cron contexts.
    $PLUGIN_HOOKS['item_add']['techbell'] = [
        Ticket::class => [Events::class, 'ticketAdded'],
        Ticket_User::class => [Events::class, 'userAssigned'],
        Group_Ticket::class => [Events::class, 'groupAssigned'],
        ITILFollowup::class => [Events::class, 'followupAdded'],
    ];
    $PLUGIN_HOOKS['item_update']['techbell'] = [
        Ticket::class => [Events::class, 'ticketUpdated'],
        Ticket_User::class => [Events::class, 'userAssignmentUpdated'],
        Group_Ticket::class => [Events::class, 'groupAssignmentUpdated'],
    ];
    $PLUGIN_HOOKS['item_purge']['techbell'] = [
        Ticket::class => [Events::class, 'ticketPurged'],
        User::class => [Events::class, 'userPurged'],
    ];
    if (Session::getLoginUserID() && Session::getCurrentInterface() === 'central') {
        $PLUGIN_HOOKS['add_javascript']['techbell'] = ['js/techbell.js'];
        $PLUGIN_HOOKS['add_css']['techbell'] = ['css/techbell.css'];
        if (Access::isAdmin()) {
            $PLUGIN_HOOKS['config_page']['techbell'] = 'Config';
            $PLUGIN_HOOKS['menu_toadd']['techbell'] = ['config' => Menu::class];
        }
    }
}
function plugin_version_techbell(): array
{
    return [
        'name' => 'TechBell - Central de notificações',
        'version' => PLUGIN_TECHBELL_VERSION,
        'author' => 'Kawan Costa',
        'license' => 'MIT',
        'homepage' => 'https://github.com/KawanCostaNs',
        'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '11.0.99'],
            'php' => ['min' => '8.2'],
        ],
    ];
}
function plugin_techbell_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, '11.0.0', '<') || version_compare(GLPI_VERSION, '12.0.0', '>=')) {
        echo 'TechBell requer GLPI 11.x.';
        return false;
    }
    return version_compare(PHP_VERSION, '8.2.0', '>=');
}
function plugin_techbell_check_config($verbose = false): bool
{
    return true;
}
