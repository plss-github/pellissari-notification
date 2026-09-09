<?php
// Test doubles, NOT a GLPI runtime. CLI only. SPDX-License-Identifier: MIT
namespace Symfony\Component\HttpKernel\Exception {
    class AccessDeniedHttpException extends \RuntimeException {}
    class MethodNotAllowedHttpException extends \RuntimeException { public function __construct(array $methods) { parent::__construct('POST required'); } }
}
namespace Symfony\Component\HttpFoundation {
    class ParameterBag {
        public function __construct(private array $values = []) {}
        public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    }
    class Request {
        public ParameterBag $request;
        public function __construct(private string $method = 'GET', array $values = []) { $this->request = new ParameterBag($values); }
        public function isMethod(string $method): bool { return $this->method === $method; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('READ', 1); define('UPDATE', 2); define('DAY_TIMESTAMP', 86400); define('GLPI_VERSION', '11.0.8');
    class CommonGLPI {}
    class CommonDBTM { public array $fields = []; public array $updates = []; public array $oldvalues = []; public function getID(): int { return (int) ($this->fields['id'] ?? 0); } }
    class CommonITILActor { public const REQUESTER = 1; public const ASSIGN = 2; public const OBSERVER = 3; }
    class Ticket extends CommonDBTM {
        public function getFromDB(int $id): bool { global $DB; $this->fields = $DB->tables['glpi_tickets'][$id] ?? []; return (bool) $this->fields; }
        public function can(int $id, int $right): bool { return in_array($id, Session::$visible, true); }
        public static function getStatus(int $id): string { return [1=>'Novo',2=>'Em atendimento',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado'][$id] ?? 'Desconhecido'; }
    }
    class Ticket_User extends CommonDBTM {}
    class Group_Ticket extends CommonDBTM {}
    class User extends CommonDBTM {}
    class ITILFollowup extends CommonDBTM {
        public function getFromDB(int $id): bool { global $DB; $this->fields = $DB->tables['glpi_itilfollowups'][$id] ?? []; return (bool) $this->fields; }
        public function can(int $id, int $right): bool { return empty($this->fields['is_private']) && in_array((int) $this->fields['items_id'], Session::$visible, true); }
    }
    class Session {
        public static int $id = 1; public static bool $admin = true; public static string $interface = 'central';
        public static array $entities = [0,1,2]; public static array $visible = [101,102];
        public static function getLoginUserID(): int { return self::$id; }
        public static function getCurrentInterface(): string { return self::$interface; }
        public static function haveRight(string $right, int $permission): bool { return self::$admin; }
        public static function haveAccessToEntity(int $id): bool { return in_array($id, self::$entities, true); }
        public static function getNewCSRFToken(bool $standalone=false): string { return 'test-native-token'; }
    }
    class Toolbox { public static array $logs = []; public static function logInFile($file, $message): void { self::$logs[] = [$file,$message]; } }
    class CronTask { public const STATE_WAITING = 1; public const MODE_EXTERNAL = 2;
        public static function register(...$args): bool { return true; } public static function unregister($name): bool { return true; }
    }
    class FakeDB {
        public array $tables = []; public array $sql = []; private int $lastId=0; private int $affected=0; private array $backup=[];
        public function request(array $query): \ArrayIterator {
            $rows = array_values($this->tables[$query['FROM']] ?? []);
            $rows = array_values(array_filter($rows, fn(array $row): bool => $this->matches($row, $query['WHERE'] ?? [])));
            if (isset($query['ORDERBY'])) {
                $order = is_array($query['ORDERBY']) ? $query['ORDERBY'][0] : $query['ORDERBY'];
                [$field, $direction] = array_pad(explode(' ', $order), 2, 'ASC');
                usort($rows, fn($a,$b) => ($a[$field] <=> $b[$field]) * ($direction === 'DESC' ? -1 : 1));
            }
            if (isset($query['LIMIT'])) { $rows = array_slice($rows, 0, $query['LIMIT']); }
            return new \ArrayIterator($rows);
        }
        private function matches(array $row, array $where): bool {
            foreach ($where as $field=>$value) {
                if ($field === 'OR' || $field === 'AND') {
                    $parts = [];
                    foreach ($value as $key=>$sub) { $parts[] = $this->matches($row, is_int($key) ? $sub : [$key=>$sub]); }
                    if ($field === 'OR' && !in_array(true,$parts,true)) return false;
                    if ($field === 'AND' && in_array(false,$parts,true)) return false;
                    continue;
                }
                $v = $row[$field] ?? null;
                if (is_array($value)) {
                    if (in_array($value[0] ?? null, ['<','>','<=','>=','LIKE'],true)) {
                        [$op,$expected]=$value;
                        $ok = match($op) { '<'=>$v<$expected,'>'=>$v>$expected,'<='=>$v<=$expected,'>='=>$v>=$expected,
                            'LIKE'=>str_contains(strtolower((string)$v),strtolower(trim($expected,'%'))) };
                        if (!$ok) return false;
                    } elseif (!in_array($v,$value,false)) return false;
                } elseif ($value === null ? $v !== null : $v != $value) return false;
            }
            return true;
        }
        public function insert(string $table, array $data): bool {
            $id = $data['id'] ?? (empty($this->tables[$table]) ? 1 : max(array_keys($this->tables[$table]))+1);
            if ($table === 'glpi_plugin_pellissarinotification_notifications') {
                foreach ($this->tables[$table] ?? [] as $old) {
                    if ($old['users_id']===$data['users_id'] && $old['event_key']===$data['event_key']) throw new \RuntimeException('Duplicate key');
                }
            }
            $this->tables[$table][$id] = ['id'=>$id]+$data; $this->lastId=$id; $this->affected=1; return true;
        }
        public function insertId(): int { return $this->lastId; }
        public function update(string $table,array $values,array $where): bool {
            $this->affected=0;
            foreach ($this->tables[$table] ?? [] as $id=>$row) {
                if ($this->matches($row,$where)) {
                    $next=array_replace($row,$values);
                    if ($next !== $row) $this->affected++;
                    $this->tables[$table][$id]=$next;
                }
            }
            return true;
        }
        public function affectedRows(): int { return $this->affected; }
        public function delete(string $table,array $where): bool {
            $this->affected=0;
            foreach ($this->tables[$table] ?? [] as $id=>$row) if ($this->matches($row,$where)) { unset($this->tables[$table][$id]);$this->affected++; }
            return true;
        }
        public function beginTransaction(): void { $this->backup=$this->tables; }
        public function commit(): void { $this->backup=[]; }
        public function rollback(): void { $this->tables=$this->backup;$this->backup=[]; }
        public function doQuery(string $sql): bool { $this->sql[]=$sql; return true; }
    }
    require_once dirname(__DIR__) . '/autoload.php';
    $CFG_GLPI=['root_doc'=>'/glpi']; $_SESSION=[];
    function fixture(): void {
        global $DB;
        $DB=new FakeDB();
        $DB->insert(\GlpiPlugin\Pellissarinotification\Settings::TABLE,\GlpiPlugin\Pellissarinotification\Settings::defaults());
        foreach ([1,2,3,4,5,6] as $id) $DB->insert('glpi_users',['id'=>$id,'is_active'=>$id===5?0:1,'is_deleted'=>0,'name'=>'user'.$id,'firstname'=>'User','realname'=>(string)$id]);
        $DB->insert('glpi_profiles',['id'=>1,'interface'=>'central']);$DB->insert('glpi_profiles',['id'=>2,'interface'=>'helpdesk']);
        foreach ([1,2,3,4,5,6] as $id) $DB->insert('glpi_profiles_users',['users_id'=>$id,'profiles_id'=>in_array($id,[4,6],true)?2:1]);
        $DB->insert('glpi_tickets',['id'=>101,'name'=>'Ticket A','entities_id'=>1,'status'=>2,'is_deleted'=>0]);
        $DB->insert('glpi_tickets',['id'=>102,'name'=>'Secret ticket B','entities_id'=>2,'status'=>2,'is_deleted'=>0]);
        $DB->insert('glpi_tickets_users',['id'=>11,'tickets_id'=>101,'users_id'=>2,'type'=>2]);
        $DB->insert('glpi_tickets_users',['id'=>12,'tickets_id'=>101,'users_id'=>4,'type'=>1]);
        $DB->insert('glpi_groups',['id'=>7,'name'=>'Infra']);
        $DB->insert('glpi_groups_tickets',['id'=>21,'tickets_id'=>101,'groups_id'=>7,'type'=>2]);
        foreach ([2,3,3,5,6] as $id) $DB->insert('glpi_groups_users',['groups_id'=>7,'users_id'=>$id]);
        Session::$id=1;Session::$admin=true;Session::$entities=[0,1,2];Session::$visible=[101,102];Session::$interface='central';
        \GlpiPlugin\Pellissarinotification\Settings::reset();\GlpiPlugin\Pellissarinotification\Store::resetCaches();Toolbox::$logs=[];
    }
    fixture();
}
