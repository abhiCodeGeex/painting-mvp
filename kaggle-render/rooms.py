"""
Renders the photoreal 360° rooms used by the "360° room" preview (resources/js/components/enquiry/room-360.ts).

    docker compose --profile render run --rm render --python docker/render/rooms.py -- --room living
    docker compose --profile render run --rm render --python docker/render/rooms.py -- --room all --size 2048 --samples 160
    ... -- --room living --size 384 --samples 24 --preview     (quick composition check, written to docker/render/out)
    ... -- --room all --samples 96 --layers    (switchable furniture for rooms already rendered: see "layers" below)
    ... -- --room all --check-items            (list each room's switchable furniture, no rendering)
    ... --out /work/some/dir                   (write rooms there instead of public/3d/rooms)

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
PUBLIC = os.path.abspath(os.path.join(HERE, '..', '..', 'public', '3d', 'rooms'))  # '--out <dir>' writes there instead

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
                dict(asset='sofa_03', at=(0, 'wall'), rot=0, sx=0.8, item='sofa'),
                dict(asset='side_table_01', at=(1.4, 'wall'), rot=0, gap=0.12, item='side_table'),
                dict(asset='ceramic_vase_02', on='side_table_01', rot=0, item='side_table'),
                dict(asset='modern_coffee_table_01', at=(0, 1.95), rot=90, item='coffee_table'),
                dict(asset='potted_plant_01', at=(-2.45, 'wall'), rot=30, gap=0.25, item='plants'),
                dict(asset='mid_century_lounge_chair', at=(2.05, 1.15), rot=-125, item='lounge_chair'),
                dict(asset='modern_ceiling_lamp_01', at=(0, -0.4), rot=0, ceiling=True),
                dict(asset='potted_plant_02', at=(-2.45, -2.2), rot=0, item='plants'),
                dict(asset='ClassicNightstand_01', at=(2.6, -1.4), rot=-90, item='cabinet'),
                dict(asset='ceramic_vase_01', on='ClassicNightstand_01', rot=0, item='cabinet'),
            ],
            extras=[dict(kind='books', on='modern_coffee_table_01', n=3, seed=3, dx=-0.2, item='coffee_table')],
            rug=dict(at=(0, 2.0), size=(2.8, 2.0), tint=(0.36, 0.33, 0.29), item='rug'),
            # Furniture the customer can switch off (in the room by default) or add (layer_extras, not in the
            # normal render): rendered as layers by --layers. Every object without an item stays in the empty room.
            items=dict(sofa='Sofa', side_table='Side table', coffee_table='Coffee table', lounge_chair='Lounge chair',
                       plants='Plants', cabinet='Side cabinet', rug='Rug'),
            layer_extras=[
                dict(key='armchair', label='Armchair',
                     props=[dict(asset='modern_arm_chair_01', at=(-2.05, 1.15), rot=125)]),
                dict(key='sideboard', label='Sideboard',
                     props=[dict(asset='modern_wooden_cabinet', at=(0.2, 'back'), rot=180, gap=0.04),
                            dict(asset='brass_vase_01', on='modern_wooden_cabinet', rot=0, dx=0.7)]),
            ],
        ),
        'bedroom': dict(
            title='Bedroom', width=5.0, front=3.6, back=2.0, height=2.9, eye=1.45,
            floor=('rectangular_parquet', 2.0, (1.0, 1.0, 1.0)), window=dict(side='r', centre=1.45, width=1.6, sill=0.55, top=2.56),
            drape=(0.42, 0.42, 0.42),
            props=[
                dict(asset='modern_bed', at=(0, 'wall'), gap=0.07, item='bed'),
                dict(asset='ClassicNightstand_01', at=(-1.38, 'wall'), rot=0, gap=0.07, item='bedside'),
                dict(asset='ClassicNightstand_01', at=(1.38, 'wall'), rot=0, gap=0.07, item='bedside'),
                dict(asset='ceramic_vase_01', on='ClassicNightstand_01', rot=0, item='bedside'),
                dict(asset='potted_plant_04', at=(-2.05, 'wall'), rot=0, gap=0.2, item='plant'),
            ],
            extras=[dict(kind='headboard_panel', item='panel'), dict(kind='bedside_pendants'), dict(kind='window_seat')],
            rug=dict(at=(0, 1.6), size=(2.6, 1.8), tint=(0.3, 0.29, 0.28), item='rug'),
            items=dict(bed='Bed', bedside='Bedside tables', panel='Slatted wall panel', plant='Plant', rug='Rug'),
            layer_extras=[
                dict(key='armchair', label='Armchair',
                     props=[dict(asset='modern_arm_chair_01', at=(-1.85, 0.5), rot=75)]),
                dict(key='dresser', label='Dresser',
                     props=[dict(asset='modern_wooden_cabinet', at=(0, 'back'), rot=180, gap=0.04),
                            dict(asset='ceramic_vase_03', on='modern_wooden_cabinet', rot=0, dx=0.75),
                            dict(asset='potted_plant_04', on='modern_wooden_cabinet', rot=0, dx=-0.7)]),
            ],
        ),
        'kitchen': dict(
            title='Kitchen', width=5.4, front=3.6, back=2.6, height=2.9, eye=1.45,
            floor=('wood_floor', 2.2, (1.0, 0.98, 0.95)), window=dict(side='r', centre=0.9, width=1.8, sill=0.7, top=2.56),
            drape=(0.55, 0.52, 0.47),
            props=[
                dict(asset='dining_chair_02', at=(-0.5, 1.95), rot=0, item='table'),
                dict(asset='dining_chair_02', at=(0.5, 1.95), rot=0, item='table'),
                dict(asset='potted_plant_04', at=(2.3, 'wall'), rot=0, gap=0.25, item='plants'),
                dict(asset='potted_plant_02', at=(2.25, -2.1), rot=0, item='plants'),
            ],
            extras=[dict(kind='kitchen')],
            rug=None,
            # loose furniture inside the fitted kitchen (extra_kitchen), by object name prefix
            item_names=dict(table=['k-table-'], banquette=['k-bench'], stools=['stool-']),
            items=dict(table='Bistro table & chairs', banquette='Banquette', stools='Bar stools', plants='Plants'),
            layer_extras=[
                dict(key='rug', label='Rug', rug=dict(at=(0, 2.45), size=(2.2, 1.7), tint=(0.42, 0.38, 0.32))),
                dict(key='island_decor', label='Vase & plant on the island',
                     props=[dict(asset='ceramic_vase_03', on='k-island-top', rot=0, dy=-0.45),
                            dict(asset='potted_plant_04', on='k-island-top', rot=0, dy=0.35)]),
            ],
        ),
        'office': dict(
            title='Office', width=5.0, front=3.0, back=2.5, height=2.9, eye=1.45,
            floor=('herringbone_parquet', 1.6, (1.0, 1.0, 1.0)), window=dict(side='l', centre=1.2, width=1.8, sill=0.8, top=2.56),
            drape=(0.3, 0.32, 0.35),
            props=[
                dict(asset='metal_office_desk', at=(0, 'wall'), rot=0, gap=0.05, item='desk'),
                dict(asset='modern_arm_chair_01', at=(0, 2.0), rot=180, item='chair'),
                dict(asset='desk_lamp_arm_01', on='metal_office_desk', rot=20, dx=-0.82, item='desk'),
                dict(asset='potted_plant_02', at=(-2.0, -1.9), rot=0, item='plant'),
            ],
            extras=[dict(kind='shelving', side='r', centre=0.2, width=2.2, item='shelving'),
                    dict(kind='books', on='metal_office_desk', n=2, seed=7, dx=0.55, item='desk')],
            rug=dict(at=(0, 1.6), size=(2.4, 1.8), tint=(0.22, 0.24, 0.26), item='rug'),
            items=dict(desk='Desk', chair='Desk chair', shelving='Bookshelf', plant='Plant', rug='Rug'),
            layer_extras=[
                dict(key='lounge_chair', label='Lounge chair & side table',
                     props=[dict(asset='mid_century_lounge_chair', at=(1.45, -1.5), rot=-135),
                            dict(asset='side_table_01', at=(2.15, -2.15), rot=0),
                            dict(asset='ceramic_vase_03', on='side_table_01', rot=0)]),
                dict(key='desk_plant', label='Plant on the desk',
                     props=[dict(asset='potted_plant_04', on='metal_office_desk', rot=0, dx=-0.4)]),
            ],
        ),
        'dining': dict(
            title='Dining room', width=6.0, front=3.4, back=3.2, height=3.0, eye=1.45,
            floor=('herringbone_parquet', 1.6, (0.92, 0.9, 0.88)), window=dict(side='r', centre=1.6, width=2.0, sill=0.5, top=2.66),
            drape=(0.45, 0.4, 0.34),
            props=[
                dict(asset='modern_wooden_cabinet', at=(0, 'wall'), rot=0, gap=0.04, item='sideboard'),
                dict(asset='ceramic_vase_03', on='modern_wooden_cabinet', rot=0, dx=0.55, item='sideboard'),
                dict(asset='modern_dining_table', at=(0, -1.6), item='table'),
                dict(asset='dining_chair_02', beside='dining_table', side='l', along=-0.45, rot=-90, item='chairs'),
                dict(asset='dining_chair_02', beside='dining_table', side='l', along=0.45, rot=-90, item='chairs'),
                dict(asset='dining_chair_02', beside='dining_table', side='r', along=-0.45, rot=90, item='chairs'),
                dict(asset='dining_chair_02', beside='dining_table', side='r', along=0.45, rot=90, item='chairs'),
                dict(asset='modern_ceiling_lamp_01', at=(0, -1.6), rot=0, ceiling=True),
                dict(asset='ceramic_vase_02', on='dining_table', rot=0, dx=0.0, item='table'),
                dict(asset='potted_plant_01', at=(-2.5, 'wall'), rot=0, gap=0.3, item='plant'),
            ],
            extras=[dict(kind='books', on='modern_wooden_cabinet', n=3, seed=11, dx=-0.6, item='sideboard')],
            rug=dict(at=(0, -1.6), size=(2.4, 3.0), tint=(0.34, 0.3, 0.26), item='rug'),
            items=dict(sideboard='Sideboard', table='Dining table', chairs='Dining chairs', plant='Plant', rug='Rug'),
            layer_extras=[
                dict(key='console', label='Console table',
                     props=[dict(asset='ClassicConsole_01', at=('lwall', 1.0), rot=90, gap=0.04),
                            dict(asset='brass_vase_01', on='ClassicConsole_01', rot=0, dy=0.4)]),
                dict(key='plant_right', label='Second plant',
                     props=[dict(asset='potted_plant_02', at=(1.8, 'wall'), rot=40, gap=0.25)]),
            ],
        ),
        'lobby': dict(
            title='Hotel lobby', width=8.0, front=4.2, back=3.0, height=3.6, eye=1.5,
            floor=('marble_01', 2.4, (1.0, 1.0, 1.0)), window=dict(side='r', centre=1.8, width=2.6, sill=0.3, top=3.26),
            drape=(0.3, 0.26, 0.22),
            props=[
                dict(asset='ClassicConsole_01', at=(0, 'wall'), rot=0, gap=0.04, item='console'),
                dict(asset='brass_vase_01', on='ClassicConsole_01', rot=0, dx=-0.45, item='console'),
                dict(asset='ArmChair_01', at=(-1.55, 2.1), rot=-80, item='armchairs'),
                dict(asset='ArmChair_01', at=(1.55, 2.1), rot=80, item='armchairs'),
                dict(asset='coffee_table_round_01', at=(0, 2.1), rot=0, item='coffee_table'),
                dict(asset='potted_plant_02', at=(-3.3, 'wall'), rot=0, gap=0.3, item='plants'),
                dict(asset='potted_plant_02', at=(3.3, 'wall'), rot=60, gap=0.3, item='plants'),
            ],
            extras=[dict(kind='wall_panels', sides=('l', 'b')),
                    dict(kind='books', on='coffee_table_round_01', n=2, seed=21, item='coffee_table')],
            rug=dict(at=(0, 2.1), size=(3.4, 2.4), tint=(0.28, 0.22, 0.18), item='rug'),
            items=dict(console='Console', armchairs='Armchairs', coffee_table='Coffee table', plants='Plants', rug='Rug'),
            layer_extras=[
                dict(key='sofa', label='Sofa & side tables',
                     props=[dict(asset='sofa_03', at=(0, 'back'), rot=180, sx=0.8, gap=0.06),
                            dict(asset='side_table_01', at=(-1.5, 'back'), rot=0, gap=0.1),
                            dict(asset='side_table_01', at=(1.5, 'back'), rot=0, gap=0.1)]),
                dict(key='sideboard', label='Sideboard',
                     props=[dict(asset='modern_wooden_cabinet', at=('lwall', 0.4), rot=90, gap=0.05),
                            dict(asset='ceramic_vase_01', on='modern_wooden_cabinet', rot=0, dy=-0.6)]),
            ],
        ),
    }


# --------------------------------------------------------------------------------------------- helpers

def args():
    argv = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else []
    opts = {'room': 'living', 'size': 2048, 'samples': 160, 'preview': False, 'detail_only': False, 'detail_size': 2048, 'device': 'CPU'}
    i = 0
    while i < len(argv):
        key = argv[i].lstrip('-')
        if key == 'preview':
            opts['preview'] = True
        elif key == 'detail-only':
            opts['detail_only'] = True
        elif key == 'zone-only':
            opts['zone_only'] = True
        elif key == 'layers':
            opts['layers'] = True
        elif key == 'check-items':
            opts['check_items'] = True
        else:
            opts[key] = argv[i + 1]
            i += 1
        i += 1
    opts['size'] = int(opts['size'])
    opts['samples'] = int(opts['samples'])
    opts['detail_size'] = int(opts.get('detail-size', opts['detail_size']))
    return opts


def plaster_paint(name):
    """Matt emulsion over plaster: a very fine, irregular relief and uneven sheen, like a real wall."""
    m = principled(name, (WALL_ALBEDO,) * 3, rough=0.9, spec=0.25)
    nt = m.node_tree
    b = nt.nodes['Principled BSDF']
    coord = nt.nodes.new('ShaderNodeTexCoord')
    fine = nt.nodes.new('ShaderNodeTexNoise')
    fine.inputs['Scale'].default_value = 900.0
    fine.inputs['Detail'].default_value = 6.0
    broad = nt.nodes.new('ShaderNodeTexNoise')
    broad.inputs['Scale'].default_value = 3.0
    nt.links.new(coord.outputs['Object'], fine.inputs['Vector'])
    nt.links.new(coord.outputs['Object'], broad.inputs['Vector'])
    bump = nt.nodes.new('ShaderNodeBump')
    bump.inputs['Strength'].default_value = 0.06
    bump.inputs['Distance'].default_value = 0.0006
    nt.links.new(fine.outputs['Fac'], bump.inputs['Height'])
    nt.links.new(bump.outputs['Normal'], b.inputs['Normal'])
    ramp = nt.nodes.new('ShaderNodeMapRange')
    ramp.inputs['To Min'].default_value = 0.82
    ramp.inputs['To Max'].default_value = 0.95
    nt.links.new(broad.outputs['Fac'], ramp.inputs['Value'])
    nt.links.new(ramp.outputs['Result'], b.inputs['Roughness'])
    return m


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

def _object_names():
    return {o.name for o in bpy.data.objects}


def _tag_item(before, item, extra=False):
    """Mark every object created since `before` as part of a switchable furniture item (see --layers)."""
    if not item:
        return
    for o in bpy.data.objects:
        if o.name not in before and not o.get('item'):
            o['item'] = item
            if extra:
                o['layer_extra'] = True


def rug(r, name='rug'):
    wool = textured(name, 'wool_boucle', 0.35, flat=r['tint'])
    (rx, ry), (rw, rd) = r['at'], r['size']
    box(name, rx - rw / 2, rx + rw / 2, ry - rd / 2, ry + rd / 2, 0, 0.012, wool)


def place_prop(cfg, p, placed):
    """Import (or build) one piece of furniture and put it in place: against the art wall ('wall'), the back
    wall ('back'), a side wall ('lwall'/'rwall'), on top of another piece ('on'), beside a table, or at (x, y)."""
    W, F, B, H = cfg['width'], cfg['front'], cfg['back'], cfg['height']
    y0, y1 = -B, F
    if p['asset'] == 'modern_dining_table':
        objs = modern_dining_table(*p['at'])
        placed['dining_table'] = world_bbox(objs)
        return
    if p['asset'] == 'modern_bed':
        objs = modern_bed(p['at'][0], y1 - p.get('gap', 0.02))
        placed['modern_bed'] = world_bbox(objs)
        return
    root, objs = import_asset(p['asset'])
    root.rotation_euler = (0, 0, math.radians(p.get('rot', 0)))
    if 'sx' in p:  # narrower/wider without changing the height (e.g. a 2.7 m sofa → a usual 2.2 m)
        root.scale = (p['sx'], 1, 1)
    bpy.context.view_layer.update()
    lo, hi = world_bbox(objs)
    if 'beside' in p:
        # A chair along a table's long side, facing it, seat front just under the table edge.
        base_lo, base_hi = placed[p['beside']]
        ty = (base_lo.y + base_hi.y) / 2 + p.get('along', 0)
        depth = hi.x - lo.x
        tx = base_lo.x - depth / 2 + 0.12 if p['side'] == 'l' else base_hi.x + depth / 2 - 0.12
        root.location = (tx - (lo.x + hi.x) / 2, ty - (lo.y + hi.y) / 2, -lo.z)
    elif 'on' in p:
        if p['on'] in placed:
            base_lo, base_hi = placed[p['on']]
        else:  # on a built piece, by object name
            base_lo, base_hi = world_bbox([o for o in bpy.data.objects if o.name.startswith(p['on'])])
        bx, by = (base_lo.x + base_hi.x) / 2 + p.get('dx', 0), (base_lo.y + base_hi.y) / 2 + p.get('dy', 0)
        root.location = (bx - (lo.x + hi.x) / 2, by - (lo.y + hi.y) / 2, base_hi.z - lo.z)
    else:
        ax, ay = p['at']
        if ay == 'wall':
            ay = y1 - p.get('gap', 0.03) - (hi.y - lo.y) / 2
        elif ay == 'back':
            ay = y0 + p.get('gap', 0.03) + (hi.y - lo.y) / 2
        if ax == 'lwall':
            ax = -W / 2 + p.get('gap', 0.03) + (hi.x - lo.x) / 2
        elif ax == 'rwall':
            ax = W / 2 - p.get('gap', 0.03) - (hi.x - lo.x) / 2
        z = (H - 0.002 - hi.z) if p.get('ceiling') else -lo.z
        root.location = (ax - (lo.x + hi.x) / 2, ay - (lo.y + hi.y) / 2, z)
    bpy.context.view_layer.update()
    placed[p['asset']] = world_bbox(objs)
    for o in objs:
        o['ceiling'] = bool(p.get('ceiling'))
        o['prop'] = True


def build(cfg):
    bpy.ops.wm.read_factory_settings(use_empty=True)
    scene = bpy.context.scene
    W, F, B, H, eye = cfg['width'], cfg['front'], cfg['back'], cfg['height'], cfg['eye']
    x0, x1, y0, y1 = -W / 2, W / 2, -B, F
    t = 0.12  # wall thickness

    wall_mat = plaster_paint('wall')
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
        before = _object_names()
        rug(cfg['rug'])
        _tag_item(before, cfg['rug'].get('item'))

    # furniture
    placed = {}
    for p in cfg['props']:
        before = _object_names()
        place_prop(cfg, p, placed)
        _tag_item(before, p.get('item'))

    for extra in cfg.get('extras', []):
        before = _object_names()
        EXTRAS[extra['kind']](cfg, **{k: v for k, v in extra.items() if k not in ('kind', 'item')})
        _tag_item(before, extra.get('item'))
    for item, prefixes in cfg.get('item_names', {}).items():
        for o in bpy.data.objects:
            if not o.get('item') and any(o.name.startswith(pre) for pre in prefixes):
                o['item'] = item

    # Furniture that is only offered as an optional layer (--layers): not in the normal render.
    if cfg.get('_layers'):
        for ex in cfg.get('layer_extras', []):
            before = _object_names()
            if ex.get('rug'):
                rug(ex['rug'], name=f'rug-{ex["key"]}')
            for p in ex.get('props', []):
                place_prop(cfg, p, placed)
            _tag_item(before, ex['key'], extra=True)

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


def modern_dining_table(cx, cy, width=0.95, length=2.1):
    """Solid oak top on slim black steel legs, with a linen runner and a bowl: a contemporary dining table."""
    oak = textured('table-oak', 'rectangular_parquet', 1.6, (0.9, 0.8, 0.68), rough_mul=0.7)
    steel = principled('table-steel', (0.02, 0.02, 0.02), rough=0.4, metal=0.8)
    runner = textured('runner', 'rough_linen', 0.4, flat=(0.78, 0.74, 0.66))
    x0, x1, y0, y1 = cx - width / 2, cx + width / 2, cy - length / 2, cy + length / 2
    objs = [soft_box('table-top', x0, x1, y0, y1, 0.72, 0.76, oak, bevel=0.006, segments=3)]
    for (lx, ly) in ((x0 + 0.08, y0 + 0.12), (x1 - 0.08, y0 + 0.12), (x0 + 0.08, y1 - 0.12), (x1 - 0.08, y1 - 0.12)):
        objs.append(box(f'table-leg-{lx:.2f}-{ly:.2f}', lx - 0.02, lx + 0.02, ly - 0.02, ly + 0.02, 0, 0.72, steel))
    objs.append(box('table-runner', cx - 0.22, cx + 0.22, y0 + 0.15, y1 - 0.15, 0.76, 0.762, runner))
    for o in objs:
        o['prop'] = True
    bpy.context.view_layer.update()
    return objs


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
    ring.name = f'{name}-ring'
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
                    for j, (ya2, yb2, za, zb) in enumerate(((a, b, z0, z0 + t), (a, b, z1 - t, z1), (a, a + t, z0 + t, z1 - t), (b - t, b, z0 + t, z1 - t))):
                        box(f'panel-{side}-{i}-{k}-{j}', xa, xb, ya2, yb2, za, zb, wall_mat, WALL_INDEX)
        else:
            y = -B
            n = max(2, round(W / 1.6))
            for i in range(n):
                a = -W / 2 + W * i / n + 0.2
                b = -W / 2 + W * (i + 1) / n - 0.2
                for k, (z0, z1) in enumerate(((0.3, 0.95), (1.15, top))):
                    for j, (xa2, xb2, za, zb) in enumerate(((a, b, z0, z0 + t), (a, b, z1 - t, z1), (a, a + t, z0 + t, z1 - t), (b - t, b, z0 + t, z1 - t))):
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
    sun.energy = 3.6
    sun.angle = math.radians(2)
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


def use_gpu():
    """'--device GPU' renders on NVIDIA (OptiX, else CUDA), e.g. a free Kaggle/Colab GPU; falls back to CPU."""
    if args().get('device', 'CPU').upper() != 'GPU':
        return 'CPU'
    prefs = bpy.context.preferences.addons['cycles'].preferences
    for kind in ('OPTIX', 'CUDA'):
        try:
            prefs.compute_device_type = kind
            prefs.get_devices()
        except TypeError:
            continue
        gpus = [d for d in prefs.devices if d.type == kind]
        if gpus:
            for d in prefs.devices:
                d.use = d.type == kind
            print(f'rendering on {kind}: ' + ', '.join(d.name for d in gpus), flush=True)
            return 'GPU'
    print('no NVIDIA GPU found, rendering on CPU', flush=True)
    return 'CPU'


def setup_render(size, samples, outdir, item_mask=False):
    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    c = scene.cycles
    c.device = use_gpu()
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
    if item_mask:
        # Exact (sub-pixel) coverage of the switchable furniture, from Cryptomatte: only the item whose layer
        # renders is visible, so a matte of every item object is that item.
        scene.view_layers[0].use_pass_cryptomatte_object = True
        crypto = nt.nodes.new('CompositorNodeCryptomatteV2')
        crypto.source = 'RENDER'
        crypto.scene = scene
        crypto.matte_id = ','.join(o.name for o in bpy.data.objects if o.get('item') and o.type == 'MESH')
        nt.links.new(rl.outputs['Image'], crypto.inputs['Image'])
        out.file_slots.new('item')
        nt.links.new(crypto.outputs['Matte'], out.inputs['item'])
    return out


# --------------------------------------------------------------------------------------------- output

def read_exr(path):
    img = bpy.data.images.load(path)
    w, h = img.size
    px = np.empty(w * h * 4, dtype=np.float32)
    img.pixels.foreach_get(px)
    bpy.data.images.remove(img)
    return px.reshape(h, w, 4)[::-1]  # top row first


def bloom(img, w=None):
    """A camera lens's soft glow round very bright areas (windows, lamps): what makes a render read as a photo.
    `w` = width of the full image when `img` is a crop of it (the glow radii follow the full image)."""
    bright = np.maximum(img - 1.0, 0.0)
    if not bright.any():
        return img
    glow = np.zeros_like(img)
    w = w or img.shape[1]
    for radius, weight in ((max(2, w // 256), 0.5), (max(4, w // 96), 0.35), (max(8, w // 32), 0.15)):
        k = np.exp(-0.5 * (np.arange(-3 * radius, 3 * radius + 1) / radius) ** 2)
        k /= k.sum()
        g = bright
        for axis in (0, 1):
            pad = [(0, 0)] * 3
            pad[axis] = (len(k) // 2, len(k) // 2)
            gp = np.pad(g, pad, mode='edge')
            g = np.apply_along_axis(lambda v: np.convolve(v, k, mode='valid'), axis, gp)
        glow += weight * g
    return img + 0.22 * glow


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
    band = (-0.6, 0.6)  # what sits under the artwork's centre; tall things to the sides limit the width
    min_bottom = 0.75
    left, right = -W / 2 + 0.25, W / 2 - 0.25
    tops = {}
    for o in bpy.data.objects:
        if o.type != 'MESH' or not o.get('prop') or o.get('ceiling') or o.get('layer_extra'):
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

    dest = os.path.join(ROOT, key) if not preview else os.path.join(OUT_TMP, f'{key}-preview')
    os.makedirs(dest, exist_ok=True)
    for name, (beauty, mask) in faces.items():
        enc = srgb(tone(bloom(beauty * exposure)))
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


def detail_frame(cfg, zone):
    """Window over the art wall's free area (+ a margin), in tangent units of the forward face:
    (half size, centre x, centre y)."""
    F = cfg['front']
    m = 0.2
    # Where artworks hang: the central 2.8 m of the free wall (very wide walls would spread the pixels thin).
    xa, xb = (max(zone['left'], -1.4) - m) / F, (min(zone['right'], 1.4) + m) / F
    ya, yb = (zone['minBottom'] - m) / F, (zone['maxTop'] + m) / F
    half = min(0.95, max(xb - xa, yb - ya) / 2)
    cx = float(np.clip((xa + xb) / 2, -1 + half, 1 - half))
    cy = float(np.clip((ya + yb) / 2, -1 + half, 1 - half))
    return half, cx, cy


def aim_detail(camera, half, cx, cy):
    camera.rotation_euler = tuple(math.radians(a) for a in FACES['f'][0])
    camera.data.angle = 2 * math.atan(half)
    camera.data.shift_x = cx / (2 * half)
    camera.data.shift_y = cy / (2 * half)


def aim_face(camera, name):
    camera.rotation_euler = tuple(math.radians(a) for a in FACES[name][0])
    camera.data.angle = math.pi / 2
    camera.data.shift_x = camera.data.shift_y = 0.0


def render_detail(key, cfg, size, samples):
    """A sharper render of the art wall only (the part of the room people zoom into), seen from the same eye
    through a narrower lens, so it lines up exactly with the forward face. Uses the room's own exposure."""
    dest = os.path.join(ROOT, key)
    with open(os.path.join(dest, 'meta.json')) as fh:
        meta = json.load(fh)
    exposure = np.array(meta['exposure'], dtype=np.float32)
    camera, _ = build(cfg)
    half, cx, cy = detail_frame(cfg, free_zone(cfg))
    tmp = os.path.join(OUT_TMP, key + '-detail')
    shutil.rmtree(tmp, ignore_errors=True)
    os.makedirs(tmp, exist_ok=True)
    out = setup_render(size, samples, tmp)
    scene = bpy.context.scene
    aim_detail(camera, half, cx, cy)
    out.file_slots[0].path = 'detail-beauty-'
    out.file_slots[1].path = 'detail-mask-'
    scene.render.filepath = os.path.join(tmp, 'detail-composite')
    print(f'[{key}] rendering art-wall detail at {size}px ({size / half / 2:.0f}px face equivalent), {samples} samples', flush=True)
    bpy.ops.render.render(write_still=False)
    frame = scene.frame_current
    beauty = read_exr(os.path.join(tmp, f'detail-beauty-{frame:04d}.exr'))[..., :3]
    mask = read_exr(os.path.join(tmp, f'detail-mask-{frame:04d}.exr'))[..., 0]
    png = os.path.join(tmp, 'detail.png')
    save_png(png, srgb(tone(bloom(beauty * exposure))))
    mpng = os.path.join(tmp, 'detail-mask.png')
    save_png(mpng, np.repeat(np.clip(mask, 0, 1)[..., None], 3, axis=2))
    webp(png, os.path.join(dest, 'detail.webp'), quality=92, alpha=False)
    webp(mpng, os.path.join(dest, 'detail-mask.webp'), quality=90, alpha=False)
    # Where it sits on the forward face, in that face's texture coordinates (v up).
    meta['detail'] = {
        'file': 'detail.webp', 'mask': 'detail-mask.webp', 'size': size,
        'u0': round((cx - half + 1) / 2, 6), 'u1': round((cx + half + 1) / 2, 6),
        'v0': round((cy - half + 1) / 2, 6), 'v1': round((cy + half + 1) / 2, 6),
    }
    with open(os.path.join(dest, 'meta.json'), 'w') as fh:
        json.dump(meta, fh, indent=2)
    print(f'[{key}] detail done → {dest}', flush=True)


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


