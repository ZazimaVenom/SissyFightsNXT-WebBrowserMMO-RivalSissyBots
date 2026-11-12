/* Sissy Fights NXT — original SiSSYFiGHT 2000 audio by Lem Jay Ignacio.
 * Assets: moonmilk/sissyfight, CC BY 4.0. See docs/AUDIO-CREDITS.md.
 * No network audio service, synthesizer, or external runtime is required. */
(() => {
  'use strict';
  const themes = ['666', 'franklin', 'suzy', 'angel'];
  const read = key => { try { return localStorage.getItem(key); } catch { return null; } };
  const write = (key, value) => { try { localStorage.setItem(key, String(value)); } catch {} };
  const clamp = value => Math.max(0, Math.min(1, Number(value)));
  const savedVolume = read('sfnxt-volume');
  let enabled = read('sfnxt-sound') !== '0';
  let volume = savedVolume !== null && Number.isFinite(Number(savedVolume)) ? clamp(savedVolume) : 0.45;
  let unlocked = false, blocked = false, unavailable = false, scene = 'lobby', theme = '666';
  let music = null, musicPath = '', roomSnapshot = null, cueTimer = null, duckTimer = null, ducked = false;
  let countdownKey = '', roomDeadline = 0, clockOffset = 0;
  const effects = new Set();
  const effectTimes = new Map();
  const aliases = {click:'click', select:'action-select', lock:'action-select', countdown:'countdown', buzzer:'buzzer', key:'keyclick'};

  function getState() {
    return {enabled, unlocked, playing:!!music && !music.paused && !document.hidden,
      volume, scene, title:'SiSSYFiGHT 2000 · Original soundtrack', blocked, unavailable};
  }
  function notify() { window.dispatchEvent(new CustomEvent('sfnxt:audiochange', {detail:getState()})); }
  function makeAudio(path, loop = false) {
    const audio = new Audio();
    audio.preload = 'auto';
    audio.loop = loop;
    for (const [extension, type] of [['mp3','audio/mpeg'], ['ogg','audio/ogg']]) {
      const source = document.createElement('source');
      source.src = `assets/audio/${path}.${extension}`;
      source.type = type;
      audio.appendChild(source);
    }
    return audio;
  }
  function gain() { if (music) music.volume = volume * (ducked ? 0.15 : 0.48); }
  function stopEffects() {
    for (const audio of effects) { audio.pause(); audio.currentTime = 0; }
    effects.clear();
    clearTimeout(duckTimer);
    clearTimeout(cueTimer);
    ducked = false;
    gain();
  }
  function stopMusic() {
    if (music) { music.pause(); music.currentTime = 0; }
    music = null;
    musicPath = '';
  }
  function musicForScene() {
    // Original in-fight rhythm and original scene music, kept in their roles.
    return scene === 'match' && roomSnapshot?.status === 'playing'
      ? 'sound/playloop' : `music/${theme}/polaroid`;
  }
  function startMusic() {
    if (!enabled || document.hidden) return;
    const path = musicForScene();
    if (musicPath !== path) {
      stopMusic();
      music = makeAudio(path, true);
      musicPath = path;
      const candidate = music;
      candidate.addEventListener('error', () => {
        if (music !== candidate) return;
        unavailable = true;
        notify();
      });
      candidate.addEventListener('playing', () => {
        if (music !== candidate) return;
        unlocked = true;
        blocked = false;
        unavailable = false;
        notify();
      });
    }
    gain();
    const candidate = music;
    const promise = candidate.play();
    if (promise?.catch) promise.catch(error => {
      if (music !== candidate || !enabled || document.hidden || error?.name === 'AbortError') return;
      if (error?.name === 'NotAllowedError') { blocked = true; unlocked = false; }
      else unavailable = true;
      notify();
    });
  }
  function unlock() {
    // Called synchronously inside pointer/key events, so browser consent is retained.
    if (!enabled || document.hidden) return;
    blocked = false;
    unlocked = true;
    startMusic();
    notify();
  }
  function setEnabled(value) {
    enabled = Boolean(value);
    write('sfnxt-sound', enabled ? '1' : '0');
    if (!enabled) { music?.pause(); stopEffects(); blocked = false; }
    else startMusic();
    notify();
  }
  function setVolume(value) {
    if (!Number.isFinite(Number(value))) return;
    volume = clamp(value);
    write('sfnxt-volume', volume);
    gain();
    for (const audio of effects) audio.volume = volume * Number(audio.dataset.gain || 0.8);
    notify();
  }
  function setScene(value) {
    const next = value === 'match' ? 'match' : 'lobby';
    if (scene === next) return;
    scene = next;
    stopEffects();
    countdownKey = '';
    roomDeadline = 0;
    if (scene === 'lobby') { roomSnapshot = null; theme = '666'; }
    startMusic();
    notify();
  }
  function play(key, options = {}) {
    if (!enabled || !unlocked || document.hidden || volume === 0) return;
    let path;
    if (aliases[key]) path = `sound/${aliases[key]}`;
    else if (['intro','winner','loser','polaroid'].includes(key)) path = `music/${theme}/${key}`;
    else return;
    const now = Date.now();
    // Multiple click handlers or a rapid repeat must not stack the same effect.
    if (now - (effectTimes.get(key) || 0) < (key === 'click' ? 55 : 150)) return;
    effectTimes.set(key, now);
    if (effects.size >= 5) { const oldest = effects.values().next().value; oldest.pause(); effects.delete(oldest); }
    const audio = makeAudio(path);
    const scale = key === 'countdown' ? 0.45 : 0.8;
    audio.dataset.gain = String(scale);
    audio.dataset.key = key;
    audio.volume = volume * scale;
    effects.add(audio);
    if (Number.isFinite(options.offset) && options.offset > 0) {
      audio.addEventListener('loadedmetadata', () => { if (audio.duration > options.offset) audio.currentTime = options.offset; }, {once:true});
    }
    const release = () => { effects.delete(audio); };
    audio.addEventListener('ended', release, {once:true});
    audio.addEventListener('error', release, {once:true});
    const promise = audio.play();
    if (promise?.catch) promise.catch(release);
    if (['intro','winner','loser'].includes(key)) {
      ducked = true;
      gain();
      clearTimeout(duckTimer);
      const restore = () => { ducked = false; gain(); };
      audio.addEventListener('ended', restore, {once:true});
      duckTimer = setTimeout(restore, 12000);
    }
  }
  function stopCountdown() {
    for (const audio of effects) if (audio.dataset.key === 'countdown') { audio.pause(); effects.delete(audio); }
  }
  function updateMatch(room, meId) {
    if (!room || scene !== 'match') return;
    const players = room.players || room.state?.players || [];
    const winners = (room.winners || room.state?.winners || []).map(item => Number(typeof item === 'object' ? item.id : item));
    const next = {id:Number(room.id), status:room.status, turn:Number(room.turn),
      health:new Map(players.map(p => [Number(p.id), Number(p.health)]))};
    const previous = roomSnapshot;
    const newRoom = !previous || previous.id !== next.id;
    theme = themes[(Math.max(1, Number(room.arena_id) || Number(room.id) || 1) - 1) % themes.length];
    roomSnapshot = next;
    roomDeadline = Number(room.deadline) || Date.parse(String(room.next_turn_at).replace(' ', 'T') + 'Z') / 1000 || 0;
    clockOffset = Number(room.server_time) ? Date.now() / 1000 - Number(room.server_time) : 0;
    if (newRoom) {
      stopEffects();
      countdownKey = '';
      startMusic();
      // Joining/reloading an existing result must not replay its win/lose cue.
      if (next.status === 'playing') play('intro');
      return;
    }
    if (previous.status !== next.status || previous.turn !== next.turn) {
      clearTimeout(cueTimer);
      stopCountdown();
      countdownKey = '';
    }
    if (next.status === 'playing' && previous.status !== 'playing') {
      startMusic();
      play('intro');
    } else if (next.status === 'finished' && previous.status !== 'finished') {
      startMusic();
      const participated = next.health.has(Number(meId));
      play(participated && !winners.includes(Number(meId)) ? 'loser' : winners.length ? 'winner' : 'loser');
    } else if (next.status === 'playing' && next.turn !== previous.turn) {
      play('buzzer');
      // The original set has no per-move impact files; the round buzzer punctuates resolution.
      const wasAlive = (previous.health.get(Number(meId)) || 0) > 0;
      const nowOut = next.health.has(Number(meId)) && next.health.get(Number(meId)) <= 0;
      if (wasAlive && nowOut) {
        clearTimeout(cueTimer);
        cueTimer = setTimeout(() => play('loser'), 1050);
      }
    }
  }
  function checkCountdown() {
    if (scene !== 'match' || roomSnapshot?.status !== 'playing' || !roomDeadline || !enabled || !unlocked || document.hidden) return;
    const remaining = roomDeadline - (Date.now() / 1000 - clockOffset);
    const key = `${roomSnapshot.id}:${roomSnapshot.turn}`;
    if (remaining > 0 && remaining <= 9.8 && countdownKey !== key) {
      countdownKey = key;
      play('countdown', {offset:Math.max(0, 9.8 - remaining)});
    }
  }
  window.SFNXTAudio = Object.freeze({setEnabled, unlock, setScene, play, updateMatch, setVolume, getState});
  document.addEventListener('pointerdown', () => { if (!unlocked || music?.paused) unlock(); }, {passive:true});
  document.addEventListener('keydown', event => {
    if (!event.isComposing && !event.ctrlKey && !event.metaKey && !event.altKey && (!unlocked || music?.paused)) unlock();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { music?.pause(); stopEffects(); }
    else if (enabled && unlocked) startMusic();
    notify();
  });
  window.addEventListener('pagehide', () => { music?.pause(); stopEffects(); });
  setInterval(checkCountdown, 250);
  // Attempt allowed autoplay; if blocked, the first ordinary user gesture starts it.
  startMusic();
})();
