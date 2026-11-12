<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function nxt_config(): array {
    static $config;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) throw new RuntimeException('NOT_INSTALLED');
        $config = require $path;
        if (!is_array($config)) throw new RuntimeException('INVALID_CONFIG');
    }
    return $config;
}
function nxt_db(): PDO {
    static $db;
    if ($db === null) {
        $c = nxt_config();
        $db = new PDO('mysql:host='.$c['db_host'].';port='.(int)$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4',
            $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $db->exec("SET time_zone = '+00:00'");
    }
    return $db;
}
function nxt_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    session_name('SFNXTSESSID');
    $path = str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($path === '.' || $path === '/') $path='/'; else $path=rtrim($path,'/').'/';
    session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
        'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function nxt_public_user(array $u): array {
    $out=[];
    foreach (['id','username','is_bot','bot_number','personality','avatar','rating','points','wins','losses','games'] as $k) {
        if (!array_key_exists($k,$u)) continue;
        $out[$k]=in_array($k,['username','personality'],true) ? $u[$k] : ($u[$k]===null ? null : (int)$u[$k]);
    }
    return $out;
}
function nxt_user(PDO $db): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $q=$db->prepare('SELECT * FROM users WHERE id=? AND is_bot=0');
    $q->execute([(int)$_SESSION['user_id']]);
    $u=$q->fetch();
    if (!$u) { unset($_SESSION['user_id']); return null; }
    return nxt_public_user($u);
}
function nxt_json(array $body,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
function nxt_setting(PDO $db,string $name,?string $value=null): ?string {
    if ($value!==null) {
        $q=$db->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $q->execute([$name,$value]); return $value;
    }
    $q=$db->prepare('SELECT value FROM settings WHERE name=?'); $q->execute([$name]);
    $v=$q->fetchColumn(); return $v===false?null:(string)$v;
}
function nxt_rate(PDO $db,string $scope,string $identity,int $limit,int $window): void {
    $c=nxt_config(); $key=hash_hmac('sha256',$scope.':'.$identity,$c['app_secret']); $now=time();
    $q=$db->prepare('INSERT INTO rate_limits(key_name,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at<=?,1,hits+1), expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)');
    $q->execute([$key,$now+$window,$now,$now]);
    $q=$db->prepare('SELECT hits,expires_at FROM rate_limits WHERE key_name=?');$q->execute([$key]);$row=$q->fetch();
    if ((int)$row['hits']>$limit) {
        header('Retry-After: '.max(1,(int)$row['expires_at']-$now));
        nxt_json(['ok'=>false,'error'=>'Easy, tiger. Please wait a moment and try again.'],429);
    }
    if (random_int(1,100)===1) $db->exec('DELETE FROM rate_limits WHERE expires_at < '.($now-3600).' LIMIT 1000');
}
function nxt_messages(PDO $db,?int $roomId,int $limit=60): array {
    $sql='SELECT m.id,m.body,m.created_at,m.user_id,u.username,u.is_bot,u.bot_number,u.avatar FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE '.($roomId===null?'m.room_id IS NULL':'m.room_id=?').' ORDER BY m.id DESC LIMIT '.max(1,min(100,$limit));
    $q=$db->prepare($sql);$q->execute($roomId===null?[]:[$roomId]);
    $rows=array_reverse($q->fetchAll());
    foreach ($rows as &$r) {
        $r['id']=(int)$r['id'];$r['user_id']=$r['user_id']===null?null:(int)$r['user_id'];
        $r['is_bot']=(int)($r['is_bot']??0);$r['bot_number']=$r['bot_number']===null?null:(int)$r['bot_number'];
        $r['username']=$r['username']??'Playground PA';$r['avatar']=(int)($r['avatar']??0);
    } unset($r);
    return $rows;
}

/** Bound each lobby tab independently so waiting rooms cannot crowd out live fights. */
function nxt_lobby_rooms(PDO $db): array {
    $query=$db->prepare("SELECT r.id,r.title,r.status,r.turn,r.starts_at,u.username host_name,u.is_bot host_bot,u.bot_number AS host_bot_number,COUNT(m.user_id) count FROM rooms r JOIN users u ON u.id=r.host_id LEFT JOIN memberships m ON m.room_id=r.id WHERE r.status=? GROUP BY r.id,r.title,r.status,r.turn,r.starts_at,u.username,u.is_bot,u.bot_number ORDER BY r.id DESC LIMIT 40");
    $rooms=[];
    foreach (['waiting','playing'] as $status) {
        $query->execute([$status]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $room) {
            foreach (['id','turn','host_bot','count'] as $key) $room[$key]=(int)$room[$key];
            $room['host_bot_number']=$room['host_bot_number']===null?null:(int)$room['host_bot_number'];
            $rooms[]=$room;
        }
    }
    return $rooms;
}