# --------------------------------------------------------------------------------------------- layers
#
# '--layers' renders switchable furniture for a room that was rendered normally before (its installed faces,
# detail, masks and exposure are the base, so everything matches):
#   <face>-empty.webp (+ -sm, -mask, -mask-sm), detail-empty.webp, detail-empty-mask.webp
#       the room with every switchable item hidden (walls, windows, curtains, lights and built-ins stay): only
#       the union of the items' boxes is rendered, feathered into the full render. Only for views where an
#       item shows (meta.layers.empty.facesList / hasDetail); elsewhere the normal image is already empty.
#   L-<item>-<face>-obj.webp, L-<item>-detail-obj.webp
#       grey coverage of the item itself (no shadow), same box as its layer
#   L-<item>-<face>.webp, L-<item>-detail.webp
#       RGBA, covering only meta.layers.items[].faces[face].rect (u left→right, v bottom→top, 0..1 of the image).
#       Alpha-blended (straight alpha, in the image's sRGB values) over the empty room it reproduces the room
#       with that item: the item itself is opaque, its shadows and contact darkening are black at partial alpha,
#       so they darken whatever lies under them (a re-painted wall, a rug, another item).
# Items are listed far → near: composite them in that order.

LAYER_T0, LAYER_T1 = 1.5 / 255, 4.0 / 255   # sRGB difference ignored as noise / fully counted
LUM = np.array([0.2126, 0.7152, 0.0722], dtype=np.float32)
_EDGES = [(i, i ^ b) for i in range(8) for b in (1, 2, 4) if i < i ^ b]


