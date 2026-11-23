"""
Real 360° photographed rooms for the 360° viewer (alongside the rendered rooms from rooms.py).

Sources: CC0 360° photographs from Poly Haven (tonemapped 8K JPG, in docker/render/assets/photo/).
Each photo is cut into the viewer's six cube faces (2048 px, the photo's own resolution) and given an
"art wall": the wall the panorama is turned to face, with its distance, the eye height and the free area,
measured from the photo (see PHOTO_ROOMS). The artwork is then hung at true scale in the browser, lit by
the photo's own light on that wall. The walls of a photo cannot be repainted (no wall mask).

    docker compose --profile render run --rm render --python docker/render/photo_rooms.py -- --contact
        → docker/render/out/photo/<id>-strip.png: the four sides at yaw 0, to choose and measure a wall
    docker compose --profile render run --rm render --python docker/render/photo_rooms.py -- --room all
        → public/3d/rooms/<key>/ and updates public/3d/rooms/index.json
"""
import json
import math
import os
import subprocess
import sys

import bpy
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'assets', 'photo')
OUT = os.path.join(HERE, 'out', 'photo')
PUBLIC = os.path.abspath(os.path.join(HERE, '..', '..', 'public', '3d', 'rooms'))
FACE = 2048
# The room menu, in this order (rendered and photographed rooms together).
MENU_ORDER = ['living', 'bedroom', 'kitchen', 'office', 'dining', 'lobby',
              'photo-reading-room', 'photo-seaview-suite', 'photo-sunny-apartment']
KNEE = 0.8
LIGHT_SCALE = 2.0
WALL_ALBEDO = 0.8

# Same faces as rooms.py: view direction and image-up in Blender space (x right, y forward, z up).
FACES = {
    'f': ((0, 1, 0), (0, 0, 1)),
    'r': ((1, 0, 0), (0, 0, 1)),
    'b': ((0, -1, 0), (0, 0, 1)),
    'l': ((-1, 0, 0), (0, 0, 1)),
    'u': ((0, 0, 1), (0, -1, 0)),
    'd': ((0, 0, -1), (0, 1, 0)),
}

# Measured per photo: yaw turns the panorama so the chosen wall is straight ahead; distance is eye → wall
# (metres), eye is the camera height; left/right/bottom/top bound the free wall area (metres, from the eye).
PHOTO_ROOMS = {
    # Measured from squared-up views: door tops / cornices / skirting lines, cross-checked with sockets (0.30 m),
    # a desk top (0.76 m) and doorways (2.06–2.2 m).
    'photo-reading-room': dict(
        photo='reading_room', title='Reading room', yaw=-33.7, distance=1.68, eye=1.39,
        left=-0.40, right=1.15, bottom=-0.43, top=1.35, albedo=0.72,
        credit='photo: Poly Haven (CC0)',
    ),
    'photo-seaview-suite': dict(
        photo='relax_inn_seaview_suite', title='Sea-view suite', yaw=-36.9, distance=1.84, eye=1.45,
        left=-1.55, right=0.35, bottom=-0.72, top=0.95, albedo=0.7,
        erase=[(238, 400, 444, 610)],  # the wall clock
        ceiling=2.53,  # cornice underside
        credit='photo: Poly Haven (CC0)',
    ),
    'photo-sunny-apartment': dict(
        photo='lebombo', title='Sunny apartment', yaw=-25.7, distance=2.38, eye=1.43, ceiling=3.2,
        left=-2.2, right=-0.25, bottom=-0.80, top=1.25, albedo=0.75,
        credit='photo: Poly Haven (CC0)',
    ),
}


def args():
    argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else []
    o = {'contact': '--contact' in argv, 'calib': '--calib' in argv, 'room': 'all', 'yaw': 0.0}
    if '--room' in argv:
        o['room'] = argv[argv.index('--room') + 1]
    if '--yaw' in argv:
        o['yaw'] = float(argv[argv.index('--yaw') + 1])
    return o


def load(path):
    img = bpy.data.images.load(path)
    img.colorspace_settings.name = 'Non-Color'  # keep the photo's own (sRGB) values
    w, h = img.size
    px = np.empty(w * h * 4, dtype=np.float32)
    img.pixels.foreach_get(px)
    bpy.data.images.remove(img)
    return px.reshape(h, w, 4)[::-1, :, :3].copy()  # top row first


