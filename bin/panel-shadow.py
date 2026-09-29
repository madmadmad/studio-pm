#!/usr/bin/env python3
# Run from the project root: python3 bin/panel-shadow.py
#
# Pre-dithered panel shadow: a 128px strip (tiles vertically) that sits
# directly left of the content panel. Same falloff as the CSS shadow it
# replaces -- -24px 0 64px -8px @ .30 plus -2px 0 6px -2px @ .20 -- but each
# pixel's shade is randomly rounded up or down (dithered) before the screen
# can band it. Black with per-pixel alpha, computed against the canvas.
import math, random, struct, zlib, sys
W, H = 128, 256
CANVAS = 14            # canvas green channel (#0C0E11); one screen level = 1/14 alpha
Phi = lambda z: 0.5 * (1 + math.erf(z / math.sqrt(2)))
def shadow(p):          # p: px from the panel edge, negative = leftward
    s1 = 0.30 * Phi((p + 16) / 32)   # offset -24, spread -8 -> edge at -16; blur 64 -> sigma 32
    s2 = 0.20 * Phi(p / 3)           # offset -2, spread -2 -> edge at 0; blur 6 -> sigma 3
    return 1 - (1 - s1) * (1 - s2)
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