def smoothstep(e0, e1, x):
    t = np.clip((x - e0) / (e1 - e0), 0, 1)
    return t * t * (3 - 2 * t)


def box_blur(a, r=1):
    h, w = a.shape
    p = np.pad(a, r, mode='edge')
    out = np.zeros_like(a)
    for dy in range(2 * r + 1):
        for dx in range(2 * r + 1):
            out += p[dy:dy + h, dx:dx + w]
    return out / (2 * r + 1) ** 2


def layer_items(cfg):
    """The room's switchable furniture, far → near (rugs first): [dict(key, label, default, objs)]."""
    by = {}
    for o in bpy.data.objects:
        if o.get('item'):
            by.setdefault(o['item'], []).append(o)
    decl = [(k, label, True) for k, label in cfg.get('items', {}).items()]
    decl += [(ex['key'], ex['label'], False) for ex in cfg.get('layer_extras', [])]
    keys = [k for k, _, _ in decl]
    missing = [k for k in keys if not any(o.type == 'MESH' for o in by.get(k, []))]
    stray = [k for k in by if k not in keys]
    if missing or stray or len(set(keys)) != len(keys):
        raise SystemExit(f'item list does not resolve: no objects for {missing}, undeclared {stray}, keys {keys}')
    eye = Vector((0, 0, cfg['eye']))
    items = []
    for k, label, default in decl:
        objs = [o for o in by[k] if o.type == 'MESH']
        lo, hi = world_bbox(objs)
        near = Vector((min(max(eye.x, lo.x), hi.x), min(max(eye.y, lo.y), hi.y), min(max(eye.z, lo.z), hi.z)))
        items.append(dict(key=k, label=label, default=default, objs=objs, flat=hi.z < 0.05, dist=(near - eye).length))
    items.sort(key=lambda it: (not it['flat'], -it['dist']))

    # Something standing on another item (a plant on the desk) composites after it.
    boxes = {it['key']: [world_bbox([ob]) for ob in it['objs']] for it in items}

    def rests_on(a, b):
        for lo_a, hi_a in boxes[a['key']]:
            if lo_a.z < 0.05:
                continue
            cx, cy = (lo_a.x + hi_a.x) / 2, (lo_a.y + hi_a.y) / 2
            if any(lo.x <= cx <= hi.x and lo.y <= cy <= hi.y and abs(hi.z - lo_a.z) < 0.03 for lo, hi in boxes[b['key']]):
                return True
        return False

    for _ in range(len(items) ** 2):
        swap = next(((i, j) for i in range(len(items)) for j in range(i + 1, len(items)) if rests_on(items[i], items[j])), None)
        if not swap:
            break
        items.insert(swap[1], items.pop(swap[0]))
    return items


