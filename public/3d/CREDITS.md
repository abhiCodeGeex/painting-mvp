# 3D room assets

All files in this folder come from [Poly Haven](https://polyhaven.com) and are licensed
**CC0 1.0** (public domain): free for any use, including commercial, no attribution required.
They are used by `resources/js/components/enquiry/three/room-assets.ts`.

| Folder / file | Asset | Source |
|---|---|---|
| `models/sofa_02` | Sofa 02 (1k textures, glTF) | https://polyhaven.com/a/sofa_02 |
| `models/side_table_01` | Side Table 01 | https://polyhaven.com/a/side_table_01 |
| `models/ceramic_vase_01` | Ceramic Vase 01 | https://polyhaven.com/a/ceramic_vase_01 |
| `models/potted_plant_02` | Potted Plant 02 | https://polyhaven.com/a/potted_plant_02 |
| `textures/laminate_floor_02_*` | Laminate Floor 02 (1k: colour, normal, roughness) | https://polyhaven.com/a/laminate_floor_02 |
| `hdri/lebombo_1k.hdr` | Lebombo (interior HDRI, 1k) — lighting and reflections | https://polyhaven.com/a/lebombo |

Total ≈ 7.3 MB. Loaded only when a customer opens the 3D room, then cached by the browser.
If any file is missing the room falls back to the simple built-in sofa and floor.
