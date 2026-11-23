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

## 360° rooms (`rooms/`)

Photoreal cube panoramas rendered offline with Blender 4.2 LTS (Cycles + OpenImageDenoise) by
`docker/render/rooms.py`, from CC0 Poly Haven sources fetched by `docker/render/fetch_assets.py`:

- Models: sofa_03, modern_coffee_table_01, side_table_01, potted_plant_01/02/04, mid_century_lounge_chair,
  ceramic_vase_01/02/03, modern_ceiling_lamp_01, ClassicNightstand_01, metal_office_desk, modern_arm_chair_01,
  desk_lamp_arm_01, dining_table, dining_chair_02, modern_wooden_cabinet, ClassicConsole_01, ArmChair_01,
  coffee_table_round_01, brass_vase_01 (https://polyhaven.com/models)
- Textures: wood_floor, herringbone_parquet, rectangular_parquet, marble_01, wool_boucle, rough_linen
- HDRI (sky seen through the windows): green_point_park

The bed, kitchen, shelving, books, pendant lights, cove ceiling, panelling, windows, curtains and rugs are
modelled by the script. Rooms: living room, bedroom, kitchen, office, dining room, hotel lobby. Each room folder
holds six faces (`f l r b u d`; ceiling and floor at 2/3 size), their painted-wall masks, 512 px versions for a fast first view, the light on the
art wall and `meta.json`. Current renders: 1024 px faces, 8 samples + denoiser (about 6 minutes per room
on 4 CPU cores). For sharper rooms render at 1536 px / 32 samples (about an hour per room):

    docker compose --profile render build render
    docker compose --profile render run --rm --entrypoint /opt/blender/4.2/python/bin/python3.11 \
        -e SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt render docker/render/fetch_assets.py
    docker compose --profile render run --rm render --python docker/render/rooms.py -- --room all --size 1536 --samples 32

## Real photographed rooms (`rooms/photo-*`)

360° photographs from [Poly Haven](https://polyhaven.com/hdris) (CC0), tonemapped 8K JPG, cut into the viewer's cube
faces by `docker/render/photo_rooms.py`:

| Room | Poly Haven photo |
|---|---|
| Reading room | `reading_room` — https://polyhaven.com/a/reading_room |
| Sea-view suite | `relax_inn_seaview_suite` — https://polyhaven.com/a/relax_inn_seaview_suite |
| Sunny apartment | `lebombo` — https://polyhaven.com/a/lebombo |

Each room's art wall was measured from the photo (squared up, then scaled from doors, sockets, desks and cornices),
see `PHOTO_ROOMS` in the script. Existing pictures on that wall are painted out, and the wall can be repainted (its
own colour is measured, so a new colour keeps the photo's light and shade). To add a room, put an equirectangular
360° photo in `docker/render/assets/photo/`, measure it with `--contact` / `--calib`, add it to `PHOTO_ROOMS` and run:

    docker compose --profile render run --rm render --python docker/render/photo_rooms.py -- --room all