def item_boxes(objs, footprint=0.4):
    """World boxes the item (and its contact shadow: a footprint round each piece) can cover."""
    boxes = []
    for o in objs:
        lo, hi = world_bbox([o])
        boxes.append((lo, hi))
        # Floor-standing pieces get the full footprint; things on a shelf/table or flat on the floor (a rug) little.
        fp = footprint if lo.z < 0.05 and hi.z > 0.05 else 0.08
        boxes.append((Vector((lo.x - fp, lo.y - fp, lo.z)), Vector((hi.x + fp, hi.y + fp, lo.z + 0.001))))
    return boxes


def project_rect(cam, boxes, margin=0.12, near=0.02):
    """Screen rectangle (u0, v0, u1, v1; v up) of the boxes in the camera's current view, + margin; None if off screen."""
    from bpy_extras.object_utils import world_to_camera_view
    scene = bpy.context.scene
    to_cam = cam.matrix_world.inverted()
    us, vs = [], []
    for lo, hi in boxes:
        cs = [to_cam @ Vector((x, y, z)) for x in (lo.x, hi.x) for y in (lo.y, hi.y) for z in (lo.z, hi.z)]
        pts = [c for c in cs if -c.z > near]
        for i, j in _EDGES:  # where the box crosses the near plane
            da, db = -cs[i].z - near, -cs[j].z - near
            if da * db < 0:
                pts.append(cs[i].lerp(cs[j], da / (da - db)))
        for c in pts:
            p = world_to_camera_view(scene, cam, cam.matrix_world @ c)
            us.append(p.x)
            vs.append(p.y)
    if not us:
        return None
    u0, u1, v0, v1 = max(min(us), 0.0), min(max(us), 1.0), max(min(vs), 0.0), min(max(vs), 1.0)
    if u1 - u0 <= 0 or v1 - v0 <= 0:
        return None
    mu, mv = (u1 - u0) * margin, (v1 - v0) * margin
    return max(u0 - mu, 0.0), max(v0 - mv, 0.0), min(u1 + mu, 1.0), min(v1 + mv, 1.0)


