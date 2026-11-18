import * as THREE from 'three';
import type { PreviewFrame, PreviewMat } from '../preview-helpers';
import { buildMoulding } from './moulding';

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

/**
 * Back wall, side walls, floor, ceiling and skirting board. The floor uses the real laminate
 * texture set when it has loaded, otherwise the procedural oak planks.
 */
export function buildRoom(
    wallW: number,
    wallH: number,
    wallColour: string,
    floorTexture: THREE.Texture,
    realFloor?: { map: THREE.Texture; normalMap: THREE.Texture; roughnessMap: THREE.Texture; tile: number },
    sky?: THREE.Texture,
): THREE.Group {
    const group = new THREE.Group();
    // Named "wall" so a colour change can repaint it in place.
    const wallMaterial = () => new THREE.MeshStandardMaterial({ name: 'wall', color: wallColour, roughness: 0.95 });

    const back = new THREE.Mesh(new THREE.PlaneGeometry(wallW, wallH), wallMaterial());
    back.position.set(0, wallH / 2, 0);
    back.receiveShadow = true;
    group.add(back);

    for (const side of [-1, 1]) {
        // The right wall has a window opening; it casts shadows so sunlight only enters through the window.
        const geometry = side === 1 ? wallWithWindow(wallH) : new THREE.PlaneGeometry(ROOM_DEPTH, wallH);
        const wall = new THREE.Mesh(geometry, wallMaterial());
        wall.position.set((side * wallW) / 2, wallH / 2, ROOM_DEPTH / 2);
        wall.rotation.set(0, (-side * Math.PI) / 2, 0);
        wall.receiveShadow = true;
        wall.castShadow = side === 1;
        group.add(wall);
    }
    group.add(buildWindow(wallW, sky));

    let floorMaterial: THREE.MeshStandardMaterial;
    if (realFloor) {
        for (const t of [realFloor.map, realFloor.normalMap, realFloor.roughnessMap])
            t.repeat.set(wallW / realFloor.tile, ROOM_DEPTH / realFloor.tile);
        floorMaterial = new THREE.MeshStandardMaterial({
            map: realFloor.map,
            normalMap: realFloor.normalMap,
            roughnessMap: realFloor.roughnessMap,
            roughness: 1,
        });
    } else {
        floorTexture.repeat.set(wallW / 1.2, ROOM_DEPTH / 1.2);
        floorMaterial = new THREE.MeshStandardMaterial({ map: floorTexture, roughness: 0.7 });
    }
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(wallW, ROOM_DEPTH), floorMaterial);
    floor.rotation.set(-Math.PI / 2, 0, 0);
    floor.position.set(0, 0, ROOM_DEPTH / 2);
    floor.receiveShadow = true;
    group.add(floor);

    const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(wallW, ROOM_DEPTH), new THREE.MeshStandardMaterial({ color: '#f7f5f1', roughness: 1 }));
    ceiling.rotation.set(Math.PI / 2, 0, 0);
    ceiling.position.set(0, wallH, ROOM_DEPTH / 2);
    ceiling.castShadow = true; // keeps the sun out except through the window
    group.add(ceiling);

    group.add(box(wallW, 0.09, 0.016, new THREE.MeshStandardMaterial({ color: '#f4f1ec', roughness: 0.6 }), [0, 0.045, 0.008], false, true));
    return group;
}

/** Window in the right-hand wall (metres): centre along the room depth, size and sill height. */
export const WINDOW = { z: 2.35, width: 1.3, height: 1.45, sill: 0.85 };

/** Right wall as a plane with the window cut out (wall-local coordinates, centred). */
function wallWithWindow(wallH: number): THREE.ShapeGeometry {
    const shape = rectShape(0, 0, ROOM_DEPTH, wallH);
    // Wall-local x runs along +z in the room; y is height from the wall centre.
    shape.holes.push(rectPath(WINDOW.z - ROOM_DEPTH / 2, WINDOW.sill + WINDOW.height / 2 - wallH / 2, WINDOW.width, WINDOW.height));
    return new THREE.ShapeGeometry(shape);
}

