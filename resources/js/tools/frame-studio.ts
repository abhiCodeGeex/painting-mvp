/*
 * Frame photo studio (local development tool, /dev/frame-studio).
 *
 * Renders a product-style "corner sample" photo of every frame with the same 3D mouldings the
 * customer sees in the room preview: studio HDRI lighting, a soft shadow on a backdrop wall and a
 * piece of artwork in the opening. Each photo is posted back and stored on the frame (and as a
 * seed asset, so fresh installs get the same pictures).
 */
import * as THREE from 'three';
import { HDRLoader } from 'three/examples/jsm/loaders/HDRLoader.js';
import type { PreviewFrame } from '../components/enquiry/preview-helpers';
import { buildMoulding } from '../components/enquiry/three/moulding';

interface StudioFrame extends PreviewFrame {
    id: number;
    code: string;
    name: string;
    upload_url: string;
}

const SIZE = 1200;
const OPENING = 0.3; // 30 × 30 cm picture opening

const data = JSON.parse(document.getElementById('studio-data')!.textContent!) as { frames: StudioFrame[]; csrf: string };
const log = document.getElementById('log')!;
const gallery = document.getElementById('gallery')!;
const say = (text: string) => (log.textContent += text + '\n');

const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
renderer.setPixelRatio(1);
renderer.setSize(SIZE, SIZE);
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 0.85;
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;

/** Soft abstract painting for the opening (so the frame is shown "in use"). */
function artworkTexture(): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = c.height = 1024;
    const g = c.getContext('2d')!;
    const bg = g.createLinearGradient(0, 0, 1024, 1024);
    bg.addColorStop(0, '#d9824f');
    bg.addColorStop(0.5, '#c45c3c');
    bg.addColorStop(1, '#2f4f7f');
    g.fillStyle = bg;
    g.fillRect(0, 0, 1024, 1024);
    const blobs: [number, number, number, string][] = [
        [180, 200, 220, 'rgba(214,110,80,0.55)'],
        [430, 380, 260, 'rgba(70,96,140,0.5)'],
        [700, 250, 200, 'rgba(255,236,200,0.6)'],
        [260, 640, 240, 'rgba(232,170,110,0.55)'],
        [760, 760, 300, 'rgba(60,84,128,0.45)'],
    ];
    for (const [x, y, r, col] of blobs) {
        const grd = g.createRadialGradient(x, y, 0, x, y, r);
        grd.addColorStop(0, col);
        grd.addColorStop(1, 'rgba(255,255,255,0)');
        g.fillStyle = grd;
        g.beginPath();
        g.arc(x, y, r, 0, Math.PI * 2);
        g.fill();
    }
    // fine canvas weave
    g.globalAlpha = 0.018;
    for (let i = 0; i < 1024; i += 3) {
        g.fillStyle = i % 6 ? '#000' : '#fff';
        g.fillRect(i, 0, 1, 1024);
        g.fillRect(0, i, 1024, 1);
    }
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = 16;
    return t;
}

async function main(): Promise<void> {
    const hdr = await new HDRLoader().loadAsync('/3d/hdri/lebombo_1k.hdr');
    hdr.mapping = THREE.EquirectangularReflectionMapping;
    const pmrem = new THREE.PMREMGenerator(renderer);
    const environment = pmrem.fromEquirectangular(hdr).texture;
    const art = artworkTexture();

    for (const frame of data.frames) {
        const scene = new THREE.Scene();
        scene.background = new THREE.Color('#ebe7e1');
        scene.environment = environment;
        scene.environmentIntensity = 0.55;

        const faceW = Math.max(0.005, frame.width_mm / 1000);
        const depth = Math.max(0.01, (frame.depth_mm ?? 30) / 1000);
        const floater = frame.profile === 'floater';

        // Backdrop wall the frame hangs on (catches the shadow).
        const wall = new THREE.Mesh(new THREE.PlaneGeometry(4, 4), new THREE.MeshStandardMaterial({ color: '#ece8e2', roughness: 0.95 }));
        wall.receiveShadow = true;
        scene.add(wall);

        const group = new THREE.Group();
        group.position.z = 0.002;
        const moulding = buildMoulding(OPENING, OPENING, faceW, depth, frame);
        group.add(moulding);

        // Artwork: canvas sits deep inside a floater (with its own white edge), prints sit in the rebate.
        const artDepth = floater ? depth * 0.85 : 0.004;
        const picture = new THREE.Mesh(new THREE.BoxGeometry(floater ? OPENING - 0.012 : OPENING, floater ? OPENING - 0.012 : OPENING, artDepth), [
            ...Array.from({ length: 4 }, () => new THREE.MeshStandardMaterial({ color: '#f1ede6', roughness: 0.9 })),
            new THREE.MeshStandardMaterial({ map: art, roughness: 0.95, envMapIntensity: 0.35 }),
            new THREE.MeshStandardMaterial({ color: '#d8d2c8' }),
        ]);
        picture.position.z = floater ? depth * 0.12 + artDepth / 2 : Math.min(depth * 0.55, depth - 0.006) - artDepth / 2;
        picture.castShadow = picture.receiveShadow = true;
        group.add(picture);
        scene.add(group);

        // Key light from the top left, soft fill from the HDRI.
        const key = new THREE.DirectionalLight('#fff4e6', 1.7);
        key.position.set(-1.2, 1.6, 1.4);
        key.castShadow = true;
        key.shadow.mapSize.set(4096, 4096);
        key.shadow.radius = 6;
        key.shadow.bias = -0.0002;
        Object.assign(key.shadow.camera, { left: -0.5, right: 0.5, top: 0.5, bottom: -0.5, near: 0.1, far: 5 });
        scene.add(key);
        scene.add(new THREE.HemisphereLight('#ffffff', '#cbbfae', 0.35));

        // Three-quarter view of the top-left corner: face, mitre and side depth all visible.
        const corner = new THREE.Vector3(-OPENING / 2 - faceW, OPENING / 2 + faceW, depth);
        const camera = new THREE.PerspectiveCamera(28, 1, 0.01, 10);
        const reach = Math.max(0.34, faceW * 6 + 0.26);
        camera.position.copy(corner).add(new THREE.Vector3(reach * 0.62, -reach * 0.5, reach * 1.55));
        camera.lookAt(corner.clone().add(new THREE.Vector3(reach * 0.2, -reach * 0.2, -depth * 0.5)));

        renderer.render(scene, camera);
        const dataUrl = renderer.domElement.toDataURL('image/jpeg', 0.92);

        const img = document.createElement('img');
        img.src = dataUrl;
        img.title = frame.name;
        gallery.appendChild(img);

        const response = await fetch(frame.upload_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data.csrf, Accept: 'application/json' },
            body: JSON.stringify({ image: dataUrl }),
        });
        say(`${frame.code} ${frame.name}: ${response.ok ? 'saved' : 'FAILED ' + response.status}`);

        moulding.geometry.dispose();
        scene.traverse((o) => {
            if (o instanceof THREE.Mesh && o !== moulding) o.geometry.dispose();
        });
    }
    say('done');
    document.body.dataset['done'] = '1';
}

main().catch((error) => {
    say('error: ' + String(error));
    document.body.dataset['done'] = 'error';
});
