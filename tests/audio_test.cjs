const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const src=fs.readFileSync(require('node:path').join(__dirname,'../assets/audio.js'),'utf8');
function harness(initial={}){
 const store=new Map(Object.entries(initial));const instances=[], intervals=[], timers=new Map();let timerId=0,allowed=false;
 class Target{constructor(){this.events={};}addEventListener(n,f,o={}){(this.events[n]??=[]).push({f,once:o.once});}dispatchEvent(e){for(const h of [...(this.events[e.type]||[])]){h.f(e);if(h.once)this.events[e.type]=this.events[e.type].filter(x=>x!==h);}return true;}}
 class Audio extends Target{constructor(){super();this.paused=true;this.dataset={};this.children=[];this.currentTime=0;this.duration=10;this.plays=0;instances.push(this);}appendChild(c){this.children.push(c);}play(){this.plays++;if(!allowed)return Promise.reject(Object.assign(new Error('autoplay'),{name:'NotAllowedError'}));this.paused=false;this.dispatchEvent({type:'playing'});return Promise.resolve();}pause(){this.paused=true;}}
 const document=new Target();document.hidden=false;document.createElement=()=>({});const window=new Target();
 const ctx={window,document,Audio,localStorage:{getItem:k=>store.has(k)?store.get(k):null,setItem:(k,v)=>store.set(k,v)},CustomEvent:class{constructor(type,o){this.type=type;this.detail=o.detail;}},setInterval:f=>{intervals.push(f);return intervals.length;},setTimeout:(f,ms)=>{timers.set(++timerId,{f,ms});return timerId;},clearTimeout:i=>timers.delete(i),Date,Map,Set,Math,Number,Object,String,Boolean};
 vm.runInNewContext(src,ctx);
 return {api:window.SFNXTAudio,store,instances,intervals,timers,document,window,allow:()=>allowed=true,cues:key=>instances.filter(a=>a.children[0].src.includes('/'+key+'.mp3')).reduce((n,a)=>n+a.plays,0)};
}
(async()=>{
 const h=harness();await Promise.resolve();
 assert.equal(h.api.getState().enabled,true);assert.equal(h.api.getState().blocked,true);assert.equal(h.api.getState().unlocked,false);assert.equal(h.instances.length,1);
 assert.equal(h.instances[0].children[0].src,'assets/audio/music/666/polaroid.mp3');assert.equal(h.instances[0].children[1].src,'assets/audio/music/666/polaroid.ogg');
 h.allow();h.api.unlock();assert.equal(h.api.getState().playing,true);assert.equal(h.api.getState().blocked,false);
 h.api.setVolume(2);assert.equal(h.api.getState().volume,1);h.api.setVolume(-1);assert.equal(h.api.getState().volume,0);h.api.setVolume(.5);
 h.api.setScene('match');
 let r={id:22,arena_id:4,status:'waiting',turn:0,players:[{id:1,health:10},{id:2,health:10}],server_time:Date.now()/1000};
 h.api.updateMatch(r,1);assert.equal(h.cues('intro'),0);
 r={...r,status:'playing',turn:1,deadline:Date.now()/1000+30};h.api.updateMatch(r,1);assert.equal(h.cues('intro'),1);h.api.updateMatch(r,1);assert.equal(h.cues('intro'),1);
 r={...r,turn:2,deadline:Date.now()/1000+5};h.api.updateMatch(r,1);assert.equal(h.cues('buzzer'),1);h.api.updateMatch(r,1);assert.equal(h.cues('buzzer'),1);
 h.intervals.forEach(f=>f());assert.equal(h.cues('countdown'),1);h.intervals.forEach(f=>f());h.api.updateMatch(r,1);h.intervals.forEach(f=>f());assert.equal(h.cues('countdown'),1);
 r={...r,status:'finished',winners:[1]};h.api.updateMatch(r,1);assert.equal(h.cues('winner'),1);h.api.updateMatch(r,1);assert.equal(h.cues('winner'),1);
 h.document.hidden=true;h.document.dispatchEvent({type:'visibilitychange'});assert.equal(h.api.getState().playing,false);
 h.document.hidden=false;h.document.dispatchEvent({type:'visibilitychange'});assert.equal(h.api.getState().playing,true);
 h.api.setEnabled(false);assert.equal(h.api.getState().playing,false);assert.equal(h.store.get('sfnxt-sound'),'0');h.document.hidden=true;h.document.dispatchEvent({type:'visibilitychange'});h.document.hidden=false;h.document.dispatchEvent({type:'visibilitychange'});assert.equal(h.api.getState().playing,false);
 const muted=harness({'sfnxt-sound':'0','sfnxt-volume':'.7'});assert.equal(muted.instances.length,0);muted.allow();muted.api.unlock();assert.equal(muted.instances.length,0);assert.equal(muted.api.getState().volume,.7);muted.api.setEnabled(true);assert.equal(muted.api.getState().playing,true);
 console.log('PASS: default-on; autoplay rejection/recovery; MP3/OGG source fallback; volume; persisted mute; scene playback; deduplicated intro/round/countdown/results; background pause/resume.');
})().catch(e=>{console.error(e);process.exit(1)});
