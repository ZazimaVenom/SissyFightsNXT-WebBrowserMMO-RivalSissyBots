# Original SiSSYFiGHT 2000 music and sound

Sound and music: **Lem Jay Ignacio**.

The audio in this edition is the original SiSSYFiGHT audio distributed in the
original developers' official open-source revival repository. It is not an
AI-generated replacement or a newly composed imitation.

Source: https://github.com/moonmilk/sissyfight
Pinned revision: `29e97dd7cdb0d30a933f0c0de215482c07136233`
Original music: https://github.com/moonmilk/sissyfight/tree/master/public/client/assets/music
Original effects: https://github.com/moonmilk/sissyfight/tree/master/public/client/assets/sound
Original credits: https://github.com/moonmilk/sissyfight/blob/master/public/site/credits.html
Repository license: https://github.com/moonmilk/sissyfight/blob/master/licenses.md

## License and attribution

The developers release all original art, music, and sounds under
**Creative Commons Attribution 4.0 International (CC BY 4.0)**:
https://creativecommons.org/licenses/by/4.0/

The complete supplied license information is retained in `ORIGINAL-LICENSES.md`.
Audio bytes are unmodified. NXT changes playback sequencing, looping, volume,
scene selection, and event timing. This credit does not imply endorsement by
Lem Jay Ignacio or the original developers.

## Included audio

| Source collection | Included cues |
| --- | --- |
| `music/666` | intro, winner, loser, polaroid |
| `music/franklin` | intro, winner, loser, polaroid |
| `music/suzy` | intro, winner, loser, polaroid |
| `music/angel` | intro, winner, loser, polaroid |
| `sound` | action-select, buzzer, click, countdown, keyclick, playloop |

All 22 cues are supplied in both original MP3 and OGG: 44 files, approximately
4.2 MB. These are the original short game cues and loops, not full-length songs.
Some upstream themes share recordings; all files retain the original bytes.
`assets/audio/manifest.json` records each exact source URL, byte size, and SHA-256.

## Playback

Music and effects default to **on** for new visitors. A previously chosen mute
is remembered. The volume control is also remembered. Browser autoplay policy
may require the first click, tap, or key press before sound becomes audible;
the interface shows that state. NXT does not bypass the browser's policy.

The lobby, profiles, ladder, and rules use the original P.S. 666 scene loop.
The match theme is selected consistently from the room's arena. Active fights
use the original gameplay rhythm, entrance cue, countdown, round buzzer, and
result music. A player's elimination uses the original losing cue. UI clicks
and action selection use the corresponding original effects. Audio pauses in
hidden tabs and resumes only when sound is enabled and playback has unlocked.
Each resolved round/result is sounded once, not on every server poll.

All audio is served locally by Apache. There is no streaming service,
third-party request, account, API key, or extra installation step.

## Integration API

Load `assets/audio.js` before `assets/app.js` with deferred scripts.
`window.SFNXTAudio` exposes:

- `setEnabled(boolean)` / `setVolume(0..1)` — apply and remember controls.
- `unlock()` — attempt playback within a user gesture.
- `setScene('lobby'|'match')` — match the visible page.
- `play('click'|'select'|'lock'|'countdown'|'buzzer'|'key')` — UI cues.
- `updateMatch(room, meId)` — use server room snapshots for match cues.
- `getState()` — enabled, unlocked, playing, volume, scene, title, blocked,
  and unavailable fields.

`window` emits `sfnxt:audiochange` with that state in `event.detail`.
A failed or blocked sound does not stop gameplay.

## Verification

All 44 files were checked against their recorded SHA-256 values and fully
decoded with FFmpeg. The JavaScript passes Node syntax validation. A simulated
browser test checks default-on playback, autoplay rejection and recovery,
saved mute, volume clamping, scene changes, one-shot round/result cues,
countdown deduplication, and background-tab pause/resume. Browser listening
quality and autoplay behavior should also be checked on the target browser.
