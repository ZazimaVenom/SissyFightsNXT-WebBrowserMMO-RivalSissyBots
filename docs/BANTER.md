# Sissy Fights NXT — bot names and banter

The shipped roster contains **1,000 persistent bot identities**. Each has a unique handle, one of five strategy personalities, and an avatar choice. The installer assigns the permanent bot number from the roster's position (1–1,000); the game shows that number separately from the username. Once installed, changing the order of this file does not rename existing accounts.

`data/bots.json` contains the roster. Handles include `SaintLunchThief`, `CaptainRubbishRaccoon`, and `VelvetBurpWizard`. All five personalities have 200 initial members, and all 12 avatar choices appear. Handles use ASCII letters with no numeric filler and fit within 24 characters.

`data/banter.json` contains **exactly 10,000 distinct, complete chat lines**. These are pre-expanded combinations of 100 curated opening roasts and 100 curated closing quips, shuffled into a fixed order. They are literal strings in the package: the game does not need an AI service, network connection, or runtime sentence generator. Phrases deliberately recur across combinations; this is a large mix-and-match library, not a claim that every sentence fragment was written 10,000 times independently.

The voice is loud, petty, absurd and nostalgic: dial-up modems, badly behaved printers, rubbish bins, terrible tactics, toilet humour, and occasional profanity. No sexual content or insults about gender identity, sexuality, race, disability, or other protected traits are included. Characters can be ridiculous without making those identities the joke.

Example lines:

- “Your entire combat style is a printer jam with shoes. The fanfare is just a kazoo full of soup.”
- “Your moves are sponsored by buyer's remorse. Put another coin in the excuse machine.”
- “I'm treating this match like an unpaid parking fine. My cunning plan is mostly aggressive finger-pointing.”

The engine controls when bots speak and may prefix a selected line with the target's username. Banter is flavour; a boast does not announce a confirmed combat result. Combat results come from the authoritative round log.

Run `python tests/validate_content.py` to verify exact counts, case-insensitive uniqueness, lengths, handle format, strategy distribution, avatar range, literal text, and editorial regression checks. Python is only used for this developer check, not to run the game. The built-in text check is not a replacement for reviewing new content added by the server operator.
