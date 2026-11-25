SISSY FIGHTS NXT
PHP / MySQL XAMPP edition — 1.1.0 / RECESS REMIX
================================

UPDATING AN EXISTING 1.0.0 INSTALLATION
Read UPDATE-WINDOWS.txt. Keep your app/config.php and database. Overlay the
new files while the worker is stopped; do not delete/reinstall your game.

WHAT IS NEW
- All 12 original fighter looks redrawn in the hero illustration style.
- 20 additional selectable looks: 32 complete new-style fighter designs.
- Existing bots automatically receive the expanded looks without losing
  their identities, match histories, ratings or records.
- Ten illustrated arenas randomly assigned when a fight is created and
  saved with that fight. Every viewer sees the same arena throughout.
- Card-free fighters, floating names, health, supplies and target indicators.
- A coherent retro comic interface across the lobby, matches and profiles.
- Original SiSSYFiGHT music and effects bundled locally, enabled by default.

<img width="3840" height="1946" alt="0" src="https://github.com/user-attachments/assets/6d9d0969-c3eb-4d9f-86e9-fdc5e0e237f4" />

<img width="3840" height="1946" alt="4" src="https://github.com/user-attachments/assets/9bf0ce98-f463-415e-8cf3-e661d697e8ff" />

<img width="3840" height="2021" alt="1" src="https://github.com/user-attachments/assets/442951b6-3f9c-4b18-b8ac-51f4578b24ba" />

<img width="3840" height="2026" alt="2" src="https://github.com/user-attachments/assets/53162d31-f4b2-45ca-a73b-b3eee623ddc2" />

QUICK START ON WINDOWS
1. Extract the sissy-fights-nxt folder into your XAMPP htdocs folder.
   Normal result: C:\xampp\htdocs\sissy-fights-nxt\index.php
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open http://localhost/sissy-fights-nxt/ on the server computer.
   The local setup wizard checks PHP and creates the database, tables,
   and exactly 1,000 persistent bot accounts. Supply your MySQL settings.
   A stock local XAMPP often uses root with an empty password.
4. Double-click bin\start-bots.cmd. Leave its console running. The bots
   then keep playing even when every browser is closed. Do not start
   another copy. Stop cleanly with bin\stop-bots.cmd before stopping MySQL.
5. Create your own account, join an open bot game or host your own.
   Bots fill open seats. You can start at 3 players or wait for auto-start.

NO NODE, REDIS, COMPOSER OR CLOUD ACCOUNTS REQUIRED
The game runs with PHP 8.2+, PDO MySQL, and MariaDB 10.4+ / MySQL 8+.
All fonts, game code, artwork, music and sound effects are local. HTTP polling works through
normal Apache. No WebSocket service, TLS setup or external CDN is needed.
The PHP/MySQL runtime itself is supplied by XAMPP, not this archive.

THE COMMUNITY
All 1,000 bots have their own username, bot number, avatar, personality,
match history, rating and results. They create and join real match rooms,
use the same six-move rules as you, and earn points by playing. There are
no pre-awarded wins or fabricated ladder scores. Small labels distinguish
"sissy bot #0001" etc. from human players.

Allow roughly 15 seconds for the community to populate after first start.
Bot-hosted games wait 35–55 seconds before starting, reserving a visitor
seat. A normal round allows 30 seconds when a live human is participating;
bot-only rounds take 10 seconds. Some bots may briefly be between games.
A bot needs to finish its current game before joining a new room.

The directory lists every bot. The status strip shows the worker state
and actual active match counts. Without the worker, browser requests can
advance the game, but nothing runs while every browser is closed. Run the
worker for the continuously active community requested for this edition.

10,000 unique prewritten/materialized banter strings are included. The
pool combines 100 curated opening roasts with 100 curated punchlines;
fragments recur, but no full line is duplicated. These are absurd crude
roasts and toilet humor, without sexual content or identity-based slurs.
Banter can be muted in the interface. See docs/BANTER.md.

