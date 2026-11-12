<?php
declare(strict_types=1);
require_once __DIR__.'/Content.php';

/** Sissy Fights NXT — authoritative PHP game and population scheduler.
 * Classic combat adapted from SiSSYFiGHT, ©2015 Ranjit Bhatnagar,
 * Naomi Clark and Eric Zimmerman (MIT; see docs/ORIGINAL-LICENSES.md).
 */
final class Engine
{
    private PDO $db;
    private array $cfg;
    private string $lock;
    private static ?array $banter = null;
    public const MOVES = ['scratch', 'grab', 'tease', 'cower', 'lick', 'tattle'];

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->cfg = $config + ['turn_seconds'=>30,'bot_turn_seconds'=>10,'lobby_seconds'=>35,'max_rooms'=>180,'tick_interval'=>2,'bot_chat_chance'=>0.12,'max_turns'=>60];
        $this->lock = 'sfnxt:'.substr((string)($config['db_name'] ?? 'sissy_fights_nxt'), 0, 56);
        $this->upgradeBotLooks();
    }

    /** Idempotent cosmetic upgrade. Never resets humans, bot identities, records or match snapshots. */
    private function upgradeBotLooks(): void
    {
        $readVersion = fn() => $this->query("SELECT value FROM settings WHERE name='bot_roster_version'")->fetchColumn();
        if ((string)$readVersion() === GameContent::BOT_ROSTER_VERSION) return;
        $this->locked(function() use ($readVersion) {
            // A second worker may have finished the upgrade while this process waited.
            if ((string)$readVersion() === GameContent::BOT_ROSTER_VERSION) return;
            $this->query('UPDATE users SET avatar=MOD((bot_number-1)*13+7, '.GameContent::AVATAR_COUNT.') WHERE is_bot=1 AND bot_number>=1');
            $this->setting('bot_roster_version', GameContent::BOT_ROSTER_VERSION);
        });
    }

    /** Serialize room commands and scheduler commits across every PHP process. */
    private function locked(callable $fn, int $wait = 5): mixed
    {
        $s=$this->db->prepare('SELECT GET_LOCK(?, ?)'); $s->execute([$this->lock,$wait]);
        if ((int)$s->fetchColumn() !== 1) {
            if ($wait===0) return null;
            throw new RuntimeException('The playground is busy. Please try again.');
        }
        try {
            $this->db->beginTransaction();
            $result=$fn();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$this->lock]);
        }
    }
    private function query(string $sql, array $args=[]): PDOStatement
    { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private function setting(string $name, mixed $value): void
    { $this->query('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$name,(string)$value]); }
    private function state(array $room): array
    { return json_decode($room['state'],true,512,JSON_THROW_ON_ERROR); }
    private function writeState(int $id, array $state): void
    {
        $state['log']=array_slice($state['log']??[], -90);
        $this->query('UPDATE rooms SET state=? WHERE id=?',[json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
    }
    private function getRoom(int $id): array
    {
        $r=$this->query('SELECT * FROM rooms WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('That game could not be found.');
        return $r;
    }
    private function getUser(int $id): array
    {
        $u=$this->query('SELECT id,username,is_bot,bot_number,avatar,personality,rating FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new RuntimeException('Player not found.');
        return $u;
    }
    private function player(array $u): array
    {
        return ['id'=>(int)$u['id'],'username'=>$u['username'],'is_bot'=>(bool)$u['is_bot'],
            'bot_number'=>isset($u['bot_number'])?(int)$u['bot_number']:null,'avatar'=>(int)$u['avatar'],
            'personality'=>$u['personality']??'chaotic','rating'=>(int)$u['rating'],
            'health'=>10,'lollies'=>3,'tattles'=>2,'cowardice'=>0,'action'=>null,'target'=>null,
            'alive'=>true,'left'=>false,'last_attacker'=>null,'last_move'=>null];
    }
    private function membership(int $userId): int
    { return (int)$this->query('SELECT room_id FROM memberships WHERE user_id=?',[$userId])->fetchColumn(); }
    private function message(?int $roomId, ?int $userId, string $body): void
    { $this->query('INSERT INTO messages(room_id,user_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$roomId,$userId,substr($body,0,300)]); }

    public function createRoom(int $userId, string $title): int
    {
        return $this->locked(function() use($userId,$title) {
            if ($this->membership($userId)) throw new RuntimeException('Leave your current game before creating another.');
            $active=(int)$this->query("SELECT COUNT(*) FROM rooms WHERE status IN ('waiting','playing')")->fetchColumn();
            if($active>=max(10,(int)$this->cfg['max_rooms'])+20) throw new RuntimeException('The playground is full. Please join an open game.');
            $title=trim($title);
            if ($title==='' || strlen($title)>64 || preg_match('/[\x00-\x1F\x7F]/',$title)) throw new RuntimeException('Use a game name of 1–64 characters.');
            return $this->createInternal($this->getUser($userId),$title);
        });
    }
    private function createInternal(array $host,string $title): int
    {
        $state=['arena_id'=>random_int(1,GameContent::ARENA_COUNT),'players'=>[$this->player($host)],'log'=>[['turn'=>0,'text'=>'The game is open. Choose your enemies wisely.']], 'winners'=>[]];
        $delay=max(15,(int)$this->cfg['lobby_seconds']);
        if (!empty($host['is_bot'])) $delay+=random_int(0,20);
        $this->query('INSERT INTO rooms(title,host_id,status,turn,state,created_at,starts_at) VALUES(?,?,\'waiting\',0,?,UTC_TIMESTAMP(),?)',
            [$title,$host['id'],json_encode($state,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',time()+$delay)]);
        $id=(int)$this->db->lastInsertId();
        $this->query('INSERT INTO memberships(user_id,room_id) VALUES(?,?)',[$host['id'],$id]);
        if (!empty($host['is_bot'])) $this->query('UPDATE users SET last_seen=UTC_TIMESTAMP() WHERE id=?',[$host['id']]);
        return $id;
    }
    public function joinRoom(int $userId,int $roomId): void
    {
        $this->locked(function() use($userId,$roomId) {
            $current=$this->membership($userId);
            if ($current===$roomId) return;
            if ($current) throw new RuntimeException('Leave your current game first.');
            $room=$this->getRoom($roomId);$state=$this->state($room);
            if ($room['status']!=='waiting') throw new RuntimeException('That fight has already started. Join an open game.');
            if (count($state['players'])>=6) throw new RuntimeException('That game is full.');
            $this->addPlayer($roomId,$state,$this->getUser($userId));
            $this->writeState($roomId,$state);
        });
    }
    private function addPlayer(int $roomId,array &$state,array $u): void
    {
        $this->query('INSERT INTO memberships(user_id,room_id) VALUES(?,?)',[$u['id'],$roomId]);
        $state['players'][]=$this->player($u);
        $state['log'][]=['turn'=>0,'text'=>$u['username'].' joined the game.'];
        if (!empty($u['is_bot'])) $this->query('UPDATE users SET last_seen=UTC_TIMESTAMP() WHERE id=?',[$u['id']]);
    }
    public function startRoom(int $userId,int $roomId): void
    {
        $this->locked(function() use($userId,$roomId) {
            $room=$this->getRoom($roomId);
            if ((int)$room['host_id']!==$userId) throw new RuntimeException('Only the host can start this game.');
            if ($room['status']!=='waiting') throw new RuntimeException('This game has already started.');
            $state=$this->state($room);
            if (count($state['players'])<3) throw new RuntimeException('At least 3 players are needed. The bots will join shortly.');
            $this->startInternal($room,$state);
        });
    }
    private function duration(array $state): int
    {
        foreach($state['players'] as $p) if (!$p['is_bot'] && $p['alive'] && !$p['left']) return max(15,(int)$this->cfg['turn_seconds']);
        return max(3,(int)$this->cfg['bot_turn_seconds']);
    }
    private function startInternal(array $room,array $state): void
    {
        $state['log'][]=['turn'=>1,'text'=>'Fight! Everyone chooses in secret. Moves resolve together.'];
        $this->chooseBots($state);
        $this->query('UPDATE rooms SET status=\'playing\',turn=1,next_turn_at=? WHERE id=?',
            [gmdate('Y-m-d H:i:s',time()+$this->duration($state)),$room['id']]);
        $this->writeState((int)$room['id'],$state);
    }
    public function submitAction(int $userId,int $roomId,string $action,?int $target,int $turn): void
    {
        $this->locked(function() use($userId,$roomId,$action,$target,$turn) {
            $room=$this->getRoom($roomId);
            if ($room['status']!=='playing' || (int)$room['turn']!==$turn) throw new RuntimeException('The turn changed. Refresh your game and choose again.');
            if (strtotime($room['next_turn_at'].' UTC')<=time()) throw new RuntimeException('That turn has closed. Your next turn will appear shortly.');
            if (!in_array($action,self::MOVES,true)) throw new RuntimeException('Choose a valid move.');
            $state=$this->state($room);$index=null;
            foreach($state['players'] as $i=>$p) if ($p['id']===$userId) $index=$i;
            if ($index===null || !$state['players'][$index]['alive'] || $state['players'][$index]['left']) throw new RuntimeException('You are not an active fighter in this game.');
            $p=&$state['players'][$index];
            if ($action==='lick' && $p['lollies']<=0) throw new RuntimeException('You have no lollipops left.');
            if ($action==='tattle' && $p['tattles']<=0) throw new RuntimeException('You have no tattles left.');
            if (in_array($action,['scratch','grab','tease'],true)) {
                $valid=false;
                foreach($state['players'] as $other) if ($other['id']===$target && $other['id']!==$userId && $other['alive'] && !$other['left']) $valid=true;
                if (!$valid) throw new RuntimeException('Choose another active fighter as your target.');
            } else $target=null;
            $p['action']=$action;$p['target']=$target;unset($p);
            $this->writeState($roomId,$state);
            // Human submissions never change bot decisions; their actions were committed at turn start.
            $ready=true;
            foreach($state['players'] as $other) if($other['alive'] && !$other['left'] && !$other['action']) $ready=false;
            if ($ready) $this->query('UPDATE rooms SET next_turn_at=LEAST(next_turn_at,?) WHERE id=?',[gmdate('Y-m-d H:i:s',time()+3),$roomId]);
        });
    }
    public function leaveRoom(int $userId): void
    {
        $this->locked(function() use($userId) {
            $id=$this->membership($userId);
            if (!$id) return;
            $room=$this->getRoom($id);$state=$this->state($room);
            foreach($state['players'] as $i=>&$p) if ($p['id']===$userId) {
                $state['log'][]=['turn'=>(int)$room['turn'],'text'=>$p['username'].' left the game.'];
                if ($room['status']==='waiting') unset($state['players'][$i]);
                elseif ($p['alive']) { $p['left']=true;$p['alive']=false;$p['health']=0;$p['action']=null;$p['target']=null; }
                break;
            }
            unset($p);$state['players']=array_values($state['players']);
            $this->query('DELETE FROM memberships WHERE user_id=?',[$userId]);
            if (!$state['players']) {
                $this->query('UPDATE rooms SET status=\'finished\',finished_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
            } elseif($room['status']==='playing') {
                $alive=array_values(array_filter($state['players'],fn($p)=>$p['alive']&&!$p['left']));
                if(count($alive)<=2) { $state['winners']=array_column($alive,'id');$this->finish($room,$state);return; }
            }
            if ((int)$room['host_id']===$userId && $state['players']) $this->query('UPDATE rooms SET host_id=? WHERE id=?',[$state['players'][0]['id'],$id]);
            $this->writeState($id,$state);
        });
    }

    /** One bounded scheduler pass. Safe from browser polling and a dedicated CLI worker. */
    public function tick(): void
    {
        $this->locked(function() {
            $now=time();
            $last=(int)$this->query('SELECT value FROM settings WHERE name=\'engine_last_tick\'')->fetchColumn();
            if($now-$last<max(1,(int)$this->cfg['tick_interval'])) return;
            $this->setting('engine_last_tick',$now);
            $rooms=$this->query('SELECT * FROM rooms WHERE status IN (\'waiting\',\'playing\') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $idle=$this->query('SELECT u.id,u.username,u.is_bot,u.bot_number,u.avatar,u.personality,u.rating FROM users u LEFT JOIN memberships m ON m.user_id=u.id WHERE u.is_bot=1 AND m.user_id IS NULL ORDER BY COALESCE(u.last_seen,\'1970-01-01\'),u.id LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
            $cursor=0;$live=0;$waiting=0;
            foreach($rooms as $room) {
                $state=$this->state($room);$id=(int)$room['id'];
                if($room['status']==='playing') {
                    if(strtotime($room['next_turn_at'].' UTC')<=$now) {
                        $resolved=self::resolve($state['players']);
                        $state['players']=$resolved['players'];
                        foreach($resolved['log'] as $text) $state['log'][]=['turn'=>(int)$room['turn'],'text'=>$text];
                        $state['winners']=$resolved['winners'];
                        $alive=array_values(array_filter($state['players'],fn($p)=>$p['alive']&&!$p['left']));
                        if(count($alive)<=2 || (int)$room['turn']>=max(10,(int)$this->cfg['max_turns'])) {
                            if(count($alive)>2) {
                                // Sudden-death time limit uses public esteem; ties share the same placing.
                                $top=max(array_column($alive,'health'));
                                $state['winners']=array_column(array_filter($alive,fn($p)=>$p['health']===$top),'id');
                                $state['log'][]=['turn'=>(int)$room['turn'],'text'=>'Time limit: the highest remaining self-esteem wins. Ties share the win.'];
                            }
                            $this->finish($room,$state);continue;
                        }
                        $this->chooseBots($state);
                        $this->query('UPDATE rooms SET turn=turn+1,next_turn_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',$now+$this->duration($state)),$id]);
                        $this->writeState($id,$state);
                        $this->banter($id,$state);
                    }
                    $live++;continue;
                }
                $due=strtotime($room['starts_at'].' UTC')<=$now;
                // Leave a seat for a visitor during the advertised lobby countdown.
                $limit=$due?6:5;
                while(count($state['players'])<$limit && isset($idle[$cursor])) $this->addPlayer($id,$state,$idle[$cursor++]);
                if($due && count($state['players'])>=3) $this->startInternal($room,$state);
                else {$this->writeState($id,$state);$waiting++;}
                $live++;
            }
            $names=['The Glitter Gutter','Dial-Up Disaster','The Salt Mine','Lunch Money Laundering','Dumpster Divas','The Petty Committee','No Refunds, Loser','Drama.exe','The Snark Arcade','Floppy Disk Fight Club','Certified Hot Mess','Maximum Nonsense','The Trash Palace','Lollypop Lockdown','Recess Rejects','Busted Tiara Club','The Burn Ward','Emotional Damage Dept','Clown Car Collision','The Grudge Garage'];
            $created=0;
            while(isset($idle[$cursor]) && $live<max(10,(int)$this->cfg['max_rooms']) && $created<30) {
                $host=$idle[$cursor++];
                $title=$names[random_int(0,count($names)-1)].' #'.$host['bot_number'];
                $id=$this->createInternal($host,$title);$room=$this->getRoom($id);$state=$this->state($room);
                while(count($state['players'])<5 && isset($idle[$cursor])) $this->addPlayer($id,$state,$idle[$cursor++]);
                $this->writeState($id,$state);$created++;$live++;$waiting++;
            }
            // At the population ceiling, use spare sixth seats while retaining twenty
            // joinable lobbies. This seats all 1,000 bots during a cold-start wave.
            if(isset($idle[$cursor]) && $live>=max(10,(int)$this->cfg['max_rooms'])) {
                $reserve=20;
                foreach($this->query("SELECT * FROM rooms WHERE status='waiting' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $open) {
                    $st=$this->state($open);
                    if(count($st['players'])>=6)continue;
                    if($reserve>0){$reserve--;continue;}
                    while(count($st['players'])<6 && isset($idle[$cursor]))$this->addPlayer((int)$open['id'],$st,$idle[$cursor++]);
                    $this->writeState((int)$open['id'],$st);
                    if(!isset($idle[$cursor]))break;
                }
            }
            // Lobby chatter is real persisted chat from known bot accounts, not an invented online count.
            if(random_int(1,8)===1) {
                $p=$this->query('SELECT id FROM users WHERE is_bot=1 ORDER BY id LIMIT 1 OFFSET '.random_int(0,999))->fetchColumn();
                if($p) $this->message(null,(int)$p,$this->line());
            }
            $lastClean=(int)$this->query('SELECT value FROM settings WHERE name=\'engine_last_cleanup\'')->fetchColumn();
            if($now-$lastClean>60) {
                $messageCutoff=max(0,(int)$this->query('SELECT MAX(id) FROM messages')->fetchColumn()-50000);
                $this->query('DELETE FROM messages WHERE id<? LIMIT 50000',[$messageCutoff]);
                $this->query('DELETE FROM messages WHERE created_at<UTC_TIMESTAMP()-INTERVAL 7 DAY LIMIT 50000');
                $resultCutoff=max(0,(int)$this->query('SELECT MAX(id) FROM results')->fetchColumn()-100000);
                $this->query('DELETE FROM results WHERE id<? LIMIT 50000',[$resultCutoff]);
                $this->query('DELETE FROM results WHERE created_at<UTC_TIMESTAMP()-INTERVAL 90 DAY LIMIT 50000');
                $roomCutoff=max(0,(int)$this->query('SELECT MAX(id) FROM rooms')->fetchColumn()-5000);
                $this->query("DELETE FROM rooms WHERE status='finished' AND id<? LIMIT 20000",[$roomCutoff]);
                // Lifetime ratings and counters live in users/settings; detailed history is bounded.
                $this->query('DELETE FROM rooms WHERE status=\'finished\' AND finished_at<UTC_TIMESTAMP()-INTERVAL 7 DAY LIMIT 20000');
                $this->setting('engine_last_cleanup',$now);
            }
        },0);
    }

    private function chooseBots(array &$state): void
    {
        // Copy only PUBLIC information. No enemy action or target is visible to the AI.
        $public=[];
        foreach($state['players'] as $p) if($p['alive']&&!$p['left']) $public[]=['id'=>$p['id'],'health'=>$p['health'],'last_move'=>$p['last_move']??null];
        foreach($state['players'] as &$p) {
            $p['action']=null;$p['target']=null;
            if(!$p['is_bot'] || !$p['alive'] || $p['left']) continue;
            $others=array_values(array_filter($public,fn($q)=>$q['id']!==$p['id']));
            if(!$others) continue;
            $target=$others[random_int(0,count($others)-1)]['id'];
            if(random_int(1,100)<=45) {
                usort($others,fn($a,$b)=>$a['health']<=>$b['health']);$target=$others[0]['id'];
            }
            if(!empty($p['last_attacker']) && random_int(1,100)<=35) foreach($others as $q) if($q['id']===$p['last_attacker']) $target=$q['id'];
            $persona=strtolower($p['personality']);
            $weights=match($persona) {
                'bully'=>['scratch'=>55,'grab'=>24,'tease'=>12,'cower'=>3,'lick'=>3,'tattle'=>3],
                'trickster'=>['scratch'=>18,'grab'=>30,'tease'=>30,'cower'=>8,'lick'=>6,'tattle'=>8],
                'survivor'=>['scratch'=>28,'grab'=>12,'tease'=>5,'cower'=>22,'lick'=>25,'tattle'=>8],
                'schemer'=>['scratch'=>20,'grab'=>25,'tease'=>30,'cower'=>5,'lick'=>8,'tattle'=>12],
                default=>['scratch'=>25,'grab'=>18,'tease'=>22,'cower'=>8,'lick'=>12,'tattle'=>15]
            };
            if($p['lollies']<=0 || $p['health']>=9)$weights['lick']=0;
            elseif($p['health']<=4)$weights['lick']+=25;
            if($p['tattles']<=0)$weights['tattle']=0;
            if($p['cowardice']>0)$weights['cower']=0;
            if(count($others)<3)$weights['tease']=0;
            $roll=random_int(1,array_sum($weights));$action='scratch';
            foreach($weights as $move=>$weight){$roll-=$weight;if($roll<=0){$action=$move;break;}}
            $p['action']=$action;$p['target']=in_array($action,['scratch','grab','tease'],true)?$target:null;
        }unset($p);
    }
    private function line(): string
    {
        if(self::$banter===null) {
            $file=dirname(__DIR__).'/data/banter.json';
            self::$banter=is_file($file)?json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR):['Your strategy just fell down the stairs.'];
        }
        $line=self::$banter[random_int(0,count(self::$banter)-1)];
        return is_array($line)?(string)($line['text']??reset($line)):(string)$line;
    }
    private function banter(int $roomId,array $state): void
    {
        foreach($state['players'] as $p) {
            if(!$p['is_bot'] || !$p['alive'] || random_int(0,10000)>(int)((float)$this->cfg['bot_chat_chance']*10000)) continue;
            $others=array_values(array_filter($state['players'],fn($q)=>$q['id']!==$p['id']&&$q['alive']));
            $prefix=$others&&random_int(0,1)?$others[random_int(0,count($others)-1)]['username'].': ':'';
            $this->message($roomId,$p['id'],$prefix.$this->line());
        }
    }
    private function finish(array $room,array $state): void
    {
        // Transition is the idempotency boundary for payouts and the lifetime counter.
        $changed=$this->query("UPDATE rooms SET status='finished',finished_at=UTC_TIMESTAMP(),next_turn_at=NULL WHERE id=? AND status<>'finished'",[$room['id']]);
        if(!$changed->rowCount())return;
        $this->query("INSERT INTO settings(name,value) VALUES('total_games','1') ON DUPLICATE KEY UPDATE value=CAST(value AS UNSIGNED)+1");
        $winners=$state['winners'];$n=count($state['players']);$names=[];
        foreach($state['players'] as $p) if(in_array($p['id'],$winners,true)) $names[]=$p['username'];
        $state['log'][]=['turn'=>(int)$room['turn'],'text'=>$names?implode(' & ',$names).' survived the drama and won!':'Nobody survived the drama.'];
        foreach($state['players'] as $p) {
            $won=in_array($p['id'],$winners,true);$delta=0.0;
            foreach($state['players'] as $other) {
                if($p['id']===$other['id'])continue;
                $otherWon=in_array($other['id'],$winners,true);
                $score=$p['left']?0.0:($other['left']?1.0:($won===$otherWon?0.5:($won?1.0:0.0)));
                $expected=1/(1+pow(10,($other['rating']-$p['rating'])/400));
                $delta+=32*($score-$expected)/max(1,$n-1);
            }
            $delta=(int)round($delta);
            $currentRating=(int)$this->query('SELECT rating FROM users WHERE id=?',[$p['id']])->fetchColumn();
            $delta=max(100-$currentRating,$delta);
            $points=$p['left']?0:($won?10+20*($n-count($winners)):10);
            // The unique result index and enclosing transaction guarantee one payout per participant.
            $insert=$this->query('INSERT IGNORE INTO results(room_id,user_id,won,rating_delta,points,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$room['id'],$p['id'],(int)$won,$delta,$points]);
            if($insert->rowCount()) $this->query('UPDATE users SET rating=GREATEST(100,rating+?),points=points+?,wins=wins+?,losses=losses+?,games=games+1 WHERE id=?',[$delta,$points,(int)$won,(int)!$won,$p['id']]);
        }
        foreach($state['players'] as &$p){$p['action']=null;$p['target']=null;}unset($p);
        $this->query('DELETE FROM memberships WHERE room_id=?',[$room['id']]);
        $this->writeState((int)$room['id'],$state);
    }

    public function room(int $roomId,?int $viewerId): array
    {
        $room=$this->getRoom($roomId);$state=$this->state($room);$own=null;$ready=0;
        foreach($state['players'] as &$p) {
            if($p['alive']&&!$p['left']&&$p['action'])$ready++;
            if($p['id']===$viewerId && $p['action']) $own=['action'=>$p['action'],'target'=>$p['target']];
            // Never leak committed enemy moves, AI memory or future choices in JSON.
            unset($p['action'],$p['target'],$p['personality'],$p['last_attacker']);
        }unset($p);
        $room['id']=(int)$room['id'];$room['host_id']=(int)$room['host_id'];$room['turn']=(int)$room['turn'];
        $room+=GameContent::arena((int)($state['arena_id']??0));
        $room['state']=$state;$room['players']=$state['players'];$room['log']=$state['log'];$room['winners']=$state['winners'];
        $room['own_action']=$own;$room['ready_count']=$ready;$room['server_time']=time();
        $date=$room['status']==='waiting'?$room['starts_at']:$room['next_turn_at'];
        $room['deadline']=$date?strtotime($date.' UTC'):0;
        return $room;
    }

    /** Pure combat resolver. Randomizes same-kind ties symmetrically before resolving.
     * Returns players, an ordered narration log and survivor IDs (only at <=2 survivors).
     */
    public static function resolve(array $players): array
    {
        $p=[];$order=[];$log=[];
        foreach($players as $v) {
            $v+=['left'=>false,'alive'=>true,'action'=>null,'target'=>null,'cowardice'=>0,'last_attacker'=>null];
            $id=(int)$v['id'];$v['id']=$id;$p[$id]=$v;
            if(!$v['alive']||$v['left']||$v['health']<=0)continue;
            $order[]=$id;
        }
        // Randomized same-priority resolution is essential for symmetric mutual grabs.
        shuffle($order);
        $meta=[];$tattlers=[];
        foreach($order as $id) {
            $a=$p[$id]['action'];
            if(!in_array($a,self::MOVES,true))$a='timeout';
            if(in_array($a,['scratch','grab','tease'],true)) {
                $t=$p[$id]['target'];
                if(!isset($p[$t])||$t===$id||!$p[$t]['alive']||$p[$t]['left'])$a='timeout';
            }
            if($a==='lick'&&$p[$id]['lollies']<=0 || $a==='tattle'&&$p[$id]['tattles']<=0)$a='timeout';
            $p[$id]['action']=$a;$p[$id]['last_move']=$a;
            $meta[$id]=['cower'=>$a==='cower','blocked'=>false,'grabbed'=>false,'grabs'=>[],'scratches'=>[],'teases'=>[],'lick'=>$a==='lick','healed'=>false];
            if($a==='lick')$p[$id]['lollies']--;
            if($a==='tattle'){$p[$id]['tattles']--;$tattlers[]=$id;}
        }
        foreach(['grab','scratch','tease'] as $kind) foreach($order as $id) {
            if($p[$id]['action']!==$kind)continue;
            $t=(int)$p[$id]['target'];
            if($meta[$id]['grabbed']){$log[]=$p[$id]['username'].' was grabbed and could not '.$kind.'.';continue;}
            if($kind!=='tease'&&$meta[$t]['cower']&&!$meta[$t]['blocked']) {
                $meta[$t]['blocked']=true;$log[]=$p[$t]['username'].' cowered away from '.$p[$id]['username'].'\'s '.$kind.'.';continue;
            }
            if($kind==='grab'){$meta[$t]['grabbed']=true;$meta[$t]['grabs'][]=$id;}
            elseif($kind==='scratch')$meta[$t]['scratches'][]=$id;
            else $meta[$t]['teases'][]=$id;
            $p[$t]['last_attacker']=$id;
        }
        $hurt=static function(int $id,int $damage) use (&$p):void{$p[$id]['health']=max(0,$p[$id]['health']-$damage);};
        foreach($order as $id) {
            if($meta[$id]['cower']) {
                if($meta[$id]['blocked']||count($tattlers)===1)$p[$id]['cowardice']=0;
                else {
                    $p[$id]['cowardice']++;
                    if($p[$id]['cowardice']>=2){$hurt($id,1);$log[]=$p[$id]['username'].' cowered at nothing again. −1 esteem.';}
                }
            } else $p[$id]['cowardice']=0;
        }
        foreach($order as $id) {
            $g=count($meta[$id]['grabs']);$s=count($meta[$id]['scratches']);
            if($g||$s){
                $damage=max(0,$g-1)+$s*(($g||$meta[$id]['lick'])?2:1);$hurt($id,$damage);
                $log[]=$p[$id]['username'].' took '.$g.' grab'.($g===1?'':'s').' and '.$s.' scratch'.($s===1?'':'es').': −'.$damage.' esteem'.($meta[$id]['lick']?' and lost the lollipop.':'.');
            }
        }
        foreach($order as $id) {
            $n=count($meta[$id]['teases']);
            if($n>=2){$hurt($id,2*$n);$log[]=$p[$id]['username'].' was roasted by '.$n.' players: −'.(2*$n).' esteem.';}
            elseif($n===1)$log[]=$p[$meta[$id]['teases'][0]]['username'].' teased '.$p[$id]['username'].' alone. Nobody backed it up.';
        }
        foreach($order as $id) if($meta[$id]['lick']&&!$meta[$id]['grabbed']&&!$meta[$id]['scratches']) {
            $meta[$id]['healed']=true;
            if($p[$id]['health']>0){$p[$id]['health']=min(10,$p[$id]['health']+2);$log[]=$p[$id]['username'].' enjoyed a lollipop: +2 esteem.';}
        }
        if(count($tattlers)===1) {
            $t=$tattlers[0];$log[]=$p[$t]['username'].' tattled. Exposed fighters lose 3 esteem.';
            foreach($order as $id)if($id!==$t&&!$meta[$id]['cower']&&!$meta[$id]['healed']){$hurt($id,3);$p[$id]['last_attacker']=$t;}
        } elseif(count($tattlers)>1) foreach($tattlers as $id){$hurt($id,3);$log[]=$p[$id]['username'].' tattled at the same time as someone else: −3 esteem.';}
        foreach($order as $id) {
            if($p[$id]['action']==='timeout'){$hurt($id,1);$log[]=$p[$id]['username'].' missed the turn: −1 esteem.';}
            if($p[$id]['health']<=0){$p[$id]['alive']=false;$log[]=$p[$id]['username'].' ran out of self-esteem and is out!';}
            $p[$id]['action']=null;$p[$id]['target']=null;
        }
        $survivors=array_values(array_filter($p,fn($v)=>$v['alive']&&!$v['left']&&$v['health']>0));
        return ['players'=>array_values($p),'log'=>$log,'winners'=>count($survivors)<=2?array_column($survivors,'id'):[]];
    }
}