def sample(equi, dirs):
    """Bilinear lookup in an equirectangular image (Blender's convention) for unit directions (..., 3)."""
    h, w = equi.shape[:2]
    x, y, z = dirs[..., 0], dirs[..., 1], dirs[..., 2]
    u = -np.arctan2(y, x) / (2 * math.pi) + 0.5
    v = np.arctan2(z, np.hypot(x, y)) / math.pi + 0.5
    fx = (u * w - 0.5) % w
    fy = np.clip((1 - v) * h - 0.5, 0, h - 1)
    x0 = np.floor(fx).astype(int)
    y0 = np.floor(fy).astype(int)
    x1 = (x0 + 1) % w
    y1 = np.minimum(y0 + 1, h - 1)
    ax = (fx - x0)[..., None]
    ay = (fy - y0)[..., None]
    top = equi[y0, x0] * (1 - ax) + equi[y0, x1] * ax
    bottom = equi[y1, x0] * (1 - ax) + equi[y1, x1] * ax
    return top * (1 - ay) + bottom * ay


def face_dirs(name, size, yaw_deg):
    d, up = (np.array(v, dtype=np.float64) for v in FACES[name])
    right = np.cross(d, up)
    t = (np.arange(size) + 0.5) / size * 2 - 1
    X, Y = np.meshgrid(t, -t)  # top row first
    dirs = d + X[..., None] * right + Y[..., None] * up
    dirs /= np.linalg.norm(dirs, axis=-1, keepdims=True)
    a = math.radians(yaw_deg)
    rot = np.array([[math.cos(a), -math.sin(a), 0], [math.sin(a), math.cos(a), 0], [0, 0, 1]])
    return dirs @ rot.T


def save_png(path, rgb):
    h, w = rgb.shape[:2]
    img = bpy.data.images.new(os.path.basename(path), w, h, alpha=False, float_buffer=False)
    img.colorspace_settings.name = 'Non-Color'
    data = np.ones((h, w, 4), dtype=np.float32)
    data[..., :3] = np.clip(rgb, 0, 1)
    img.pixels.foreach_set(data[::-1].ravel())
    img.filepath_raw = path
    img.file_format = 'PNG'
    img.save()
    bpy.data.images.remove(img)


def webp(src, dst, quality=92, resize=None):
    cmd = ['cwebp', '-quiet', '-q', str(quality), '-m', '6', '-sharp_yuv', '-noalpha']
    if resize:
        cmd += ['-resize', str(resize), str(resize)]
    subprocess.run(cmd + [src, '-o', dst], check=True)


def srgb_to_linear(c):
    return np.where(c <= 0.04045, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)


def linear_to_srgb(c):
    c = np.clip(c, 0, 1)
    return np.where(c <= 0.0031308, c * 12.92, 1.055 * np.power(c, 1 / 2.4) - 0.055)


def tone_inverse(y):
    s = 1 - KNEE
    return np.where(y <= KNEE, y, KNEE - s * np.log(np.maximum(1 - (np.minimum(y, 0.9995) - KNEE) / s, 1e-4)))


def contact(ids):
    os.makedirs(OUT, exist_ok=True)
    for pid in ids:
        equi = load(os.path.join(SRC, f'{pid}.jpg'))
        strip = np.concatenate([sample(equi, face_dirs(n, 512, 0)) for n in ('l', 'f', 'r', 'b')], axis=1)
        save_png(os.path.join(OUT, f'{pid}-strip.png'), strip)
        print('strip', pid, flush=True)


def calib(pid, yaw):
    """Forward face at a yaw, 1024 px, with a grid every 64 px (bold every 256) to measure walls from."""
    os.makedirs(OUT, exist_ok=True)
    equi = load(os.path.join(SRC, f'{pid}.jpg'))
    img = sample(equi, face_dirs('f', 1024, yaw))
    for k in range(0, 1025, 64):
        c = np.array([1.0, 0.1, 0.1]) if k % 256 == 0 else np.array([0.1, 0.9, 1.0])
        w = 2 if k % 256 == 0 else 1
        img[max(0, k - w):k + w, :] = c
        img[:, max(0, k - w):k + w] = c
    save_png(os.path.join(OUT, f'{pid}-calib-{yaw:g}.png'), img)
    print('calib', pid, yaw, flush=True)


