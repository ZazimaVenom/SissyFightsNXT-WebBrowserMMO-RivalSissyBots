<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Engine.php';
$tests=0;
function p(int $id,string $action,?int $target=null,int $health=10):array{return ['id'=>$id,'username'=>'Player'.$id,'is_bot'=>false,'bot_number'=>null,'avatar'=>0,'health'=>$health,'lollies'=>3,'tattles'=>2,'cowardice'=>0,'alive'=>true,'left'=>false,'action'=>$action,'target'=>$target];}
function resolved(array $players):array{$r=Engine::resolve($players);return array_column($r['players'],null,'id');}
function eq(mixed $actual,mixed $expected,string $label):void{global $tests;$tests++;if($actual!==$expected)throw new RuntimeException($label.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
$r=resolved([p(1,'scratch',2),p(2,'scratch',1),p(3,'cower')]);eq($r[1]['health'],9,'mutual scratch A');eq($r[2]['health'],9,'mutual scratch B');
$r=resolved([p(1,'scratch',2),p(2,'cower'),p(3,'scratch',2)]);eq($r[2]['health'],9,'cower blocks exactly one scratch');eq($r[2]['cowardice'],0,'productive cower resets streak');
$r=resolved([p(1,'grab',2),p(2,'scratch',3),p(3,'lick',null,5)]);eq($r[2]['health'],10,'first grab has no damage');eq($r[3]['health'],7,'grab interrupts scratch');
$r=resolved([p(1,'grab',2),p(2,'lick',null,5),p(3,'grab',2),p(4,'scratch',2)]);eq($r[2]['health'],2,'double grab plus scratch into licking');eq($r[2]['lollies'],2,'interrupted lolly consumed');
$r=resolved([p(1,'tease',2),p(2,'cower'),p(3,'cower')]);eq($r[2]['health'],10,'single tease no damage');
$r=resolved([p(1,'tease',2),p(2,'cower'),p(3,'tease',2)]);eq($r[2]['health'],6,'two teases hit through cower');
$r=resolved([p(1,'tattle'),p(2,'lick',null,6),p(3,'cower'),p(4,'scratch',1)]);eq($r[2]['health'],8,'successful lolly immune to tattle');eq($r[3]['health'],10,'cower immune to tattle');eq($r[4]['health'],7,'tattle damages exposed fighter');
$r=resolved([p(1,'tattle'),p(2,'tattle'),p(3,'cower')]);eq($r[1]['health'],7,'double tattle penalty A');eq($r[2]['health'],7,'double tattle penalty B');eq($r[3]['health'],10,'double tattle spares others');
$r=resolved([p(1,'tease',2),p(2,'lick',null,3),p(3,'tease',2)]);eq($r[2]['health'],0,'lethal tease before lolly does not resurrect');eq($r[2]['alive'],false,'zero health eliminates');
$a=p(1,'cower');$a['cowardice']=1;$r=resolved([$a,p(2,'cower'),p(3,'cower')]);eq($r[1]['health'],9,'repeat idle cower penalty');
$a=p(1,'tattle');$a['tattles']=0;$r=resolved([$a,p(2,'cower'),p(3,'cower')]);eq($r[1]['health'],9,'empty tattle is timeout');
$r=resolved([p(1,'scratch',1),p(2,'cower'),p(3,'cower')]);eq($r[1]['health'],9,'self targeting is invalid');
$r=resolved([p(1,'scratch',99),p(2,'cower'),p(3,'cower')]);eq($r[1]['health'],9,'nonexistent targeting is invalid');
$a=p(1,'scratch',2);$a['alive']=false;$a['health']=0;$r=resolved([$a,p(2,'cower'),p(3,'cower')]);eq($r[2]['health'],10,'eliminated players cannot act');
$r=Engine::resolve([p(1,'scratch',2,1),p(2,'scratch',1,1),p(3,'cower')]);eq($r['winners'],[3],'sole survivor wins');
for($i=0;$i<60;$i++){$r=resolved([p(1,'grab',2),p(2,'grab',1),p(3,'cower')]);eq($r[1]['health']+$r[2]['health'],20,'mutual grabs do not invent damage');}
eq(GameContent::AVATAR_COUNT,32,'32 available portraits');
$looks=[];for($i=1;$i<=1000;$i++)$looks[]=GameContent::botAvatar($i);
eq(count(array_unique($looks)),32,'every new and original look appears in bot roster');
eq(min($looks),0,'bot looks lower bound');eq(max($looks),31,'bot looks upper bound');
for($i=1;$i<=10;$i++)eq(GameContent::arena($i),['arena_id'=>$i,'arena_name'=>GameContent::ARENA_NAMES[$i],'arena_image'=>'assets/arenas/'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'.png'],'arena path '.$i);
eq(GameContent::arena(0),['arena_id'=>0,'arena_name'=>'The Original Playground','arena_image'=>'assets/arena.png'],'legacy arena fallback');
eq(GameContent::arena(11),GameContent::arena(0),'invalid arena cannot form arbitrary path');
echo "PASS: $tests combat and content assertions\n";
