<?php
// SPDX-License-Identifier: MIT
require_once __DIR__ . '/autoload.php';

function plugin_techbell_install(): bool
{
    global $DB;
    $tables = [
        'glpi_plugin_techbell_configs' => "
            `id` int unsigned NOT NULL,
            `enabled` tinyint NOT NULL DEFAULT 1,
            `poll_interval` int NOT NULL DEFAULT 10,
            `toast_duration` int NOT NULL DEFAULT 10,
            `retention_days` int NOT NULL DEFAULT 90,
            `suppress_self` tinyint NOT NULL DEFAULT 0,
            `enabled_group_assigned` tinyint NOT NULL DEFAULT 1,
            `enabled_user_assigned` tinyint NOT NULL DEFAULT 1,
            `enabled_status_changed` tinyint NOT NULL DEFAULT 1,
            `enabled_requester_comment` tinyint NOT NULL DEFAULT 1,
            `color_group_assigned` varchar(7) NOT NULL DEFAULT '#2563EB',
            `color_user_assigned` varchar(7) NOT NULL DEFAULT '#16A34A',
            `color_status_changed` varchar(7) NOT NULL DEFAULT '#D97706',
            `color_requester_comment` varchar(7) NOT NULL DEFAULT '#7C3AED',
            PRIMARY KEY (`id`)",
        'glpi_plugin_techbell_userrules' => "
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `event_type` varchar(32) NOT NULL,
            `enabled` tinyint NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_event` (`users_id`, `event_type`)",
        'glpi_plugin_techbell_notifications' => "
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `event_type` varchar(32) NOT NULL,
            `event_key` char(64) NOT NULL,
            `actor_id` int unsigned NOT NULL DEFAULT 0,
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            `followups_id` int unsigned NOT NULL DEFAULT 0,
            `old_status` int NOT NULL DEFAULT 0,
            `new_status` int NOT NULL DEFAULT 0,
            `is_test` tinyint NOT NULL DEFAULT 0,
            `test_color` varchar(7) DEFAULT NULL,
            `date_creation` timestamp NOT NULL,
            `read_at` timestamp NULL DEFAULT NULL,
            `toast_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `recipient_event` (`users_id`, `event_key`),
            KEY `user_history` (`users_id`, `id`),
            KEY `user_unread` (`users_id`, `read_at`, `id`),
            KEY `user_toast` (`users_id`, `toast_at`, `id`),
            KEY `ticket` (`tickets_id`),
            KEY `retention` (`date_creation`)",
    ];
    foreach ($tables as $name => $schema) {
        $DB->doQuery("CREATE TABLE IF NOT EXISTS `$name` ($schema) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!count($DB->request(['FROM' => 'glpi_plugin_techbell_configs', 'WHERE' => ['id' => 1]]))) {
        $DB->insert('glpi_plugin_techbell_configs', GlpiPlugin\Techbell\Settings::defaults());
    }
    CronTask::register(GlpiPlugin\Techbell\Maintenance::class, 'Purge', DAY_TIMESTAMP, [
        'state' => CronTask::STATE_WAITING,
        'mode' => CronTask::MODE_EXTERNAL,
        'comment' => 'TechBell: remover notificações fora da retenção configurada.',
    ]);
    return true;
}
function plugin_techbell_uninstall(): bool
{
    global $DB;
    // Uninstall is destructive; deactivation alone preserves all plugin data.
    CronTask::unregister('techbell');
    foreach (['notifications', 'userrules', 'configs'] as $suffix) {
        $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_techbell_' . $suffix . '`');
    }
    return true;
}
