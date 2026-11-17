import * as THREE from 'three';
import type { PreviewFrame, PreviewMat } from '../preview-helpers';

/* Builders for the 3D room, ported 1:1 from the React Three Fiber components. All sizes in metres. */

export const M = (mm: number) => mm / 1000;
export const SOFA = { w: 2.0, h: 0.85, d: 0.9 };
export const ROOM_DEPTH = 4.2;
export const EYE = 1.55;

function box(w: number, h: number, d: number, material: THREE.Material, pos: [number, number, number], cast = true, receive = true): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
    mesh.position.set(...pos);
    mesh.castShadow = cast;
    mesh.receiveShadow = receive;
    return mesh;
}

export function rectShape(cx: number, cy: number, w: number, h: number): THREE.Shape {
    const s = new THREE.Shape();
    s.moveTo(cx - w / 2, cy - h / 2);
    s.lineTo(cx + w / 2, cy - h / 2);
    s.lineTo(cx + w / 2, cy + h / 2);
    s.lineTo(cx - w / 2, cy + h / 2);
    s.closePath();
    return s;
}

export function rectPath(cx: number, cy: number, w: number, h: number): THREE.Path {
    const p = new THREE.Path();
    p.moveTo(cx - w / 2, cy - h / 2);
    p.lineTo(cx - w / 2, cy + h / 2);
    p.lineTo(cx + w / 2, cy + h / 2);
    p.lineTo(cx + w / 2, cy - h / 2);
    p.closePath();
    return p;
}

/** Back wall, side walls, oak floor, ceiling and skirting board. */
export function buildRoom(wallW: number, wallH: number, wallColour: string, floorTexture: THREE.Texture): THREE.Group {
    const group = new THREE.Group();
    const wallMaterial = () => new THREE.MeshStandardMaterial({ color: wallColour, roughness: 0.95 });

    const back = new THREE.Mesh(new THREE.PlaneGeometry(wallW, wallH), wallMaterial());
    back.position.set(0, wallH / 2, 0);
    back.receiveShadow = true;
    group.add(back);

    for (const side of [-1, 1]) {
        const wall = new THREE.Mesh(new THREE.PlaneGeometry(ROOM_DEPTH, wallH), wallMaterial());
        wall.position.set((side * wallW) / 2, wallH / 2, ROOM_DEPTH / 2);
        wall.rotation.set(0, (-side * Math.PI) / 2, 0);
        wall.receiveShadow = true;
        group.add(wall);
    }

    floorTexture.repeat.set(wallW / 1.2, ROOM_DEPTH / 1.2);
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(wallW, ROOM_DEPTH), new THREE.MeshStandardMaterial({ map: floorTexture, roughness: 0.7 }));
    floor.rotation.set(-Math.PI / 2, 0, 0);
    floor.position.set(0, 0, ROOM_DEPTH / 2);
    floor.receiveShadow = true;
    group.add(floor);

    const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(wallW, ROOM_DEPTH), new THREE.MeshStandardMaterial({ color: '#f7f5f1', roughness: 1 }));
    ceiling.rotation.set(Math.PI / 2, 0, 0);
    ceiling.position.set(0, wallH, ROOM_DEPTH / 2);
    group.add(ceiling);

    group.add(box(wallW, 0.09, 0.016, new THREE.MeshStandardMaterial({ color: '#f4f1ec', roughness: 0.6 }), [0, 0.045, 0.008], false, true));
    return group;
}

