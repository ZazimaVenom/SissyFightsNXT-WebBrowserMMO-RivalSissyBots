#!/usr/bin/env python3
"""Exercise avatar validation and arena persistence through the real HTTP API.

Run ONLY against a disposable test installation:
    python tests/http_smoke.py http://127.0.0.1:8766/
Creates a temporary fighter and room in that installation; does not delete data.
Uses Python's standard library and never prints test credentials.
"""
import http.cookiejar
import json
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8766/'
if not base.endswith('/'):
    base += '/'
checks = 0


def check(condition, label):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(label)


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''

    def call(self, action, body=None, *, expected=200, csrf=True, query=None):
        url = base + 'api.php?' + urllib.parse.urlencode({'action': action, **(query or {})})
        headers = {}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(url, data=data, headers=headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        status = response.code
        result = json.loads(response.read())
        check(status == expected, f'{action}: expected HTTP {expected}, got {status}: {result.get("error", "")}')
        check(bool(result.get('ok')) == (expected < 400), f'{action}: correct API success flag')
        if result.get('csrf'):
            self.csrf = result['csrf']
        return result


player, guest = Client(), Client()
bootstrap = player.call('bootstrap')
check(bool(player.csrf) and bootstrap['me'] is None, 'guest receives CSRF token without authentication')
username = 'Http_' + secrets.token_hex(5)
password = secrets.token_urlsafe(20)
player.call('register', {'username': username, 'password': password, 'avatar': 31}, expected=403, csrf=False)
registered = player.call('register', {'username': username, 'password': password, 'avatar': 31})
uid = registered['me']['id']
check(registered['me']['avatar'] == 31, 'register accepts highest new avatar31')
check(player.call('bootstrap')['me']['avatar'] == 31, 'registered avatar31 persists across request')

for chosen in (12, 31):
    saved = player.call('profile', {'avatar': chosen})
    check(saved['me']['avatar'] == chosen, f'profile accepts avatar{chosen}')
    public = guest.call('profile', query={'id': uid})
    check(public['player']['avatar'] == chosen, f'avatar{chosen} persisted and visible to guest')

for invalid in (32, -1):
    player.call('profile', {'avatar': invalid}, expected=400)
    check(player.call('profile', query={'id': uid})['player']['avatar'] == 31,
          f'invalid avatar{invalid} leaves saved selection unchanged')
    player.call('register', {'username': 'Bad_' + secrets.token_hex(5), 'password': password, 'avatar': invalid}, expected=400)

player.call('profile', {'avatar': 12}, expected=403, csrf=False)
check(player.call('bootstrap')['me']['avatar'] == 31, 'missing CSRF cannot change avatar')
room_id = player.call('create', {'title': 'HTTP Smoke Test Fight'})['room_id']
first = player.call('room', query={'id': room_id})['room']
arena = first['arena_id']
check(isinstance(arena, int) and 1 <= arena <= 10, 'new room gets a valid random arena')
check(first['arena_image'] == f'assets/arenas/{arena:02d}.png', 'arena image uses selected stable ID')
check(bool(first['arena_name']), 'arena has a display name')
check(first['players'][0]['avatar'] == 31, 'new match snapshots avatar31')
for viewer in (guest, player, guest):
    read = viewer.call('room', query={'id': room_id})['room']
    check(read['arena_id'] == arena and read['arena_image'] == first['arena_image'],
          'guest and authenticated repeated reads retain the same arena')
    check(read['state']['arena_id'] == arena, 'public room mirrors persisted arena')

player.call('logout', {})
check(player.call('bootstrap')['me'] is None, 'logout clears authentication')
player.call('profile', {'avatar': 12}, expected=401)
logged_in = player.call('login', {'username': username, 'password': password})
check(logged_in['me']['id'] == uid and logged_in['me']['avatar'] == 31,
      'login restores account identity and avatar31')
player.call('logout', {})
print(json.dumps({'ok': True, 'assertions': checks,
                  'validated': ['register31', 'profile12and31', 'reject32andMinus1',
                                'csrf', 'authentication', 'persistedArenaAcrossViewers']}, indent=2))
