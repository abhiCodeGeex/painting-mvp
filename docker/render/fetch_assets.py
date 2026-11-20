"""
Downloads the CC0 Poly Haven sources used by rooms.py into docker/render/assets/ (git-ignored).

    docker compose --profile render run --rm --entrypoint python3 render docker/render/fetch_assets.py --dry-run
    docker compose --profile render run --rm --entrypoint python3 render docker/render/fetch_assets.py

Models are fetched as glTF (1k textures): the compressed GLBs in public/3d can't be read by Blender.
"""
import json
import os
import sys
import urllib.request

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'assets')
API = 'https://api.polyhaven.com/files/'
UA = {'User-Agent': 'paint-mvp-room-renderer/1.0'}

MODELS = [
    # living
    'sofa_03', 'modern_coffee_table_01', 'side_table_01', 'potted_plant_01', 'throw_pillows_01',
    'mid_century_lounge_chair', 'ceramic_vase_02', 'modern_ceiling_lamp_01',
    # bedroom
    'ClassicNightstand_01', 'ceramic_vase_01', 'potted_plant_04',
    # office
    'metal_office_desk', 'modern_arm_chair_01', 'desk_lamp_arm_01', 'potted_plant_02', 'book_encyclopedia_set_01',
    # dining
    'dining_table', 'dining_chair_02', 'ceramic_vase_03', 'modern_wooden_cabinet',
    # lobby
    'ClassicConsole_01', 'ArmChair_01', 'coffee_table_round_01', 'brass_vase_01',
]
# texture id -> resolution
TEXTURES = {
    'wood_floor': '2k', 'herringbone_parquet': '2k', 'rectangular_parquet': '2k', 'marble_01': '2k',
    'wool_boucle': '1k', 'rough_linen': '1k',
}
MAPS = ['Diffuse', 'nor_gl', 'Rough']
HDRIS = {'green_point_park': '2k'}


def get_json(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
        return json.load(r)


def plan():
    """(url, local path, size) for every file."""
    files = []
    for mid in MODELS:
        data = get_json(API + mid)
        if 'gltf' not in data:
            print('no glTF for', mid, '- formats:', ', '.join(data))
            continue
        g = data['gltf']['1k']['gltf']
        base = os.path.join(ROOT, 'models', mid)
        files.append((g['url'], os.path.join(base, os.path.basename(g['url'])), g['size']))
        for rel, inc in g.get('include', {}).items():
            files.append((inc['url'], os.path.join(base, rel), inc['size']))
    for tid, res in TEXTURES.items():
        data = get_json(API + tid)
        for m in MAPS:
            f = data[m][res]['jpg']
            files.append((f['url'], os.path.join(ROOT, 'textures', tid, f'{m}.jpg'), f['size']))
    for hid, res in HDRIS.items():
        f = get_json(API + hid)['hdri'][res]['hdr']
        files.append((f['url'], os.path.join(ROOT, 'hdri', f'{hid}.hdr'), f['size']))
    return files


def main():
    files = plan()
    total = sum(s for _, _, s in files)
    todo = [f for f in files if not (os.path.exists(f[1]) and os.path.getsize(f[1]) == f[2])]
    print(f'{len(files)} files, {total / 1e6:.1f} MB total, {sum(s for *_, s in todo) / 1e6:.1f} MB to download')
    if '--dry-run' in sys.argv:
        return
    for url, path, size in todo:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=300) as r, open(path, 'wb') as out:
            out.write(r.read())
        print('ok', os.path.relpath(path, ROOT), f'{size / 1e6:.1f} MB')


if __name__ == '__main__':
    main()