def erase(face, rect, size):
    """Fills a rectangle (x0, y0, x1, y1 in 1024-px calibration-view pixels) with the wall around it: a smooth
    blend of the four borders (keeps the light's gradient) plus the wall's own fine texture, feathered in."""
    k = size / 1024
    x0, y0, x1, y1 = (int(round(v * k)) for v in rect)
    band = max(2, int(3 * k))
    top = face[y0 - band:y0].mean(axis=0)[x0:x1]
    bottom = face[y1:y1 + band].mean(axis=0)[x0:x1]
    left = face[y0:y1, x0 - band:x0].mean(axis=1)
    right = face[y0:y1, x1:x1 + band].mean(axis=1)
    h, w = y1 - y0, x1 - x0
    u = np.linspace(0, 1, w)[None, :, None]
    v = np.linspace(0, 1, h)[:, None, None]
    tl, tr, bl, br = top[0], top[-1], bottom[0], bottom[-1]
    fill = ((1 - v) * top[None] + v * bottom[None] + (1 - u) * left[:, None] + u * right[:, None]
            - ((1 - u) * (1 - v) * tl + u * (1 - v) * tr + (1 - u) * v * bl + u * v * br))
    # The wall's grain: a patch of real wall just above (tiled), with its local average removed.
    src = face[max(0, y0 - h - band):y0 - band, x0:x1]
    if src.shape[0] > 4:
        reps = int(np.ceil(h / src.shape[0]))
        grain = np.concatenate([src] * reps, axis=0)[:h]
        grain = grain - grain.mean(axis=(0, 1), keepdims=True)
        fill = fill + 0.8 * grain
    # Feathered edge so no seam shows.
    f = max(2, int(4 * k))
    mask = np.ones((h, w, 1))
    ramp = np.linspace(0, 1, f)
    mask[:f] *= ramp[:, None, None]
    mask[-f:] *= ramp[::-1][:, None, None]
    mask[:, :f] *= ramp[None, :, None]
    mask[:, -f:] *= ramp[None, ::-1, None]
    face[y0:y1, x0:x1] = mask * fill + (1 - mask) * face[y0:y1, x0:x1]
    return face


