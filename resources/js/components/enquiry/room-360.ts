import { Icon } from '@/ui/icon';
import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    computed,
    effect,
    ElementRef,
    input,
    OnDestroy,
    signal,
    untracked,
    viewChild,
} from '@angular/core';
import { Download, Expand, Eye, LoaderCircle, Minimize, RotateCcw, Smartphone, ZoomIn, ZoomOut } from 'lucide';
import * as THREE from 'three';
import { WALL_COLOURS, type WallPiece } from './preview-helpers';
import { buildArtwork, coverTexture, disposeTree, gradientTexture, M } from './three/scene-parts';

/**
 * Photoreal 360° room (spec: "Hybrid 360° rooms").
 *
 * Each room is an offline Cycles render (docker/render/rooms.py): a cube panorama seen from one eye point,
 * plus a mask of the painted walls and a map of the light falling on the art wall. In the browser:
 *  - the six faces are drawn around the eye (rotate and zoom only — the same as a 3DVista-style tour);
 *  - the walls are re-painted in the customer's colour in linear light (wall × colour / render albedo);
 *  - the customer's framed artwork is real 3D geometry hung at true scale on the art wall, lit by the
 *    rendered light at that spot and passed through the same tone curve as the render, so it sits in the
 *    photo instead of floating on it.
 * Everything is drawn only when something changes; the GPU is idle otherwise.
 */

interface FaceInfo {
    dir: [number, number, number];
    up: [number, number, number];
}

interface RoomMeta {
    key: string;
    title: string;
    faceSize: number;
    faces: Record<string, FaceInfo>;
    eyeHeight: number;
    artWall: { distance: number; floor: number; ceiling: number; minBottom: number; maxTop: number; left: number; right: number };
    light: { file: string; scale: number; rect: [number, number, number, number] };
    wallAlbedo: number;
    knee: number;
}

interface PieceDims {
    picW: number;
    picH: number;
    matB: number;
    matBottom: number;
    frameW: number;
    outerW: number;
    outerH: number;
}

const BASE = '/3d/rooms';
const FACE_ORDER = ['f', 'l', 'r', 'b', 'd', 'u'];
const SKY_RADIUS = 50;
const GAP = 0.08;
const ROOM_KEY = 'room-360-style';

function dimsOf(p: WallPiece): PieceDims {
    const picW = M(Math.max(50, p.widthMm || 0));
    const picH = M(Math.max(50, p.heightMm || 0));
    const matB = p.mat ? M(p.mat.border_mm) : 0;
    const matBottom = p.mat ? M(p.mat.bottom_weight_mm ?? 0) : 0;
    const frameW = p.frame ? M(p.frame.width_mm) : 0;
    return { picW, picH, matB, matBottom, frameW, outerW: picW + 2 * matB + 2 * frameW, outerH: picH + 2 * matB + matBottom + 2 * frameW };
}

function hexToLinear(hex: string): THREE.Vector3 {
    const c = new THREE.Color(hex); // three converts sRGB hex to linear working colour
    return new THREE.Vector3(c.r, c.g, c.b);
}

/** GLSL shared by the panorama and the artwork: sRGB transfer and the render's tone curve. */
const TONE_GLSL = /* glsl */ `
    uniform float roomKnee;
    vec3 r360SrgbToLinear(vec3 c) {
        return mix(c / 12.92, pow((c + 0.055) / 1.055, vec3(2.4)), step(0.04045, c));
    }
    vec3 r360LinearToSrgb(vec3 c) {
        c = clamp(c, 0.0, 1.0);
        return mix(c * 12.92, 1.055 * pow(c, vec3(1.0 / 2.4)) - 0.055, step(0.0031308, c));
    }
    vec3 r360Tone(vec3 x) {
        float s = 1.0 - roomKnee;
        vec3 shoulder = roomKnee + s * (1.0 - exp(-(x - roomKnee) / s));
        return mix(x, shoulder, step(roomKnee, x));
    }
    vec3 r360ToneInverse(vec3 y) {
        float s = 1.0 - roomKnee;
        vec3 inv = roomKnee - s * log(max(1.0 - (min(y, vec3(0.9995)) - roomKnee) / s, 1e-4));
        return mix(y, inv, step(roomKnee, y));
    }
`;

