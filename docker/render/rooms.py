"""
Renders the photoreal 360° rooms used by the "360° room" preview (resources/js/components/enquiry/room-360.ts).

    docker compose --profile render run --rm render --python docker/render/rooms.py -- --room living
    docker compose --profile render run --rm render --python docker/render/rooms.py -- --room all --size 2048 --samples 160
    ... -- --room living --size 384 --samples 24 --preview     (quick composition check, written to docker/render/out)

For each room it builds the scene from CC0 Poly Haven assets (docker/render/fetch_assets.py), renders a cube
panorama from one eye point with Cycles (6 faces, 90° each, OpenImageDenoise) and writes to public/3d/rooms/<room>/:

  <face>.webp      the face (sRGB, after the tone curve)
  <face>-mask.webp painted-wall mask (white = wall): the browser re-paints the walls in the customer's colour,
                   wall pixel × colour / WALL_ALBEDO, in linear light
  <face>-sm.webp, <face>-mask-sm.webp   512 px versions shown while the full faces load
  light.webp       light falling on the art wall (linear light / LIGHT_SCALE, sRGB-encoded), used to light the
                   live artwork exactly like the wall around it
  meta.json        face orientations, wall geometry (metres, relative to the eye), where art can hang, tone curve

Faces: f = towards the art wall, r = right, b = behind, l = left, u = up, d = down.
Coordinates in meta.json are three.js style: x right, y up, -z forward (towards the art wall), origin = the eye.
"""
import json
import math
import os
import random
import shutil
import subprocess
import sys

import bpy
import numpy as np
from mathutils import Vector

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, 'assets')
OUT_TMP = os.path.join(HERE, 'out')
PUBLIC = os.path.abspath(os.path.join(HERE, '..', '..', 'public', '3d', 'rooms'))

WALL_ALBEDO = 0.8      # linear albedo the walls are rendered with
LIGHT_SCALE = 2.0      # light.webp stores light / LIGHT_SCALE
KNEE = 0.8             # tone curve: linear up to KNEE, then a soft shoulder up to 1.0 (invertible in the shader)
TARGET_WALL = 0.74     # auto exposure: median art-wall brightness (linear, after exposure)
WALL_TINT = (1.0, 0.975, 0.935)  # auto white balance: the art wall keeps a slight warmth, like a photo
WALL_INDEX = 1

# Blender camera rotations (XYZ euler, degrees) for each face, with the face's view direction and image-up
# in Blender space (x right, y forward, z up).
FACES = {
    'f': ((90, 0, 0), (0, 1, 0), (0, 0, 1)),
    'r': ((90, 0, -90), (1, 0, 0), (0, 0, 1)),
    'b': ((90, 0, 180), (0, -1, 0), (0, 0, 1)),
    'l': ((90, 0, 90), (-1, 0, 0), (0, 0, 1)),
    'u': ((180, 0, 0), (0, 0, 1), (0, -1, 0)),
    'd': ((0, 0, 0), (0, 0, -1), (0, 1, 0)),
}


# --------------------------------------------------------------------------------------------- rooms

def room_configs():
    """Room layouts. y = distance in front of the eye (art wall at y = depth_front), x right, z up (metres).
    Windows stop below the ceiling ledge (height - 0.3)."""
    return {
        'living': dict(
            title='Living room', width=6.0, front=3.4, back=2.6, height=3.0, eye=1.45,
            floor=('wood_floor', 2.2, (1.0, 1.0, 1.0)), window=dict(side='l', centre=1.7, width=2.0, sill=0.35, top=2.66),
            drape=(0.36, 0.35, 0.33),
            props=[
                dict(asset='sofa_03', at=(0, 'wall'), rot=0, sx=0.8),
                dict(asset='side_table_01', at=(1.4, 'wall'), rot=0, gap=0.12),
                dict(asset='ceramic_vase_02', on='side_table_01', rot=0),
                dict(asset='modern_coffee_table_01', at=(0, 1.95), rot=90),
                dict(asset='potted_plant_01', at=(-2.45, 'wall'), rot=30, gap=0.25),
                dict(asset='mid_century_lounge_chair', at=(2.05, 1.15), rot=-125),
                dict(asset='modern_ceiling_lamp_01', at=(0, -0.4), rot=0, ceiling=True),
                dict(asset='potted_plant_02', at=(-2.45, -2.2), rot=0),
                dict(asset='ClassicNightstand_01', at=(2.6, -1.4), rot=-90),
                dict(asset='ceramic_vase_01', on='ClassicNightstand_01', rot=0),
            ],
            extras=[dict(kind='books', on='modern_coffee_table_01', n=3, seed=3, dx=-0.2)],
            rug=dict(at=(0, 2.0), size=(2.8, 2.0), tint=(0.36, 0.33, 0.29)),
        ),
        'bedroom': dict(
            title='Bedroom', width=5.0, front=3.6, back=2.0, height=2.9, eye=1.45,
            floor=('rectangular_parquet', 2.0, (1.0, 1.0, 1.0)), window=dict(side='r', centre=1.45, width=1.6, sill=0.55, top=2.56),
            drape=(0.42, 0.42, 0.42),
            props=[
                dict(asset='modern_bed', at=(0, 'wall'), gap=0.07),
                dict(asset='ClassicNightstand_01', at=(-1.38, 'wall'), rot=0, gap=0.07),
                dict(asset='ClassicNightstand_01', at=(1.38, 'wall'), rot=0, gap=0.07),
                dict(asset='ceramic_vase_01', on='ClassicNightstand_01', rot=0),
                dict(asset='potted_plant_04', at=(-2.05, 'wall'), rot=0, gap=0.2),
            ],
            extras=[dict(kind='headboard_panel'), dict(kind='bedside_pendants'), dict(kind='window_seat')],
            rug=dict(at=(0, 1.6), size=(2.6, 1.8), tint=(0.3, 0.29, 0.28)),
        ),
        'kitchen': dict(
            title='Kitchen', width=5.4, front=3.6, back=2.6, height=2.9, eye=1.45,
            floor=('wood_floor', 2.2, (1.0, 0.98, 0.95)), window=dict(side='r', centre=0.9, width=1.8, sill=0.7, top=2.56),
            drape=(0.55, 0.52, 0.47),
            props=[
                dict(asset='dining_chair_02', at=(-0.5, 1.95), rot=0),
                dict(asset='dining_chair_02', at=(0.5, 1.95), rot=0),
                dict(asset='potted_plant_04', at=(2.3, 'wall'), rot=0, gap=0.25),
                dict(asset='potted_plant_02', at=(2.25, -2.1), rot=0),
            ],
            extras=[dict(kind='kitchen')],
            rug=None,
        ),
        'office': dict(
            title='Office', width=5.0, front=3.0, back=2.5, height=2.9, eye=1.45,
            floor=('herringbone_parquet', 1.6, (1.0, 1.0, 1.0)), window=dict(side='l', centre=1.2, width=1.8, sill=0.8, top=2.56),
            drape=(0.3, 0.32, 0.35),
            props=[
                dict(asset='metal_office_desk', at=(0, 'wall'), rot=0, gap=0.05),
                dict(asset='modern_arm_chair_01', at=(0, 2.0), rot=180),
                dict(asset='desk_lamp_arm_01', on='metal_office_desk', rot=20, dx=-0.5),
                dict(asset='potted_plant_02', at=(-2.0, -1.9), rot=0),
            ],
            extras=[dict(kind='shelving', side='r', centre=0.2, width=2.2),
                    dict(kind='books', on='metal_office_desk', n=2, seed=7, dx=0.55)],
            rug=dict(at=(0, 1.6), size=(2.4, 1.8), tint=(0.22, 0.24, 0.26)),
        ),
        'dining': dict(
            title='Dining room', width=6.0, front=3.4, back=3.2, height=3.0, eye=1.45,
            floor=('herringbone_parquet', 1.6, (0.92, 0.9, 0.88)), window=dict(side='r', centre=1.6, width=2.0, sill=0.5, top=2.66),
            drape=(0.45, 0.4, 0.34),
            props=[
                dict(asset='modern_wooden_cabinet', at=(0, 'wall'), rot=0, gap=0.04),
                dict(asset='ceramic_vase_03', on='modern_wooden_cabinet', rot=0, dx=0.55),
                dict(asset='dining_table', at=(0, -1.6), rot=90),
                dict(asset='dining_chair_02', at=(-0.55, -0.95), rot=180),
                dict(asset='dining_chair_02', at=(0.55, -0.95), rot=180),
                dict(asset='dining_chair_02', at=(-0.55, -2.25), rot=0),
                dict(asset='dining_chair_02', at=(0.55, -2.25), rot=0),
                dict(asset='modern_ceiling_lamp_01', at=(0, -1.6), rot=0, ceiling=True),
                dict(asset='potted_plant_01', at=(-2.5, 'wall'), rot=0, gap=0.3),
            ],
            extras=[dict(kind='books', on='modern_wooden_cabinet', n=3, seed=11, dx=-0.6)],
            rug=dict(at=(0, -1.6), size=(2.4, 3.0), tint=(0.34, 0.3, 0.26)),
        ),
        'lobby': dict(
            title='Hotel lobby', width=8.0, front=4.2, back=3.0, height=3.6, eye=1.5,
            floor=('marble_01', 2.4, (1.0, 1.0, 1.0)), window=dict(side='r', centre=1.8, width=2.6, sill=0.3, top=3.26),
            drape=(0.3, 0.26, 0.22),
            props=[
                dict(asset='ClassicConsole_01', at=(0, 'wall'), rot=0, gap=0.04),
                dict(asset='brass_vase_01', on='ClassicConsole_01', rot=0, dx=-0.45),
                dict(asset='ArmChair_01', at=(-1.55, 2.1), rot=-80),
                dict(asset='ArmChair_01', at=(1.55, 2.1), rot=80),
                dict(asset='coffee_table_round_01', at=(0, 2.1), rot=0),
                dict(asset='potted_plant_02', at=(-3.3, 'wall'), rot=0, gap=0.3),
                dict(asset='potted_plant_02', at=(3.3, 'wall'), rot=60, gap=0.3),
            ],
            extras=[dict(kind='wall_panels', sides=('l', 'b')),
                    dict(kind='books', on='coffee_table_round_01', n=2, seed=21)],
            rug=dict(at=(0, 2.1), size=(3.4, 2.4), tint=(0.28, 0.22, 0.18)),
        ),
    }