def rect_pixels(rect, px):
    """(u0, v0, u1, v1) → pixel box (x0, y0, x1, y1), rows top first."""
    u0, v0, u1, v1 = rect
    return (max(0, int(math.floor(u0 * px))), max(0, int(math.floor((1 - v1) * px))),
            min(px, int(math.ceil(u1 * px))), min(px, int(math.ceil((1 - v0) * px))))


def render_view(out, tmp, tag, px, box=None):
    """Render the camera's current view at px × px, or only the pixel box (x0, y0, x1, y1; rows top first)."""
    scene = bpy.context.scene
    r = scene.render
    r.resolution_x = r.resolution_y = px
    if box:
        x0, y0, x1, y1 = box
        r.use_border = True
        r.use_crop_to_border = True
        # Blender truncates border × size to whole pixels: a quarter pixel in keeps it exact.
        r.border_min_x, r.border_max_x = (x0 + 0.25) / px, min(1.0, (x1 + 0.25) / px)
        r.border_min_y, r.border_max_y = (px - y1 + 0.25) / px, min(1.0, (px - y0 + 0.25) / px)
    else:
        r.use_border = False
    names = ['beauty', 'mask', 'item'][:len(out.file_slots)]
    for slot, n in zip(out.file_slots, names):
        slot.path = f'{tag}-{n}-'
    r.filepath = os.path.join(tmp, f'{tag}-composite')
    bpy.ops.render.render(write_still=False)
    frame = scene.frame_current
    res = {n: read_exr(os.path.join(tmp, f'{tag}-{n}-{frame:04d}.exr')) for n in names}
    for n in names:
        os.remove(os.path.join(tmp, f'{tag}-{n}-{frame:04d}.exr'))
    beauty = res['beauty'][..., :3]
    if box and beauty.shape[:2] != (box[3] - box[1], box[2] - box[0]):
        raise SystemExit(f'border render is {beauty.shape[:2]}, expected {(box[3] - box[1], box[2] - box[0])}')
    return beauty, res['mask'][..., 0], (res['item'][..., 0] if 'item' in res else None)