def _blur(img, k):
    kern = np.ones(k) / k
    out = img
    for axis in (0, 1):
        out = np.apply_along_axis(lambda v: np.convolve(np.pad(v, k // 2, mode='edge'), kern, mode='valid')[: v.size], axis, out)
    return out


def wall_mask(face, cfg, size, ref_hue=None):
    """The art wall's painted surface in the forward face, so the customer can repaint it (a feature wall).
    A flood fill from the bare wall where art hangs, spreading over smooth surface only: it stops at the edges of
    doors, frames, furniture, lamps and plants, while the light's gentle gradients across the wall pass. Limited to
    the band between floor and cornice. Soft edges; returns (mask, wall colour in linear light)."""
    F, eye = cfg['distance'], cfg['eye']
    n = size // 2  # work at half resolution
    small = face[::2, ::2]
    lin = tone_inverse(srgb_to_linear(small))
    lum = lin @ np.array([0.2126, 0.7152, 0.0722]) + 1e-4
    # Smooth = small relative gradient after a light blur (ignores the wall's fine texture).
    soft = _blur(np.log(lum), 5)
    gy, gx = np.gradient(soft)
    smooth = np.hypot(gx, gy) < cfg.get('edge', 0.012)
    half = n / 2

    def px(xm, zm):
        return half + xm / F * half, half - zm / F * half

    rows = np.arange(n)[:, None]
    floor_y = half + eye / F * half
    top_y = half - (cfg['top'] + 0.15) / F * half
    # Down to the floor line; upwards it stops at the ceiling's own edge (cfg 'ceiling' if the cornice is soft).
    band = rows < floor_y - 2
    if 'ceiling' in cfg:
        band = band & (rows > half - (cfg['ceiling'] - eye) / F * half)
    if 'paint_x' in cfg:
        cols = np.arange(n)[None, :]
        xa, _ = px(cfg['paint_x'][0], 0)
        xb, _ = px(cfg['paint_x'][1], 0)
        band = band & (cols >= xa) & (cols <= xb)
    allowed = smooth & band
    chroma = lin / lin.sum(axis=-1, keepdims=True).clip(1e-5)
    if ref_hue is None:
        # Art wall: seed = the bare free area where art hangs.
        (ax, ay), (bx, by) = px(cfg['left'], cfg['top']), px(cfg['right'], cfg['bottom'])
        seed = np.zeros((n, n), dtype=bool)
        seed[int(max(0, ay)):int(min(n, by)), int(max(0, ax)):int(min(n, bx))] = True
    else:
        # The other walls (same paint): seed = smooth pixels of the art wall's hue around eye level, where
        # there is wall in every direction (ceiling and floor are far from the horizon).
        allowed = smooth
        band = np.ones((n, n), dtype=bool)
        hue_ok = np.linalg.norm(chroma - ref_hue, axis=-1) < cfg.get('hue', 0.03) * 0.8
        seed = np.zeros((n, n), dtype=bool)
        seed[int(n * 0.4):int(n * 0.62)] = True
        seed &= smooth & hue_ok
    region = seed & allowed
    for _ in range(4 * n):
        grown = region.copy()
        grown[1:] |= region[:-1]
        grown[:-1] |= region[1:]
        grown[:, 1:] |= region[:, :-1]
        grown[:, :-1] |= region[:, 1:]
        grown &= allowed
        if grown.sum() == region.sum():
            break
        region = grown
    # Second pass: shadows on the same wall (sharp-edged, but the same paint) — grow into neighbouring pixels of
    # the wall's own hue, whatever their brightness. Doors, wood, fabrics and plants have other hues and stay out.
    ref_c = ref_hue if ref_hue is not None else (np.median(chroma[region].reshape(-1, 3), axis=0) if region.any() else chroma.reshape(-1, 3).mean(axis=0))
    same_hue = (np.linalg.norm(chroma - ref_c, axis=-1) < cfg.get('hue', 0.03)) & band & (lum > 0.03)
    if ref_hue is not None:
        # Never above the wall found so far in each column (keeps a white ceiling of a similar hue out).
        has = region.any(axis=0)
        top = np.where(has, region.argmax(axis=0), n)
        same_hue &= rows >= top[None, :]
    allowed2 = allowed | same_hue
    for _ in range(2 * n):
        grown = region.copy()
        grown[1:] |= region[:-1]
        grown[:-1] |= region[1:]
        grown[:, 1:] |= region[:, :-1]
        grown[:, :-1] |= region[:, 1:]
        grown &= allowed2
        if grown.sum() == region.sum():
            break
        region = grown
    # Close pin-holes (fine texture flagged as edges), keep real objects (switches, sockets) out.
    m = region.astype(np.float64)
    closed = _blur(m, 5) > 0.55
    m = np.maximum(m, closed & band)
    m = np.clip(_blur(m.astype(np.float64), 3) * 1.4 - 0.2, 0, 1)
    # Back to full resolution.
    m = np.repeat(np.repeat(m, 2, axis=0), 2, axis=1)[:size, :size]
    ref = region & seed
    ref_lin = np.median(lin[ref].reshape(-1, 3), axis=0) if ref.any() else np.array([0.6, 0.6, 0.6])
    wall_colour = cfg.get('albedo', 0.72) * ref_lin / float(ref_lin @ np.array([0.2126, 0.7152, 0.0722]))
    return m, wall_colour, ref_c


def build(key, cfg):
    equi = load(os.path.join(SRC, f"{cfg['photo']}.jpg"))
    dest = os.path.join(PUBLIC, key)
    tmp = os.path.join(OUT, key)
    os.makedirs(dest, exist_ok=True)
    os.makedirs(tmp, exist_ok=True)
    faces = {}
    for name in FACES:
        rgb = sample(equi, face_dirs(name, FACE, cfg['yaw']))
        if name == 'f':
            for rect in cfg.get('erase', []):
                rgb = erase(rgb, rect, FACE)
        faces[name] = rgb
        png = os.path.join(tmp, f'{name}.png')
        save_png(png, rgb)
        webp(png, os.path.join(dest, f'{name}.webp'), quality=93)
        webp(png, os.path.join(dest, f'{name}-sm.webp'), quality=80, resize=512)
        # The art wall can be repainted (a feature wall). Other walls in a photo keep their colours: automatic
        # detection there is not reliable (white wardrobes, ceilings and floors look like paint).
        mpng = os.path.join(tmp, f'{name}-mask.png')
        if name == 'f' and cfg.get('paint'):  # off for photos (see 'paintable' below)
            mask, wall_colour, _ = wall_mask(rgb, cfg, FACE)
            save_png(mpng, np.repeat(mask[..., None], 3, axis=2))
            # A preview for checking the mask: the wall tinted red.
            check = rgb.copy()
            check[..., 0] = np.clip(check[..., 0] + 0.5 * mask, 0, 1)
            save_png(os.path.join(OUT, f'{key}-{name}-mask-check.png'), check[::2, ::2])
            webp(mpng, os.path.join(dest, f'{name}-mask.webp'), quality=90)
            webp(mpng, os.path.join(dest, f'{name}-mask-sm.webp'), quality=85, resize=512)
        else:
            save_png(mpng, np.zeros((64, 64, 3), dtype=np.float32))
            webp(mpng, os.path.join(dest, f'{name}-mask.webp'), quality=50)
            webp(mpng, os.path.join(dest, f'{name}-mask-sm.webp'), quality=50)

    # Light on the art wall, from the photo itself: the wall's brightness ÷ its assumed paint albedo
    # (neutralised colour, so a tinted wall does not tint the artwork).
    F, eye = cfg['distance'], cfg['eye']
    xa, xb = max(cfg['left'] - 0.6, -F), min(cfg['right'] + 0.6, F)
    za, zb = max(-eye, -F), min(cfg['top'] + 0.4, F)
    gw, gh = 128, max(16, int(128 * (zb - za) / (xb - xa)))
    X, Z = np.meshgrid(np.linspace(xa, xb, gw), np.linspace(zb, za, gh))
    fa = faces['f']
    px = np.clip(((X / F) * 0.5 + 0.5) * (FACE - 1), 0, FACE - 1).astype(int)
    py = np.clip(((-Z / F) * 0.5 + 0.5) * (FACE - 1), 0, FACE - 1).astype(int)
    lin = tone_inverse(srgb_to_linear(fa[py, px]))
    lum = lin @ np.array([0.2126, 0.7152, 0.0722])
    # Smooth: light varies slowly across a wall; this also removes wall texture and small objects.
    k = np.ones(9) / 9
    for axis in (0, 1):
        lum = np.apply_along_axis(lambda v: np.convolve(np.pad(v, 4, mode='edge'), k, mode='valid'), axis, lum)
    tint = np.mean(lin.reshape(-1, 3), axis=0)
    tint = 0.7 + 0.3 * tint / max(float(np.mean(tint)), 1e-4)
    light = (lum / cfg.get('albedo', 0.72))[..., None] * tint
    lpng = os.path.join(tmp, 'light.png')
    save_png(lpng, linear_to_srgb(np.clip(light / LIGHT_SCALE, 0, 1)))
    webp(lpng, os.path.join(dest, 'light.webp'), quality=95)

    three = lambda v: [round(v[0], 5), round(v[2], 5), round(-v[1], 5)]  # noqa: E731
    meta = {
        'key': key,
        'title': cfg['title'],
        'kind': 'photo',
        # Real photos are shown as photographed: automatic wall detection in photos is not clean enough to
        # repaint (shadows, switches, white furniture). The rendered rooms are the ones to try paint colours in.
        'paintable': False,
        **({'wallColour': [round(float(c), 4) for c in wall_colour]} if cfg.get('paint') else {}),
        'credit': cfg['credit'],
        'faceSize': FACE,
        'faces': {n: {'dir': three(d), 'up': three(u)} for n, (d, u) in FACES.items()},
        'eyeHeight': eye,
        'room': {'width': 2 * F, 'front': F, 'back': F, 'height': cfg['top'] + eye + 0.3},
        'artWall': {'distance': F, 'floor': -eye, 'ceiling': cfg['top'] + 0.3,
                    'minBottom': cfg['bottom'], 'maxTop': cfg['top'], 'left': cfg['left'], 'right': cfg['right']},
        'light': {'file': 'light.webp', 'scale': LIGHT_SCALE, 'rect': [round(xa, 4), round(xb, 4), round(za, 4), round(zb, 4)]},
        'wallAlbedo': WALL_ALBEDO,
        'knee': KNEE,
    }
    with open(os.path.join(dest, 'meta.json'), 'w') as fh:
        json.dump(meta, fh, indent=2)
    print(f'[{key}] done → {dest}', flush=True)


def write_index():
    """Every room folder with a meta.json: rendered rooms first (paintable), then photographed rooms."""
    rooms = []
    for key in sorted(os.listdir(PUBLIC)):
        meta_path = os.path.join(PUBLIC, key, 'meta.json')
        if os.path.isfile(meta_path):
            with open(meta_path) as fh:
                meta = json.load(fh)
            rooms.append({'key': key, 'title': meta['title'], 'kind': meta.get('kind', 'render')})
    rooms.sort(key=lambda r: (MENU_ORDER.index(r['key']) if r['key'] in MENU_ORDER else 99, r['title']))
    with open(os.path.join(PUBLIC, 'index.json'), 'w') as fh:
        json.dump(rooms, fh, indent=2)


def main():
    o = args()
    if o['calib']:
        calib(o['room'], o['yaw'])
        return
    if o['contact']:
        ids = sorted(f[:-4] for f in os.listdir(SRC) if f.endswith('.jpg')) if o['room'] == 'all' else o['room'].split(',')
        contact(ids)
        return
    keys = list(PHOTO_ROOMS) if o['room'] == 'all' else o['room'].split(',')
    for key in keys:
        build(key, PHOTO_ROOMS[key])
    write_index()


main()
