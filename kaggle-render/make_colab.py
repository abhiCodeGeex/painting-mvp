"""Writes render-rooms-colab.ipynb: renders the six 360-degree rooms on Google Colab's free T4 GPU.

Reads paint-rooms.zip from Google Drive (My Drive/paint-render/), saves every finished room straight back to
Drive (My Drive/paint-render/rooms-hd/<room>), and skips rooms already there, so a disconnect never loses work.
"""
import json
import os

cells = [
    ("markdown", "# Paint MVP: render the 360° rooms in HD (Google Colab)\n"
                 "1. **Runtime → Change runtime type → T4 GPU → Save**\n"
                 "2. **Runtime → Run all**, and allow Google Drive access when asked.\n"
                 "3. Keep this tab open. Each finished room is saved to **My Drive/paint-render/rooms-hd/** right away.\n"
                 "4. At the end, download **My Drive/paint-render/rooms-hd.zip**."),
    ("code", r'''# 1. Google Drive: the uploaded paint-rooms.zip in My Drive/paint-render/
from google.colab import drive
import os, subprocess
drive.mount('/content/drive')
BASE = '/content/drive/MyDrive/paint-render'
ZIP = f'{BASE}/paint-rooms.zip'
assert os.path.exists(ZIP), 'Upload paint-rooms.zip to a folder called paint-render in My Drive first'
OUT = f'{BASE}/rooms-hd'
os.makedirs(OUT, exist_ok=True)
!nvidia-smi --query-gpu=name,memory.total --format=csv
print('found', ZIP)'''),
    ("code", r'''# 2. Tools (Blender 4.2 + WebP) and the project files, about 2-3 minutes
subprocess.run('apt-get -qq update > /dev/null; apt-get -qq install -y webp libxi6 libxkbcommon0 libxxf86vm1 libsm6 libxrender1 libxfixes3 > /dev/null', shell=True)
if not os.path.exists('/content/blender/blender'):
    subprocess.run('curl -fsSL https://download.blender.org/release/Blender4.2/blender-4.2.23-linux-x64.tar.xz -o /tmp/b.tar.xz '
                   '&& mkdir -p /content/blender && tar -xJf /tmp/b.tar.xz -C /content/blender --strip-components=1 && rm /tmp/b.tar.xz',
                   shell=True, check=True)
subprocess.run(f'rm -rf /content/proj && mkdir -p /content/proj && cd /content/proj && unzip -q "{ZIP}"', shell=True, check=True)
print(subprocess.run(['/content/blender/blender', '--version'], capture_output=True, text=True).stdout.splitlines()[0])
print('project ready:', os.path.exists('/content/proj/docker/render/rooms.py'))'''),
    ("code", r'''# 3. Render the rooms one by one (faces 2048 px, art-wall detail 4096 px). Finished rooms are saved to Drive.
import shutil, time
ROOMS = ['living', 'bedroom', 'kitchen', 'office', 'dining', 'lobby']
PUBLIC = '/content/proj/public/3d/rooms'
t0 = time.time()
for room in ROOMS:
    if os.path.exists(f'{OUT}/{room}/meta.json') and os.path.exists(f'{OUT}/{room}/f.webp'):
        print(f'{room}: already done, skipped'); continue
    print(f'{room}: rendering...', flush=True)
    log = open(f'/content/{room}.log', 'w')
    p = subprocess.Popen(['/content/blender/blender', '--background', '--factory-startup', '--python', '/content/proj/docker/render/rooms.py', '--',
                          '--room', room, '--size', '2048', '--samples', '128', '--detail-size', '4096', '--device', 'GPU'],
                         stdout=log, stderr=subprocess.STDOUT, cwd='/content/proj')
    while p.poll() is None:
        time.sleep(30)
        lines = [l.strip() for l in open(f'/content/{room}.log') if l.startswith('[') or 'rendering on' in l]
        print(f'  {(time.time() - t0) / 60:5.1f} min  {lines[-1] if lines else "starting"}', flush=True)
    if p.returncode != 0 or not os.path.exists(f'{PUBLIC}/{room}/f.webp'):
        print(f'{room}: FAILED (code {p.returncode}). Last lines:'); print(''.join(open(f'/content/{room}.log').readlines()[-15:]))
        break
    shutil.rmtree(f'{OUT}/{room}', ignore_errors=True)
    shutil.copytree(f'{PUBLIC}/{room}', f'{OUT}/{room}')
    print(f'{room}: done and saved to Drive ({(time.time() - t0) / 60:.1f} min so far)', flush=True)
print('finished rooms in Drive:', sorted(r for r in ROOMS if os.path.exists(f'{OUT}/{r}/f.webp')))'''),
    ("code", r'''# 4. Pack everything into My Drive/paint-render/rooms-hd.zip (download that file)
shutil.make_archive(f'{BASE}/rooms-hd', 'zip', OUT)
print(os.path.getsize(f'{BASE}/rooms-hd.zip') // 2**20, 'MB  ->  My Drive/paint-render/rooms-hd.zip')'''),
]

nb = {
    "nbformat": 4, "nbformat_minor": 5,
    "metadata": {
        "kernelspec": {"name": "python3", "display_name": "Python 3", "language": "python"},
        "accelerator": "GPU",
        "colab": {"provenance": [], "gpuType": "T4"},
    },
    "cells": [
        ({"cell_type": "markdown", "metadata": {}, "source": src} if kind == "markdown"
         else {"cell_type": "code", "metadata": {}, "execution_count": None, "outputs": [], "source": src})
        for kind, src in cells
    ],
}
here = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(here, 'render-rooms-colab.ipynb'), 'w', encoding='utf-8') as fh:
    json.dump(nb, fh, indent=1)
print('wrote render-rooms-colab.ipynb')