# --------------------------------------------------------------------------------------------- helpers

def args():
    argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else []
    opts = {'room': 'living', 'size': 2048, 'samples': 160, 'preview': False}
    i = 0
    while i < len(argv):
        key = argv[i].lstrip('-')
        if key == 'preview':
            opts['preview'] = True
        else:
            opts[key] = argv[i + 1]
            i += 1
        i += 1
    opts['size'] = int(opts['size'])
    opts['samples'] = int(opts['samples'])
    return opts


def principled(name, colour=(0.8, 0.8, 0.8), rough=0.5, spec=0.5, metal=0.0):
    m = bpy.data.materials.new(name)
    m.use_nodes = True
    b = m.node_tree.nodes['Principled BSDF']
    b.inputs['Base Color'].default_value = (*colour, 1)
    b.inputs['Roughness'].default_value = rough
    b.inputs['Specular IOR Level'].default_value = spec
    b.inputs['Metallic'].default_value = metal
    return m


def textured(name, tex_id, tile_m, tint=(1, 1, 1), rough_mul=1.0, flat=None):
    """PBR material from a Poly Haven texture set, mapped in metres (object UVs are metres)."""
    m = bpy.data.materials.new(name)
    m.use_nodes = True
    nt = m.node_tree
    b = nt.nodes['Principled BSDF']
    uv = nt.nodes.new('ShaderNodeTexCoord')
    mapping = nt.nodes.new('ShaderNodeMapping')
    mapping.inputs['Scale'].default_value = (1 / tile_m, 1 / tile_m, 1)
    nt.links.new(uv.outputs['UV'], mapping.inputs['Vector'])

    def tex(map_name, colour_space):
        n = nt.nodes.new('ShaderNodeTexImage')
        n.image = bpy.data.images.load(os.path.join(ASSETS, 'textures', tex_id, f'{map_name}.jpg'))
        n.image.colorspace_settings.name = colour_space
        nt.links.new(mapping.outputs['Vector'], n.inputs['Vector'])
        return n

    diff = tex('Diffuse', 'sRGB')
    mix = nt.nodes.new('ShaderNodeMix')
    mix.data_type = 'RGBA'
    mix.blend_type = 'MULTIPLY'
    mix.inputs['Factor'].default_value = 1.0
    nt.links.new(diff.outputs['Color'], mix.inputs[6])
    mix.inputs[7].default_value = (*tint, 1)
    if flat is None:
        nt.links.new(mix.outputs[2], b.inputs['Base Color'])
    else:
        b.inputs['Base Color'].default_value = (*flat, 1)

    rough = tex('Rough', 'Non-Color')
    if rough_mul != 1.0:
        mul = nt.nodes.new('ShaderNodeMath')
        mul.operation = 'MULTIPLY'
        mul.inputs[1].default_value = rough_mul
        nt.links.new(rough.outputs['Color'], mul.inputs[0])
        nt.links.new(mul.outputs[0], b.inputs['Roughness'])
    else:
        nt.links.new(rough.outputs['Color'], b.inputs['Roughness'])

    nor = tex('nor_gl', 'Non-Color')
    nmap = nt.nodes.new('ShaderNodeNormalMap')
    nt.links.new(nor.outputs['Color'], nmap.inputs['Color'])
    nt.links.new(nmap.outputs['Normal'], b.inputs['Normal'])
    return m


def box(name, x0, x1, y0, y1, z0, z1, material, pass_index=0):
    """Axis-aligned box with UVs in metres on every side."""
    mesh = bpy.data.meshes.new(name)
    v = [(x0, y0, z0), (x1, y0, z0), (x1, y1, z0), (x0, y1, z0), (x0, y0, z1), (x1, y0, z1), (x1, y1, z1), (x0, y1, z1)]
    f = [(0, 3, 2, 1), (4, 5, 6, 7), (0, 1, 5, 4), (1, 2, 6, 5), (2, 3, 7, 6), (3, 0, 4, 7)]
    mesh.from_pydata(v, [], f)
    uv = mesh.uv_layers.new()
    for poly in mesh.polygons:
        n = poly.normal
        for li in poly.loop_indices:
            co = mesh.vertices[mesh.loops[li].vertex_index].co
            if abs(n.z) > 0.5:
                uv.data[li].uv = (co.x, co.y)
            elif abs(n.x) > 0.5:
                uv.data[li].uv = (co.y, co.z)
            else:
                uv.data[li].uv = (co.x, co.z)
    mesh.materials.append(material)
    obj = bpy.data.objects.new(name, mesh)
    obj.pass_index = pass_index
    bpy.context.collection.objects.link(obj)
    return obj


def world_bbox(objs):
    pts = [o.matrix_world @ Vector(c) for o in objs if o.type == 'MESH' for c in o.bound_box]
    if not pts:
        return Vector((0, 0, 0)), Vector((0, 0, 0))
    return (Vector((min(p.x for p in pts), min(p.y for p in pts), min(p.z for p in pts))),
            Vector((max(p.x for p in pts), max(p.y for p in pts), max(p.z for p in pts))))


def import_asset(asset_id):
    folder = os.path.join(ASSETS, 'models', asset_id)
    gltf = next(f for f in os.listdir(folder) if f.endswith('.gltf'))
    before = set(bpy.data.objects)
    bpy.ops.import_scene.gltf(filepath=os.path.join(folder, gltf))
    objs = [o for o in bpy.data.objects if o not in before]
    root = bpy.data.objects.new(asset_id, None)
    bpy.context.collection.objects.link(root)
    for o in objs:
        if o.parent is None:
            o.parent = root
    bpy.context.view_layer.update()
    return root, [o for o in objs]


