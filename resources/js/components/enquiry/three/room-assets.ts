import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { HDRLoader } from 'three/examples/jsm/loaders/HDRLoader.js';

/*
 * Real furniture, floor texture and interior lighting for the 3D room.
 * All assets are CC0 from Poly Haven (polyhaven.com), stored in public/3d (≈7 MB, 1k textures),
 * loaded only when the 3D room is opened and cached by the browser afterwards.
 */

const BASE = '/3d';

/** Gap between furniture backs and the wall, and between pieces (metres). */
const WALL_GAP = 0.1;
const SIDE_GAP = 0.15;

export interface FloorMaps {
    map: THREE.Texture;
    normalMap: THREE.Texture;
    roughnessMap: THREE.Texture;
    /** Real size of one texture tile (metres). */
    tile: number;
}

export interface RoomAssets {
    /** Sofa, side table with vase, and a plant — positioned against the back wall, centred on x = 0. */
    furniture: THREE.Group;
    /** Total width of the furniture arrangement (metres). */
    furnitureWidth: number;
    floor: FloorMaps;
    /** Pre-filtered interior environment for lighting and reflections. */
    environment: THREE.Texture;
}

let cache: Promise<Omit<RoomAssets, 'environment'> & { hdr: THREE.DataTexture }> | null = null;

/** Loads (once per page) and arranges everything. Rejects if any file is missing; callers keep the simple room then. */
export async function loadRoomAssets(renderer: THREE.WebGLRenderer): Promise<RoomAssets> {
    cache ??= loadFiles();
    const files = await cache.catch((error) => {
        cache = null; // allow a retry next time the room opens
        throw error;
    });

    const pmrem = new THREE.PMREMGenerator(renderer);
    const environment = pmrem.fromEquirectangular(files.hdr).texture;
    pmrem.dispose();

    return { furniture: files.furniture.clone(true), furnitureWidth: files.furnitureWidth, floor: files.floor, environment };
}

async function loadFiles() {
    const gltf = new GLTFLoader();
    const model = async (name: string) => {
        const scene = (await gltf.loadAsync(`${BASE}/models/${name}/${name}_1k.gltf`)).scene;
        scene.traverse((child) => {
            const mesh = child as THREE.Mesh;
            if (mesh.isMesh) {
                mesh.castShadow = true;
                mesh.receiveShadow = true;
            }
        });
        return scene;
    };

    const textures = new THREE.TextureLoader();
    const texture = async (file: string, srgb = false) => {
        const t = await textures.loadAsync(`${BASE}/textures/${file}`);
        t.wrapS = t.wrapT = THREE.RepeatWrapping;
        t.anisotropy = 8;
        if (srgb) t.colorSpace = THREE.SRGBColorSpace;
        return t;
    };

    const [sofa, table, vase, plant, map, normalMap, roughnessMap, hdr] = await Promise.all([
        model('sofa_02'),
        model('side_table_01'),
        model('ceramic_vase_01'),
        model('potted_plant_02'),
        texture('laminate_floor_02_diff_1k.jpg', true),
        texture('laminate_floor_02_nor_gl_1k.jpg'),
        texture('laminate_floor_02_rough_1k.jpg'),
        new HDRLoader().loadAsync(`${BASE}/hdri/lebombo_1k.hdr`),
    ]);
    hdr.mapping = THREE.EquirectangularReflectionMapping;

    const { group, width } = arrange(sofa, table, vase, plant);
    return { furniture: group, furnitureWidth: width, floor: { map, normalMap, roughnessMap, tile: 1.7 }, hdr };
}

/** Puts a model on the floor with its back against the wall, at the given x (centre). */
function place(object: THREE.Object3D, x: number): THREE.Box3 {
    const box = new THREE.Box3().setFromObject(object);
    const centreX = (box.min.x + box.max.x) / 2;
    object.position.set(x - centreX, -box.min.y, WALL_GAP - box.min.z);
    return new THREE.Box3().setFromObject(object);
}

function size(object: THREE.Object3D): THREE.Vector3 {
    return new THREE.Box3().setFromObject(object).getSize(new THREE.Vector3());
}

/** Plant · sofa · side table with a vase on it, centred under the artwork. */
function arrange(sofa: THREE.Object3D, table: THREE.Object3D, vase: THREE.Object3D, plant: THREE.Object3D) {
    const group = new THREE.Group();
    group.add(sofa, table, vase, plant);

    const sofaBox = place(sofa, 0);
    const tableBox = place(table, sofaBox.max.x + SIDE_GAP + size(table).x / 2);
    const plantBox = place(plant, sofaBox.min.x - SIDE_GAP - size(plant).x / 2);

    // The vase stands on the side table, slightly off-centre.
    const vaseBox = new THREE.Box3().setFromObject(vase);
    const tableCentre = tableBox.getCenter(new THREE.Vector3());
    vase.position.set(tableCentre.x + 0.06 - (vaseBox.min.x + vaseBox.max.x) / 2, tableBox.max.y - vaseBox.min.y, tableCentre.z - 0.04 - (vaseBox.min.z + vaseBox.max.z) / 2);

    return { group, width: tableBox.max.x - plantBox.min.x };
}