@Component({
    selector: 'room-360',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div #root [class]="'relative w-full overflow-hidden rounded-lg border bg-neutral-900 select-none ' + (fullscreen() ? 'h-full' : height())">
            <div
                #host
                class="absolute inset-0 cursor-grab touch-none active:cursor-grabbing"
                [style.opacity]="shown() ? 1 : 0"
                style="transition: opacity 0.5s"
            ></div>

            @if (!shown()) {
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-sm text-white/80">
                    <svg [icon]="LoaderCircle" class="size-5 animate-spin" />
                    {{ error() || 'Preparing the 360° room…' }}
                </div>
            }

            <!-- rooms -->
            <div class="absolute top-2 left-2 flex max-w-[calc(100%-1rem)] flex-wrap gap-1">
                @for (r of rooms(); track r.key) {
                    <button
                        type="button"
                        [class]="
                            'rounded-full px-2.5 py-1 text-xs font-medium backdrop-blur transition ' +
                            (r.key === roomKey() ? 'bg-white text-neutral-900 shadow' : 'bg-black/45 text-white hover:bg-black/60')
                        "
                        (click)="chooseRoom(r.key)"
                    >
                        {{ r.title }}
                    </button>
                }
            </div>

            @if (loadingHd()) {
                <div
                    class="absolute top-2 right-2 flex items-center gap-1 rounded-full bg-black/45 px-2 py-1 text-[11px] text-white/85 backdrop-blur"
                >
                    <svg [icon]="LoaderCircle" class="size-3 animate-spin" /> Sharpening…
                </div>
            }

            @if (notice(); as n) {
                <div
                    class="absolute inset-x-2 top-11 mx-auto max-w-md rounded-md bg-amber-50/95 px-3 py-1.5 text-center text-xs text-amber-900 shadow"
                >
                    {{ n }}
                </div>
            }

            <!-- wall colours + tools -->
            <div class="absolute inset-x-2 bottom-2 flex flex-wrap items-end justify-between gap-2">
                <div class="flex items-center gap-1 rounded-full bg-black/45 px-2 py-1 backdrop-blur" title="Wall colour">
                    @for (c of colours; track c.hex) {
                        <button
                            type="button"
                            [attr.aria-label]="'Wall colour ' + c.name"
                            [title]="c.name"
                            [class]="
                                'size-5 rounded-full border transition ' +
                                (sameColour(c.hex) ? 'scale-110 border-white ring-2 ring-white/70' : 'border-white/40')
                            "
                            [style.background]="c.hex"
                            (click)="wall.set(c.hex)"
                        ></button>
                    }
                </div>
                <div class="flex items-center gap-1 rounded-full bg-black/45 p-1 backdrop-blur">
                    @for (b of buttons(); track b.label) {
                        <button
                            type="button"
                            [title]="b.label"
                            [attr.aria-label]="b.label"
                            class="rounded-full p-1.5 text-white hover:bg-white/15"
                            (click)="b.run()"
                        >
                            <svg [icon]="b.icon" class="size-4" />
                        </button>
                    }
                </div>
            </div>

            <div
                class="pointer-events-none absolute bottom-12 left-1/2 -translate-x-1/2 rounded-full bg-black/40 px-2.5 py-1 text-[11px] text-white/80 transition-opacity"
                [style.opacity]="hint() ? 1 : 0"
            >
                Drag to look around · scroll or pinch to zoom
            </div>
        </div>
    `,
})
export class Room360 implements OnDestroy {
    readonly pieces = input.required<WallPiece[]>();
    readonly wallColour = input.required<string>();
    readonly height = input('h-[420px]');

    protected readonly LoaderCircle = LoaderCircle;
    protected readonly colours = WALL_COLOURS;
    protected rooms = signal<{ key: string; title: string }[]>([]);
    protected roomKey = signal<string>(this.savedRoom());
    protected wall = signal<string>('#EDE8E1');
    protected shown = signal(false);
    protected loadingHd = signal(false);
    protected error = signal('');
    protected hint = signal(true);
    protected fullscreen = signal(false);
    protected notice = signal('');

    private root = viewChild.required<ElementRef<HTMLDivElement>>('root');
    private host = viewChild.required<ElementRef<HTMLDivElement>>('host');

    private renderer!: THREE.WebGLRenderer;
    private scene = new THREE.Scene();
    private camera = new THREE.PerspectiveCamera(70, 1, 0.05, 200);
    private sky = new THREE.Group();
    private art = new THREE.Group();
    private lights = new THREE.Group();
    private meta: RoomMeta | null = null;
    private envMap: THREE.Texture | null = null;
    private envTarget: THREE.WebGLRenderTarget | null = null;
    /** Cropped copies of the pictures made for the current pieces (freed on rebuild). */
    private pieceTextures: THREE.Texture[] = [];
    private pendingPictures = new Set<string>();
    private envTimer = 0;
    private textures = new Map<string, THREE.Texture>();
    private placeholder = gradientTexture();
    private pictureVersion = signal(0);
    private loadToken = 0;
    private loadedRoom = '';
    private frameQueued = 0;
    private destroyed = false;
    private cleanup: (() => void)[] = [];

    // view state (radians / degrees)
    private yaw = 0;
    private pitch = 0;
    private fov = 70;
    private velocity = { yaw: 0, pitch: 0 };

    /** Shared by every material: switching rooms or colours updates them all at once. */
    private uniforms = {
        roomLight: { value: null as THREE.Texture | null },
        roomRect: { value: new THREE.Vector4(-1, 1, -1, 1) },
        roomLightScale: { value: 2 },
        roomKnee: { value: 0.8 },
        wallRatio: { value: new THREE.Vector3(1, 1, 1) },
    };

    private dims = computed(() => this.pieces().map((p) => ({ piece: p, key: String(p.id), d: dimsOf(p) })));

    protected buttons = computed(() => [
        { label: 'Look at the artwork', icon: Eye, run: () => this.lookAtArt(true) },
        { label: 'Zoom in', icon: ZoomIn, run: () => this.zoomBy(0.8) },
        { label: 'Zoom out', icon: ZoomOut, run: () => this.zoomBy(1.25) },
        { label: 'Reset view', icon: RotateCcw, run: () => this.lookAtArt(false) },
        { label: 'Save 4K picture', icon: Download, run: () => this.saveImage() },
        ...(this.canGyro
            ? [
                  {
                      label: this.gyro() ? 'Stop moving with the phone' : 'Move the phone to look around',
                      icon: Smartphone,
                      run: () => void this.toggleGyro(),
                  },
              ]
            : []),
        {
            label: this.fullscreen() ? 'Exit full screen' : 'Full screen',
            icon: this.fullscreen() ? Minimize : Expand,
            run: () => this.toggleFullscreen(),
        },
    ]);

    constructor() {
        effect(() => this.wall.set(this.wallColour() || '#EDE8E1'));

        afterNextRender(() => {
            this.init();
            void this.loadIndex();
        });

        // Room change → load it.
        effect(() => {
            // Read both signals before any early return, so the effect re-runs when either changes.
            const key = this.roomKey();
            const rooms = this.rooms();
            if (!this.renderer || !rooms.length) return;
            untracked(() => void this.loadRoom(key));
        });

        // Wall colour → re-paint (a uniform; no reload).
        effect(() => {
            const c = hexToLinear(this.wall());
            const albedo = this.meta?.wallAlbedo ?? 0.8;
            this.uniforms.wallRatio.value.set(c.x / albedo, c.y / albedo, c.z / albedo);
            this.requestFrame();
            // Glass and metal frames reflect the repainted room too (rebuilt once the colour settles).
            clearTimeout(this.envTimer);
            this.envTimer = window.setTimeout(() => {
                if (!this.meta || this.destroyed) return;
                this.buildEnvironment();
                this.art.traverse((o) => {
                    const mesh = o as THREE.Mesh;
                    if (!mesh.isMesh) return;
                    for (const m of Array.isArray(mesh.material) ? mesh.material : [mesh.material]) {
                        const std = m as THREE.MeshStandardMaterial;
                        if (std.envMap) std.envMap = this.envMap;
                    }
                });
                this.requestFrame();
            }, 250);
        });

        // Artwork changes → rebuild the pieces.
        effect(() => {
            this.dims();
            this.pictureVersion();
            untracked(() => {
                if (this.meta) this.buildPieces();
            });
        });
    }

    // ------------------------------------------------------------------ setup

    private init(): void {
        const host = this.host().nativeElement;
        this.renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.toneMapping = THREE.NoToneMapping; // the render's own tone curve is applied in the shaders
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.domElement.style.width = '100%';
        this.renderer.domElement.style.height = '100%';
        this.renderer.domElement.style.display = 'block';
        host.appendChild(this.renderer.domElement);
        this.scene.add(this.sky, this.art, this.lights);

        // Neutral lights that, together, give a front-facing surface exactly its albedo; the rendered
        // light map then scales it to the light at that spot of the wall. The directional light comes
        // from above-front so mouldings and canvas edges get natural shading. (three's Lambert divides
        // both by π: key = π·0.5 gives 0.5·cosθ, ambient = π·(1 − 0.5·cosθ) makes the front sum 1.)
        const keyDir = new THREE.Vector3(0.15, 0.6, 1);
        const cosFront = keyDir.clone().normalize().z;
        const ambient = new THREE.AmbientLight('#ffffff', Math.PI * (1 - 0.5 * cosFront));
        const key = new THREE.DirectionalLight('#ffffff', Math.PI * 0.5);
        key.position.copy(keyDir);
        this.lights.add(ambient, key);

        const resize = () => {
            const w = Math.max(1, host.clientWidth);
            const h = Math.max(1, host.clientHeight);
            this.renderer.setSize(w, h, false);
            this.camera.aspect = w / h;
            this.camera.updateProjectionMatrix();
            this.requestFrame();
        };
        const observer = new ResizeObserver(resize);
        observer.observe(host);
        this.cleanup.push(() => observer.disconnect());
        resize();
        this.bindControls(host);

        const onFs = () => {
            this.fullscreen.set(document.fullscreenElement === this.root().nativeElement);
            requestAnimationFrame(resize);
        };
        document.addEventListener('fullscreenchange', onFs);
        this.cleanup.push(() => document.removeEventListener('fullscreenchange', onFs));
    }

    private async loadIndex(): Promise<void> {
        try {
            const res = await fetch(`${BASE}/index.json`, { cache: 'no-cache' });
            const list = (await res.json()) as { key: string; title: string }[];
            if (!list.length) throw new Error('no rooms');
            this.rooms.set(list);
            if (!list.some((r) => r.key === this.roomKey())) this.roomKey.set(list[0].key);
        } catch {
            this.error.set('The 360° rooms are not available right now.');
        }
    }

    protected chooseRoom(key: string): void {
        if (key === this.roomKey()) return;
        this.roomKey.set(key);
        try {
            localStorage.setItem(ROOM_KEY, key);
        } catch {
            /* ignore */
        }
    }

    private savedRoom(): string {
        try {
            return localStorage.getItem(ROOM_KEY) || 'living';
        } catch {
            return 'living';
        }
    }

    // ------------------------------------------------------------------ loading

    private loadTexture(url: string, colour: boolean): Promise<THREE.Texture> {
        return new Promise((resolve, reject) => {
            new THREE.TextureLoader().load(
                url,
                (t) => {
                    // Decoded by hand in the shader (sRGB → linear → inverse tone curve), so no conversion here.
                    t.colorSpace = THREE.NoColorSpace;
                    t.generateMipmaps = true;
                    t.minFilter = THREE.LinearMipmapLinearFilter;
                    t.magFilter = THREE.LinearFilter;
                    t.anisotropy = colour ? Math.min(8, this.renderer.capabilities.getMaxAnisotropy()) : 1;
                    t.wrapS = t.wrapT = THREE.ClampToEdgeWrapping;
                    resolve(t);
                },
                undefined,
                reject,
            );
        });
    }

    private async loadRoom(key: string): Promise<void> {
        const token = ++this.loadToken;
        const dir = `${BASE}/${key}`;
        this.error.set('');
        try {
            const meta = (await (await fetch(`${dir}/meta.json`)).json()) as RoomMeta;
            // Small faces first: the room appears within a moment, then sharpens.
            const [light, ...small] = await Promise.all([
                this.loadTexture(`${dir}/${meta.light.file}`, false),
                ...FACE_ORDER.flatMap((f) => [this.loadTexture(`${dir}/${f}-sm.webp`, true), this.loadTexture(`${dir}/${f}-mask-sm.webp`, false)]),
            ]);
            if (token !== this.loadToken || this.destroyed) return;

            const firstLoad = !this.meta;
            this.meta = meta;
            const [xa, xb, za, zb] = meta.light.rect;
            this.uniforms.roomLight.value?.dispose();
            this.uniforms.roomLight.value = light;
            this.uniforms.roomRect.value.set(xa, xb, za, zb);
            this.uniforms.roomLightScale.value = meta.light.scale;
            this.uniforms.roomKnee.value = meta.knee;
            const c = hexToLinear(this.wall());
            this.uniforms.wallRatio.value.set(c.x / meta.wallAlbedo, c.y / meta.wallAlbedo, c.z / meta.wallAlbedo);

            this.buildSky(meta, (f) => {
                const i = FACE_ORDER.indexOf(f);
                return { map: small[2 * i], mask: small[2 * i + 1] };
            });
            this.buildEnvironment();
            this.buildPieces();
            if (firstLoad) this.lookAtArt(false, true);
            this.shown.set(true);
            this.requestFrame();

            // Full-resolution faces, the one in view first, uploaded one per frame (no hitch).
            this.loadingHd.set(true);
            const order = [...FACE_ORDER].sort((a, b) => this.faceWeight(meta, b) - this.faceWeight(meta, a));
            for (const f of order) {
                const [map, mask] = await Promise.all([this.loadTexture(`${dir}/${f}.webp`, true), this.loadTexture(`${dir}/${f}-mask.webp`, false)]);
                if (token !== this.loadToken || this.destroyed) {
                    map.dispose();
                    mask.dispose();
                    return;
                }
                this.renderer.initTexture(map);
                this.renderer.initTexture(mask);
                await new Promise((r) => requestAnimationFrame(() => r(null)));
                if (token !== this.loadToken || this.destroyed) {
                    map.dispose();
                    mask.dispose();
                    return;
                }
                this.swapFace(f, map, mask);
            }
            if (token === this.loadToken) this.loadingHd.set(false);
            this.loadedRoom = key;
        } catch {
            if (token !== this.loadToken) return;
            this.loadingHd.set(false);
            const message = 'This room could not be loaded. Please try another one.';
            if (this.shown()) {
                // Keep showing the room that works, and say so.
                this.notice.set(message);
                if (this.loadedRoom && this.loadedRoom !== key) this.roomKey.set(this.loadedRoom);
            } else {
                this.error.set(message);
            }
        }
    }

    /** How much of the view a face covers (dot product with the view direction). */
    private faceWeight(meta: RoomMeta, f: string): number {
        const dir = new THREE.Vector3(...meta.faces[f].dir);
        return dir.dot(this.camera.getWorldDirection(new THREE.Vector3()));
    }

    // ------------------------------------------------------------------ panorama

    private skyMaterial(map: THREE.Texture, mask: THREE.Texture, linearOut = false): THREE.ShaderMaterial {
        return new THREE.ShaderMaterial({
            uniforms: {
                map: { value: map },
                mask: { value: mask },
                wallRatio: this.uniforms.wallRatio,
                roomKnee: this.uniforms.roomKnee,
            },
            vertexShader: /* glsl */ `
                varying vec2 vUv;
                void main() {
                    vUv = uv;
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }
            `,
            fragmentShader: /* glsl */ `
                uniform sampler2D map;
                uniform sampler2D mask;
                uniform vec3 wallRatio;
                varying vec2 vUv;
                ${TONE_GLSL}
                void main() {
                    // Crisp, like a photo viewer: the mip level is biased one notch finer (trilinear
                    // filtering otherwise blends in the half-size level and softens the whole room), and
                    // a light unsharp mask at the render's own pixel size restores edge contrast.
                    vec2 texel = 1.0 / vec2(textureSize(map, 0));
                    vec3 c = texture2D(map, vUv, -0.6).rgb;
                    vec3 blur = 0.25 * (texture2D(map, vUv + vec2(texel.x, 0.0), -0.6).rgb + texture2D(map, vUv - vec2(texel.x, 0.0), -0.6).rgb
                        + texture2D(map, vUv + vec2(0.0, texel.y), -0.6).rgb + texture2D(map, vUv - vec2(0.0, texel.y), -0.6).rgb);
                    c = clamp(c + 0.45 * (c - blur), 0.0, 1.0);
                    vec3 lin = r360ToneInverse(r360SrgbToLinear(c));
                    float m = texture2D(mask, vUv).r;
                    lin = mix(lin, lin * wallRatio, m);
                    ${linearOut ? 'gl_FragColor = vec4(lin, 1.0);' : 'gl_FragColor = vec4(r360LinearToSrgb(r360Tone(lin)), 1.0);'}
                }
            `,
            depthWrite: false,
            depthTest: false,
            side: THREE.DoubleSide,
        });
    }

    private buildSky(meta: RoomMeta, textures: (face: string) => { map: THREE.Texture; mask: THREE.Texture }): void {
        for (const child of [...this.sky.children]) {
            this.sky.remove(child);
            const mesh = child as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial>;
            mesh.geometry.dispose();
            mesh.material.uniforms['map'].value?.dispose();
            mesh.material.uniforms['mask'].value?.dispose();
            mesh.material.dispose();
        }
        for (const f of FACE_ORDER) {
            const info = meta.faces[f];
            const dir = new THREE.Vector3(...info.dir);
            const up = new THREE.Vector3(...info.up);
            const right = new THREE.Vector3().crossVectors(dir, up);
            const c = dir.clone().multiplyScalar(SKY_RADIUS);
            const r = right.clone().multiplyScalar(SKY_RADIUS);
            const u = up.clone().multiplyScalar(SKY_RADIUS);
            // Corners: bottom-left, bottom-right, top-right, top-left (uv 0,0 → 1,1), facing the eye.
            const corners = [c.clone().sub(r).sub(u), c.clone().add(r).sub(u), c.clone().add(r).add(u), c.clone().sub(r).add(u)];
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute(
                'position',
                new THREE.Float32BufferAttribute(
                    corners.flatMap((v) => [v.x, v.y, v.z]),
                    3,
                ),
            );
            geometry.setAttribute('uv', new THREE.Float32BufferAttribute([0, 0, 1, 0, 1, 1, 0, 1], 2));
            geometry.setIndex([0, 1, 2, 0, 2, 3]);
            const { map, mask } = textures(f);
            const mesh = new THREE.Mesh(geometry, this.skyMaterial(map, mask));
            mesh.name = f;
            mesh.renderOrder = -10;
            mesh.frustumCulled = false;
            this.sky.add(mesh);
        }
    }

    private swapFace(face: string, map: THREE.Texture, mask: THREE.Texture): void {
        const mesh = this.sky.getObjectByName(face) as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial> | undefined;
        if (!mesh) return;
        const u = mesh.material.uniforms;
        const oldMap = u['map'].value as THREE.Texture;
        const oldMask = u['mask'].value as THREE.Texture;
        u['map'].value = map;
        u['mask'].value = mask;
        this.requestFrame();
        // Free the small versions after the new ones have been drawn.
        requestAnimationFrame(() => {
            oldMap.dispose();
            oldMask.dispose();
        });
    }

    /** Reflections for glass and metal frames: the panorama itself, prefiltered once per room. */
    private buildEnvironment(): void {
        const target = new THREE.WebGLCubeRenderTarget(256, { type: THREE.HalfFloatType });
        const cubeCamera = new THREE.CubeCamera(0.05, 200, target);
        const envScene = new THREE.Scene();
        for (const child of this.sky.children) {
            const mesh = child as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial>;
            const m = this.skyMaterial(mesh.material.uniforms['map'].value, mesh.material.uniforms['mask'].value, true);
            const copy = new THREE.Mesh(mesh.geometry, m);
            copy.renderOrder = -10;
            copy.frustumCulled = false;
            envScene.add(copy);
        }
        cubeCamera.update(this.renderer, envScene);
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        const envTarget = pmrem.fromCubemap(target.texture);
        pmrem.dispose();
        target.dispose();
        envScene.children.forEach((c) => ((c as THREE.Mesh).material as THREE.Material).dispose());
        this.envTarget?.dispose();
        this.envTarget = envTarget;
        this.envMap = envTarget.texture;
    }

    // ------------------------------------------------------------------ artwork

    private layout(meta: RoomMeta) {
        const dims = this.dims();
        const zone = meta.artWall;
        const rowW = dims.reduce((s, x) => s + x.d.outerW, 0) + GAP * Math.max(0, dims.length - 1);
        const maxH = Math.max(0.05, ...dims.map((x) => x.d.outerH));
        // Centre ~1.5 m from the floor, at least clear of the furniture, below the ceiling if possible.
        const centreFromFloor = 1.5;
        let y = Math.max(zone.floor + centreFromFloor, zone.minBottom + maxH / 2);
        if (y + maxH / 2 > zone.maxTop) y = Math.max(zone.minBottom + maxH / 2, zone.maxTop - maxH / 2);
        const free = zone.right - zone.left;
        const cx = Math.abs((zone.left + zone.right) / 2) < 0.05 ? 0 : (zone.left + zone.right) / 2;
        let x = cx - rowW / 2;
        const placed = dims.map(({ key, d, piece }) => {
            const centre = x + d.outerW / 2;
            x += d.outerW + GAP;
            return { key, d, piece, x: centre, y };
        });
        const tooWide = rowW > free + 0.01;
        const tooTall = y + maxH / 2 > zone.maxTop + 0.01 || y - maxH / 2 < zone.minBottom - 0.01;
        return { placed, tooBig: tooWide || tooTall, rowW, maxH, y, cx };
    }

    private buildPieces(): void {
        const meta = this.meta;
        if (!meta) return;
        for (const child of [...this.art.children]) {
            this.art.remove(child);
            disposeTree(child);
        }
        this.pieceTextures.splice(0).forEach((t) => t.dispose());
        const { placed, tooBig } = this.layout(meta);
        this.notice.set(tooBig ? 'Your artwork is larger than the free space on this wall — try a bigger room, such as the Hotel lobby.' : '');
        for (const { piece, d, x, y } of placed) {
            const group = buildArtwork(d, {
                frame: piece.frame,
                mat: piece.mat,
                glazing: piece.glazing,
                depthMm: piece.depthMm,
                edgeStyle: piece.edgeStyle,
                texture: this.pictureTexture(piece.imageUrl, d.picW, d.picH),
            });
            group.position.set(x, y, -meta.artWall.distance + 0.002);
            group.traverse((o) => {
                const mesh = o as THREE.Mesh;
                if (!mesh.isMesh) return;
                mesh.castShadow = mesh.receiveShadow = false;
                // A material can be shared by several faces of one mesh (canvas sides): patch it once.
                const mats = new Set(Array.isArray(mesh.material) ? mesh.material : [mesh.material]);
                mats.forEach((m) => this.lightByRoom(m, mesh.name));
            });
            this.art.add(group);
        }
        this.requestFrame();
    }

    /**
     * Lights a material like the rendered wall behind it: its shaded colour is multiplied by the light
     * map at that point and passed through the render's tone curve (the panorama uses the same curve).
     */
    private lightByRoom(material: THREE.Material, meshName: string): void {
        const std = material as THREE.MeshStandardMaterial;
        if (meshName === 'wall-shadow') return; // a soft darkening, already in display space
        if (material.userData['room360']) return;
        material.userData['room360'] = true;
        if (std.isMeshStandardMaterial) {
            // Real reflections of this room in glass and metal frames.
            if (meshName === 'glass' || std.metalness > 0.3) {
                std.envMap = this.envMap;
                std.envMapIntensity = meshName === 'glass' ? std.envMapIntensity : 1;
            }
        }
        const previous = material.onBeforeCompile.bind(material);
        const previousKey = material.customProgramCacheKey.bind(material);
        material.onBeforeCompile = (shader, renderer) => {
            previous(shader, renderer);
            Object.assign(shader.uniforms, {
                roomLight: this.uniforms.roomLight,
                roomRect: this.uniforms.roomRect,
                roomLightScale: this.uniforms.roomLightScale,
                roomKnee: this.uniforms.roomKnee,
            });
            shader.vertexShader = shader.vertexShader
                .replace('void main() {', 'varying vec3 vRoomPos;\nvoid main() {')
                .replace('#include <project_vertex>', '#include <project_vertex>\nvRoomPos = (modelMatrix * vec4(transformed, 1.0)).xyz;');
            shader.fragmentShader = shader.fragmentShader
                .replace(
                    'void main() {',
                    `varying vec3 vRoomPos;\nuniform sampler2D roomLight;\nuniform vec4 roomRect;\nuniform float roomLightScale;\n${TONE_GLSL}\nvoid main() {`,
                )
                .replace(
                    '#include <tonemapping_fragment>',
                    `vec2 roomUv = clamp(vec2((vRoomPos.x - roomRect.x) / (roomRect.y - roomRect.x), (vRoomPos.y - roomRect.z) / (roomRect.w - roomRect.z)), 0.0, 1.0);
                    vec3 roomL = r360SrgbToLinear(texture2D(roomLight, roomUv).rgb) * roomLightScale;
                    gl_FragColor.rgb = r360Tone(gl_FragColor.rgb * roomL);`,
                );
        };
        material.customProgramCacheKey = () => previousKey() + '|room360';
        material.needsUpdate = true;
    }

    private pictureTexture(url: string | null, picW: number, picH: number): THREE.Texture {
        if (!url) return this.placeholder;
        const loaded = this.textures.get(url);
        if (loaded) {
            const cropped = coverTexture(loaded, picW, picH);
            if (cropped !== loaded) this.pieceTextures.push(cropped);
            return cropped;
        }
        if (this.pendingPictures.has(url)) return this.placeholder;
        this.pendingPictures.add(url);
        new THREE.TextureLoader().load(
            url,
            (texture) => {
                this.pendingPictures.delete(url);
                if (this.destroyed) {
                    texture.dispose();
                    return;
                }
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
                this.textures.get(url)?.dispose();
                this.textures.set(url, texture);
                this.pictureVersion.update((v) => v + 1);
            },
            undefined,
            () => this.pendingPictures.delete(url),
        );
        return this.placeholder;
    }

    // ------------------------------------------------------------------ view

    /** Turns to the artwork; `close` frames it tightly, otherwise shows it with the room around it. */
    lookAtArt(close: boolean, instant = false): void {
        const meta = this.meta;
        if (!meta) return;
        const { rowW, maxH, y, cx } = this.layout(meta);
        const dist = meta.artWall.distance;
        const yaw = Math.atan2(cx, dist);
        // Look slightly below the art in the room view so furniture stays in the picture.
        // Room view: like a photo of the room, wide, horizon a little low, the art in the upper half.
        const lookY = close ? y : y - 0.45;
        const pitch = Math.atan2(lookY, dist);
        const share = close ? 0.62 : 0.32;
        const vFov = 2 * Math.atan(maxH / share / 2 / dist);
        const hFov = 2 * Math.atan(rowW / (share * 1.3) / 2 / dist);
        const vFromH = 2 * Math.atan(Math.tan(hFov / 2) / Math.max(0.5, this.camera.aspect));
        const fit = THREE.MathUtils.radToDeg(Math.max(vFov, vFromH));
        const fov = this.clampFov(close ? Math.min(fit, 70) : THREE.MathUtils.clamp(fit, 72, 88));
        this.animateTo(yaw, pitch, fov, instant);
    }

    /**
     * Zoom stops where the panorama has about as many pixels as the screen (like a 3DVista tour), so the
     * room never turns soft: vertical field of view ≥ screen height ÷ panorama pixels per degree.
     */
    private clampFov(fov: number): number {
        const face = this.meta?.faceSize ?? 1536;
        // CSS pixels (a little extra on high-DPI screens), as photo tours do: limiting by device pixels
        // would forbid any zoom on a retina display.
        const css = this.renderer?.domElement.clientHeight || 600;
        const screenPx = css * Math.min(window.devicePixelRatio || 1, 1.25);
        const minFov = THREE.MathUtils.clamp(screenPx / ((face / 90) * 1.15), 22, 75);
        return THREE.MathUtils.clamp(fov, minFov, 95);
    }

    zoomBy(factor: number): void {
        this.animateTo(this.yaw, this.pitch, this.clampFov(this.fov * factor));
    }

    private animation = 0;
    private animateTo(yaw: number, pitch: number, fov: number, instant = false): void {
        cancelAnimationFrame(this.animation);
        this.velocity = { yaw: 0, pitch: 0 };
        if (instant) {
            this.yaw = yaw;
            this.pitch = pitch;
            this.fov = fov;
            this.requestFrame();
            return;
        }
        const from = { yaw: this.yaw, pitch: this.pitch, fov: this.fov };
        // Shortest way round.
        let dYaw = yaw - from.yaw;
        dYaw = Math.atan2(Math.sin(dYaw), Math.cos(dYaw));
        const start = performance.now();
        const step = (now: number) => {
            const t = Math.min(1, (now - start) / 700);
            const e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            this.yaw = from.yaw + dYaw * e;
            this.pitch = from.pitch + (pitch - from.pitch) * e;
            this.fov = from.fov + (fov - from.fov) * e;
            this.draw();
            if (t < 1) this.animation = requestAnimationFrame(step);
        };
        this.animation = requestAnimationFrame(step);
    }

    private bindControls(el: HTMLElement): void {
        const pointers = new Map<number, { x: number; y: number }>();
        let pinch = 0;
        let last = 0;
        const down = (e: PointerEvent) => {
            el.setPointerCapture(e.pointerId);
            pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            cancelAnimationFrame(this.animation);
            this.velocity = { yaw: 0, pitch: 0 };
            this.hint.set(false);
            last = performance.now();
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                pinch = Math.hypot(a.x - b.x, a.y - b.y);
            }
        };
        const move = (e: PointerEvent) => {
            const prev = pointers.get(e.pointerId);
            if (!prev) return;
            const now = { x: e.clientX, y: e.clientY };
            pointers.set(e.pointerId, now);
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                if (pinch > 0) this.fov = this.clampFov(this.fov * (pinch / d));
                pinch = d;
                this.requestFrame();
                return;
            }
            // Drag speed follows the zoom: the point under the finger stays under it.
            const perPx = THREE.MathUtils.degToRad(this.fov) / Math.max(1, el.clientHeight);
            // The room moves with the finger (like any panorama viewer).
            const dy = -(now.x - prev.x) * perPx;
            const dp = (now.y - prev.y) * perPx;
            this.yaw += dy;
            this.pitch += dp;
            const t = performance.now();
            const dt = Math.max(1, t - last);
            last = t;
            this.velocity = { yaw: (dy / dt) * 16, pitch: (dp / dt) * 16 };
            this.requestFrame();
        };
        const up = (e: PointerEvent) => {
            pointers.delete(e.pointerId);
            pinch = 0;
            if (pointers.size === 0 && performance.now() - last < 80) this.coast();
        };
        const wheel = (e: WheelEvent) => {
            e.preventDefault();
            this.hint.set(false);
            this.fov = this.clampFov(this.fov * Math.exp(e.deltaY * 0.0012));
            this.requestFrame();
        };
        const dbl = () => this.lookAtArt(true);
        el.addEventListener('pointerdown', down);
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerup', up);
        el.addEventListener('pointercancel', up);
        el.addEventListener('wheel', wheel, { passive: false });
        el.addEventListener('dblclick', dbl);
        this.cleanup.push(() => {
            el.removeEventListener('pointerdown', down);
            el.removeEventListener('pointermove', move);
            el.removeEventListener('pointerup', up);
            el.removeEventListener('pointercancel', up);
            el.removeEventListener('wheel', wheel);
            el.removeEventListener('dblclick', dbl);
        });
    }

    /** Gentle inertia after a flick. */
    private coast(): void {
        const step = () => {
            this.velocity.yaw *= 0.92;
            this.velocity.pitch *= 0.92;
            this.yaw += this.velocity.yaw;
            this.pitch += this.velocity.pitch;
            this.draw();
            if (Math.abs(this.velocity.yaw) + Math.abs(this.velocity.pitch) > 0.0002) this.animation = requestAnimationFrame(step);
        };
        this.animation = requestAnimationFrame(step);
    }

    // ------------------------------------------------------------------ drawing

    private requestFrame(): void {
        if (this.frameQueued || !this.renderer) return;
        this.frameQueued = requestAnimationFrame(() => {
            this.frameQueued = 0;
            this.draw();
        });
    }

    private draw(): void {
        if (!this.renderer || this.destroyed) return;
        this.pitch = THREE.MathUtils.clamp(this.pitch, -1.45, 1.45);
        this.camera.fov = this.fov;
        this.camera.updateProjectionMatrix();
        this.camera.rotation.set(0, 0, 0);
        this.camera.rotation.order = 'YXZ';
        this.camera.rotation.y = -this.yaw;
        this.camera.rotation.x = this.pitch;
        this.renderer.render(this.scene, this.camera);
    }

    protected sameColour(hex: string): boolean {
        return hex.toLowerCase() === this.wall().toLowerCase();
    }

    /** Saves a 3840 px wide picture of the current view. */
    saveImage(): void {
        const canvas = this.renderer.domElement;
        const w = canvas.clientWidth || 1;
        const h = canvas.clientHeight || 1;
        const ratio = this.renderer.getPixelRatio();
        // Long side 3840 px, but never beyond what the GPU can draw (phones: often 4096).
        const gl = this.renderer.getContext();
        const maxSize = Math.min(gl.getParameter(gl.MAX_RENDERBUFFER_SIZE) as number, ...(gl.getParameter(gl.MAX_VIEWPORT_DIMS) as number[]));
        this.renderer.setPixelRatio(Math.min(3840 / Math.max(w, h), maxSize / Math.max(w, h)));
        this.renderer.setSize(w, h, false);
        this.draw();
        const link = document.createElement('a');
        link.download = `artwork-${this.roomKey()}-360.jpg`;
        link.href = canvas.toDataURL('image/jpeg', 0.93);
        link.click();
        this.renderer.setPixelRatio(ratio);
        this.renderer.setSize(w, h, false);
        this.draw();
    }

    // ---- phone gyroscope: hold the phone up and turn around in the room ----
    protected readonly canGyro =
        typeof window !== 'undefined' && 'DeviceOrientationEvent' in window && window.matchMedia('(pointer: coarse)').matches;
    protected gyro = signal(false);
    private gyroStop: (() => void) | null = null;

    private async toggleGyro(): Promise<void> {
        if (this.gyroStop) {
            this.gyroStop();
            this.gyroStop = null;
            this.gyro.set(false);
            return;
        }
        // iOS asks for permission first.
        try {
            const ask = (DeviceOrientationEvent as unknown as { requestPermission?: () => Promise<string> }).requestPermission;
            if (ask && (await ask.call(DeviceOrientationEvent)) !== 'granted') return;
        } catch {
            return;
        }
        // The device's orientation as a quaternion (no gimbal lock when the phone is held upright),
        // corrected for the screen's rotation, then read as yaw/pitch like the drag controls.
        const q = new THREE.Quaternion();
        const euler = new THREE.Euler();
        const toCamera = new THREE.Quaternion(-Math.sqrt(0.5), 0, 0, Math.sqrt(0.5)); // camera looks out of the back of the phone
        const screenTurn = new THREE.Quaternion();
        const z = new THREE.Vector3(0, 0, 1);
        const view = new THREE.Euler();
        let base: { yaw: number; start: number } | null = null;
        const onTurn = (e: DeviceOrientationEvent) => {
            if (e.alpha === null || e.beta === null || e.gamma === null) return;
            const d = THREE.MathUtils.degToRad;
            const screenAngle = d(screen.orientation?.angle ?? 0);
            euler.set(d(e.beta), d(e.alpha), -d(e.gamma), 'YXZ');
            q.setFromEuler(euler).multiply(toCamera).multiply(screenTurn.setFromAxisAngle(z, -screenAngle));
            view.setFromQuaternion(q, 'YXZ');
            const yaw = -view.y;
            base ??= { yaw: this.yaw, start: yaw };
            this.yaw = base.yaw + (yaw - base.start);
            this.pitch = view.x;
            this.requestFrame();
        };
        window.addEventListener('deviceorientation', onTurn);
        this.gyroStop = () => window.removeEventListener('deviceorientation', onTurn);
        this.cleanup.push(() => this.gyroStop?.());
        this.gyro.set(true);
        this.hint.set(false);
    }

    private toggleFullscreen(): void {
        const el = this.root().nativeElement;
        if (document.fullscreenElement) void document.exitFullscreen();
        else void el.requestFullscreen?.();
    }

    ngOnDestroy(): void {
        this.destroyed = true;
        cancelAnimationFrame(this.frameQueued);
        cancelAnimationFrame(this.animation);
        this.cleanup.forEach((fn) => fn());
        if (!this.renderer) return;
        disposeTree(this.art);
        for (const child of this.sky.children) {
            const mesh = child as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial>;
            mesh.geometry.dispose();
            mesh.material.uniforms['map'].value?.dispose();
            mesh.material.uniforms['mask'].value?.dispose();
            mesh.material.dispose();
        }
        this.uniforms.roomLight.value?.dispose();
        clearTimeout(this.envTimer);
        this.envTarget?.dispose();
        this.pieceTextures.forEach((t) => t.dispose());
        this.textures.forEach((t) => t.dispose());
        this.placeholder.dispose();
        // Free the WebGL context itself: browsers allow only a few at once, and this view is
        // created and destroyed whenever the customer switches preview tabs.
        this.renderer.forceContextLoss();
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}
