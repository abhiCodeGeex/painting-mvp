import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { HDRLoader } from 'three/examples/jsm/loaders/HDRLoader.js';
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js';

/*
 * Real furniture, floor texture and interior lighting for the 3D room.
 * All assets are CC0 from Poly Haven (polyhaven.com), stored in public/3d as single .glb files
 * optimised with gltf-transform (Meshopt geometry + WebP 1k textures, ~75% smaller than the originals).
 * Each room style is loaded only when it is chosen and cached for the rest of the visit.
 */

const BASE = '/3d';
const WALL_GAP = 0.1; // furniture back to wall (m)

export type RoomStyle = 'living' | 'bedroom' | 'office' | 'dining' | 'lobby';

export const ROOM_STYLES: { value: RoomStyle; label: string }[] = [
    { value: 'living', label: 'Living room' },
    { value: 'bedroom', label: 'Bedroom' },
    { value: 'office', label: 'Office' },
    { value: 'dining', label: 'Dining room' },
    { value: 'lobby', label: 'Hotel lobby' },
];

export interface FloorMaps {
    map: THREE.Texture;
    normalMap: THREE.Texture;
    roughnessMap: THREE.Texture;
    /** Real size of one texture tile (metres). */
    tile: number;
}

export interface Furniture {
    group: THREE.Group;
    /** Total width of the arrangement (m). */
    width: number;
    /** Height of the furniture right under the artwork (m) — the art hangs at least 20 cm above it. */
    hangAbove: number;
}

export interface RoomBase {
    floor: FloorMaps;
    /** Pre-filtered interior environment for lighting and reflections. */
    environment: THREE.Texture;
}

const gltf = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
const models = new Map<string, Promise<THREE.Group>>();

/** Loads a model once (cached) and returns a fresh copy. */
async function model(name: string): Promise<THREE.Group> {
    let promise = models.get(name);
    if (!promise) {
        promise = gltf.loadAsync(`${BASE}/models/${name}.glb`).then((result) => {
            result.scene.traverse((child) => {
                const mesh = child as THREE.Mesh;
                if (mesh.isMesh) mesh.castShadow = mesh.receiveShadow = true;
            });
            return result.scene;
        });
        promise.catch(() => models.delete(name)); // allow a retry
        models.set(name, promise);
    }
    return (await promise).clone(true);
}

let baseFiles: Promise<{ floor: FloorMaps; hdr: THREE.DataTexture }> | null = null;

/** Floor textures and interior lighting (shared by all styles). */
export async function loadRoomBase(renderer: THREE.WebGLRenderer): Promise<RoomBase> {
    baseFiles ??= (async () => {
        const loader = new THREE.TextureLoader();
        const texture = async (file: string, srgb = false) => {
            const t = await loader.loadAsync(`${BASE}/textures/${file}`);
            t.wrapS = t.wrapT = THREE.RepeatWrapping;
            t.anisotropy = 8;
            if (srgb) t.colorSpace = THREE.SRGBColorSpace;
            return t;
        };
        const [map, normalMap, roughnessMap, hdr] = await Promise.all([
            texture('laminate_floor_02_diff_1k.webp', true),
            texture('laminate_floor_02_nor_gl_1k.webp'),
            texture('laminate_floor_02_rough_1k.webp'),
            new HDRLoader().loadAsync(`${BASE}/hdri/lebombo_1k.hdr`),
        ]);
        hdr.mapping = THREE.EquirectangularReflectionMapping;
        return { floor: { map, normalMap, roughnessMap, tile: 1.7 }, hdr };
    })();
    const files = await baseFiles.catch((error) => {
        baseFiles = null;
        throw error;
    });

    const pmrem = new THREE.PMREMGenerator(renderer);
    const environment = pmrem.fromEquirectangular(files.hdr).texture;
    pmrem.dispose();
    return { floor: files.floor, environment };
}