def colour_k(kelvin):
    """Approximate blackbody colour (linear RGB) for light temperatures 2500–6500 K."""
    t = kelvin / 100
    r = 255 if t <= 66 else 329.7 * (t - 60) ** -0.1332
    g = 99.47 * math.log(t) - 161.12 if t <= 66 else 288.1 * (t - 60) ** -0.0755
    b = 255 if t >= 66 else (0 if t <= 19 else 138.5 * math.log(t - 10) - 305.04)
    srgb = [max(0, min(255, c)) / 255 for c in (r, g, b)]
    return tuple(((c + 0.055) / 1.055) ** 2.4 if c > 0.04045 else c / 12.92 for c in srgb)


# --------------------------------------------------------------------------------------------- scene

def build(cfg):
    bpy.ops.wm.read_factory_settings(use_empty=True)
    scene = bpy.context.scene
    W, F, B, H, eye = cfg['width'], cfg['front'], cfg['back'], cfg['height'], cfg['eye']
    x0, x1, y0, y1 = -W / 2, W / 2, -B, F
    t = 0.12  # wall thickness

    wall_mat = principled('wall', (WALL_ALBEDO,) * 3, rough=0.92, spec=0.2)
    ceiling_mat = principled('ceiling', (0.82, 0.82, 0.81), rough=0.95, spec=0.1)
    trim_mat = principled('trim', (0.86, 0.85, 0.83), rough=0.35, spec=0.5)
    alu_mat = principled('window-frame', (0.05, 0.05, 0.05), rough=0.35, spec=0.5, metal=0.6)
    floor_id, tile, tint = cfg['floor']
    floor_mat = textured('floor', floor_id, tile, tint, rough_mul=0.8 if floor_id != 'marble_01' else 0.5)

    box('floor', x0 - t, x1 + t, y0 - t, y1 + t, -t, 0, floor_mat)
    box('ceiling', x0 - t, x1 + t, y0 - t, y1 + t, H, H + t, ceiling_mat)
    walls = {
        'f': box('wall-f', x0, x1, y1, y1 + t, 0, H, wall_mat, WALL_INDEX),
        'b': box('wall-b', x0, x1, y0 - t, y0, 0, H, wall_mat, WALL_INDEX),
        'l': box('wall-l', x0 - t, x0, y0, y1, 0, H, wall_mat, WALL_INDEX),
        'r': box('wall-r', x1, x1 + t, y0, y1, 0, H, wall_mat, WALL_INDEX),
    }

    # skirting boards
    s_h, s_d = 0.09, 0.015
    box('skirt-f', x0, x1, y1 - s_d, y1, 0, s_h, trim_mat)
    box('skirt-b', x0, x1, y0, y0 + s_d, 0, s_h, trim_mat)
    box('skirt-l', x0, x0 + s_d, y0, y1, 0, s_h, trim_mat)
    box('skirt-r', x1 - s_d, x1, y0, y1, 0, s_h, trim_mat)

    # window: an opening in a side wall, a slim dark frame, daylight from the sky through it
    win = cfg['window']
    side_x = x0 if win['side'] == 'l' else x1
    inward = 1 if win['side'] == 'l' else -1
    wy0, wy1 = win['centre'] - win['width'] / 2, win['centre'] + win['width'] / 2
    cutter = box('window-cut', side_x - 0.5, side_x + 0.5, wy0, wy1, win['sill'], win['top'], trim_mat)
    cutter.hide_render = True
    cutter.display_type = 'WIRE'
    for name in ('wall-l', 'wall-r', 'skirt-l', 'skirt-r'):
        obj = bpy.data.objects[name]
        if (name.endswith('-l')) == (win['side'] == 'l'):
            mod = obj.modifiers.new('window', 'BOOLEAN')
            mod.operation = 'DIFFERENCE'
            mod.object = cutter
    fw = 0.05
    fx0, fx1 = sorted((side_x - inward * t * 0.2, side_x + inward * 0.02))
    box('frame-top', fx0, fx1, wy0, wy1, win['top'] - fw, win['top'], alu_mat)
    box('frame-bottom', fx0, fx1, wy0, wy1, win['sill'], win['sill'] + fw, alu_mat)
    box('frame-a', fx0, fx1, wy0, wy0 + fw, win['sill'], win['top'], alu_mat)
    box('frame-b', fx0, fx1, wy1 - fw, wy1, win['sill'], win['top'], alu_mat)
    box('frame-mullion', fx0, fx1, win['centre'] - fw / 2, win['centre'] + fw / 2, win['sill'], win['top'], alu_mat)
    sill_x0, sill_x1 = sorted((side_x, side_x + inward * 0.06))
    box('sill', sill_x0, sill_x1, wy0 - 0.05, wy1 + 0.05, win['sill'] - 0.03, win['sill'], trim_mat)

    # Recessed cove ceiling: a ledge round the room with hidden warm LED strips lighting the ceiling.
    cove_z = ceiling_cove(cfg, ceiling_mat)
    cfg['_cove_z'] = cove_z

    # Two layers at the window: a sheer across it (soft, diffused daylight) and heavier drapes either side.
    sheer = principled('sheer', (0.93, 0.92, 0.9), rough=0.8, spec=0.2)
    sb = sheer.node_tree.nodes['Principled BSDF']
    sb.inputs['Transmission Weight'].default_value = 0.72
    drape = textured('drape', 'rough_linen', 0.5, flat=cfg.get('drape', (0.32, 0.31, 0.3)))
    top = cove_z - 0.01
    curtain('sheer', side_x + inward * 0.14, wy0 - 0.25, wy1 + 0.25, 0.015, top, sheer, folds=22, depth=0.03)
    for i, (ca, cb) in enumerate(((wy0 - 0.75, wy0 - 0.05), (wy1 + 0.05, wy1 + 0.75))):
        curtain(f'drape-{i}', side_x + inward * 0.2, ca, cb, 0.015, top, drape, folds=7, depth=0.06)

    # rug
    if cfg.get('rug'):
        r = cfg['rug']
        wool = textured('rug', 'wool_boucle', 0.35, flat=r['tint'])
        (rx, ry), (rw, rd) = r['at'], r['size']
        box('rug', rx - rw / 2, rx + rw / 2, ry - rd / 2, ry + rd / 2, 0, 0.012, wool)

    # furniture
    placed = {}
    for p in cfg['props']:
        if p['asset'] == 'modern_bed':
            objs = modern_bed(p['at'][0], y1 - p.get('gap', 0.02))
            placed['modern_bed'] = world_bbox(objs)
            continue
        root, objs = import_asset(p['asset'])
        root.rotation_euler = (0, 0, math.radians(p.get('rot', 0)))
        if 'sx' in p:  # narrower/wider without changing the height (e.g. a 2.7 m sofa → a usual 2.2 m)
            root.scale = (p['sx'], 1, 1)
        bpy.context.view_layer.update()
        lo, hi = world_bbox(objs)
        if 'on' in p:
            base_lo, base_hi = placed[p['on']]
            bx, by = (base_lo.x + base_hi.x) / 2 + p.get('dx', 0), (base_lo.y + base_hi.y) / 2
            root.location = (bx - (lo.x + hi.x) / 2, by - (lo.y + hi.y) / 2, base_hi.z - lo.z)
        else:
            ax, ay = p['at']
            if ay == 'wall':
                ay = y1 - p.get('gap', 0.03) - (hi.y - lo.y) / 2
            z = (H - 0.002 - hi.z) if p.get('ceiling') else -lo.z
            root.location = (ax - (lo.x + hi.x) / 2, ay - (lo.y + hi.y) / 2, z)
        bpy.context.view_layer.update()
        placed[p['asset']] = world_bbox(objs)
        for o in objs:
            o['ceiling'] = bool(p.get('ceiling'))
            o['prop'] = True

    for extra in cfg.get('extras', []):
        EXTRAS[extra['kind']](cfg, **{k: v for k, v in extra.items() if k != 'kind'})

    lights(cfg, win, side_x, inward, wy0, wy1)
    camera = bpy.data.objects.new('eye', bpy.data.cameras.new('eye'))
    camera.data.angle = math.pi / 2
    camera.data.clip_start = 0.05
    camera.location = (0, 0, eye)
    scene.collection.objects.link(camera)
    scene.camera = camera
    return camera, walls


