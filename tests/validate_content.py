#!/usr/bin/env python3
"""Validate the shipped Sissy Fights NXT bot roster and literal banter pool.

Run from any directory: python tests/validate_content.py
Uses only Python's standard library. Does not alter game data.
"""
import json
import re
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
bots = json.loads((ROOT / 'data' / 'bots.json').read_text(encoding='utf-8'))
banter = json.loads((ROOT / 'data' / 'banter.json').read_text(encoding='utf-8'))
assert isinstance(bots, list) and len(bots) == 1000, 'Exactly 1,000 bot records required'
assert len({b['username'].casefold() for b in bots}) == 1000, 'Names must be unique without case sensitivity'
allowed_personalities = {'bully', 'trickster', 'survivor', 'chaos', 'schemer'}
for number, bot in enumerate(bots, 1):
    assert set(bot) == {'username', 'personality', 'avatar'}, f'Unexpected record shape: bot {number}'
    assert re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{2,23}', bot['username']), f'Invalid name: bot {number}'
    assert not re.search(r'\d', bot['username']), f'Names should be handles, not numbered placeholders: {number}'
    assert bot['personality'] in allowed_personalities, f'Unknown personality: bot {number}'
    assert type(bot['avatar']) is int and 0 <= bot['avatar'] < 32, f'Invalid avatar: bot {number}'
assert Counter(b['personality'] for b in bots) == {p: 200 for p in allowed_personalities}
assert set(b['avatar'] for b in bots) == set(range(32)), 'All avatar choices should be represented'
assert isinstance(banter, list) and len(banter) == 10000, 'Exactly 10,000 literal lines required'
assert all(isinstance(line, str) for line in banter), 'Every line must be text'
assert len(set(banter)) == 10000, 'Banter must not contain duplicate lines'
assert len({line.casefold() for line in banter}) == 10000, 'Case changes must not create artificial variety'
for number, line in enumerate(banter, 1):
    assert 20 <= len(line) <= 220, f'Bad length on line {number}'
    assert line == line.strip() and '\n' not in line and '\r' not in line, f'Bad whitespace: {number}'
    assert not re.search(r'[{}<>]|https?://|www\.', line), f'Unexpanded template, HTML or URL: {number}'
    assert not re.search(r'(?:#\d+|line \d+|variant \d+|\d+\.?$)', line, re.I), f'Numeric filler found: {number}'
    assert all(32 <= ord(c) <= 126 for c in line), f'Unexpected control or non-ASCII character: {number}'
# Editorial guardrail for common sexual/identity-targeted additions. This is a
# regression check for this curated pool, not a general chat-moderation system.
blocked = re.compile(r'\b(?:rape|raped|raping|porn|nude|nudes|naked|genitals|shemale|tranny|retard|retarded)\b|she[- ]boys?', re.I)
assert not any(blocked.search(line) for line in banter), 'Unexpected sexual/identity-targeted text'
assert not any(blocked.search(bot['username']) for bot in bots), 'Unexpected username text'
print(f'PASS: {len(bots):,} unique bot handles; all 5 personalities and 32 avatars; {len(banter):,} unique literal banter lines; longest line {max(map(len, banter))} characters.')