/** Window frame, glazing bars, sill and the sky outside. */
function buildWindow(wallW: number, sky?: THREE.Texture): THREE.Group {
    const group = new THREE.Group();
    const x = wallW / 2;
    const { z, width: w, height: h, sill } = WINDOW;
    const cy = sill + h / 2;
    const white = new THREE.MeshStandardMaterial({ color: '#f4f2ee', roughness: 0.5 });
    const t = 0.05; // frame thickness
    const d = 0.12; // reveal depth
    group.add(box(d, t, w + 2 * t, white, [x + d / 2 - 0.02, sill + h + t / 2, z]));
    group.add(box(d, t, w + 2 * t, white, [x + d / 2 - 0.02, sill - t / 2, z]));
    group.add(box(d, h, t, white, [x + d / 2 - 0.02, cy, z - w / 2 - t / 2]));
    group.add(box(d, h, t, white, [x + d / 2 - 0.02, cy, z + w / 2 + t / 2]));
    // glazing bars
    group.add(box(0.03, 0.03, w, white, [x + 0.06, cy + h * 0.12, z], false, false));
    group.add(box(0.03, h, 0.03, white, [x + 0.06, cy, z], false, false));
    // sill inside the room
    group.add(box(0.2, 0.03, w + 0.2, white, [x - 0.08, sill - 0.015, z]));

    // Sky seen through the window (does not block the sun).
    const skyPlane = new THREE.Mesh(
        new THREE.PlaneGeometry(6, 4),
        new THREE.MeshBasicMaterial({ map: sky ?? null, color: sky ? '#ffffff' : '#cfe3f5', toneMapped: false }),
    );
    skyPlane.position.set(x + 1.2, cy + 0.4, z);
    skyPlane.rotation.y = -Math.PI / 2;
    skyPlane.castShadow = false;
    skyPlane.name = 'sky';
    group.add(skyPlane);
    return group;
}

/** Gradient sky for the window, per time of day. */
export function skyTexture(top: string, bottom: string, glow?: string): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = 16;
    c.height = 256;
    const g = c.getContext('2d')!;
    const grad = g.createLinearGradient(0, 0, 0, 256);
    grad.addColorStop(0, top);
    grad.addColorStop(glow ? 0.7 : 1, glow ?? bottom);
    if (glow) grad.addColorStop(1, bottom);
    g.fillStyle = grad;
    g.fillRect(0, 0, 16, 256);
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
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
/**
 * Artwork surface material with a crisper image: the mipmap level is biased one notch finer
 * (texture LOD bias -0.6), so a large image shown small stays sharp instead of soft. Anisotropic
 * filtering keeps it free of shimmer. One shared shader variant (stable program cache key).
 */