def soft_box(name, x0, x1, y0, y1, z0, z1, material, bevel=0.03, segments=4, rot=None, pivot=None):
    """A box with rounded edges (bevel + smooth shading): upholstery, mattresses, pillows."""
    obj = box(name, x0, x1, y0, y1, z0, z1, material)
    mod = obj.modifiers.new('round', 'BEVEL')
    mod.width = bevel
    mod.segments = segments
    mod.limit_method = 'NONE'
    obj.modifiers.new('normals', 'WEIGHTED_NORMAL')
    for poly in obj.data.polygons:
        poly.use_smooth = True
    if rot is not None:
        obj.data.transform(__import__('mathutils').Matrix.Translation(-Vector(pivot)))
        obj.location = pivot
        obj.rotation_euler = rot
    obj['prop'] = True
    return obj


def modern_bed(cx, wall_y, width=1.8, length=2.1):
    """Contemporary upholstered double bed (Poly Haven has none): plinth, base, headboard, mattress,
    duvet, pillows and a throw — all soft-edged."""
    fabric = textured('bed-fabric', 'wool_boucle', 0.3, flat=(0.42, 0.4, 0.37))
    head = textured('headboard', 'wool_boucle', 0.3, flat=(0.55, 0.52, 0.47))
    sheet = textured('sheet', 'rough_linen', 0.5, flat=(0.86, 0.85, 0.82))
    duvet = textured('duvet', 'rough_linen', 0.5, flat=(0.78, 0.75, 0.7))
    throw = textured('throw', 'wool_boucle', 0.25, flat=(0.2, 0.19, 0.18))
    plinth = principled('plinth', (0.03, 0.03, 0.03), rough=0.6)
    x0, x1 = cx - width / 2, cx + width / 2
    y_head = wall_y - 0.1
    y_foot = y_head - length
    objs = [
        box('bed-plinth', x0 + 0.08, x1 - 0.08, y_foot + 0.08, y_head - 0.05, 0, 0.06, plinth),
        soft_box('bed-base', x0, x1, y_foot, y_head, 0.06, 0.34, fabric, bevel=0.025),
        soft_box('bed-headboard', x0 - 0.08, x1 + 0.08, y_head, wall_y, 0.06, 1.18, head, bevel=0.04),
        soft_box('bed-mattress', x0 + 0.03, x1 - 0.03, y_foot + 0.03, y_head - 0.02, 0.34, 0.56, sheet, bevel=0.05),
        soft_box('bed-duvet', x0 - 0.02, x1 + 0.02, y_foot - 0.02, y_head - 0.62, 0.5, 0.62, duvet, bevel=0.05),
        soft_box('bed-throw', x0 - 0.04, x1 + 0.04, y_foot - 0.03, y_foot + 0.42, 0.6, 0.66, throw, bevel=0.03),
    ]
    for i, px in enumerate((cx - width / 4, cx + width / 4)):
        pivot = (px, y_head - 0.18, 0.62)
        objs.append(soft_box(f'bed-pillow-{i}', px - 0.36, px + 0.36, y_head - 0.3, y_head - 0.12, 0.56, 0.98, sheet,
                             bevel=0.07, segments=5, rot=(math.radians(-14), 0, 0), pivot=pivot))
    for o in objs:
        o['prop'] = True
    bpy.context.view_layer.update()
    return objs


COVE_WIDTH = 0.45


def ceiling_cove(cfg, ceiling_mat):
    """A ledge round the room 0.3 m below the ceiling, with an up-lit gap: the soft glow of a designer ceiling."""
    W, F, B, H = cfg['width'], cfg['front'], cfg['back'], cfg['height']
    x0, x1, y0, y1 = -W / 2, W / 2, -B, F
    w = COVE_WIDTH
    under, top, lip = H - 0.3, H - 0.2, H - 0.15
    parts = [(x0, x1, y1 - w, y1), (x0, x1, y0, y0 + w), (x0, x0 + w, y0 + w, y1 - w), (x1 - w, x1, y0 + w, y1 - w)]
    for i, (a, b, c, d) in enumerate(parts):
        box(f'cove-{i}', a, b, c, d, under, top, ceiling_mat)
    t = 0.02
    lips = [(x0 + w - t, x1 - w + t, y1 - w, y1 - w + t), (x0 + w - t, x1 - w + t, y0 + w - t, y0 + w),
            (x0 + w - t, x0 + w, y0 + w, y1 - w), (x1 - w, x1 - w + t, y0 + w, y1 - w)]
    for i, (a, b, c, d) in enumerate(lips):
        box(f'cove-lip-{i}', a, b, c, d, top, lip, ceiling_mat)
    # warm LED strips on the ledge, shining up at the ceiling
    strips = [((0, y1 - w + 0.1), W - 2 * w, 0), ((0, y0 + w - 0.1), W - 2 * w, 0),
              ((x0 + w - 0.1, (y0 + y1) / 2), F + B - 2 * w, 90), ((x1 - w + 0.1, (y0 + y1) / 2), F + B - 2 * w, 90)]
    for i, ((cx, cy), length, rz) in enumerate(strips):
        light = bpy.data.lights.new(f'cove-led-{i}', 'AREA')
        light.shape = 'RECTANGLE'
        light.size = length
        light.size_y = 0.04
        light.energy = 9 * length
        light.color = colour_k(3000)
        obj = bpy.data.objects.new(light.name, light)
        obj.location = (cx, cy, top + 0.01)
        obj.rotation_euler = (math.pi, 0, math.radians(rz))
        bpy.context.scene.collection.objects.link(obj)
    return under


def cylinder(name, x, y, z0, z1, r, material, verts=48):
    bpy.ops.mesh.primitive_cylinder_add(radius=r, depth=z1 - z0, location=(x, y, (z0 + z1) / 2), vertices=verts)
    obj = bpy.context.active_object
    obj.name = name
    obj.data.materials.append(material)
    for poly in obj.data.polygons:
        poly.use_smooth = True
    obj['prop'] = True
    return obj


_BOOK_COLOURS = [(0.55, 0.47, 0.38), (0.12, 0.16, 0.2), (0.75, 0.72, 0.66), (0.42, 0.18, 0.14),
                 (0.2, 0.28, 0.24), (0.86, 0.84, 0.8), (0.6, 0.52, 0.32)]


def book_stack(x, y, z, n=3, rot=0.0, seed=0):
    """Coffee-table books lying in a stack."""
    rng = random.Random(seed)
    zc = z
    objs = []
    for i in range(n):
        w, d, t = rng.uniform(0.22, 0.32), rng.uniform(0.16, 0.24), rng.uniform(0.025, 0.05)
        m = principled(f'book-{seed}-{i}', rng.choice(_BOOK_COLOURS), rough=0.65, spec=0.3)
        b = soft_box(f'book-{seed}-{i}', -w / 2, w / 2, -d / 2, d / 2, 0, t, m, bevel=0.003, segments=2)
        b.location = (x, y, zc)
        b.rotation_euler = (0, 0, math.radians(rot + rng.uniform(-8, 8)))
        objs.append(b)
        zc += t
    return objs


def book_row(x, y, z, run, along_y, seed=0):
    """A row of books standing on a shelf, spines out."""
    rng = random.Random(seed)
    pos = -run / 2
    i = 0
    while pos < run / 2 - 0.03:
        t = rng.uniform(0.022, 0.045)
        h = rng.uniform(0.2, 0.29)
        d = rng.uniform(0.15, 0.2)
        m = principled(f'row-{seed}-{i}', rng.choice(_BOOK_COLOURS), rough=0.7, spec=0.3)
        if along_y:
            soft_box(f'row-{seed}-{i}', x - d / 2, x + d / 2, y + pos, y + pos + t, z, z + h, m, bevel=0.003, segments=2)
        else:
            soft_box(f'row-{seed}-{i}', x + pos, x + pos + t, y - d / 2, y + d / 2, z, z + h, m, bevel=0.003, segments=2)
        pos += t + 0.003
        i += 1


