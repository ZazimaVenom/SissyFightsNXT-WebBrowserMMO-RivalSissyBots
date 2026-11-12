<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Command line only.');}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Engine.php';
set_time_limit(0);
$lock=null;$db=null;$lockName='';
try {
    $config=nxt_config();$db=nxt_db();
    $arg=$argv[1]??'';
    if ($arg==='--stop') {
        nxt_setting($db,'worker_stop','1');
        echo "Sissy Fights NXT: clean stop requested. The worker will stop after its current tick.\n";exit(0);
    }
    if ($arg==='--once') {(new Engine($db,$config))->tick();echo "Sissy Fights NXT: one tick complete.\n";exit(0);}
    if ($arg!=='' && $arg!=='--run') {fwrite(STDERR,"Usage: php bin/worker.php [--run|--once|--stop]\n");exit(2);}
    $lock=fopen(__DIR__.'/worker.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('A Sissy Fights NXT bot worker is already running from this folder.');
    $lockName='sfnxt-worker:'.substr(hash('sha256',(string)$config['db_name']),0,48);
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('A bot worker already runs for this database. Use its existing window.');
    nxt_setting($db,'worker_stop','0');
    $stop=false;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM,static function()use(&$stop):void{$stop=true;});
        pcntl_signal(SIGINT,static function()use(&$stop):void{$stop=true;});
    }
    echo "SISSY FIGHTS NXT — PLAYERBOT COMMUNITY\n";
    echo "1,000 persistent Sissy Bots / MySQL database: ".$config['db_name']."\n";
    echo "Keep this window open while the community is running.\n";
    echo "To stop cleanly, run stop-bots.cmd (or php bin/worker.php --stop).\n\n";
    $engine=new Engine($db,$config);$lastReport=0;
    while (!$stop) {
        if (nxt_setting($db,'worker_stop')==='1') break;
        nxt_setting($db,'worker_heartbeat',(string)time());
        $started=microtime(true);$engine->tick();
        nxt_setting($db,'worker_heartbeat',(string)time());
        if (time()-$lastReport>=30) {
            $counts=$db->query("SELECT status,COUNT(*) n FROM rooms WHERE status IN ('waiting','playing') GROUP BY status")->fetchAll();
            $rooms=[];foreach($counts as $row)$rooms[]=$row['n'].' '.$row['status'];
            $active=(int)$db->query('SELECT COUNT(*) FROM memberships m JOIN users u ON u.id=m.user_id WHERE u.is_bot=1')->fetchColumn();
            echo gmdate('Y-m-d H:i:s')." UTC | ".$active." bots in games | ".implode(', ',$rooms)." rooms | tick ".round((microtime(true)-$started)*1000)." ms\n";$lastReport=time();
        }
        usleep(500000);
    }
    nxt_setting($db,'worker_heartbeat','0');
    echo "Sissy Fights NXT: worker stopped cleanly. Games and ratings remain saved.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Sissy Fights NXT worker could not continue: ".$e->getMessage()."\n");
    fwrite(STDERR,"Check Apache/MySQL in XAMPP, run the local installer, then start again.\n");
    exit(1);
} finally {
    if ($db instanceof PDO && $lockName!=='') {try{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}catch(Throwable $ignored){}}
    if (is_resource($lock)) {flock($lock,LOCK_UN);fclose($lock);}
}