/** A 2 m sofa for scale. */
export function buildSofa(): THREE.Group {
    const group = new THREE.Group();
    group.position.set(0, 0, 0.12 + SOFA.d / 2);
    const fabric = new THREE.MeshStandardMaterial({ color: '#5b6472', roughness: 1 });
    const legs = new THREE.MeshStandardMaterial({ color: '#3a2a1f', roughness: 0.5 });

    group.add(box(SOFA.w - 0.3, 0.2, SOFA.d - 0.25, fabric, [0, 0.3, 0.05])); // seat
    group.add(box(SOFA.w, 0.18, SOFA.d, fabric, [0, 0.16, 0])); // base
    group.add(box(SOFA.w, 0.55, 0.24, fabric, [0, 0.58, -SOFA.d / 2 + 0.12])); // back
    for (const s of [-1, 1]) group.add(box(0.15, 0.32, SOFA.d, fabric, [(s * (SOFA.w - 0.15)) / 2, 0.4, 0])); // arms
    for (const sx of [-1, 1])
        for (const sz of [-1, 1]) group.add(box(0.05, 0.07, 0.05, legs, [sx * (SOFA.w / 2 - 0.08), 0.035, sz * (SOFA.d / 2 - 0.08)], true, false));
    for (const x of [-0.45, 0.45]) {
        const cushion = box(
            0.42,
            0.38,
            0.12,
            new THREE.MeshStandardMaterial({ color: x < 0 ? '#c9a27e' : '#e8dccb', roughness: 1 }),
            [x, 0.62, -SOFA.d / 2 + 0.3],
            true,
            false,
        );
        cushion.rotation.set(-0.25, 0, 0);
        group.add(cushion);
    }
    return group;
}

export interface ArtDims {
    picW: number;
    picH: number;
    matB: number;
    matBottom: number;
    frameW: number;
    outerW: number;
    outerH: number;
}