def pendant_globe(name, x, y, bottom, top, r=0.08, power=2.5):
    """A glass globe on a thin cord, glowing warm."""
    cord = principled(f'{name}-cord', (0.02, 0.02, 0.02), rough=0.5)
    c = cylinder(f'{name}-cord', x, y, bottom + 2 * r, top, 0.003, cord, verts=8)
    c['ceiling'] = True
    glass = bpy.data.materials.new(f'{name}-glass')
    glass.use_nodes = True
    g = glass.node_tree.nodes['Principled BSDF']
    g.inputs['Base Color'].default_value = (1, 0.93, 0.85, 1)
    g.inputs['Roughness'].default_value = 0.4
    g.inputs['Transmission Weight'].default_value = 1.0
    g.inputs['Emission Color'].default_value = (*colour_k(2700), 1)
    g.inputs['Emission Strength'].default_value = power
    bpy.ops.mesh.primitive_uv_sphere_add(radius=r, location=(x, y, bottom + r), segments=48, ring_count=24)
    globe = bpy.context.active_object
    globe.name = name
    globe.data.materials.append(glass)
    for poly in globe.data.polygons:
        poly.use_smooth = True
    globe['prop'] = True
    globe['ceiling'] = True


def _root(o):
    while o.parent:
        o = o.parent
    return o


def extra_headboard_panel(cfg, width=3.4, height=1.25):
    """A full-width timber-slat panel behind the bed, like a hotel suite."""
    F = cfg['front']
    wood = textured('slat-wood', 'rectangular_parquet', 1.2, (0.78, 0.7, 0.62), rough_mul=0.9)
    gap = principled('slat-gap', (0.04, 0.035, 0.03), rough=0.8)
    box('panel-back', -width / 2, width / 2, F - 0.03, F, 0.0, height, gap)['prop'] = True
    n = int(width / 0.075)
    for i in range(n):
        a = -width / 2 + i * width / n
        box(f'slat-{i}', a + 0.004, a + width / n - 0.004, F - 0.05, F - 0.03, 0.0, height, wood)['prop'] = True
    cap = principled('panel-cap', (0.08, 0.07, 0.06), rough=0.5)
    box('panel-cap', -width / 2 - 0.01, width / 2 + 0.01, F - 0.07, F, height, height + 0.025, cap)['prop'] = True


def extra_bedside_pendants(cfg, x=1.35, bottom=0.95):
    for i, px in enumerate((-x, x)):
        pendant_globe(f'bedside-{i}', px, cfg['front'] - 0.35, bottom, cfg['_cove_z'])


def extra_window_seat(cfg):
    win = cfg['window']
    W = cfg['width']
    side_x = -W / 2 if win['side'] == 'l' else W / 2
    inward = 1 if win['side'] == 'l' else -1
    base = principled('seat-base', (0.08, 0.075, 0.07), rough=0.5)
    cushion = textured('seat-cushion', 'wool_boucle', 0.3, flat=(0.62, 0.6, 0.57))
    a, b = sorted((side_x, side_x + inward * 0.55))
    y0, y1 = win['centre'] - win['width'] / 2 - 0.1, win['centre'] + win['width'] / 2 + 0.1
    box('seat-base', a, b, y0, y1, 0, 0.42, base)['prop'] = True
    soft_box('seat-cushion', a + 0.01, b - 0.01, y0 + 0.01, y1 - 0.01, 0.42, 0.5, cushion, bevel=0.02)
    for i, cy in enumerate((y0 + 0.35, y1 - 0.35)):
        pillow = textured(f'seat-pillow-{i}', 'wool_boucle', 0.25, flat=((0.3, 0.33, 0.35) if i else (0.7, 0.62, 0.5)))
        pa = (a + b) / 2
        soft_box(f'seat-pillow-{i}', pa - 0.2, pa + 0.2, cy - 0.06, cy + 0.06, 0.5, 0.88, pillow, bevel=0.06, segments=5)


def extra_books(cfg, at=(0, 0), n=3, rot=0.0, seed=1, on=None, dx=0.0):
    x, y = at
    z = 0.0
    if on:
        objs = [o for o in bpy.data.objects if o.type == 'MESH' and _root(o).name.startswith(on)]
        lo, hi = world_bbox(objs)
        x, y, z = (lo.x + hi.x) / 2 + dx, (lo.y + hi.y) / 2, hi.z
    book_stack(x, y, z, n=n, rot=rot, seed=seed)


def extra_shelving(cfg, side='r', centre=0.0, width=1.8, height=2.2):
    """A wall of oak shelves with books."""
    W = cfg['width']
    sx = W / 2 if side == 'r' else -W / 2
    inward = -1 if side == 'r' else 1
    oak = textured('shelf-oak', 'rectangular_parquet', 1.5, (0.85, 0.76, 0.66), rough_mul=0.9)
    frame = principled('shelf-frame', (0.03, 0.03, 0.03), rough=0.45, metal=0.7)
    d = 0.32
    a, b = sorted((sx, sx + inward * d))
    y0, y1 = centre - width / 2, centre + width / 2
    for k, yy in enumerate((y0, (y0 + y1) / 2, y1)):
        box(f'shelf-post-{k}', a, b, yy - 0.012, yy + 0.012, 0, height, frame)['prop'] = True
    mid = (a + b) / 2
    for k, z in enumerate((0.35, 0.8, 1.25, 1.7, 2.15)):
        box(f'shelf-{k}', a, b, y0, y1, z - 0.025, z, oak)['prop'] = True
        if z > 2.0:
            continue
        for j, half in enumerate((y0 + width * 0.25, y0 + width * 0.75)):
            if (k + j) % 3 == 0:
                book_stack(mid, half, z, n=3, seed=k * 10 + j, rot=90)
            else:
                book_row(mid, half, z, 0.5, along_y=True, seed=k * 10 + j)


def stool(name, x, y, metal):
    seat = textured(f'{name}-seat', 'wool_boucle', 0.25, flat=(0.42, 0.3, 0.2))
    cylinder(f'{name}-seat', x, y, 0.62, 0.68, 0.19, seat)
    for k in range(4):
        ang = math.pi / 4 + k * math.pi / 2
        cylinder(f'{name}-leg-{k}', x + 0.14 * math.cos(ang), y + 0.14 * math.sin(ang), 0, 0.62, 0.011, metal, verts=12)
    bpy.ops.mesh.primitive_torus_add(major_radius=0.14, minor_radius=0.008, location=(x, y, 0.24))
    ring = bpy.context.active_object
    ring.data.materials.append(metal)
    ring['prop'] = True