/** The furniture for one room style, arranged against the back wall and centred on x = 0. */
export async function loadFurniture(style: RoomStyle): Promise<Furniture> {
    switch (style) {
        case 'bedroom': {
            const [bed, left, right, vase] = await Promise.all([model('GothicBed_01'), model('ClassicNightstand_01'), model('ClassicNightstand_01'), model('ceramic_vase_01')]);
            const group = new THREE.Group();
            group.add(bed, left, right, vase);
            const bedBox = place(bed, 0);
            const l = place(left, bedBox.min.x - 0.08 - size(left).x / 2);
            const r = place(right, bedBox.max.x + 0.08 + size(right).x / 2);
            standOn(vase, r, 0.05);
            // Headboard panel height (the corner posts are taller).
            return { group, width: r.max.x - l.min.x, hangAbove: 1.05 };
        }
        case 'office': {
            const [desk, chair, plant] = await Promise.all([model('metal_office_desk'), model('modern_arm_chair_01'), model('potted_plant_02')]);
            const group = new THREE.Group();
            group.add(desk, chair, plant);
            const deskBox = place(desk, 0);
            // Chair in front of the desk, facing the wall.
            chair.rotation.y = Math.PI;
            const chairBox = place(chair, 0.15, deskBox.max.z - 0.12);
            const p = place(plant, deskBox.max.x + 0.2 + size(plant).x / 2);
            return { group, width: p.max.x - Math.min(deskBox.min.x, chairBox.min.x), hangAbove: deskBox.max.y };
        }
        case 'dining': {
            const [table, c1, c2, vase] = await Promise.all([model('dining_table'), model('dining_chair_02'), model('dining_chair_02'), model('ceramic_vase_01')]);
            const group = new THREE.Group();
            group.add(table, c1, c2, vase);
            const tableBox = place(table, 0, 0.35);
            // Two chairs on the room side, facing the wall, tucked in a little.
            for (const [chair, x] of [[c1, -0.45], [c2, 0.45]] as const) {
                chair.rotation.y = Math.PI;
                place(chair, x, tableBox.max.z - 0.25);
            }
            standOn(vase, tableBox, 0);
            return { group, width: size(table).x, hangAbove: tableBox.max.y };
        }
        case 'lobby': {
            const [console, a1, a2, vase] = await Promise.all([model('ClassicConsole_01'), model('ArmChair_01'), model('ArmChair_01'), model('ceramic_vase_01')]);
            const group = new THREE.Group();
            group.add(console, a1, a2, vase);
            const consoleBox = place(console, 0);
            // Armchairs either side, turned slightly towards each other.
            a1.rotation.y = 0.35;
            a2.rotation.y = -0.35;
            const left = place(a1, consoleBox.min.x - 0.15 - size(a1).x / 2, WALL_GAP + 0.15);
            const right = place(a2, consoleBox.max.x + 0.15 + size(a2).x / 2, WALL_GAP + 0.15);
            standOn(vase, consoleBox, -0.4);
            return { group, width: right.max.x - left.min.x, hangAbove: consoleBox.max.y };
        }
        default: {
            const [sofa, table, vase, plant] = await Promise.all([model('sofa_02'), model('side_table_01'), model('ceramic_vase_01'), model('potted_plant_02')]);
            const group = new THREE.Group();
            group.add(sofa, table, vase, plant);
            const sofaBox = place(sofa, 0);
            const tableBox = place(table, sofaBox.max.x + 0.15 + size(table).x / 2);
            const plantBox = place(plant, sofaBox.min.x - 0.15 - size(plant).x / 2);
            standOn(vase, tableBox, 0.06);
            return { group, width: tableBox.max.x - plantBox.min.x, hangAbove: sofaBox.max.y };
        }
    }
}

/** Puts an object on the floor, centred on x, with its back at z (default: against the wall). */
function place(object: THREE.Object3D, x: number, backZ = WALL_GAP): THREE.Box3 {
    object.position.set(0, 0, 0);
    object.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(object);
    object.position.set(x - (box.min.x + box.max.x) / 2, -box.min.y, backZ - box.min.z);
    object.updateMatrixWorld(true);
    return new THREE.Box3().setFromObject(object);
}

/** Stands an object on top of another (given its box), shifted by dx. */
function standOn(object: THREE.Object3D, base: THREE.Box3, dx: number): void {
    object.position.set(0, 0, 0);
    object.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(object);
    const centre = base.getCenter(new THREE.Vector3());
    object.position.set(centre.x + dx - (box.min.x + box.max.x) / 2, base.max.y - box.min.y, centre.z - (box.min.z + box.max.z) / 2);
}

function size(object: THREE.Object3D): THREE.Vector3 {
    object.updateMatrixWorld(true);
    return new THREE.Box3().setFromObject(object).getSize(new THREE.Vector3());
}
