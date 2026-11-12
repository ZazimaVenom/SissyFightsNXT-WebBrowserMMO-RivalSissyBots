<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

try {
    nxt_session();
    if (!is_file(__DIR__.'/app/config.php')) nxt_json(['ok'=>false,'needs_install'=>true,'error'=>'Run the local installer first.'],503);
    $db=nxt_db(); $config=nxt_config();
    $action=(string)($_GET['action']??'bootstrap');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $reads=['bootstrap','room','ladder','community','profile'];
    $writes=['register','login','logout','create','join','leave','start','move','chat','profile'];
    if (($method==='GET' && !in_array($action,$reads,true)) || ($method==='POST' && !in_array($action,$writes,true)) || !in_array($method,['GET','POST'],true)) {
        nxt_json(['ok'=>false,'error'=>'That action is not available.'],405);
    }
    $csrf=(string)$_SESSION['csrf'];
    $body=[];
    if ($method==='POST') {
        if (!hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) nxt_json(['ok'=>false,'error'=>'Your session changed. Refresh the page and try again.'],403);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) nxt_json(['ok'=>false,'error'=>'Request too large.'],413);
        $raw=file_get_contents('php://input',false,null,0,8193);
        if ($raw===false || strlen($raw)>8192) nxt_json(['ok'=>false,'error'=>'Request too large.'],413);
        try {$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch (JsonException $e) {nxt_json(['ok'=>false,'error'=>'Invalid request data.'],400);}
        if (!is_array($body)) nxt_json(['ok'=>false,'error'=>'Invalid request data.'],400);
    }
    $me=nxt_user($db);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    if ($method==='POST' && !in_array($action,['register','login'],true) && !$me) nxt_json(['ok'=>false,'error'=>'Sign in to enter the playground.'],401);
    if ($me && (int)($_SESSION['seen_at']??0)<time()-30) {
        $db->prepare('UPDATE users SET last_seen=UTC_TIMESTAMP() WHERE id=?')->execute([$me['id']]);$_SESSION['seen_at']=time();
    }
    if (!in_array($action,['register','login','logout'],true)) session_write_close();
    require_once __DIR__.'/app/Engine.php';
    $engine=new Engine($db,$config);
    // A real worker keeps the community active when nobody browses. This bounded
    // fallback also makes a plain Apache/PHP installation immediately playable.
    if ($method==='GET' && (int)(nxt_setting($db,'worker_heartbeat')??0)<time()-6) $engine->tick();

    if ($method==='POST' && in_array($action,['register','login'],true)) {
        nxt_rate($db,'auth',$ip,20,300);
        $username=trim(is_string($body['username']??null)?$body['username']:'');
        $password=is_string($body['password']??null)?$body['password']:'';
        if ($action==='register') {
            nxt_rate($db,'register',$ip,5,3600);
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,23}\z/',$username)) nxt_json(['ok'=>false,'error'=>'Use 3–24 letters, numbers, dots, dashes or underscores for your name.'],400);
            if (strlen($password)<8 || strlen($password)>72) nxt_json(['ok'=>false,'error'=>'Your password must be 8–72 bytes long.'],400);
            $avatar=filter_var($body['avatar']??0,FILTER_VALIDATE_INT);
            if ($avatar===false || $avatar<0 || $avatar>=GameContent::AVATAR_COUNT) nxt_json(['ok'=>false,'error'=>'Choose one of the 32 fighter portraits.'],400);
            try {
                $q=$db->prepare('INSERT INTO users(username,password_hash,avatar,personality,created_at,last_seen) VALUES(?,?,?,\'human\',UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                $q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$avatar]);
            } catch (PDOException $e) {
                if ($e->getCode()==='23000') nxt_json(['ok'=>false,'error'=>'That name is already causing trouble. Pick another.'],409);
                throw $e;
            }
            $id=(int)$db->lastInsertId();
        } else {
            $q=$db->prepare('SELECT id,password_hash,is_bot FROM users WHERE username=?');$q->execute([$username]);$row=$q->fetch();
            $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $verified=password_verify($password,$row && !$row['is_bot'] && $row['password_hash']?$row['password_hash']:$dummy);
            if (!$row || (int)$row['is_bot']===1 || !$row['password_hash'] || !$verified) nxt_json(['ok'=>false,'error'=>'Name or password did not match.'],401);
            $id=(int)$row['id'];
            if (password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT)) $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        }
        session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['seen_at']=time();
        $db->prepare('UPDATE users SET last_seen=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        nxt_json(['ok'=>true,'me'=>nxt_user($db),'csrf'=>$csrf]);
    }
    if ($method==='POST' && $action==='logout') {
        // Leaving a live match is handled by the game engine and cannot award a win.
        $engine->leaveRoom((int)$me['id']);
        $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
        nxt_json(['ok'=>true,'csrf'=>$_SESSION['csrf'],'me'=>null]);
    }
    if ($method==='POST') {
        nxt_rate($db,'play',(string)$me['id'],100,60);
        $roomId=filter_var($body['room_id']??0,FILTER_VALIDATE_INT);
        $uid=(int)$me['id'];
        if (in_array($action,['join','start','move'],true) && (!$roomId || $roomId<1)) nxt_json(['ok'=>false,'error'=>'Choose a valid game.'],400);
        switch ($action) {
            case 'create':
                nxt_rate($db,'create',(string)$uid,8,60);
                $title=trim(is_string($body['title']??null)?$body['title']:'');
                $title=preg_replace('/[\x00-\x1F\x7F]/u','',$title)??'';
                if ($title==='' || preg_match_all('/./us',$title)>50) nxt_json(['ok'=>false,'error'=>'Give your game a name of 1–50 characters.'],400);
                $id=$engine->createRoom($uid,$title);nxt_json(['ok'=>true,'room_id'=>$id]);
            case 'join': $engine->joinRoom($uid,(int)$roomId);nxt_json(['ok'=>true,'room_id'=>(int)$roomId]);
            case 'leave': $engine->leaveRoom($uid);nxt_json(['ok'=>true]);
            case 'start': $engine->startRoom($uid,(int)$roomId);nxt_json(['ok'=>true]);
            case 'move':
                $move=is_string($body['move']??null)?$body['move']:'';
                $target=isset($body['target'])?filter_var($body['target'],FILTER_VALIDATE_INT):null;
                $turn=filter_var($body['turn']??-1,FILTER_VALIDATE_INT);
                if (!in_array($move,['scratch','grab','tease','cower','lick','tattle'],true) || $turn===false || $turn<1 || $target===false) nxt_json(['ok'=>false,'error'=>'Choose a valid move for this round.'],400);
                $engine->submitAction($uid,(int)$roomId,$move,$target===null?null:(int)$target,(int)$turn);nxt_json(['ok'=>true]);
            case 'chat':
                nxt_rate($db,'chat',(string)$uid,1,2);
                $message=trim(is_string($body['body']??null)?$body['body']:'');
                $message=preg_replace('/[\x00-\x1F\x7F]/u',' ',$message)??'';
                $length=preg_match_all('/./us',$message);
                if ($message==='' || $length===false || $length>240) nxt_json(['ok'=>false,'error'=>'Chat needs 1–240 characters.'],400);
                $rid=isset($body['room_id'])?(int)$body['room_id']:null;
                if ($rid!==null) {
                    $q=$db->prepare('SELECT room_id FROM memberships WHERE user_id=? AND room_id=?');$q->execute([$uid,$rid]);
                    if (!$q->fetchColumn()) nxt_json(['ok'=>false,'error'=>'Join that game before chatting in it.'],403);
                }
                $db->prepare('INSERT INTO messages(room_id,user_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$rid,$uid,$message]);nxt_json(['ok'=>true]);
            case 'profile':
                $avatar=filter_var($body['avatar']??-1,FILTER_VALIDATE_INT);
                if ($avatar===false || $avatar<0 || $avatar>=GameContent::AVATAR_COUNT) nxt_json(['ok'=>false,'error'=>'Choose one of the 32 fighter portraits.'],400);
                $q=$db->prepare('SELECT room_id FROM memberships WHERE user_id=?');$q->execute([$uid]);
                if ($q->fetchColumn()) nxt_json(['ok'=>false,'error'=>'Change your portrait between games.'],409);
                $db->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$avatar,$uid]);$me['avatar']=$avatar;nxt_json(['ok'=>true,'me'=>$me]);
        }
    }
    if ($action==='room') {
        $id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT);
        if (!$id || $id<1) nxt_json(['ok'=>false,'error'=>'Game not found.'],404);
        nxt_json(['ok'=>true,'room'=>$engine->room((int)$id,$me?(int)$me['id']:null),'messages'=>nxt_messages($db,(int)$id)]);
    }
    $columns='u.id,u.username,u.is_bot,u.bot_number,u.personality,u.avatar,u.rating,u.points,u.wins,u.losses,u.games';
    if ($action==='ladder' || $action==='community') {
        $page=max(1,min(10000,(int)($_GET['page']??1)));$size=40;$offset=($page-1)*$size;
        $query=is_string($_GET['q']??null)?trim($_GET['q']):'';$query=substr($query,0,64);
        $where=$action==='community'?' WHERE u.is_bot=1':'';
        if ($query!=='') $where.=($where!==''?' AND':' WHERE').' u.username LIKE ?';
        $args=$query!==''?['%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query).'%']:[];
        $q=$db->prepare('SELECT COUNT(*) FROM users u'.$where);$q->execute($args);$total=(int)$q->fetchColumn();
        $rankColumn=$action==='ladder' && $query!=='' ? ',(SELECT COUNT(*)+1 FROM users ranked WHERE ranked.rating>u.rating OR (ranked.rating=u.rating AND ranked.wins>u.wins) OR (ranked.rating=u.rating AND ranked.wins=u.wins AND ranked.id<u.id)) AS global_rank' : '';
        $sql='SELECT '.$columns.$rankColumn.($action==='community'?',r.status AS room_status,m.room_id,u.last_seen':'').' FROM users u'.($action==='community'?' LEFT JOIN memberships m ON m.user_id=u.id LEFT JOIN rooms r ON r.id=m.room_id':'').$where.' ORDER BY '.($action==='ladder'?'u.rating DESC,u.wins DESC,u.id ASC':'u.is_bot DESC,u.bot_number ASC,u.id ASC').' LIMIT '.$size.' OFFSET '.$offset;
        $q=$db->prepare($sql);$q->execute($args);$players=[];
        foreach ($q->fetchAll() as $i=>$r) {
            $u=nxt_public_user($r);$u['rank']=isset($r['global_rank'])?(int)$r['global_rank']:$offset+$i+1;
            if ($action==='community') {$u['status']=$r['room_status']??((int)$r['is_bot']===1?'ready':'offline');$u['room_id']=$r['room_id']===null?null:(int)$r['room_id'];}
            $players[]=$u;
        }
        nxt_json(['ok'=>true,'players'=>$players,'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
    if ($action==='profile') {
        $id=(int)($_GET['id']??($me['id']??0));
        $q=$db->prepare('SELECT '.$columns.' FROM users u WHERE u.id=?');$q->execute([$id]);$row=$q->fetch();
        if (!$row) nxt_json(['ok'=>false,'error'=>'Fighter not found.'],404);
        $q=$db->prepare('SELECT res.room_id,res.won,res.rating_delta,res.points,res.created_at,EXISTS(SELECT 1 FROM rooms retained WHERE retained.id=res.room_id) AS room_exists FROM results res WHERE res.user_id=? ORDER BY res.id DESC LIMIT 20');$q->execute([$id]);
        $results=$q->fetchAll();
        foreach ($results as &$result) $result['room_exists']=(bool)$result['room_exists'];unset($result);
        nxt_json(['ok'=>true,'player'=>nxt_public_user($row),'results'=>$results]);
    }
    $stats=['bots'=>(int)$db->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn(),
        'total_games'=>(int)(nxt_setting($db,'total_games')??0),'playing'=>0,'waiting'=>0,
        'worker_age'=>null];
    foreach ($db->query("SELECT status,COUNT(*) n FROM rooms WHERE status IN ('playing','waiting') GROUP BY status") as $r) $stats[$r['status']]=(int)$r['n'];
    $heartbeat=(int)(nxt_setting($db,'worker_heartbeat')??0);$stats['worker_age']=$heartbeat?max(0,time()-$heartbeat):null;
    $stats['active_bots']=(int)$db->query('SELECT COUNT(*) FROM memberships m JOIN users u ON u.id=m.user_id WHERE u.is_bot=1')->fetchColumn();
    $rooms=nxt_lobby_rooms($db);
    $ladder=array_map('nxt_public_user',$db->query('SELECT '.$columns.' FROM users u ORDER BY u.rating DESC,u.wins DESC,u.id ASC LIMIT 8')->fetchAll());
    $myRoom=null;if ($me) {$q=$db->prepare('SELECT room_id FROM memberships WHERE user_id=?');$q->execute([$me['id']]);$v=$q->fetchColumn();$myRoom=$v===false?null:(int)$v;}
    nxt_json(['ok'=>true,'csrf'=>$csrf,'me'=>$me,'stats'=>$stats,'rooms'=>$rooms,'ladder'=>$ladder,'messages'=>nxt_messages($db,null,40),'my_room'=>$myRoom,'server_time'=>time()]);
} catch (PDOException $e) {
    error_log('Sissy Fights NXT database failure: '.$e->getMessage());
    nxt_json(['ok'=>false,'error'=>'Database unavailable. Check that MySQL is running in XAMPP and app/config.php has the correct settings.'],503);
} catch (InvalidArgumentException|DomainException $e) {
    nxt_json(['ok'=>false,'error'=>$e->getMessage()],400);
} catch (RuntimeException $e) {
    $message=$e->getMessage();
    if ($message==='NOT_INSTALLED') nxt_json(['ok'=>false,'needs_install'=>true,'error'=>'Run the local installer first.'],503);
    // Engine validation errors are human-readable, while unexpected errors are logged.
    if (strlen($message)<220 && !preg_match('/SQLSTATE|password|mysql:|\.php|stack trace/i',$message)) nxt_json(['ok'=>false,'error'=>$message],409);
    error_log('Sissy Fights NXT runtime failure: '.$message);
    nxt_json(['ok'=>false,'error'=>'The playground hit a snag. Check the PHP error log.'],500);
} catch (Throwable $e) {
    error_log('Sissy Fights NXT unexpected failure: '.$e->getMessage());
    nxt_json(['ok'=>false,'error'=>'The playground hit a snag. Check the PHP error log.'],500);
}
