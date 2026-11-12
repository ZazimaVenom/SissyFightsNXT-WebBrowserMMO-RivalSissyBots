#!/usr/bin/env python3
"""Recompose the supplied SiSSYFiGHT artwork into dependency-free PNG avatars.

Requires Pillow for rebuilding only; the website needs no Python.
Original art: SiSSYFiGHT creators, CC BY 4.0. See ../docs/ and upstream license.
Source offsets, palettes and poses follow the supplied client/config.js and
Avatar.js. Changes: 12 selected looks, palette substitutions, neutral legs
beneath the original upper-body-only action poses, transparent fixed canvas.
Run from any directory: python tools/build_avatars.py
"""
from pathlib import Path
from PIL import Image, ImageOps, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'tools/source-assets'
OUT = ROOT / 'assets/avatars'
OUT.mkdir(parents=True, exist_ok=True)
SKIN = [
 [(204,153,102),(153,102,51),(102,51,0),(0,0,0)],
 [(255,153,102),(204,102,51),(153,51,0),(102,0,0)],
 [(255,206,156),(206,156,99),(156,99,49),(99,49,0)],
 [(255,255,153),(204,204,102),(153,153,51),(102,102,0)],
 [(204,204,153),(153,153,102),(102,102,51),(51,51,0)],
 [(207,200,96),(159,152,48),(96,96,0),(48,48,0)],
]
HAIR = [
 [(204,204,153),(153,153,102),(102,102,51),(22,27,0)],
 [(255,255,204),(204,204,153),(153,153,102),(51,51,0)],
 [(102,102,153),(51,51,102),(0,0,51),(0,0,0)],
 [(246,150,121),(237,28,36),(157,10,14),(121,0,0)],
 [(204,153,153),(153,102,102),(102,51,51),(51,0,0)],
 [(124,197,118),(57,180,74),(25,122,48),(0,94,32)],
 [(153,204,204),(102,153,153),(51,102,102),(0,51,51)],
 [(240,110,169),(236,0,140),(158,0,93),(123,0,70)],
]
UNIFORM = [
 [(156,255,255),(49,156,156),(0,99,99),(0,49,49)],
 [(255,206,255),(156,99,156),(99,49,99),(49,0,49)],
 [(206,206,255),(99,99,156),(49,49,99),(0,0,49)],
 [(156,255,156),(49,156,49),(0,99,0),(0,49,0)],
 [(255,255,156),(156,156,49),(99,99,0),(49,49,0)],
 [(255,206,156),(156,99,49),(99,49,0),(49,0,0)],
]
# face, skin, hairstyle, hair color, outfit, outfit color.
LOOKS = [
 (0,2,11,7,0,1),(2,0,8,2,2,4),(4,1,12,3,3,0),
 (3,2,2,1,1,2),(6,0,4,5,4,3),(7,1,0,7,0,4),
 (1,4,10,2,2,5),(5,2,3,3,3,1),(0,0,9,6,1,3),
 (4,1,6,0,4,2),(7,2,7,5,0,5),(2,0,1,4,2,0),
]
POSES = {'neutral':(0,0),'scratch':(6,2),'grab':(8,2),'tease':(5,4),
         'cower':(4,3),'lick':(3,1),'tattle':(7,2),'cry':(1,3),'victory':(2,1)}
SHEETS = {name: Image.open(SRC / name).convert('RGBA') for name in
          ['all_faces.png','all_hairs.png','all_bods.png','all_uniforms.png','overlays.png']}


def cell(name, width, height, index):
    img = SHEETS[name]
    columns = img.width // width
    x, y = index % columns * width, index // columns * height
    return img.crop((x, y, x + width, y + height))


def recolor(img, base, colors):
    result = img.copy()
    mapping = {tuple(a):tuple(b) for a,b in zip(base, colors)}
    result.putdata([(*mapping.get(p[:3],p[:3]),p[3]) for p in img.getdata()])
    return result


def avatar(look, action):
    face,skin,hair,haircolor,outfit,outfitcolor = look
    pose,expression = POSES[action]
    result = Image.new('RGBA',(96,128))
    head_drop = 19 if pose == 1 else 0
    face_image = recolor(cell('all_faces.png',40,50,face*7+expression),SKIN[1],SKIN[skin])
    hair_image = recolor(cell('all_hairs.png',65,70,hair),HAIR[0],HAIR[haircolor])
    body_image = recolor(cell('all_bods.png',81,116,pose),SKIN[1],SKIN[skin])
    uniform_image = recolor(cell('all_uniforms.png',81,116,outfit*9+pose),UNIFORM[0],UNIFORM[outfitcolor])
    # Original action poses are waist-up for the result polaroids. Preserve
    # their arms while attaching the corresponding standing lower body.
    if pose >= 3:
        body_base = recolor(cell('all_bods.png',81,116,0),SKIN[1],SKIN[skin])
        uniform_base = recolor(cell('all_uniforms.png',81,116,outfit*9),UNIFORM[0],UNIFORM[outfitcolor])
        body_image.alpha_composite(body_base.crop((0,90,81,116)),(0,90))
        uniform_image.alpha_composite(uniform_base.crop((0,90,81,116)),(0,90))
    # Original local origin is (0,0), offset40; translated to (48,9).
    # Face and hair are flipped in the original right-facing default pose.
    result.alpha_composite(ImageOps.mirror(face_image),(28,23+head_drop))
    result.alpha_composite(ImageOps.mirror(hair_image),(22,9+head_drop))
    result.alpha_composite(body_image,(8,0))
    result.alpha_composite(uniform_image,(8,0))
    if action == 'lick':
        # Original lick overlay (regX17, regY-47), beside the mouth.
        overlay = SHEETS['overlays.png'].crop((54,0,62,8))
        result.alpha_composite(overlay,(31,56))
    if action == 'cry':
        overlay = SHEETS['overlays.png'].crop((0,0,24,13))
        result.alpha_composite(overlay,(26,70))
    return result


def main():
    for index,look in enumerate(LOOKS):
        for action in POSES:
            img = avatar(look,action)
            img.save(OUT/f'{index}-{action}.png', optimize=True)
            if action == 'neutral':
                img.save(OUT/f'{index}.png', optimize=True)
    # Trim old branding, baked-in speech bubble and source atlas footer.
    arena = Image.open(SRC/'arena-source.png').convert('RGBA').crop((0,102,528,276))
    arena.save(ROOT/'assets/arena.png', optimize=True)
    # QA sheets are reproducible scratch output, not needed by the game.
    qa = ROOT.parent/'avatar-qa.png'
    sheet = Image.new('RGB',(96*12,148*len(POSES)), '#ddd4ee')
    draw = ImageDraw.Draw(sheet)
    for row,action in enumerate(POSES):
        for index in range(12):
            img = avatar(LOOKS[index],action)
            sheet.paste(img,(index*96,row*148),img)
            draw.text((index*96+4,row*148+128),f'{index} {action}',fill='#292337')
    sheet.save(qa)
    print(f'Wrote {len(LOOKS)*(len(POSES)+1)} transparent 96x128 PNG avatars and 528x174 arena.')
    print(f'QA sheet: {qa}')

if __name__=='__main__':main()
