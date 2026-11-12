#!/usr/bin/env python3
"""Check release media completeness, PNG integrity and original audio hashes."""
from pathlib import Path
import hashlib
import json
import struct
import zlib

ROOT = Path(__file__).resolve().parents[1]

def png(path):
    data = path.read_bytes()
    assert data[:8] == b'\x89PNG\r\n\x1a\n', f'Invalid PNG: {path}'
    offset, header, end = 8, None, False
    while offset < len(data):
        length = struct.unpack('>I', data[offset:offset+4])[0]
        kind = data[offset+4:offset+8]
        payload = data[offset+8:offset+8+length]
        crc = struct.unpack('>I', data[offset+8+length:offset+12+length])[0]
        assert zlib.crc32(kind + payload) & 0xffffffff == crc, f'Corrupt PNG chunk: {path}'
        if kind == b'IHDR':
            header = struct.unpack('>IIBBBBB', payload)
        if kind == b'IEND':
            end = True
            break
        offset += length+12
    assert end and header, f'Incomplete PNG: {path}'
    return header

seen = set()
for identifier in range(32):
    path = ROOT / 'assets' / 'avatars-v2' / f'{identifier}.png'
    header = png(path)
    assert header[:2] == (320,400) and header[3] == 6, f'Expected transparent RGBA sprite: {path}'
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    assert digest not in seen, f'Duplicate sprite: {path}'
    seen.add(digest)
for identifier in range(1,11):
    header = png(ROOT / 'assets' / 'arenas' / f'{identifier:02}.png')
    assert header[0] >= 1280 and header[1] >= 720, 'Arena resolution too small'
manifest = json.loads((ROOT / 'assets' / 'audio' / 'manifest.json').read_text())
assert len(manifest['files']) == 44, 'Original music/effects incomplete'
for entry in manifest['files']:
    data = (ROOT / 'assets' / 'audio' / entry['file']).read_bytes()
    assert len(data) == entry['bytes'], entry['file']
    assert hashlib.sha256(data).hexdigest() == entry['sha256'], entry['file']
assert (ROOT/'assets/fonts/Anton-Regular.ttf').stat().st_size > 100000
assert (ROOT/'assets/fonts/OFL.txt').is_file()
assert 'assets/avatars/' not in (ROOT/'assets/app.js').read_text(), 'Old fighters still referenced by game UI'
print('PASS:32 distinct RGBA fighter PNGs;10 arena PNGs;44 original verified audio files; local font/licence; all active fighter views use new artwork.')
