"""Writes render-rooms.ipynb: renders the six 360-degree rooms on Kaggle's free 2x T4 GPUs."""
import json
import os

cells = [
    ("markdown", "# Paint MVP: render the 360° rooms in HD\nRun all cells (Run All). Needs **Accelerator: GPU T4 x2** and **Internet on** in the right-hand Settings panel. Takes about 30-45 minutes. At the end, download **rooms-hd.zip** from the Output panel."),
    ("code", r'''# 1. Tools: Blender 4.2 + WebP encoder
import subprocess, os
subprocess.run('apt-get -qq update && apt-get -qq install -y webp libxi6 libxkbcommon0 libxxf86vm1 libsm6 libxrender1 libxfixes3 > /dev/null', shell=True, check=True)
if not os.path.exists('/kaggle/working/blender/blender'):
    subprocess.run('curl -fsSL https://download.blender.org/release/Blender4.2/blender-4.2.23-linux-x64.tar.xz -o /tmp/b.tar.xz '
                   '&& mkdir -p /kaggle/working/blender && tar -xJf /tmp/b.tar.xz -C /kaggle/working/blender --strip-components=1 && rm /tmp/b.tar.xz',
                   shell=True, check=True)
print(subprocess.run(['/kaggle/working/blender/blender', '--version'], capture_output=True, text=True).stdout.splitlines()[0])
!nvidia-smi --query-gpu=name,memory.total --format=csv'''),
    ("code", r'''# 2. Copy the uploaded project files (the "paint-rooms" dataset) somewhere writable
import glob, shutil
src = glob.glob('/kaggle/input/**/docker/render/rooms.py', recursive=True)
assert src, 'Add the paint-rooms dataset to this notebook first (right panel: Add Input)'
root = src[0].rsplit('/docker/render/rooms.py', 1)[0]
shutil.rmtree('/kaggle/working/proj', ignore_errors=True)
shutil.copytree(root, '/kaggle/working/proj')
print('project at /kaggle/working/proj from', root)'''),
    ("code", r'''# 3. Render: three rooms per GPU, both GPUs at once (faces 2048 px, art-wall detail 4096 px)
import subprocess, time
os.chdir('/kaggle/working/proj')
jobs = {0: 'living,bedroom,kitchen', 1: 'office,dining,lobby'}
procs = []
t0 = time.time()
for gpu, rooms in jobs.items():
    env = dict(os.environ, CUDA_VISIBLE_DEVICES=str(gpu))
    log = open(f'/kaggle/working/gpu{gpu}.log', 'w')
    cmd = ['/kaggle/working/blender/blender', '--background', '--factory-startup', '--python', '/kaggle/working/proj/docker/render/rooms.py', '--',
           '--room', rooms, '--size', '2048', '--samples', '128', '--detail-size', '4096', '--device', 'GPU']
    procs.append(subprocess.Popen(cmd, env=env, stdout=log, stderr=subprocess.STDOUT, cwd='/kaggle/working/proj'))
while any(p.poll() is None for p in procs):
    time.sleep(60)
    for gpu in jobs:
        lines = [l for l in open(f'/kaggle/working/gpu{gpu}.log') if l.startswith('[') or 'rendering on' in l]
        print(f'{(time.time() - t0) / 60:5.1f} min  GPU{gpu}: {lines[-1].strip() if lines else "starting"}')
print('exit codes', [p.returncode for p in procs])
for gpu in jobs:
    print(f'--- GPU{gpu} last lines'); print(''.join(open(f'/kaggle/working/gpu{gpu}.log').readlines()[-5:]))'''),
    ("code", r'''# 4. Pack the result: download rooms-hd.zip from the Output panel
import shutil
shutil.make_archive('/kaggle/working/rooms-hd', 'zip', '/kaggle/working/proj/public/3d/rooms')
print(os.path.getsize('/kaggle/working/rooms-hd.zip') // 2**20, 'MB  ->  /kaggle/working/rooms-hd.zip')'''),
]

nb = {
    "nbformat": 4, "nbformat_minor": 5,
    "metadata": {"kernelspec": {"name": "python3", "display_name": "Python 3", "language": "python"}},
    "cells": [
        ({"cell_type": "markdown", "metadata": {}, "source": src} if kind == "markdown"
         else {"cell_type": "code", "metadata": {}, "execution_count": None, "outputs": [], "source": src})
        for kind, src in cells
    ],
}
here = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(here, 'render-rooms.ipynb'), 'w', encoding='utf-8') as fh:
    json.dump(nb, fh, indent=1)
print('wrote render-rooms.ipynb')