def extra_kitchen(cfg):
    """Fitted kitchen along the left wall, an island, and a breakfast banquette under the art wall."""
    W, F, B = cfg['width'], cfg['front'], cfg['back']
    x0 = -W / 2
    paint = principled('cabinet', (0.13, 0.17, 0.15), rough=0.42, spec=0.4)
    brass = principled('brass', (0.8, 0.6, 0.32), rough=0.28, metal=1.0)
    stone = principled('quartz', (0.86, 0.85, 0.82), rough=0.22, spec=0.5)
    oak = textured('kitchen-oak', 'rectangular_parquet', 1.4, (0.86, 0.77, 0.66), rough_mul=0.9)
    black = principled('appliance', (0.015, 0.015, 0.015), rough=0.12, spec=0.6)
    steel = principled('steel', (0.62, 0.62, 0.6), rough=0.25, metal=1.0)

    ya, yb = -B + 0.05, F - 1.9
    depth = 0.62
    box('k-plinth', x0, x0 + depth - 0.06, ya, yb, 0, 0.1, black)['prop'] = True
    n = max(2, round((yb - ya) / 0.6))
    for i in range(n):
        a = ya + (yb - ya) * i / n
        b = ya + (yb - ya) * (i + 1) / n
        soft_box(f'k-door-{i}', x0 + 0.02, x0 + depth, a + 0.0015, b - 0.0015, 0.1, 0.88, paint, bevel=0.003, segments=2)
        handle = cylinder(f'k-handle-{i}', x0 + depth + 0.015, (a + b) / 2, 0.79, 0.83, 0.006, brass, verts=12)
        handle.scale = (1, 1, 1)
    box('k-worktop', x0, x0 + depth + 0.03, ya, yb, 0.88, 0.92, stone)['prop'] = True
    box('k-splash', x0, x0 + 0.015, ya, yb, 0.92, 1.5, stone)['prop'] = True
    hob_y = ya + (yb - ya) * 0.3
    box('k-hob', x0 + 0.1, x0 + 0.62, hob_y - 0.3, hob_y + 0.3, 0.92, 0.926, black)['prop'] = True
    sink_y = ya + (yb - ya) * 0.72
    box('k-sink', x0 + 0.12, x0 + 0.56, sink_y - 0.3, sink_y + 0.3, 0.915, 0.921, steel)['prop'] = True
    cylinder('k-tap', x0 + 0.08, sink_y, 0.92, 1.25, 0.012, brass, verts=16)
    box('k-spout', x0 + 0.08, x0 + 0.3, sink_y - 0.011, sink_y + 0.011, 1.225, 1.245, brass)['prop'] = True
    for k, z in enumerate((1.62, 2.0)):
        box(f'k-shelf-{k}', x0, x0 + 0.26, ya + 0.3, yb - 0.3, z - 0.035, z, oak)['prop'] = True
        book_row(x0 + 0.13, ya + 0.65 + k * 0.5, z, 0.45, along_y=True, seed=40 + k)

    # island beside and behind the eye
    ix0, ix1, iy0, iy1 = -1.6, -0.8, -1.7, 0.3
    box('k-island', ix0 + 0.03, ix1 - 0.03, iy0 + 0.03, iy1 - 0.03, 0, 0.88, paint)['prop'] = True
    box('k-island-top', ix0, ix1, iy0, iy1, 0.88, 0.93, stone)['prop'] = True
    for k, sy in enumerate((-1.3, -0.7)):
        stool(f'stool-{k}', -0.5, sy, brass)

    # breakfast nook under the art wall: banquette and a round pedestal table
    fabric = textured('banquette', 'wool_boucle', 0.3, flat=(0.55, 0.48, 0.38))
    soft_box('k-bench', -1.3, 1.3, F - 0.6, F - 0.05, 0, 0.46, fabric, bevel=0.03)
    soft_box('k-bench-back', -1.3, 1.3, F - 0.16, F - 0.02, 0.46, 0.92, fabric, bevel=0.04)
    cylinder('k-table-base', 0, F - 1.05, 0, 0.03, 0.26, black)
    cylinder('k-table-stem', 0, F - 1.05, 0.03, 0.72, 0.045, black, verts=24)
    cylinder('k-table-top', 0, F - 1.05, 0.72, 0.75, 0.55, stone, verts=96)
    pendant_globe('k-pendant', 0, F - 1.05, 1.6, cfg['_cove_z'], r=0.12, power=3.0)


def extra_wall_panels(cfg, sides=('l', 'r', 'b')):
    """Classic raised panel mouldings on the walls, painted with the wall."""
    W, F, B = cfg['width'], cfg['front'], cfg['back']
    top = cfg['_cove_z'] - 0.35
    wall_mat = bpy.data.materials['wall']
    t, p = 0.035, 0.012
    win = cfg['window']
    for side in sides:
        if side in ('l', 'r'):
            x = -W / 2 if side == 'l' else W / 2
            inward = 1 if side == 'l' else -1
            length = F + B
            n = max(2, round(length / 1.6))
            for i in range(n):
                a = -B + length * i / n + 0.2
                b = -B + length * (i + 1) / n - 0.2
                if win['side'] == side and b > win['centre'] - win['width'] / 2 - 0.9 and a < win['centre'] + win['width'] / 2 + 0.9:
                    continue
                xa, xb = sorted((x, x + inward * p))
                for k, (z0, z1) in enumerate(((0.3, 0.95), (1.15, top))):
                    for j, (ya2, yb2, za, zb) in enumerate(((a, b, z0, z0 + t), (a, b, z1 - t, z1), (a, a + t, z0, z1), (b - t, b, z0, z1))):
                        box(f'panel-{side}-{i}-{k}-{j}', xa, xb, ya2, yb2, za, zb, wall_mat, WALL_INDEX)
        else:
            y = -B
            n = max(2, round(W / 1.6))
            for i in range(n):
                a = -W / 2 + W * i / n + 0.2
                b = -W / 2 + W * (i + 1) / n - 0.2
                for k, (z0, z1) in enumerate(((0.3, 0.95), (1.15, top))):
                    for j, (xa2, xb2, za, zb) in enumerate(((a, b, z0, z0 + t), (a, b, z1 - t, z1), (a, a + t, z0, z1), (b - t, b, z0, z1))):
                        box(f'panel-b-{i}-{k}-{j}', xa2, xb2, y, y + p, za, zb, wall_mat, WALL_INDEX)


EXTRAS = {
    'headboard_panel': extra_headboard_panel,
    'bedside_pendants': extra_bedside_pendants,
    'window_seat': extra_window_seat,
    'books': extra_books,
    'shelving': extra_shelving,
    'kitchen': extra_kitchen,
    'wall_panels': extra_wall_panels,
}


def curtain(name, x, ya, yb, z0, z1, material, folds=9, depth=0.035):
    """Pleated curtain: a vertical sheet with sine folds, solidified."""
    nx, nz = max(60, folds * 10), 2
    verts, faces = [], []
    for i in range(nx + 1):
        u = i / nx
        y = ya + (yb - ya) * u
        dx = depth * math.sin(u * math.pi * folds)
        for k in range(nz + 1):
            verts.append((x + dx, y, z0 + (z1 - z0) * k / nz))
    for i in range(nx):
        for k in range(nz):
            a = i * (nz + 1) + k
            faces.append((a, a + nz + 1, a + nz + 2, a + 1))
    mesh = bpy.data.meshes.new(name)
    mesh.from_pydata(verts, [], faces)
    uv = mesh.uv_layers.new()
    for poly in mesh.polygons:
        for li in poly.loop_indices:
            co = mesh.vertices[mesh.loops[li].vertex_index].co
            uv.data[li].uv = (co.y, co.z)
    for poly in mesh.polygons:
        poly.use_smooth = True
    mesh.materials.append(material)
    obj = bpy.data.objects.new(name, mesh)
    mod = obj.modifiers.new('thick', 'SOLIDIFY')
    mod.thickness = 0.004
    bpy.context.collection.objects.link(obj)


