<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;
final class Maintenance extends \CommonDBTM
{
    public static function getTypeName($nb = 0): string { return 'Pellissari Notification'; }
    public static function cronInfo($name): array
    {
        return ['description' => 'Limpar histórico Pellissari Notification conforme a retenção configurada.'];
    }
    public static function cronPurge($task): int
    {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', time() - (int) Settings::get()['retention_days'] * 86400);
        $total = 0;
        // Bounded work: at most 50,000 rows per run. Next run continues if necessary.
        for ($batch = 0; $batch < 10; $batch++) {
            $ids = [];
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => Store::TABLE,
                'WHERE' => ['date_creation' => ['<', $cutoff]], 'LIMIT' => 5000]) as $row) {
                $ids[] = (int) $row['id'];
            }
            if (!$ids) { break; }
            $DB->delete(Store::TABLE, ['id' => $ids]);
            $total += count($ids);
            if (count($ids) < 5000) { break; }
        }
        if ($total > 0) { $task->addVolume($total); }
        return $total > 0 ? 1 : 0;
    }
}
