# 3D room assets

All models, textures and the HDRI in this folder come from [Poly Haven](https://polyhaven.com) and are
licensed **CC0 1.0** (public domain): free for any use, including commercial, no attribution required.
They are used by `resources/js/components/enquiry/three/room-assets.ts`. Each room style is
downloaded only when a customer picks it, then cached by the browser.

| Room style | Folder | Asset | Source |
|---|---|---|---|
| Living room | `models/sofa_02.glb` | Sofa 02 | https://polyhaven.com/a/sofa_02 |
| Living room | `models/side_table_01.glb` | Side Table 01 | https://polyhaven.com/a/side_table_01 |
| Living, bedroom, dining, lobby | `models/ceramic_vase_01.glb` | Ceramic Vase 01 | https://polyhaven.com/a/ceramic_vase_01 |
| Living room, office | `models/potted_plant_02.glb` | Potted Plant 02 | https://polyhaven.com/a/potted_plant_02 |
| Bedroom | `models/GothicBed_01.glb` | Gothic Bed 01 | https://polyhaven.com/a/GothicBed_01 |
| Bedroom | `models/ClassicNightstand_01.glb` | Classic Nightstand 01 | https://polyhaven.com/a/ClassicNightstand_01 |
| Office | `models/metal_office_desk.glb` | Metal Office Desk | https://polyhaven.com/a/metal_office_desk |
| Office | `models/modern_arm_chair_01.glb` | Modern Arm Chair 01 | https://polyhaven.com/a/modern_arm_chair_01 |
| Dining room | `models/dining_table.glb` | Dining Table | https://polyhaven.com/a/dining_table |
| Dining room | `models/dining_chair_02.glb` | Dining Chair 02 | https://polyhaven.com/a/dining_chair_02 |
| Hotel lobby | `models/ClassicConsole_01.glb` | Classic Console 01 | https://polyhaven.com/a/ClassicConsole_01 |
| Hotel lobby | `models/ArmChair_01.glb` | Arm Chair 01 | https://polyhaven.com/a/ArmChair_01 |
| All | `textures/laminate_floor_02_*.webp` | Laminate Floor 02 (colour, normal, roughness) | https://polyhaven.com/a/laminate_floor_02 |
| All | `hdri/lebombo_1k.hdr` | Lebombo interior HDRI (lighting and reflections) | https://polyhaven.com/a/lebombo |

Models were optimised with [gltf-transform](https://gltf-transform.dev) (`optimize --compress meshopt --texture-compress webp`,
1k textures) into single `.glb` files — about 75% smaller than the originals. The floor maps are WebP.
A customer downloads about 3 MB for the first room style (including the 1.5 MB HDRI) and 0.5–1.2 MB per further style.
If any file is missing the room falls back to the simple built-in sofa and floor.

## AR viewer

`vendor/model-viewer-4.4.0.min.js` is Google's [`<model-viewer>`](https://modelviewer.dev) 4.4.0
(the self-contained bundle from the npm package `@google/model-viewer`), licence in
`vendor/model-viewer.LICENSE.txt` (Apache-2.0; file header BSD-3-Clause for bundled parts).
It is loaded only when a customer opens "View on your wall (AR)".
