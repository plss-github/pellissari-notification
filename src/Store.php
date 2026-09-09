<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Techbell;

use CommonITILActor;
use ITILFollowup;
use Session;
use Ticket;

final class Store
{
    public const TABLE = 'glpi_plugin_techbell_notifications';
    private array $ticketCache = [];
    private array $followupCache = [];
    private array $groupCache = [];
    private static array $eligibleCache = [];

    public static function assignedUsers(int $ticket): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_tickets_users', 'WHERE' => [
            'tickets_id' => $ticket, 'type' => CommonITILActor::ASSIGN,
        ]]) as $row) { $ids[] = (int) $row['users_id']; }
        return array_values(array_unique(array_filter($ids)));
    }
    public static function groupUsers(int $group): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => [
            'groups_id' => $group,
        ]]) as $row) { $ids[] = (int) $row['users_id']; }
        return array_values(array_unique(array_filter($ids)));
    }
    public static function eligible(int $user): bool
    {
        global $DB;
        if ($user <= 0) { return false; }
        if (array_key_exists($user, self::$eligibleCache)) { return self::$eligibleCache[$user]; }
        $active = count($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => [
            'id' => $user, 'is_active' => 1, 'is_deleted' => 0,
        ]])) > 0;
        $central = false;
        if ($active) {
            $profiles = [];
            foreach ($DB->request(['SELECT' => 'profiles_id', 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $user]]) as $row) {
                $profiles[] = (int) $row['profiles_id'];
            }
            if ($profiles) {
                $central = count($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => [
                    'id' => array_values(array_unique($profiles)), 'interface' => 'central',
                ], 'LIMIT' => 1])) > 0;
            }
        }
        return self::$eligibleCache[$user] = $active && $central;
    }
    public static function resetCaches(): void { self::$eligibleCache = []; }

    public static function emit(int $ticketId, string $type, array $recipients, string $key, array $extra = []): void
    {
        global $DB;
        Settings::validateType($type);
        if (!Settings::get()['enabled']) { return; }
        $ticket = new Ticket();
        if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || !empty($ticket->fields['is_deleted'])) { return; }
        $actor = (int) ($extra['actor_id'] ?? Session::getLoginUserID() ?? 0);
        $data = [
            'entities_id' => (int) $ticket->fields['entities_id'], 'tickets_id' => $ticketId,
            'event_type' => $type, 'event_key' => hash('sha256', $type . ':' . $key),
            'actor_id' => max(0, $actor), 'groups_id' => max(0, (int) ($extra['groups_id'] ?? 0)),
            'followups_id' => max(0, (int) ($extra['followups_id'] ?? 0)),
            'old_status' => (int) ($extra['old_status'] ?? 0), 'new_status' => (int) ($extra['new_status'] ?? 0),
            'is_test' => 0, 'test_color' => null, 'date_creation' => date('Y-m-d H:i:s'),
            'read_at' => null, 'toast_at' => null,
        ];
        foreach (array_unique(array_map('intval', $recipients)) as $user) {
            if (!self::eligible($user) || !Settings::enabledFor($user, $type)
                || (Settings::get()['suppress_self'] && $actor > 0 && $actor === $user)) { continue; }
            $where = ['users_id' => $user, 'event_key' => $data['event_key']];
            if (!count($DB->request(['SELECT' => 'id', 'FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1]))) {
                // A UNIQUE key is a second, database-level defense against duplicate recipients/events.
                $DB->insert(self::TABLE, ['users_id' => $user] + $data);
            }
        }
    }
    public function createTest(string $type, ?string $color = null): array
    {
        global $DB;
        $user = Access::admin(); // Recipient comes ONLY from the authenticated server session.
        Settings::validateType($type);
        $now = date('Y-m-d H:i:s');
        $data = [
            'users_id' => $user, 'entities_id' => 0, 'tickets_id' => 0,
            'event_type' => $type, 'event_key' => hash('sha256', random_bytes(32)),
            'actor_id' => $user, 'groups_id' => 0, 'followups_id' => 0,
            'old_status' => 0, 'new_status' => 0, 'is_test' => 1,
            'test_color' => $color === null ? null : Settings::validateColor($color),
            'date_creation' => $now, 'read_at' => null,
            // The initiating response displays the toast; polling must not display it twice.
            'toast_at' => $now,
        ];
        $DB->insert(self::TABLE, $data);
        $data['id'] = (int) $DB->insertId();
        return $this->present($data) ?? throw new \RuntimeException('Falha ao criar teste.');
    }
    private function ticket(int $id): Ticket|false
    {
        if (!array_key_exists($id, $this->ticketCache)) {
            $ticket = new Ticket();
            $ok = $ticket->getFromDB($id) && empty($ticket->fields['is_deleted'])
                && Session::haveAccessToEntity((int) $ticket->fields['entities_id']) && $ticket->can($id, READ);
            $this->ticketCache[$id] = $ok ? $ticket : false;
        }
        return $this->ticketCache[$id];
    }
    private function allowedFollowup(int $id, int $ticket): bool
    {
        $key = $ticket . ':' . $id;
        if (!array_key_exists($key, $this->followupCache)) {
            $item = new ITILFollowup();
            $this->followupCache[$key] = $id > 0 && $item->getFromDB($id)
                && ($item->fields['itemtype'] ?? '') === 'Ticket'
                && (int) $item->fields['items_id'] === $ticket
                && empty($item->fields['is_private']) && $item->can($id, READ);
        }
        return $this->followupCache[$key];
    }
    public function allowed(array $row): bool
    {
        if ((int) $row['users_id'] !== (int) Session::getLoginUserID()) { return false; }
        if (!isset(Settings::TYPES[$row['event_type']])) { return false; }
        if ((bool) $row['is_test']) { return true; }
        $ticket = (int) $row['tickets_id'];
        if (!$this->ticket($ticket)) { return false; }
        return $row['event_type'] !== 'requester_comment' || $this->allowedFollowup((int) $row['followups_id'], $ticket);
    }
    private function groupName(int $id): string
    {
        global $DB;
        if (!isset($this->groupCache[$id])) {
            $name = 'seu grupo';
            foreach ($DB->request(['SELECT' => 'name', 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
                $name = self::plain((string) $row['name']);
            }
            $this->groupCache[$id] = $name;
        }
        return $this->groupCache[$id];
    }
    private static function plain(string $value): string
    {
        // No rich text enters our HTML. The browser also renders these strings with textContent.
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
    public function present(array $row): ?array
    {
        global $CFG_GLPI;
        if (!$this->allowed($row)) { return null; }
        $type = $row['event_type'];
        $test = (bool) $row['is_test'];
        $ticket = $test ? false : $this->ticket((int) $row['tickets_id']);
        $message = match ($type) {
            'group_assigned' => $test ? 'Um chamado entrou na fila do seu grupo.' : 'Atribuído ao grupo: ' . $this->groupName((int) $row['groups_id']) . '.',
            'user_assigned' => 'Um chamado foi atribuído diretamente a você.',
            'status_changed' => $test ? 'Status: Em atendimento > Pendente.' : 'Status: ' . self::plain((string) Ticket::getStatus((int) $row['old_status'])) . ' > ' . self::plain((string) Ticket::getStatus((int) $row['new_status'])) . '.',
            'requester_comment' => 'O solicitante adicionou um comentário público ao chamado.',
        };
        $color = $test && !empty($row['test_color']) ? $row['test_color'] : Settings::get()['color_' . $type];
        return [
            'id' => (int) $row['id'], 'type' => $type,
            'title' => ($test ? 'TESTE - ' : '') . Settings::TYPES[$type],
            'ticket_id' => (int) $row['tickets_id'],
            'ticket_title' => $test ? 'Exemplo de notificação - visível somente para você' : self::plain((string) $ticket->fields['name']),
            'message' => $message, 'color' => Settings::validateColor((string) $color),
            'date' => (new \DateTimeImmutable($row['date_creation']))->format(DATE_ATOM),
            'read' => !empty($row['read_at']), 'test' => $test,
            'url' => $test ? null : rtrim($CFG_GLPI['root_doc'] ?? '', '/') . '/front/ticket.form.php?id=' . (int) $row['tickets_id'],
        ];
    }
    private function scope(): array
    {
        return ['users_id' => Access::user(),
            'date_creation' => ['>=', date('Y-m-d H:i:s', time() - (int) Settings::get()['retention_days'] * 86400)]];
    }
    public function history(int $before = 0, string $filter = '', bool $unread = false): array
    {
        global $DB;
        $where = $this->scope();
        if ($filter !== '') { $where['event_type'] = Settings::validateType($filter); }
        if ($unread) { $where['read_at'] = null; }
        $items = [];
        $cursor = max(0, $before);
        $exhausted = false;
        $scanned = 0;
        // Scan bounded batches because current GLPI ACLs must be evaluated, not copied into SQL.
        while (count($items) < 25 && $scanned < 1000) {
            $query = $where;
            if ($cursor > 0) { $query['id'] = ['<', $cursor]; }
            $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $query, 'ORDERBY' => ['id DESC'], 'LIMIT' => 100]);
            if (!count($rows)) { $exhausted = true; break; }
            $consumed = 0;
            foreach ($rows as $row) {
                $cursor = (int) $row['id']; $scanned++; $consumed++;
                $item = $this->present($row);
                if ($item !== null) { $items[] = $item; }
                if (count($items) >= 25) { break; }
            }
            if ($consumed === count($rows) && count($rows) < 100) { $exhausted = true; break; }
        }
        return ['items' => $items, 'next_before' => $exhausted ? null : $cursor];
    }
    public function unreadStats(): array
    {
        global $DB;
        $where = $this->scope(); $where['read_at'] = null;
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['id DESC'], 'LIMIT' => 1000]);
        $count = 0;
        foreach ($rows as $row) {
            if ($this->allowed($row)) { $count++; }
            if ($count >= 100) { return ['count' => 99, 'partial' => true]; }
        }
        return ['count' => $count, 'partial' => count($rows) >= 1000];
    }
    public function pending(): array
    {
        global $DB;
        if (!Settings::get()['enabled']) { return []; }
        $where = $this->scope(); $where['read_at'] = null; $where['toast_at'] = null;
        // An old backlog belongs in history, not in an endless burst of popups after login.
        $where['date_creation'] = ['>=', date('Y-m-d H:i:s', time() - 300)];
        $items = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['id DESC'], 'LIMIT' => 100]) as $row) {
            if (!Settings::enabledFor((int) $row['users_id'], $row['event_type'])) { continue; }
            $item = $this->present($row);
            if ($item !== null) { $items[] = $item; }
            if (count($items) >= 3) { break; }
        }
        return array_reverse($items);
    }
    public function claim(array $ids): array
    {
        global $DB;
        $items = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 3) { throw new \InvalidArgumentException('No máximo três alertas por vez.'); }
        if (!$ids || !Settings::get()['enabled']) { return []; }
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => [
            'id' => $ids, 'users_id' => Access::user(), 'toast_at' => null, 'read_at' => null,
            'date_creation' => ['>=', date('Y-m-d H:i:s', time() - 300)],
        ]]) as $row) {
            if (!Settings::enabledFor((int) $row['users_id'], $row['event_type'])) { continue; }
            $item = $this->present($row);
            if ($item === null) { continue; }
            $DB->update(self::TABLE, ['toast_at' => date('Y-m-d H:i:s')], [
                'id' => (int) $row['id'], 'users_id' => Access::user(), 'toast_at' => null, 'read_at' => null,
            ]);
            // Atomic compare-and-update: only one tab/session can claim the toast.
            if ($DB->affectedRows() === 1) { $items[] = $item; }
        }
        return $items;
    }
    public function markRead(int $id): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id, 'users_id' => Access::user()], 'LIMIT' => 1]) as $row) {
            if ($this->allowed($row)) {
                $now = date('Y-m-d H:i:s');
                $DB->update(self::TABLE, ['read_at' => $now, 'toast_at' => $now], ['id' => $id, 'users_id' => Access::user()]);
            }
        }
    }
    public function markAllRead(int $after = 0, int $upto = 0): array
    {
        global $DB;
        // Cursor batching avoids a huge single request. New events after the initial high-water mark stay unread.
        if ($upto <= 0) {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => self::TABLE,
                'WHERE' => ['users_id' => Access::user()], 'ORDERBY' => ['id DESC'], 'LIMIT' => 1]) as $row) {
                $upto = (int) $row['id'];
            }
        }
        if ($upto <= 0) { return ['next' => null, 'upto' => 0]; }
        $where = $this->scope(); $where['read_at'] = null;
        $where['AND'] = [['id' => ['>', max(0, $after)]], ['id' => ['<=', $upto]]];
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['id ASC'], 'LIMIT' => 200]);
        $ids = []; $last = $after;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            if ($this->allowed($row)) { $ids[] = $last; }
        }
        if ($ids) {
            $now = date('Y-m-d H:i:s');
            $DB->update(self::TABLE, ['read_at' => $now, 'toast_at' => $now], ['id' => $ids, 'users_id' => Access::user()]);
        }
        return ['next' => count($rows) < 200 ? null : $last, 'upto' => $upto];
    }
}