GAMEPLAY
Six simultaneous moves: scratch, grab, tease, cower, lick and tattle.
Start with 10 self-esteem, 3 lollipops and 2 tattles. Reduce other players
to zero; the last one or two standing share the win. Moves stay secret
until the round resolves. Bots choose before your move and cannot read it.
The in-game rules explain each move and its interactions.

The main ladder uses pairwise Elo-style rating (initially 1,000), with
lifetime wins, losses, games and original-style points tracked as well.
A winner earns 10 +20 points per eliminated opponent; defeated players
get10. Leaving while still alive forfeits the match and participation
points. A maximum60-round limit awards the highest remaining self-esteem,
including ties. This is documented as a NXT addition to classic rules.

CONFIGURATION
The installer writes app/config.php. Edit it only with the worker stopped.
Settings include turn_seconds, bot_turn_seconds, lobby_seconds, max_rooms,
tick_interval, bot_chat_chance and max_turns. Defaults are designed for the
1,000-account population. Changing bot_count does not reseed the database;
this release intentionally installs exactly1,000 accounts.

Use a different folder name or Apache port if needed; URLs are relative.
For port8080, use http://localhost:8080/sissy-fights-nxt/.
Other trusted computers on your LAN can visit
http://YOUR-SERVER-LAN-IP/sissy-fights-nxt/ after local installation.
The installer is only available from the server itself and locks on success.

HTTP transports passwords and chat without encryption. This requested
HTTP edition is suitable for localhost or a trusted LAN. Before exposing
accounts publicly, add HTTPS at the server/reverse proxy and protect the
XAMPP administration endpoints. The game does not force an HTTPS redirect.

PORTABLE WORKER
start-bots.cmd uses SFNXT_PHP if set, then the XAMPP-relative PHP path,
then PATH. See the console instructions if your
layout differs. Linux/macOS: run bin/start-bots.sh with PHP on PATH.
The singleton worker lock prevents two workers advancing the same matches.
Detailed history is bounded to approximately50,000 chat lines,100,000 result
rows and5,000 recent rooms, with age-based cleanup. Lifetime ratings, points,
wins, losses and completed-game totals remain saved.
MySQL advisory locks serialize game mutations and transaction commits.

MUSIC AND SOUND
Sound is enabled by default for a new browser profile. Browsers normally
require one click/tap or keypress before audio playback; the game unlocks
audio on that first interaction. Use SOUND and the volume slider to control
it. A saved mute choice is remembered. Music pauses while the tab is hidden.
No streaming service, network download or API key is required during play.

TROUBLESHOOTING
- Setup cannot connect: check MySQL is running and host/port/user/password.
- Missing PDO MySQL: enable extension=pdo_mysql in php.ini and restart Apache.
- No bots moving with browser closed: start bin/start-bots.cmd and leave it open.
- Game filled before you joined: choose another waiting room or create one.
- Character actions appear late: check worker status and PHP error log.
- Apache won't start: resolve its port conflict in XAMPP or choose another port.
- To back up: stop the worker, export the database with phpMyAdmin, and copy
  the application folder including app/config.php; protect its DB password.
- Do not delete tables or rerun setup to fix an existing installation.

SOURCE AND CREDITS
This is a PHP adaptation/remaster of the supplied SiSSYFiGHT2000 revival
source, not a patch for an existing Node.js/Redis server or its old DB.
Start with a fresh database; no legacy account/password migration is included.
Original MIT/CC-BY notices are preserved in docs/ORIGINAL-LICENSES.md.
Original creators: Ranjit Bhatnagar, Naomi Clark, Eric Zimmerman.
Original art/interface: Jason Mohr; art direction: Yoshi Sodeoka.
Revival visual design: Cindi Geeze and Terry Wiley.
Original audio: Lem Jay Ignacio; original music and effects are bundled.
See docs/AUDIO-CREDITS.md for sources, licence and exact included files.
Original artwork remains credited under CC-BY4.0. All active character
portraits and ten new arenas are new generated artwork matching the NXT
hero illustration. The original stage remains a fallback for old fights.
See docs/CREDITS.md and docs/VERIFICATION.txt for details.
