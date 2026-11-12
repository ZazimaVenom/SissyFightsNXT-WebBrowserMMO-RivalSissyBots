# Sissy Fights NXT — credits and source provenance

Based on the user-supplied SiSSYFiGHT 2000 revival source (`sissyfight.rar`).
The original repository uses Node.js, SockJS, CreateJS, MySQL and Redis.
This edition replaces the server and browser application with PHP/PDO,
MySQL/MariaDB and dependency-free JavaScript. It preserves the six classic
moves and remixes the original pixel assets. NXT adds persistent playerbots,
a new rating ladder, a 60-turn tiebreak, shorter turns and a new interface.

## Original work

Original code copyright 2015 Ranjit Bhatnagar, Naomi Clark and Eric Zimmerman.
MIT permission and warranty text is preserved in [ORIGINAL-LICENSES.md](ORIGINAL-LICENSES.md).

Art and media from the supplied source are licensed under
[Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/).
Original art/interface: Jason Mohr. Art direction: Yoshi Sodeoka.
Revival visual design: Cindi Geeze and Terry Wiley.
Original music/sound credit: Lem Jay Ignacio.

Adapted source artwork: avatar sprite sheets and P.S. 666 playground scene.
Changes: palette substitution, layer compositing, completed standing lower
bodies for action poses, fixed transparent canvases, and stage cropping.
`tools/build_avatars.py` records the reproducible transformations.
The archive does not require the original third-party JS packages or font.

## New artwork

`assets/hero.png` was created with the built-in image generation tool, using
the supplied original title art as visual reference. It is integrated into
the remastered playground page. No external image service runs during play.

Prompt: a polished early-2000s pixel-cartoon playground showdown inspired
by the original square-headed fighters; fully clothed characters, absurd
expressions, paper crown, bubblegum, trashcan, banana peel, chainlink fence,
cream/red/blue/green/ink palette, and “YOUR EGO IS SHOWING.” speech bubble.
No realistic injuries, sexual content, slurs, or other logos.

## Technical reference

XAMPP's standard htdocs installation route:
https://www.apachefriends.org/faq_windows

PHP PDO prepared statements:
https://www.php.net/manual/en/pdo.prepared-statements.php

PHP session cookies:
https://www.php.net/manual/en/function.session-set-cookie-params.php

## Recess Remix 1.1.0 artwork and presentation

All 32 active fighter portraits (`assets/avatars-v2/`) and all ten new
arena backgrounds (`assets/arenas/`) were created using built-in image
generation, with the existing NXT hero illustration as the visual reference.
The first 12 fighter designs reinterpret the original roster identities;
20 designs are new additions. The supplied original assets and their notices
remain preserved. Active fighters use new art for every displayed state,
with CSS motion and action indicators instead of the old pose images.

Generation prompts are included under `tools/art-prompts-*`. Atlas cropping,
transparent padding and image sizing are integration steps; the character
and environment artwork itself is generated. These assets are bundled locally
and require no image service while playing.

Original music and sound are now included; see [AUDIO-CREDITS.md](AUDIO-CREDITS.md).

## Local typography

Anton by Vernon Adams is bundled from the Google Fonts repository, under
the SIL Open Font License1.1. Licence: `assets/fonts/OFL.txt`.
Source: https://github.com/google/fonts/tree/main/ofl/anton
