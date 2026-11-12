<?php
declare(strict_types=1);
/** Destructive ONLY to a freshly generated test database. No production config read.
 * PHP tests/integration.php [host] [port] [user] [password]
 */
require dirname(__DIR__).'/app/Engine.php';
require dirname(__DIR__).'/app/bootstrap.php';
$host=$argv[1]??'127.0.0.1';$port=(int)($argv[2]??3306);$user=$argv[3]??'root';$pass=$argv[4]??'';
$dbName='sfnxt_test_'.bin2hex(random_bytes(5));
$db=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$dbName`");$db->exec("SET time_zone='+00:00'");$checks=0;
function check(bool $ok,string $text):void{global $checks;$checks++;if(!$ok)throw new RuntimeException('FAILED: '.$text);}
function scalar(string $sql):mixed{global $db;return $db->query($sql)->fetchColumn();}
function advance(Engine $engine,bool $deadlines=true):void{global $db;$db->exec("DELETE FROM settings WHERE name='engine_last_tick'");if($deadlines)$db->exec("UPDATE rooms SET starts_at='2000-01-01 00:00:00',next_turn_at='2000-01-01 00:00:00' WHERE status IN ('waiting','playing')");$engine->tick();}
function throws(callable $fn,string $label):void{$threw=false;try{$fn();}catch(RuntimeException $e){$threw=true;}check($threw,$label);}
try {
 $db->exec((string)file_get_contents(dirname(__DIR__).'/app/schema.sql'));
 $insert=$db->prepare('INSERT INTO users(username,is_bot,bot_number,personality,avatar) VALUES(?,1,?,?,?)');
 foreach(json_decode((string)file_get_contents(dirname(__DIR__).'/data/bots.json'),true,512,JSON_THROW_ON_ERROR) as $i=>$bot)$insert->execute([$bot['username'],$i+1,$bot['personality'],$bot['avatar']]);
 $db->exec("INSERT INTO users(username,password_hash) VALUES('HumanOne','test'),('HumanTwo','test')");
 $humanA=(int)scalar("SELECT id FROM users WHERE username='HumanOne'");$humanB=(int)scalar("SELECT id FROM users WHERE username='HumanTwo'");
 // Reproduce an existing v1 installation: older looks plus a played bot record.
 $db->exec('UPDATE users SET avatar=MOD(avatar,12) WHERE is_bot=1');
 $db->exec('UPDATE users SET rating=1234,points=321,wins=7,losses=3,games=10 WHERE bot_number=1');
 $db->exec('UPDATE users SET avatar=31 WHERE id='.$humanB);
 $preserved=$db->query('SELECT id,username,personality,rating,points,wins,losses,games FROM users ORDER BY id')->fetchAll();
 $engine=new Engine($db,['db_name'=>$dbName,'tick_interval'=>1,'max_rooms'=>180,'max_turns'=>60]);
 check($db->query('SELECT id,username,personality,rating,points,wins,losses,games FROM users ORDER BY id')->fetchAll()===$preserved,'cosmetic upgrade preserves accounts and every lifetime statistic');
 check((int)scalar('SELECT COUNT(DISTINCT avatar) FROM users WHERE is_bot=1')===32,'existing bots receive all32 roster looks');
 check((int)scalar('SELECT avatar FROM users WHERE id='.$humanB)===31,'upgrade preserves human avatar choice31');
 check((string)scalar("SELECT value FROM settings WHERE name='bot_roster_version'")===GameContent::BOT_ROSTER_VERSION,'cosmetic migration records version');
 // A subsequent process must not rewrite an already-upgraded bot cosmetic.
 $db->exec('UPDATE users SET avatar=31 WHERE bot_number=1');
 new Engine($db,['db_name'=>$dbName]);
 check((int)scalar('SELECT avatar FROM users WHERE bot_number=1')===31,'cosmetic migration is one-time and idempotent');
 // Reset only synthetic counters so the existing payout accounting tests start at zero.
 $db->exec('UPDATE users SET points=0,wins=0,losses=0,games=0 WHERE bot_number=1');
 $engine->tick();check((int)scalar('SELECT COUNT(*) FROM memberships')===150,'first bounded tick creates30 real bot lobbies');
 // Regression: over80 waiting rooms used to consume the whole bootstrap response,
 // making the Watch tab empty even when real matches were playing.
 $db->beginTransaction();
 try {
     $fixture=$db->prepare('INSERT INTO rooms(title,host_id,status,state,starts_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');
     for($i=0;$i<90;$i++)$fixture->execute(['Waiting crowd '.$i,$humanA,'waiting','{}']);
     for($i=0;$i<3;$i++)$fixture->execute(['Live regression '.$i,$humanA,'playing','{}']);
     $lobby=nxt_lobby_rooms($db);
     $waitingRows=array_values(array_filter($lobby,fn($r)=>$r['status']==='waiting'));
     $playingRows=array_values(array_filter($lobby,fn($r)=>$r['status']==='playing'));
     check(count($waitingRows)===40,'waiting lobby tab has independent40-room bound');
     check(count($playingRows)===3,'live fights remain visible with over80 waiting rooms');
     check(count($lobby)===43,'lobby response contains both statuses without unbounded results');
     check($playingRows[0]['title']==='Live regression 2','live tab lists newest match first');
     check(is_int($playingRows[0]['id'])&&is_int($playingRows[0]['count']),'lobby fields retain public integer types');
 } finally {$db->rollBack();}

 $botRoom=(int)scalar("SELECT id FROM rooms WHERE status='waiting' ORDER BY id LIMIT 1");
 $engine->joinRoom($humanA,$botRoom);check(count($engine->room($botRoom,$humanA)['players'])===6,'human can join bot game');
 $humanRoom=$engine->createRoom($humanB,'The Human Test Room');
 $initialRoom=$engine->room($humanRoom,$humanB);$arena=$initialRoom['arena_id'];
 check($arena>=1&&$arena<=10,'new human room chooses one of ten arenas');
 check($initialRoom['arena_image']==='assets/arenas/'.str_pad((string)$arena,2,'0',STR_PAD_LEFT).'.png','arena path matches stable content ID');
 check($initialRoom['state']['arena_id']===$arena,'selected arena is saved in authoritative room state');
 check($initialRoom['players'][0]['avatar']===31,'highest new avatar survives joining a match');
 check($engine->room($humanRoom,null)['arena_id']===$arena,'spectators see same arena as host');
 $newProcess=new Engine($db,['db_name'=>$dbName]);
 check($newProcess->room($humanRoom,$humanA)['arena_id']===$arena,'arena persists across new engine instances');
 advance($engine,false);
 check(count($engine->room($humanRoom,$humanB)['players'])===5,'bots join human-created game');
 $engine->startRoom($humanB,$humanRoom);$room=$engine->room($humanRoom,$humanB);
 check($room['status']==='playing'&&$room['turn']===1,'human host starts real match');
 check($room['arena_id']===$arena,'starting a match preserves its arena');
 $botArena=$engine->room($botRoom,null)['arena_id'];
 check($botArena>=1&&$botArena<=10,'bot-hosted matches also receive a new arena');
 // Old saved matches must remain readable and retain their original artwork.
 $legacy=json_decode((string)scalar("SELECT state FROM rooms WHERE id=$botRoom"),true);
 unset($legacy['arena_id']);
 $db->prepare('UPDATE rooms SET state=? WHERE id=?')->execute([json_encode($legacy),$botRoom]);
 $legacyRead=$engine->room($botRoom,null);
 check($legacyRead['arena_id']===0&&$legacyRead['arena_image']==='assets/arena.png','legacy match falls back without migration or data loss');
 foreach($room['players'] as $p)check(!array_key_exists('action',$p)&&!array_key_exists('target',$p),'public player does not leak sealed action');
 foreach($room['state']['players'] as $p)check(!array_key_exists('action',$p)&&!array_key_exists('target',$p),'nested state does not leak sealed action');
 $target=$room['players'][1]['id'];
 $before=json_decode((string)scalar("SELECT state FROM rooms WHERE id=$humanRoom"),true);
 throws(fn()=>$engine->submitAction($humanB,$humanRoom,'scratch',$humanB,1),'self-target rejected');
 throws(fn()=>$engine->submitAction($humanB,$humanRoom,'scratch',$target,0),'stale turn rejected');
 $engine->submitAction($humanB,$humanRoom,'scratch',$target,1);$room=$engine->room($humanRoom,$humanB);
 check($room['own_action']['action']==='scratch'&&$room['own_action']['target']===$target,'own chosen move visible');
 check($room['arena_id']===$arena,'submitting a move preserves arena');
 $after=json_decode((string)scalar("SELECT state FROM rooms WHERE id=$humanRoom"),true);
 foreach($before['players'] as $i=>$p)if($p['is_bot'])check($p['action']===$after['players'][$i]['action']&&$p['target']===$after['players'][$i]['target'],'bots do not react to sealed human choices');
 $db->exec("UPDATE rooms SET next_turn_at='2000-01-01' WHERE id=$humanRoom");
 throws(fn()=>$engine->submitAction($humanB,$humanRoom,'cower',null,1),'late move rejected');
 for($i=0;$i<8;$i++) { $db->exec("UPDATE rooms SET starts_at='2000-01-01' WHERE status='waiting'"); advance($engine,false); }
 check((int)scalar('SELECT COUNT(*) FROM memberships m JOIN users u ON u.id=m.user_id WHERE u.is_bot=1')===1000,'all1000 distinct bots assigned to real active games');
 check((int)scalar('SELECT COUNT(*) FROM users WHERE is_bot=1')===1000,'exact1000 persistent bot accounts');
 $began=microtime(true);
 for($i=0;$i<60;$i++)advance($engine);
 $elapsed=microtime(true)-$began;
 check((int)scalar('SELECT COUNT(*) FROM results')>=1000,'real match results persisted');
 check((int)scalar('SELECT COUNT(*) FROM users WHERE is_bot=1 AND games>0')===1000,'every bot completed at least one real match');
 check((int)scalar("SELECT COUNT(*) FROM results") === (int)scalar('SELECT SUM(games) FROM users'),'one stats increment per result');
 check((int)scalar("SELECT value FROM settings WHERE name='total_games'")===(int)scalar('SELECT COUNT(DISTINCT room_id) FROM results'),'lifetime match count agrees with completed matches');
 $finished=(int)scalar("SELECT id FROM rooms WHERE status='finished' ORDER BY id DESC LIMIT 1");
 $count=(int)scalar("SELECT COUNT(*) FROM results WHERE room_id=$finished");
 $engine->tick();$engine->tick();check((int)scalar("SELECT COUNT(*) FROM results WHERE room_id=$finished")===$count,'repeated ticks do not duplicate payouts');
 $rows=$db->query('SELECT state FROM rooms')->fetchAll();
 foreach($rows as $r)foreach(json_decode($r['state'],true)['players'] as $p){check($p['health']>=0&&$p['health']<=10,'health bounded');check($p['lollies']>=0&&$p['lollies']<=3,'lollies bounded');check($p['tattles']>=0&&$p['tattles']<=2,'tattles bounded');}
 // Already-eliminated humans may leave without losing their earned consolation points.
 $db->exec("INSERT INTO users(username) VALUES('ConsolationTest')");$consolation=(int)$db->lastInsertId();
 $testRoom=$engine->createRoom($consolation,'Consolation Test');advance($engine,false);$engine->startRoom($consolation,$testRoom);
 $state=json_decode((string)scalar("SELECT state FROM rooms WHERE id=$testRoom"),true);$state['players'][0]['alive']=false;$state['players'][0]['health']=0;
 $s=$db->prepare('UPDATE rooms SET state=? WHERE id=?');$s->execute([json_encode($state),$testRoom]);$engine->leaveRoom($consolation);
 $state=json_decode((string)scalar("SELECT state FROM rooms WHERE id=$testRoom"),true);check($state['players'][0]['left']===false,'eliminated departure keeps consolation eligibility');
 echo json_encode(['ok'=>true,'assertions'=>$checks,'bots_with_results'=>(int)scalar('SELECT COUNT(*) FROM users WHERE is_bot=1 AND games>0'),'completed_games'=>(int)scalar("SELECT value FROM settings WHERE name='total_games'"),'results'=>(int)scalar('SELECT COUNT(*) FROM results'),'chat_messages'=>(int)scalar('SELECT COUNT(*) FROM messages'),'active_rooms'=>(int)scalar("SELECT COUNT(*) FROM rooms WHERE status<>'finished'"),'accelerated_60_tick_seconds'=>round($elapsed,3)],JSON_PRETTY_PRINT)."\n";
} finally {$db->exec("DROP DATABASE `$dbName`");}