def lights(cfg, win, side_x, inward, wy0, wy1):
    scene = bpy.context.scene
    W, F, B, H = cfg['width'], cfg['front'], cfg['back'], cfg['height']

    # sky: HDRI seen through the window and lighting the room
    world = bpy.data.worlds.new('sky')
    scene.world = world
    world.use_nodes = True
    nt = world.node_tree
    env = nt.nodes.new('ShaderNodeTexEnvironment')
    env.image = bpy.data.images.load(os.path.join(ASSETS, 'hdri', 'green_point_park.hdr'))
    mapping = nt.nodes.new('ShaderNodeMapping')
    coord = nt.nodes.new('ShaderNodeTexCoord')
    mapping.inputs['Rotation'].default_value = (0, 0, math.radians(200 if win['side'] == 'l' else 20))
    nt.links.new(coord.outputs['Generated'], mapping.inputs['Vector'])
    nt.links.new(mapping.outputs['Vector'], env.inputs['Vector'])
    bg = nt.nodes['Background']
    bg.inputs['Strength'].default_value = 1.0
    nt.links.new(env.outputs['Color'], bg.inputs['Color'])

    # portal: tells Cycles where daylight comes in (far less noise)
    portal = bpy.data.lights.new('portal', 'AREA')
    portal.shape = 'RECTANGLE'
    portal.size = win['width']
    portal.size_y = win['top'] - win['sill']
    portal.cycles.is_portal = True
    pobj = bpy.data.objects.new('portal', portal)
    pobj.location = (side_x + inward * 0.01, win['centre'], (win['sill'] + win['top']) / 2)
    pobj.rotation_euler = (0, math.radians(-90 * inward), 0)
    scene.collection.objects.link(pobj)

    # soft sun through the window: a gentle patch on the floor, not a hard spotlight
    sun = bpy.data.lights.new('sun', 'SUN')
    sun.energy = 2.2
    sun.angle = math.radians(3)
    sun.color = colour_k(5600)
    sobj = bpy.data.objects.new('sun', sun)
    sobj.rotation_euler = (math.radians(55), 0, math.radians(-75 * inward))
    scene.collection.objects.link(sobj)

    cove_z = cfg['_cove_z']
    ledge = COVE_WIDTH
    # recessed warm downlights in the raised centre of the ceiling
    cols = max(2, round((W - 2 * ledge) / 1.8))
    rows = max(2, round((F + B - 2 * ledge) / 1.8))
    for i in range(cols):
        for j in range(rows):
            light = bpy.data.lights.new(f'down-{i}-{j}', 'AREA')
            light.shape = 'DISK'
            light.size = 0.1
            light.energy = 22
            light.color = colour_k(3500)
            obj = bpy.data.objects.new(light.name, light)
            obj.location = (-W / 2 + ledge + (W - 2 * ledge) * (i + 0.5) / cols,
                            -B + ledge + (F + B - 2 * ledge) * (j + 0.5) / rows, H - 0.01)
            scene.collection.objects.link(obj)

    # gallery lights recessed in the ledge in front of the art wall: soft scallops of light on the wall
    for x in (-1.2, 0.0, 1.2):
        if abs(x) > W / 2 - 0.4:
            continue
        spot = bpy.data.lights.new(f'gallery-{x}', 'SPOT')
        spot.energy = 55
        spot.spot_size = math.radians(60)
        spot.spot_blend = 0.9
        spot.shadow_soft_size = 0.04
        spot.color = colour_k(3300)
        obj = bpy.data.objects.new(spot.name, spot)
        obj.location = (x, F - 0.32, cove_z - 0.005)
        obj.rotation_euler = (math.radians(-18), 0, 0)
        scene.collection.objects.link(obj)

    # large, weak ceiling fill (the light bouncing off a bright ceiling)
    fill = bpy.data.lights.new('fill', 'AREA')
    fill.shape = 'RECTANGLE'
    fill.size = W * 0.8
    fill.size_y = (F + B) * 0.8
    fill.energy = 45 * W * (F + B) / 30
    fill.color = colour_k(5000)
    fobj = bpy.data.objects.new('fill', fill)
    fobj.location = (0, (F - B) / 2, H - 0.02)
    scene.collection.objects.link(fobj)


def setup_render(size, samples, outdir):
    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    c = scene.cycles
    c.device = 'CPU'
    c.samples = samples
    c.use_adaptive_sampling = True
    c.adaptive_threshold = 0.02
    c.adaptive_min_samples = 12
    c.use_denoising = True
    c.denoiser = 'OPENIMAGEDENOISE'
    c.denoising_input_passes = 'RGB_ALBEDO_NORMAL'
    c.max_bounces = 6
    c.diffuse_bounces = 3
    c.glossy_bounces = 2
    c.transmission_bounces = 4
    c.transparent_max_bounces = 8
    c.caustics_reflective = False
    c.caustics_refractive = False
    c.sample_clamp_indirect = 8
    c.blur_glossy = 1.0
    scene.render.use_persistent_data = True
    scene.render.resolution_x = scene.render.resolution_y = size
    scene.render.resolution_percentage = 100
    scene.render.film_transparent = False
    scene.view_settings.view_transform = 'Standard'
    scene.view_settings.look = 'None'
    scene.view_settings.exposure = 0
    scene.view_layers[0].use_pass_object_index = True

    scene.use_nodes = True
    nt = scene.node_tree
    for n in list(nt.nodes):
        nt.nodes.remove(n)
    rl = nt.nodes.new('CompositorNodeRLayers')
    comp = nt.nodes.new('CompositorNodeComposite')
    nt.links.new(rl.outputs['Image'], comp.inputs['Image'])
    idm = nt.nodes.new('CompositorNodeIDMask')
    idm.index = WALL_INDEX
    idm.use_antialiasing = True
    nt.links.new(rl.outputs['IndexOB'], idm.inputs['ID value'])
    out = nt.nodes.new('CompositorNodeOutputFile')
    out.base_path = outdir
    out.format.file_format = 'OPEN_EXR'
    out.format.color_depth = '32'
    out.format.exr_codec = 'ZIP'
    out.file_slots.clear()
    out.file_slots.new('beauty')
    out.file_slots.new('mask')
    nt.links.new(rl.outputs['Image'], out.inputs['beauty'])
    nt.links.new(idm.outputs['Alpha'], out.inputs['mask'])
    return out


# --------------------------------------------------------------------------------------------- output

def read_exr(path):
    img = bpy.data.images.load(path)
    w, h = img.size
    px = np.empty(w * h * 4, dtype=np.float32)
    img.pixels.foreach_get(px)
    bpy.data.images.remove(img)
    return px.reshape(h, w, 4)[::-1]  # top row first


def tone(x):
    """Linear up to KNEE, then a soft shoulder approaching 1 (the browser inverts it exactly)."""
    x = np.maximum(x, 0)
    s = 1 - KNEE
    return np.where(x <= KNEE, x, KNEE + s * (1 - np.exp(-(x - KNEE) / s)))


def srgb(x):
    x = np.clip(x, 0, 1)
    return np.where(x <= 0.0031308, x * 12.92, 1.055 * np.power(x, 1 / 2.4) - 0.055)


def save_png(path, rgba_top_first):
    h, w, ch = rgba_top_first.shape
    img = bpy.data.images.new(os.path.basename(path), w, h, alpha=(ch == 4), float_buffer=False)
    img.colorspace_settings.name = 'Non-Color'
    data = np.ones((h, w, 4), dtype=np.float32)
    data[..., :ch] = rgba_top_first
    img.pixels.foreach_set(data[::-1].ravel())
    img.filepath_raw = path
    img.file_format = 'PNG'
    img.save()
    bpy.data.images.remove(img)


def webp(src, dst, quality=92, resize=None, alpha=True):
    cmd = ['cwebp', '-quiet', '-q', str(quality), '-m', '6', '-sharp_yuv']
    if alpha:
        cmd += ['-alpha_q', '100', '-exact']
    else:
        cmd += ['-noalpha']
    if resize:
        cmd += ['-resize', str(resize), str(resize)]
    subprocess.run(cmd + [src, '-o', dst], check=True)


def three(v):
    """Blender (x, y fwd, z up) → three.js (x, y up, -z fwd)."""
    return [round(v[0], 5), round(v[2], 5), round(-v[1], 5)]


def free_zone(cfg):
    """Where art can hang on the art wall without being hidden by furniture, seen from the eye."""
    F, eye, H, W = cfg['front'], cfg['eye'], cfg['height'], cfg['width']
    band = (-1.3, 1.3)
    min_bottom = 0.75
    left, right = -W / 2 + 0.25, W / 2 - 0.25
    tops = {}
    for o in bpy.data.objects:
        if o.type != 'MESH' or not o.get('prop') or o.get('ceiling'):
            continue
        root = o
        while root.parent:
            root = root.parent
        lo, hi = world_bbox([o])
        if hi.y <= 0.3:  # behind or beside the eye
            continue
        # Project the object's silhouette onto the art wall plane from the eye.
        xs, zs = [], []
        for cx in (lo.x, hi.x):
            for cy in (lo.y, hi.y):
                for cz in (lo.z, hi.z):
                    k = F / max(cy, 0.3)
                    xs.append(cx * k)
                    zs.append(eye + (cz - eye) * k)
        a, b, top = min(xs), max(xs), max(zs)
        key = root.name
        prev = tops.get(key)
        tops[key] = (min(a, prev[0]), max(b, prev[1]), max(top, prev[2])) if prev else (a, b, top)
    for a, b, top in tops.values():
        if b > band[0] and a < band[1]:
            min_bottom = max(min_bottom, top + 0.12)
    for a, b, top in tops.values():
        if top > min_bottom + 0.15:
            if b <= 0:
                left = max(left, b + 0.08)
            elif a >= 0:
                right = min(right, a - 0.08)
    return dict(minBottom=round(min_bottom - eye, 4), maxTop=round(cfg.get('_cove_z', H) - 0.12 - eye, 4), left=round(left, 4), right=round(right, 4))