/** The framed artwork: picture (or stretched canvas block), mat with bevel, frame, glass, back panel. */
export function buildArtwork(
    d: ArtDims,
    opts: {
        frame: PreviewFrame | null;
        mat: PreviewMat | null;
        glazing: 'none' | 'standard' | 'low';
        depthMm: number;
        edgeStyle: string | null;
        texture: THREE.Texture;
    },
): THREE.Group {
    const { frame, mat, glazing, depthMm, edgeStyle, texture } = opts;
    const group = new THREE.Group();
    const frameDepth = frame ? M(Math.max(15, frame.depth_mm ?? 25)) : 0;
    const canvasDepth = frame ? 0 : M(depthMm);
    // Front of the picture sits a little behind the front of the frame.
    const faceZ = frame ? frameDepth - 0.012 : canvasDepth;
    // Picture centre is shifted up when the mat has a weighted bottom.
    const picY = d.matBottom / 2;

    // ---- picture ----
    if (canvasDepth > 0) {
        const side =
            edgeStyle === 'solid_colour'
                ? new THREE.MeshStandardMaterial({ color: '#f1efe9', roughness: 0.9 })
                : new THREE.MeshStandardMaterial({ map: texture, roughness: 0.85, color: '#d9d9d9' });
        const materials = [
            side,
            side,
            side,
            side,
            new THREE.MeshStandardMaterial({ map: texture, roughness: 0.85 }),
            new THREE.MeshStandardMaterial({ color: '#cfc8bd' }),
        ];
        const block = new THREE.Mesh(new THREE.BoxGeometry(d.picW, d.picH, canvasDepth), materials);
        block.position.set(0, picY, canvasDepth / 2);
        block.castShadow = block.receiveShadow = true;
        group.add(block);
    } else {
        const picture = new THREE.Mesh(new THREE.PlaneGeometry(d.picW, d.picH), new THREE.MeshStandardMaterial({ map: texture, roughness: 0.8 }));
        picture.position.set(0, picY, faceZ);
        picture.castShadow = picture.receiveShadow = true;
        group.add(picture);
    }

    // ---- mat with a visible bevel ----
    if (mat) {
        const overlap = 0.003;
        const bevel = 0.0025;
        const w = d.picW + 2 * d.matB;
        const h = d.picH + 2 * d.matB + d.matBottom;
        const cy = d.matBottom / 2;
        const outer = rectShape(0, 0, w, h);
        outer.holes.push(rectPath(0, cy, d.picW - 2 * overlap + 2 * bevel, d.picH - 2 * overlap + 2 * bevel));
        const ring = rectShape(0, cy, d.picW - 2 * overlap + 2 * bevel, d.picH - 2 * overlap + 2 * bevel);
        ring.holes.push(rectPath(0, cy, d.picW - 2 * overlap, d.picH - 2 * overlap));

        const matGroup = new THREE.Group();
        matGroup.position.set(0, 0, faceZ + 0.002);
        // Thin board: no shadow casting (it caused striping on itself).
        const board = new THREE.Mesh(
            new THREE.ExtrudeGeometry(outer, { depth: 0.0015, bevelEnabled: false }),
            new THREE.MeshStandardMaterial({ color: mat.colour, roughness: 0.95 }),
        );
        board.receiveShadow = true;
        matGroup.add(board);
        const core = new THREE.Mesh(
            new THREE.ExtrudeGeometry(ring, { depth: 0.0015, bevelEnabled: false }),
            new THREE.MeshStandardMaterial({ color: mat.core_colour, roughness: 0.9 }),
        );
        core.position.set(0, 0, -0.0004);
        matGroup.add(core);
        group.add(matGroup);
    }

    // ---- frame ----
    if (frame) {
        const params: Record<string, { roughness: number; metalness: number }> = {
            matte: { roughness: 0.85, metalness: 0 },
            satin: { roughness: 0.5, metalness: 0.05 },
            gloss: { roughness: 0.18, metalness: 0.05 },
            metallic: { roughness: 0.32, metalness: 0.85 },
        };
        const p = params[frame.finish] ?? params['matte'];
        const material = new THREE.MeshStandardMaterial({ color: frame.colour, roughness: p.roughness, metalness: p.metalness });
        const innerH = d.outerH - 2 * d.frameW;
        const z = frameDepth / 2;
        group.add(box(d.outerW, d.frameW, frameDepth, material, [0, d.outerH / 2 - d.frameW / 2, z]));
        group.add(box(d.outerW, d.frameW, frameDepth, material, [0, -d.outerH / 2 + d.frameW / 2, z]));
        group.add(box(d.frameW, innerH, frameDepth, material, [-d.outerW / 2 + d.frameW / 2, 0, z]));
        group.add(box(d.frameW, innerH, frameDepth, material, [d.outerW / 2 - d.frameW / 2, 0, z]));
        // inner lip giving a sense of the rebate
        const lipShape = rectShape(0, 0, d.outerW - 2 * d.frameW + 0.008, innerH + 0.008);
        lipShape.holes.push(rectPath(0, 0, d.outerW - 2 * d.frameW, innerH));
        const lip = new THREE.Mesh(new THREE.ExtrudeGeometry(lipShape, { depth: 0.004, bevelEnabled: false }), material);
        lip.position.set(0, 0, frameDepth - 0.006);
        group.add(lip);

        // glass
        if (glazing !== 'none') {
            const glass = new THREE.Mesh(
                new THREE.PlaneGeometry(d.outerW - 2 * d.frameW, d.outerH - 2 * d.frameW),
                new THREE.MeshPhysicalMaterial({
                    transparent: true,
                    opacity: glazing === 'low' ? 0.04 : 0.12,
                    roughness: 0.02,
                    metalness: 0,
                    reflectivity: glazing === 'low' ? 0.1 : 0.9,
                    envMapIntensity: glazing === 'low' ? 0.2 : 1.6,
                    color: '#ffffff',
                }),
            );
            glass.position.set(0, 0, faceZ + 0.004);
            group.add(glass);
        }

        // back panel so nothing looks hollow from the side
        const back = new THREE.Mesh(
            new THREE.PlaneGeometry(d.outerW - 0.004, d.outerH - 0.004),
            new THREE.MeshStandardMaterial({ color: '#3d3a36' }),
        );
        back.position.set(0, 0, 0.002);
        group.add(back);
    }

    return group;
}

/** Horizontal brass picture lamp on a short arm, above the frame. */
export function buildLamp(outerW: number): THREE.Group {
    const group = new THREE.Group();
    const brass = new THREE.MeshStandardMaterial({ color: '#b08d57', metalness: 0.85, roughness: 0.3 });
    const bar = new THREE.Mesh(new THREE.CylinderGeometry(0.022, 0.022, Math.min(0.6, Math.max(0.25, outerW * 0.55)), 24), brass);
    bar.position.set(0, 0, 0.12);
    bar.rotation.set(0, 0, Math.PI / 2);
    bar.castShadow = true;
    group.add(bar);
    const arm = new THREE.Mesh(new THREE.CylinderGeometry(0.008, 0.008, 0.12, 12), brass);
    arm.position.set(0, 0.03, 0.06);
    arm.rotation.set(Math.PI / 2, 0, 0);
    arm.castShadow = true;
    group.add(arm);
    return group;
}

