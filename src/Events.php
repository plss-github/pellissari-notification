<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;

use CommonDBTM;
use CommonITILActor;
use Group_Ticket;
use ITILFollowup;
use Session;
use Ticket;
use Ticket_User;
use Toolbox;

final class Events
{
    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            // A notification failure must not prevent saving the business ticket.
            // Never log ticket/comment contents or database statements here.
            Toolbox::logInFile('pellissarinotification', 'Notification hook failed: ' . get_class($e) . "\n");
        }
    }
    public static function userAssigned(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            if (!$item instanceof Ticket_User || (int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            Store::emit((int) $item->fields['tickets_id'], 'user_assigned', [(int) $item->fields['users_id']],
                'assignment-user:' . (int) $item->fields['id']);
        });
    }
    public static function groupAssigned(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            if (!$item instanceof Group_Ticket || (int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            $group = (int) $item->fields['groups_id'];
            Store::emit((int) $item->fields['tickets_id'], 'group_assigned', Store::groupUsers($group),
                'assignment-group:' . (int) $item->fields['id'], ['groups_id' => $group]);
        });
    }
    public static function userAssignmentUpdated(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            if (!$item instanceof Ticket_User || !array_intersect($item->updates, ['type', 'users_id', 'tickets_id'])
                || (int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            Store::emit((int) $item->fields['tickets_id'], 'user_assigned', [(int) $item->fields['users_id']],
                'assignment-user-update:' . bin2hex(random_bytes(16)));
        });
    }
    public static function groupAssignmentUpdated(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            if (!$item instanceof Group_Ticket || !array_intersect($item->updates, ['type', 'groups_id', 'tickets_id'])
                || (int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            $group = (int) $item->fields['groups_id'];
            Store::emit((int) $item->fields['tickets_id'], 'group_assigned', Store::groupUsers($group),
                'assignment-group-update:' . bin2hex(random_bytes(16)), ['groups_id' => $group]);
        });
    }
    public static function ticketAdded(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            global $DB;
            if (!$item instanceof Ticket) {
                return;
            }
            // Fallback for tickets born assigned. Stable keys avoid duplicating actor hooks.
            foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => [
                'tickets_id' => $item->getID(), 'type' => CommonITILActor::ASSIGN,
            ]]) as $row) {
                $link = new Ticket_User();
                $link->fields = $row;
                self::userAssigned($link);
            }
            foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => [
                'tickets_id' => $item->getID(), 'type' => CommonITILActor::ASSIGN,
            ]]) as $row) {
                $link = new Group_Ticket();
                $link->fields = $row;
                self::groupAssigned($link);
            }
        });
    }
    public static function ticketUpdated(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            if (!$item instanceof Ticket || !in_array('status', $item->updates, true)
                || !array_key_exists('status', $item->oldvalues)) {
                return;
            }
            $old = (int) $item->oldvalues['status'];
            $new = (int) ($item->fields['status'] ?? 0);
            if ($old === $new) {
                return;
            }
            Store::emit($item->getID(), 'status_changed', Store::assignedUsers($item->getID()),
                'status:' . bin2hex(random_bytes(16)), ['old_status' => $old, 'new_status' => $new]);
        });
    }
    public static function followupAdded(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            global $DB;
            if (!$item instanceof ITILFollowup || ($item->fields['itemtype'] ?? '') !== 'Ticket'
                || (bool) ($item->fields['is_private'] ?? true)) {
                return;
            }
            $ticket = (int) $item->fields['items_id'];
            $author = (int) ($item->fields['users_id'] ?? 0);
            if ($author <= 0 || !count($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => [
                'tickets_id' => $ticket, 'users_id' => $author, 'type' => CommonITILActor::REQUESTER,
            ]]))) {
                return;
            }
            Store::emit($ticket, 'requester_comment', Store::assignedUsers($ticket),
                'followup:' . (int) $item->fields['id'], [
                    'actor_id' => $author, 'followups_id' => (int) $item->fields['id'],
                ]);
        });
    }
    public static function ticketPurged(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            global $DB;
            $DB->delete(Store::TABLE, ['tickets_id' => $item->getID()]);
        });
    }
    public static function userPurged(CommonDBTM $item): void
    {
        self::safely(static function () use ($item): void {
            global $DB;
            $DB->delete(Store::TABLE, ['users_id' => $item->getID()]);
            $DB->delete(Settings::RULES, ['users_id' => $item->getID()]);
        });
    }
}