def render_room(key, cfg, size, samples, preview):
    camera, walls = build(cfg)
    tmp = os.path.join(OUT_TMP, key)
    shutil.rmtree(tmp, ignore_errors=True)
    os.makedirs(tmp, exist_ok=True)
    out = setup_render(size, samples, tmp)
    scene = bpy.context.scene

    faces = {}
    for name, (rot, _, _) in FACES.items():
        camera.rotation_euler = tuple(math.radians(a) for a in rot)
        # Ceiling and floor are seldom looked at closely: a smaller render saves a third of the time.
        face_px = size if name not in ('u', 'd') or preview else int(size * 2 / 3)
        scene.render.resolution_x = scene.render.resolution_y = face_px
        out.file_slots[0].path = f'{name}-beauty-'
        out.file_slots[1].path = f'{name}-mask-'
        scene.render.filepath = os.path.join(tmp, f'{name}-composite')
        print(f'[{key}] rendering face {name} at {size}px, {samples} samples', flush=True)
        bpy.ops.render.render(write_still=False)
        frame = scene.frame_current
        beauty = read_exr(os.path.join(tmp, f'{name}-beauty-{frame:04d}.exr'))[..., :3]
        mask = read_exr(os.path.join(tmp, f'{name}-mask-{frame:04d}.exr'))[..., 0]
        faces[name] = (beauty, mask)

    # Auto exposure from the art wall: its median brightness becomes TARGET_WALL.
    fb, fm = faces['f']
    lum = fb @ np.array([0.2126, 0.7152, 0.0722], dtype=np.float32)
    wall_lum = lum[fm > 0.99]
    exposure = TARGET_WALL / max(float(np.median(wall_lum)), 1e-4)
    # Auto white balance (per channel), like a camera set to the room's light.
    med = np.median(fb[fm > 0.99], axis=0)
    wb = np.array(WALL_TINT, dtype=np.float32) / np.maximum(med / float(np.mean(med)), 1e-4)
    wb /= float(np.mean(wb))
    exposure = exposure * wb
    print(f'[{key}] exposure ×{exposure}', flush=True)

    dest = os.path.join(PUBLIC, key) if not preview else os.path.join(OUT_TMP, f'{key}-preview')
    os.makedirs(dest, exist_ok=True)
    for name, (beauty, mask) in faces.items():
        enc = srgb(tone(beauty * exposure))
        png = os.path.join(tmp, f'{name}.png')
        save_png(png, enc)
        # The wall mask is its own grey image: browsers drop the colour of fully transparent pixels.
        mpng = os.path.join(tmp, f'{name}-mask.png')
        save_png(mpng, np.repeat(np.clip(mask, 0, 1)[..., None], 3, axis=2))
        if preview:
            shutil.copy(png, os.path.join(dest, f'{name}.png'))
            continue
        webp(png, os.path.join(dest, f'{name}.webp'), quality=92, alpha=False)
        webp(png, os.path.join(dest, f'{name}-sm.webp'), quality=80, resize=512, alpha=False)
        webp(mpng, os.path.join(dest, f'{name}-mask.webp'), quality=90, alpha=False)
        webp(mpng, os.path.join(dest, f'{name}-mask-sm.webp'), quality=85, resize=512, alpha=False)

    # Light on the art wall: wall pixels / albedo, holes (furniture in front) filled from neighbours.
    zone = free_zone(cfg)
    light, rect = wall_light(cfg, faces['f'], exposure)
    lpng = os.path.join(tmp, 'light.png')
    save_png(lpng, srgb(np.clip(light / LIGHT_SCALE, 0, 1)))
    if not preview:
        webp(lpng, os.path.join(dest, 'light.webp'), quality=95, alpha=False)
    else:
        shutil.copy(lpng, os.path.join(dest, 'light.png'))

    W, F, B, H, eye = cfg['width'], cfg['front'], cfg['back'], cfg['height'], cfg['eye']
    meta = {
        'key': key,
        'title': cfg['title'],
        'faceSize': size,
        'faces': {n: {'dir': three(d), 'up': three(u)} for n, (_, d, u) in FACES.items()},
        'eyeHeight': eye,
        'room': {'width': W, 'front': F, 'back': B, 'height': H},
        # Art wall plane: z = -front (three.js), x across, y up from the eye.
        'artWall': {'distance': F, 'floor': -eye, 'ceiling': H - eye, **zone},
        'light': {'file': 'light.webp', 'scale': LIGHT_SCALE, 'rect': rect},
        'wallAlbedo': WALL_ALBEDO,
        'knee': KNEE,
        'exposure': [round(float(e), 4) for e in exposure],
    }
    with open(os.path.join(dest, 'meta.json'), 'w') as fh:
        json.dump(meta, fh, indent=2)
    print(f'[{key}] done → {dest}', flush=True)


def wall_light(cfg, face, exposure):
    """Light arriving on the art wall (linear), sampled on a grid over the wall in metres."""
    beauty, mask = face
    size = beauty.shape[0]
    F, eye, H, W = cfg['front'], cfg['eye'], cfg['height'], cfg['width']
    # The forward face sees the wall plane y = F over x ∈ [-F, F], z - eye ∈ [-F, F].
    xa, xb = max(-W / 2, -F), min(W / 2, F)
    za, zb = max(-eye, -F), min(H - eye, F)
    gw, gh = 256, max(16, int(256 * (zb - za) / (xb - xa)))
    xs = np.linspace(xa, xb, gw)
    zs = np.linspace(zb, za, gh)  # top row first
    X, Z = np.meshgrid(xs, zs)
    px = np.clip(((X / F) * 0.5 + 0.5) * (size - 1), 0, size - 1).astype(int)
    py = np.clip(((-Z / F) * 0.5 + 0.5) * (size - 1), 0, size - 1).astype(int)
    lin = beauty[py, px] * exposure / WALL_ALBEDO
    valid = mask[py, px] > 0.99
    out = lin.copy()
    out[~valid] = np.nan
    for _ in range(400):  # fill holes left by furniture with their neighbours' light
        holes = np.isnan(out[..., 0])
        if not holes.any():
            break
        pad = np.pad(out, ((1, 1), (1, 1), (0, 0)), constant_values=np.nan)
        stack = np.stack([pad[:-2, 1:-1], pad[2:, 1:-1], pad[1:-1, :-2], pad[1:-1, 2:]])
        with np.errstate(invalid='ignore'):
            mean = np.nanmean(stack, axis=0)
        out[holes] = mean[holes]
    out = np.nan_to_num(out, nan=float(np.nanmean(lin[valid])) if valid.any() else 0.5)
    return out.astype(np.float32), [round(xa, 4), round(xb, 4), round(za, 4), round(zb, 4)]


def main():
    o = args()
    configs = room_configs()
    keys = list(configs) if o['room'] == 'all' else o['room'].split(',')
    for key in keys:
        render_room(key, configs[key], o['size'], o['samples'], o['preview'])
        if not o['preview']:
            # Updated after every room, so each one appears in the viewer as soon as it is ready.
            index = [{'key': k, 'title': c['title']} for k, c in configs.items() if os.path.exists(os.path.join(PUBLIC, k, 'meta.json'))]
            with open(os.path.join(PUBLIC, 'index.json'), 'w') as fh:
                json.dump(index, fh, indent=2)


main()