def read_image(path):
    """An 8-bit image file as stored (no colour conversion), float RGB, rows top first."""
    img = bpy.data.images.load(path)
    img.colorspace_settings.name = 'Non-Color'
    w, h = img.size
    px = np.empty(w * h * 4, dtype=np.float32)
    img.pixels.foreach_get(px)
    bpy.data.images.remove(img)
    return px.reshape(h, w, 4)[::-1, :, :3].copy()


def decode(enc, exposure):
    """Encoded image → linear light before exposure (inverse sRGB and tone curve). Capped at 1 so that
    bloom is not applied a second time to what is already baked into it."""
    y = np.clip(enc, 0, 1)
    x = np.where(y <= 0.04045, y / 12.92, ((y + 0.055) / 1.055) ** 2.4)
    s = 1 - KNEE
    x = np.where(x <= KNEE, x, KNEE - s * np.log(np.maximum(1 - (x - KNEE) / s, 1e-6)))
    return (np.minimum(x, 1.0) / exposure).astype(np.float32)


def encode_patch(canvas, exposure, px, box, sub=None):
    """Encoded (bloom, tone, sRGB) pixels of `box` from the linear canvas, with the glow computed from the
    canvas around it like the full image. `sub` = (pixel box, linear pixels) substituted first."""
    x0, y0, x1, y1 = box
    m = 3 * max(8, px // 32) + 1
    bx0, by0, bx1, by1 = max(0, x0 - m), max(0, y0 - m), min(px, x1 + m), min(px, y1 + m)
    patch = canvas[by0:by1, bx0:bx1].copy()
    if sub is not None:
        (p0x, p0y, p1x, p1y), lin = sub
        patch[p0y - by0:p1y - by0, p0x - bx0:p1x - bx0] = lin
    enc = srgb(tone(bloom(patch * exposure, w=px)))
    return enc[y0 - by0:y1 - by0, x0 - bx0:x1 - bx0]


def compose_layer(view, item_lin, item_cov, pbox, box, exposure):
    """RGBA layer over `box` from the item's render over `pbox` (a padded box round it) and the empty room."""
    px, canvas = view['px'], view['canvas']
    x0, y0, x1, y1 = box
    p0x, p0y = pbox[:2]
    I = encode_patch(canvas, exposure, px, box, (pbox, item_lin))
    E = encode_patch(canvas, exposure, px, box)
    cov = np.clip(item_cov[y0 - p0y:y1 - p0y, x0 - p0x:x1 - p0x], 0, 1)
    LI, LE = I @ LUM, E @ LUM
    d = box_blur(LI) - box_blur(LE)
    g = smoothstep(LAYER_T0, LAYER_T1, np.abs(d))
    # Shadow: black at partial alpha, so it darkens whatever is under it. (Light the item bounces onto its
    # surroundings is left out: painting the item's own view of them would erase other items behind it.)
    shade = np.where(d < 0, g * (1 - np.clip(LI / np.maximum(LE, 1e-4), 0, 1)), 0)
    # The item's own colour at its anti-aliased edges, without the floor/wall it was mixed with.
    c = cov[..., None]
    T = np.where(c > 0.02, np.clip((I - (1 - c) * E) / np.maximum(c, 0.02), 0, 1), I)
    T = np.where(c > 0.98, I, T)
    A = cov + (1 - cov) * shade
    C = np.where(A[..., None] > 1e-4, c * T / np.maximum(A, 1e-4)[..., None], I)
    return np.concatenate([np.clip(C, 0, 1), A[..., None]], axis=2).astype(np.float32), cov


def feather(shape, box, px, width=8):
    """Paste weight for `box`: 0 at its inner edges rising to 1 `width` px inside (none along the image edge)."""
    x0, y0, x1, y1 = box
    h, w = shape
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    big = np.full((h, w), 1e9, dtype=np.float32)
    dist = big.copy()
    if x0 > 0:
        dist = np.minimum(dist, xx)
    if y0 > 0:
        dist = np.minimum(dist, yy)
    if x1 < px:
        dist = np.minimum(dist, w - 1 - xx)
    if y1 < px:
        dist = np.minimum(dist, h - 1 - yy)
    return np.clip(dist / width, 0, 1)


def render_layers(key, cfg, o):
    """Empty room + one layer per switchable item, rendered only where the items are and pasted into the
    room's installed full render (its faces, detail and masks give the sizes and the exposure)."""
    import time
    t_start = time.time()
    dest = os.path.join(ROOT, key)
    meta_path = os.path.join(dest, 'meta.json')
    if not os.path.isfile(meta_path):
        raise SystemExit(f'{meta_path} missing: render the room normally first (--layers builds on it)')
    with open(meta_path) as fh:
        meta = json.load(fh)
    exposure = np.array(meta['exposure'], dtype=np.float32)
    cfg['_layers'] = True
    camera, _ = build(cfg)
    zone = free_zone(cfg)
    items = layer_items(cfg)
    tmp = os.path.join(OUT_TMP, key + '-layers')
    shutil.rmtree(tmp, ignore_errors=True)
    os.makedirs(tmp, exist_ok=True)
    out = setup_render(meta['faceSize'], o['samples'], tmp, item_mask=True)
    scene = bpy.context.scene

    views = [dict(name=n, file=f'{n}.webp', mask=f'{n}-mask.webp') for n in FACES]
    with_detail = 'detail' in meta and o['detail_size'] > 0
    if with_detail:
        half, cx, cy = detail_frame(cfg, zone)
        d = meta['detail']
        if abs(d['u0'] - round((cx - half + 1) / 2, 6)) > 1e-5 or abs(d['v0'] - round((cy - half + 1) / 2, 6)) > 1e-5:
            print(f'[{key}] WARNING: detail framing differs from meta.detail (re-render the detail)', flush=True)
        views.append(dict(name='detail', file=d['file'], mask=d['mask'], frame=(half, cx, cy)))
    for view in views:
        view['base'] = read_image(os.path.join(dest, view['file']))
        view['base_mask'] = read_image(os.path.join(dest, view['mask']))
        view['px'] = view['base'].shape[1]
        view['canvas'] = decode(view['base'], exposure)

    def aim(view):
        if view['name'] == 'detail':
            aim_detail(camera, *view['frame'])
        else:
            aim_face(camera, view['name'])
        bpy.context.view_layer.update()  # camera.matrix_world, for project_rect

    def show(keys):
        for ob in bpy.data.objects:
            if ob.get('item'):
                ob.hide_render = ob['item'] not in keys

    def padded(box, px):
        pad = max(8, px // 64)  # rendered round the box too, so the denoiser sees the same surroundings
        return (max(0, box[0] - pad), max(0, box[1] - pad), min(px, box[2] + pad), min(px, box[3] + pad))

    # Where each item can show on each view.
    for it in items:
        boxes = item_boxes(it['objs'])
        it['boxes'] = {}
        for view in views:
            aim(view)
            rect = project_rect(camera, boxes)
            if rect is not None:
                it['boxes'][view['name']] = rect_pixels(rect, view['px'])

    # 1. The empty room, rendered only over the union of the items' boxes, pasted into the full render.
    for f in os.listdir(dest):
        if (f.startswith('L-') or '-empty' in f) and f.endswith('.webp'):
            os.remove(os.path.join(dest, f))
    show(())
    area = full_area = 0
    empty_faces = []
    for view in views:
        n, px = view['name'], view['px']
        full_area += px * px
        bs = [it['boxes'][n] for it in items if n in it['boxes']]
        if not bs:
            continue
        ubox = (min(b[0] for b in bs), min(b[1] for b in bs), max(b[2] for b in bs), max(b[3] for b in bs))
        upad = padded(ubox, px)
        area += (upad[2] - upad[0]) * (upad[3] - upad[1])
        aim(view)
        print(f'[{key}] empty {n} box {ubox} of {px}px', flush=True)
        lin, mask, _ = render_view(out, tmp, f'empty-{n}', px, upad)
        view['canvas'][upad[1]:upad[3], upad[0]:upad[2]] = lin
        x0, y0, x1, y1 = ubox
        enc = view['base'].copy()
        w = feather((y1 - y0, x1 - x0), ubox, px)[..., None]
        enc[y0:y1, x0:x1] = w * encode_patch(view['canvas'], exposure, px, ubox) + (1 - w) * enc[y0:y1, x0:x1]
        emask = view['base_mask'].copy()
        emask[y0:y1, x0:x1] = np.clip(mask[y0 - upad[1]:y1 - upad[1], x0 - upad[0]:x1 - upad[0]], 0, 1)[..., None]
        png, mpng = os.path.join(tmp, f'{n}-empty.png'), os.path.join(tmp, f'{n}-empty-mask.png')
        save_png(png, enc)
        save_png(mpng, emask)
        webp(png, os.path.join(dest, f'{n}-empty.webp'), quality=92, alpha=False)
        webp(mpng, os.path.join(dest, f'{n}-empty-mask.webp'), quality=90, alpha=False)
        if n != 'detail':
            webp(png, os.path.join(dest, f'{n}-empty-sm.webp'), quality=80, resize=512, alpha=False)
            webp(mpng, os.path.join(dest, f'{n}-empty-mask-sm.webp'), quality=85, resize=512, alpha=False)
            empty_faces.append(n)
        else:
            view['has_empty'] = True
    t_empty = time.time() - t_start

    # 2. One layer per item, only where it is seen.
    entries = []
    for it in items:
        show({it['key']})
        entry = {'key': it['key'], 'label': it['label'], 'default': it['default'], 'faces': {}}
        for view in views:
            n, px = view['name'], view['px']
            if n not in it['boxes']:
                continue
            box = it['boxes'][n]
            aim(view)
            for attempt in range(3):
                pbox = padded(box, px)
                print(f'[{key}] {it["key"]} {n} box {box}', flush=True)
                lin, _, cov = render_view(out, tmp, f'{it["key"]}-{n}', px, pbox)
                area += (pbox[2] - pbox[0]) * (pbox[3] - pbox[1])
                layer, obj = compose_layer(view, lin, cov, pbox, box, exposure)
                A = layer[..., 3]
                # Shadow still reaching an inner edge of the box: grow that side and render again.
                w, h = box[2] - box[0], box[3] - box[1]
                grow = [box[0] > 0 and A[:, :2].max() > 0.2, box[1] > 0 and A[:2, :].max() > 0.2,
                        box[2] < px and A[:, -2:].max() > 0.2, box[3] < px and A[-2:, :].max() > 0.2]
                if not any(grow) or attempt == 2:
                    break
                gx, gy = max(4, w // 4), max(4, h // 4)
                box = (max(0, box[0] - gx * grow[0]), max(0, box[1] - gy * grow[1]),
                       min(px, box[2] + gx * grow[2]), min(px, box[3] + gy * grow[3]))
            if (A > 0.15).sum() < 6:
                continue  # hidden behind something here
            fname = f'L-{it["key"]}-{n}.webp'
            png = os.path.join(tmp, f'L-{it["key"]}-{n}.png')
            save_png(png, layer)
            webp(png, os.path.join(dest, fname), quality=90, alpha=True)
            # The item's own coverage (no shadow), same box: lets the viewer re-paint the wall in its shadow.
            oname = f'L-{it["key"]}-{n}-obj.webp'
            opng = os.path.join(tmp, f'L-{it["key"]}-{n}-obj.png')
            # Grown by 1–2 px (max filter) so it fully covers the item's anti-aliased edges.
            r = 2 if px >= 1024 else 1
            op = np.pad(obj, r, mode='edge')
            oh, ow = obj.shape
            obj = np.max([op[dy:dy + oh, dx:dx + ow] for dy in range(2 * r + 1) for dx in range(2 * r + 1)], axis=0)
            save_png(opng, np.repeat(obj[..., None], 3, axis=2))
            webp(opng, os.path.join(dest, oname), quality=90, alpha=False)
            x0, y0, x1, y1 = box
            uv = [round(x0 / px, 6), round(1 - y1 / px, 6), round(x1 / px, 6), round(1 - y0 / px, 6)]
            if n == 'detail':
                entry['detail'] = {'file': fname, 'obj': oname, 'rect': uv}
            else:
                entry['faces'][n] = {'file': fname, 'obj': oname, 'rect': uv}
        entries.append(entry)
    show({it['key'] for it in items if it['default']})
    scene.render.use_border = False

    has_detail = any(v.get('has_empty') for v in views)
    empty = {'faces': '<face>-empty.webp', 'mask': '<face>-empty-mask.webp', 'facesList': empty_faces, 'hasDetail': has_detail}
    if 'detail' in meta and o['detail_size'] <= 0:
        # Layers rendered without the detail (faster): the viewer hides the detail while furniture is changed.
        empty['detailSkipped'] = True
    if has_detail:
        empty.update({'detail': 'detail-empty.webp', 'detailMask': 'detail-empty-mask.webp'})
    with open(meta_path) as fh:
        meta = json.load(fh)
    meta['layers'] = {'empty': empty, 'items': entries}
    with open(meta_path, 'w') as fh:
        json.dump(meta, fh, indent=2)
    print(f'[{key}] layers done in {time.time() - t_start:.0f}s (empty room {t_empty:.0f}s); rendered '
          f'{area / 1e6:.2f} Mpx = {100 * area / full_area:.0f}% of the full faces + detail ({full_area / 1e6:.2f} Mpx): '
          + ', '.join(f'{e["key"]}[{",".join(e["faces"])}{",detail" if "detail" in e else ""}]' for e in entries), flush=True)


def check_items(key, cfg):
    """Build the room with its optional furniture and list the items (no rendering)."""
    cfg['_layers'] = True
    camera, _ = build(cfg)
    zone = free_zone(cfg)
    items = layer_items(cfg)
    meta_path = os.path.join(ROOT, key, 'meta.json')
    if os.path.isfile(meta_path):
        # Pixels --layers would render at the installed sizes (before any box grows), vs the full faces + detail.
        with open(meta_path) as fh:
            meta = json.load(fh)
        size = meta['faceSize']
        views = [(n, size if n not in ('u', 'd') else int(size * 2 / 3)) for n in FACES]
        if 'detail' in meta:
            views.append(('detail', meta['detail']['size']))
        bpy.context.scene.render.resolution_x = bpy.context.scene.render.resolution_y = size
        full = area = 0
        for n, px in views:
            full += px * px
            if n == 'detail':
                aim_detail(camera, *detail_frame(cfg, zone))
            else:
                aim_face(camera, n)
            bpy.context.view_layer.update()
            pad = max(8, px // 64)
            bs = []
            for it in items:
                rect = project_rect(camera, item_boxes(it['objs']))
                if rect:
                    b = rect_pixels(rect, px)
                    bs.append(b)
                    area += (min(px, b[2] + pad) - max(0, b[0] - pad)) * (min(px, b[3] + pad) - max(0, b[1] - pad))
            if bs:
                u = (min(b[0] for b in bs) - pad, min(b[1] for b in bs) - pad, max(b[2] for b in bs) + pad, max(b[3] for b in bs) + pad)
                area += (min(px, u[2]) - max(0, u[0])) * (min(px, u[3]) - max(0, u[1]))
        print(f'[{key}] --layers estimate: {area / 1e6:.1f} Mpx rendered vs {full / 1e6:.1f} Mpx for the full faces + detail '
              f'({100 * area / full:.0f}%)', flush=True)
    for it in items:
        lo, hi = world_bbox(it['objs'])
        print(f'[{key}] item {it["key"]:<14} {it["label"]:<28} default={it["default"]!s:<5} objects={len(it["objs"]):<4} '
              f'dist={it["dist"]:.2f} bbox=({lo.x:.2f},{lo.y:.2f},{lo.z:.2f})..({hi.x:.2f},{hi.y:.2f},{hi.z:.2f})', flush=True)
    meta_path = os.path.join(PUBLIC, key, 'meta.json')
    if os.path.isfile(meta_path):
        with open(meta_path) as fh:
            art = json.load(fh)['artWall']
        same = all(abs(art[k] - zone[k]) < 1e-3 for k in zone)
        print(f'[{key}] free zone {zone} {"matches" if same else "DIFFERS FROM"} meta.json', flush=True)


def write_index(order):
    """Every room folder with a meta.json (rendered rooms in their order, then the real photographed rooms)."""
    rooms = []
    for key in sorted(os.listdir(ROOT)):
        if key.startswith('photo-'):
            continue  # the photographed rooms were retired (furniture baked in, walls not paintable)
        meta_path = os.path.join(ROOT, key, 'meta.json')
        if os.path.isfile(meta_path):
            with open(meta_path) as fh:
                meta = json.load(fh)
            rooms.append({'key': key, 'title': meta['title'], 'kind': meta.get('kind', 'render')})
    menu = ['living', 'bedroom', 'kitchen', 'office', 'dining', 'lobby']
    rooms.sort(key=lambda r: (menu.index(r['key']) if r['key'] in menu else 99, r['title']))
    with open(os.path.join(ROOT, 'index.json'), 'w') as fh:
        json.dump(rooms, fh, indent=2)


ROOT = PUBLIC


def main():
    global ROOT
    o = args()
    if o.get('out'):
        ROOT = os.path.abspath(o['out'])
    configs = room_configs()
    keys = list(configs) if o['room'] == 'all' else o['room'].split(',')
    for key in keys:
        if o.get('check_items'):
            check_items(key, configs[key])
            continue
        if o.get('layers'):
            render_layers(key, configs[key], o)
            continue
        if o.get('zone_only'):
            # Re-measure where art can hang (no rendering) and update meta.json.
            build(configs[key])
            meta_path = os.path.join(ROOT, key, 'meta.json')
            with open(meta_path) as fh:
                meta = json.load(fh)
            meta['artWall'].update(free_zone(configs[key]))
            with open(meta_path, 'w') as fh:
                json.dump(meta, fh, indent=2)
            print(f'[{key}] zone {meta["artWall"]}', flush=True)
            continue
        if o['detail_only']:
            render_detail(key, configs[key], o['detail_size'], o['samples'])
            continue
        render_room(key, configs[key], o['size'], o['samples'], o['preview'])
        if not o['preview'] and o['detail_size'] > 0:
            render_detail(key, configs[key], o['detail_size'], o['samples'])
        if not o['preview']:
            # Updated after every room, so each one appears in the viewer as soon as it is ready.
            write_index(list(configs))


main()
