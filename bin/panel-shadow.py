#!/usr/bin/env python3
# Run from the project root: python3 bin/panel-shadow.py
#
# Pre-dithered panel shadow: a 128px strip (tiles vertically) that sits
# directly left of the content panel. The same layered style as
# --shadow-drawer in resources/scss/base/_tokens.scss (stacked copies that
# double in offset and blur at a low, even alpha), carried one step further
# for the panel's longer throw -- but each pixel's shade is randomly rounded
# up or down (dithered) before the screen can band it. Black with per-pixel
# alpha, computed against the canvas.
import math, random, struct, zlib, sys
W, H = 128, 256
CANVAS = 27            # canvas green channel (#171B21); one screen level = 1/27 alpha
Phi = lambda z: 0.5 * (1 + math.erf(z / math.sqrt(2)))
# (x offset, blur, alpha) per layer, as in a box-shadow list; no spread.
LAYERS = [
    (-1, 1, 0.07),
    (-1, 2, 0.08),
    (-3, 4, 0.075),
    (-6, 8, 0.075),
    (-16, 16, 0.075),
    (-32, 32, 0.075),   # the extra step: the panel throws further than the drawer
]
def shadow(p):          # p: px from the panel edge, negative = leftward
    # Each layer is the panel's edge moved by its offset and blurred
    # (a box-shadow blur of b is a Gaussian with sigma b/2); layers stack.
    lit = 1.0
    for dx, blur, alpha in LAYERS:
        lit *= 1 - alpha * Phi((p - dx) / (blur / 2))
    return 1 - lit
random.seed(7)
rows = []
for y in range(H):
    row = bytearray([0])  # filter: none
    for i in range(W):
        p = -(W - i - 0.5)
        v = CANVAS * (1 - shadow(p))          # target shade, fractional
        q = math.floor(v + random.random())   # dithered to a whole level
        q = min(CANVAS, max(0, q))
        a = round((1 - q / CANVAS) * 255)
        row += bytes([0, a])                  # grey 0 (black), alpha
    rows.append(bytes(row))
def chunk(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', W, H, 8, 4, 0, 0, 0)) \
    + chunk(b'IDAT', zlib.compress(b''.join(rows), 9)) + chunk(b'IEND', b'')
open(sys.argv[1] if len(sys.argv) > 1 else 'public/images/panel-shadow.png', 'wb').write(png)
print(len(png), 'bytes')