/** Local reflections for glass and metal (port of drei <Environment> with three <Lightformer>s), no network needed. */
export function buildEnvironment(renderer: THREE.WebGLRenderer): THREE.Texture {
    const envScene = new THREE.Scene();
    const former = (intensity: number, color: string, pos: [number, number, number], scale: [number, number], rotY = 0) => {
        const material = new THREE.MeshBasicMaterial({
            color: new THREE.Color(color).multiplyScalar(intensity),
            side: THREE.DoubleSide,
            toneMapped: false,
        });
        const mesh = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), material);
        mesh.position.set(...pos);
        mesh.scale.set(scale[0], scale[1], 1);
        mesh.rotation.y = rotY;
        mesh.lookAt(0, pos[1], 0);
        envScene.add(mesh);
    };
    former(2, '#fff8ef', [0, 2.5, 4], [6, 2]);
    former(1, '#ffffff', [-4, 1.5, 2], [4, 2], Math.PI / 2);
    former(0.6, '#e6eefc', [4, 1.5, 2], [4, 2], -Math.PI / 2);
    const pmrem = new THREE.PMREMGenerator(renderer);
    const texture = pmrem.fromScene(envScene, 0.04).texture;
    pmrem.dispose();
    return texture;
}

/** Colourful placeholder used until an image is chosen. */
export function gradientTexture(): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 256;
    const g = c.getContext('2d')!;
    const grad = g.createLinearGradient(0, 0, 256, 256);
    grad.addColorStop(0, '#f6d365');
    grad.addColorStop(0.35, '#fda085');
    grad.addColorStop(0.7, '#a1c4fd');
    grad.addColorStop(1, '#5b7db1');
    g.fillStyle = grad;
    g.fillRect(0, 0, 256, 256);
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

/** Simple procedural oak planks for the floor. */
export function woodTexture(): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = 512;
    c.height = 512;
    const g = c.getContext('2d')!;
    const tones = ['#a98463', '#9c7757', '#b38d6b', '#a17c5c'];
    const plankH = 64;
    for (let row = 0; row < 512 / plankH; row++) {
        let x = -((row * 137) % 256);
        while (x < 512) {
            const len = 180 + ((row * 53 + x) % 160);
            g.fillStyle = tones[(row + Math.floor(x / 97)) % tones.length];
            g.fillRect(x, row * plankH, len, plankH);
            g.strokeStyle = 'rgba(60,40,25,0.12)';
            for (let i = 0; i < 5; i++) {
                g.beginPath();
                const y = row * plankH + 8 + i * 11;
                g.moveTo(x, y);
                g.bezierCurveTo(x + len / 3, y + 4, x + (2 * len) / 3, y - 4, x + len, y);
                g.stroke();
            }
            g.fillStyle = 'rgba(40,25,15,0.35)';
            g.fillRect(x, row * plankH, 2, plankH);
            x += len;
        }
        g.fillStyle = 'rgba(40,25,15,0.35)';
        g.fillRect(0, row * plankH, 512, 2);
    }
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = 8;
    return t;
}

/** "Cover" the picture area like CSS background-size: cover. */
export function coverTexture(source: THREE.Texture, picW: number, picH: number): THREE.Texture {
    const t = source.clone();
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = 4;
    const img = source.image as { width: number; height: number } | undefined;
    if (img?.width && img.height) {
        const imageAspect = img.width / img.height;
        const areaAspect = picW / picH;
        if (imageAspect > areaAspect) {
            t.repeat.set(areaAspect / imageAspect, 1);
            t.offset.set((1 - t.repeat.x) / 2, 0);
        } else {
            t.repeat.set(1, imageAspect / areaAspect);
            t.offset.set(0, (1 - t.repeat.y) / 2);
        }
    }
    t.needsUpdate = true;
    return t;
}

/** Free GPU memory of everything under an object. */
export function disposeTree(object: THREE.Object3D): void {
    object.traverse((child) => {
        const mesh = child as THREE.Mesh;
        mesh.geometry?.dispose();
        const material = mesh.material as THREE.Material | THREE.Material[] | undefined;
        (Array.isArray(material) ? material : material ? [material] : []).forEach((m) => m.dispose());
    });
}
