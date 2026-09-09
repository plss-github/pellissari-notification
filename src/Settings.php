<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;

final class Settings
{
    public const TABLE = 'glpi_plugin_pellissarinotification_configs';
    public const RULES = 'glpi_plugin_pellissarinotification_userrules';
    public const TYPES = [
        'group_assigned' => 'Chamado atribuído ao grupo',
        'user_assigned' => 'Chamado atribuído a pessoa',
        'status_changed' => 'Status do chamado alterado',
        'requester_comment' => 'Comentário do solicitante',
    ];
    private static ?array $cache = null;
    private static array $userCache = [];

    public static function defaults(): array
    {
        return [
            'id' => 1, 'enabled' => 1, 'poll_interval' => 10, 'toast_duration' => 10,
            'retention_days' => 90, 'suppress_self' => 0,
            'enabled_group_assigned' => 1, 'enabled_user_assigned' => 1,
            'enabled_status_changed' => 1, 'enabled_requester_comment' => 1,
            'color_group_assigned' => '#2563EB', 'color_user_assigned' => '#16A34A',
            'color_status_changed' => '#D97706', 'color_requester_comment' => '#7C3AED',
        ];
    }
    public static function get(): array
    {
        global $DB;
        if (self::$cache === null) {
            $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => 1], 'LIMIT' => 1]);
            self::$cache = self::defaults();
            foreach ($rows as $row) {
                self::$cache = array_replace(self::$cache, $row);
            }
        }
        return self::$cache;
    }
    public static function reset(): void
    {
        self::$cache = null;
        self::$userCache = [];
    }
    public static function validateColor(string $value): string
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $value)) {
            throw new \InvalidArgumentException('Use uma cor hexadecimal no formato #RRGGBB.');
        }
        return strtoupper($value);
    }
    public static function validateType(string $type): string
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Tipo de notificação inválido.');
        }
        return $type;
    }
    public static function normalize(array $input): array
    {
        $out = [];
        foreach (['enabled', 'suppress_self'] as $key) {
            $out[$key] = in_array($input[$key] ?? null, [1, '1', true], true) ? 1 : 0;
        }
        foreach (['poll_interval' => [5, 120], 'toast_duration' => [5, 60], 'retention_days' => [7, 365]] as $key => [$min, $max]) {
            $v = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v === null || $v < $min || $v > $max) {
                throw new \InvalidArgumentException("Valor de $key deve estar entre $min e $max.");
            }
            $out[$key] = $v;
        }
        foreach (self::TYPES as $type => $label) {
            $out['enabled_' . $type] = in_array($input['enabled_' . $type] ?? null, [1, '1', true], true) ? 1 : 0;
            $color = $input['color_' . $type] ?? '';
            if (!is_string($color)) {
                throw new \InvalidArgumentException('Cor inválida.');
            }
            $out['color_' . $type] = self::validateColor($color);
        }
        return $out;
    }
    public static function save(array $input): void
    {
        global $DB;
        Access::admin();
        $DB->update(self::TABLE, self::normalize($input), ['id' => 1]);
        self::reset();
    }
    public static function overrides(int $user): array
    {
        global $DB;
        if (!isset(self::$userCache[$user])) {
            $result = array_fill_keys(array_keys(self::TYPES), -1);
            foreach ($DB->request(['FROM' => self::RULES, 'WHERE' => ['users_id' => $user]]) as $row) {
                if (isset(self::TYPES[$row['event_type']])) {
                    $result[$row['event_type']] = (int) $row['enabled'];
                }
            }
            self::$userCache[$user] = $result;
        }
        return self::$userCache[$user];
    }
    public static function enabledFor(int $user, string $type): bool
    {
        self::validateType($type);
        $cfg = self::get();
        if (!(bool) $cfg['enabled']) {
            return false;
        }
        $override = self::overrides($user)[$type];
        return $override === -1 ? (bool) $cfg['enabled_' . $type] : $override === 1;
    }
    public static function saveOverrides(int $user, array $input): void
    {
        global $DB;
        Access::admin();
        if ($user <= 0 || !count($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $user, 'is_deleted' => 0]]))) {
            throw new \InvalidArgumentException('Selecione um usuário válido.');
        }
        $values = [];
        foreach (self::TYPES as $type => $label) {
            $v = filter_var($input[$type] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($v, [-1, 0, 1], true)) {
                throw new \InvalidArgumentException('Regra individual inválida.');
            }
            $values[$type] = $v;
        }
        $DB->beginTransaction();
        try {
            $DB->delete(self::RULES, ['users_id' => $user]);
            foreach ($values as $type => $v) {
                if ($v !== -1) {
                    $DB->insert(self::RULES, ['users_id' => $user, 'event_type' => $type, 'enabled' => $v]);
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollback();
            throw $e;
        }
        self::reset();
    }
}
