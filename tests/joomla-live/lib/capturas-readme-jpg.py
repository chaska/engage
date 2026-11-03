#!/usr/bin/python3
"""Convierte las capturas PNG del README a JPG calidad 85, SIN metadatos (EXIF/XMP/ICC/GPS), y las deja en el directorio de destino.
Uso: capturas-readme-jpg.py ORIGEN DESTINO [ANCHO_MAX]. Si un JPG pasa de 110 KB se reduce el ancho por pasos hasta que quepa (minimo 640 px)."""
import os, sys
from PIL import Image
src, dst = sys.argv[1], sys.argv[2]
ancho = int(sys.argv[3]) if len(sys.argv) > 3 else 1000
os.makedirs(dst, exist_ok=True)
for f in sorted(os.listdir(src)):
    if not f.endswith('.png'):
        continue
    im = Image.open(os.path.join(src, f)).convert('RGB')
    w = min(ancho, im.width)
    while True:
        r = im if w == im.width else im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
        out = os.path.join(dst, f[:-4] + '.jpg')
        r.save(out, 'JPEG', quality=85, optimize=True, progressive=False, exif=b'', icc_profile=None)
        if os.path.getsize(out) <= 100 * 1024 or w <= 700:
            break
        w -= 60
    print(f"{f[:-4]}.jpg {r.width}x{r.height} {os.path.getsize(out)//1024} KB")