function artworkMaterial(params: THREE.MeshStandardMaterialParameters): THREE.MeshStandardMaterial {
    const material = new THREE.MeshStandardMaterial(params);
    material.onBeforeCompile = (shader) => {
        shader.fragmentShader = shader.fragmentShader.replace(
            '#include <map_fragment>',
            THREE.ShaderChunk.map_fragment.replace('texture2D( map, vMapUv )', 'texture2D( map, vMapUv, -0.6 )'),
        );
    };
    material.customProgramCacheKey = () => 'artwork-crisp';
    return material;
}

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
    const floater = frame?.profile === 'floater';
    // A floater holds a stretched canvas: its front sits just below the frame's front edge.
    const canvasDepth = frame && !floater ? 0 : floater ? Math.min(M(depthMm) || 0.02, frameDepth - 0.006) : M(depthMm);
    // Front of the picture sits a little behind the front of the frame.
    const faceZ = floater ? frameDepth - 0.004 : frame ? frameDepth - 0.012 : canvasDepth;
    // Picture centre is shifted up when the mat has a weighted bottom.
    const picY = d.matBottom / 2;

    // ---- soft shadow on the wall ----
    // The piece stands off the wall by its depth; light from above throws a soft shadow mostly
    // below it. A pre-blurred texture: no per-frame cost, no noise, no aliasing.
    const standOff = Math.max(frameDepth, canvasDepth, 0.004);
    const spread = 0.02 + standOff * 1.6;
    const shadow = new THREE.Mesh(
        new THREE.PlaneGeometry(d.outerW + 2 * spread, d.outerH + 2 * spread),
        new THREE.MeshBasicMaterial({
            map: dropShadowTexture(d.outerW, d.outerH, spread),
            transparent: true,
            opacity: Math.min(0.6, 0.32 + standOff * 6),
            depthWrite: false,
            polygonOffset: true,
            polygonOffsetFactor: -2,
            toneMapped: false,
        }),
    );
    // Light comes from above: the shadow falls a little lower than the piece.
    shadow.position.set(0, -standOff * 0.7, 0.0005);
    shadow.renderOrder = -1;
    shadow.name = 'wall-shadow';
    group.add(shadow);

    // ---- picture ----
    if (canvasDepth > 0) {
        const side =
            edgeStyle === 'solid_colour'
                ? new THREE.MeshStandardMaterial({ color: '#f1efe9', roughness: 0.9 })
                : artworkMaterial({ map: texture, roughness: 0.85, color: '#d9d9d9' });
        const materials = [
            side,
            side,
            side,
            side,
            artworkMaterial({ map: texture, roughness: 0.85 }),
            new THREE.MeshStandardMaterial({ color: '#cfc8bd' }),
        ];
        const block = new THREE.Mesh(new THREE.BoxGeometry(d.picW, d.picH, canvasDepth), materials);
        block.position.set(0, picY, faceZ - canvasDepth / 2);
        block.castShadow = true;
        block.receiveShadow = false;
        group.add(block);
    } else {
        const picture = new THREE.Mesh(new THREE.PlaneGeometry(d.picW, d.picH), artworkMaterial({ map: texture, roughness: 0.8 }));
        picture.position.set(0, picY, faceZ);
        // No shadow-map on the print itself: a flat plane a few mm in front of the wall gets shadow
        // "acne" (fine stripes) at grazing sun angles. The frame's shade comes from the contact shadows.
        picture.castShadow = true;
        picture.receiveShadow = false;
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
        // The real moulding shape (flat, rounded, scoop, stepped, ornate or floater), mitred at the corners.
        group.add(buildMoulding(d.outerW - 2 * d.frameW, d.outerH - 2 * d.frameW, d.frameW, frameDepth, frame));

        // glass
        if (glazing !== 'none') {
            const glass = new THREE.Mesh(
                new THREE.PlaneGeometry(d.outerW - 2 * d.frameW, d.outerH - 2 * d.frameW),
                // Glass as a pure reflection layer, added on top of the picture: a black base adds
                // nothing, so the artwork stays perfectly sharp and true in colour; the room and the sun
                // are reflected with real glass Fresnel (≈4% head-on, stronger at grazing angles).
                // Museum / anti-reflective glass barely reflects. (Transmission blurred the art and
                // rendered the whole scene a second time every frame.)
                new THREE.MeshPhysicalMaterial({
                    color: '#000000',
                    roughness: 0.02,
                    metalness: 0,
                    specularIntensity: glazing === 'low' ? 0.12 : 1,
                    envMapIntensity: glazing === 'low' ? 0.12 : 0.7,
                    transparent: true,
                    blending: THREE.AdditiveBlending,
                    depthWrite: false,
                }),
            );
            glass.position.set(0, 0, faceZ + 0.004);
            glass.name = 'glass'; // reflects the room's lighting environment
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

/**
 * Soft shadow texture for one piece: a black rectangle the size of the piece (w × h metres)
 * whose edges fade out over `spread` metres. Drawn once per piece (small canvas, no per-frame cost).
 */
export function dropShadowTexture(w: number, h: number, spread: number): THREE.Texture {
    const pxPerM = 512 / (Math.max(w, h) + 2 * spread);
    const cw = Math.round((w + 2 * spread) * pxPerM);
    const ch = Math.round((h + 2 * spread) * pxPerM);
    const c = document.createElement('canvas');
    c.width = cw;
    c.height = ch;
    const g = c.getContext('2d')!;
    // Canvas shadowBlur (supported everywhere, unlike ctx.filter on older Safari): draw the
    // rectangle off-canvas and keep only its blurred shadow.
    // Gaussian falloff reaches ~1.5 × blur: keep it inside the canvas so no edge is ever visible.
    const blur = spread * pxPerM * 0.6;
    const inset = spread * pxPerM;
    g.shadowColor = '#000';
    g.shadowBlur = blur;
    g.shadowOffsetX = cw * 4;
    g.fillStyle = '#000';
    g.fillRect(inset - cw * 4, inset, cw - 2 * inset, ch - 2 * inset);
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
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
    t.anisotropy = Math.max(source.anisotropy, 8);
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
        (Array.isArray(material) ? material : material ? [material] : []).forEach((m) => {
            // Per-piece wall shadows own their texture.
            if (mesh.name === 'wall-shadow') (m as THREE.MeshBasicMaterial).map?.dispose();
            m.dispose();
        });
    });
}
