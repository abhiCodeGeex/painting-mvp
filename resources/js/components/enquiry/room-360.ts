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
import { ArrowLeft, Box, Camera, Download, Expand, Eye, LoaderCircle, Minimize, RotateCcw, ScanSearch, Smartphone, ZoomIn, ZoomOut } from 'lucide';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { ArView } from './ar-view';
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
    room?: { width: number; front: number; back: number; height: number };
    artWall: { distance: number; floor: number; ceiling: number; minBottom: number; maxTop: number; left: number; right: number };
    light: { file: string; scale: number; rect: [number, number, number, number] };
    wallAlbedo: number;
    knee: number;
    /** Photographed rooms: only the art wall can be repainted (a feature wall), from its own colour. */
    paintable?: boolean;
    paintScope?: 'room' | 'art-wall';
    /** The painted wall's own colour (linear RGB) — repainting scales by new colour ÷ this. */
    wallColour?: [number, number, number];
    kind?: 'render' | 'photo';
    credit?: string;
    /** Sharper render of the art wall, laid over the forward face (texture coordinates of that face). */
    detail?: { file: string; mask: string; size: number; u0: number; u1: number; v0: number; v1: number };
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
const SNAP = 0.04;
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

/** A tileable plain-weave canvas normal map (threads over/under), drawn once. */
function weaveNormalMap(): THREE.Texture {
    const n = 128;
    const h = new Float32Array(n * n);
    const threads = 8;
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const u = (x / n) * threads;
            const v = (y / n) * threads;
            const warp = Math.sin(Math.PI * (u % 1)) ** 0.6; // round thread profile
            const weft = Math.sin(Math.PI * (v % 1)) ** 0.6;
            const over = (Math.floor(u) + Math.floor(v)) % 2 === 0;
            h[y * n + x] = over ? warp * 0.65 + weft * 0.35 : weft * 0.65 + warp * 0.35;
        }
    }
    const data = new Uint8Array(n * n * 4);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const hx = h[y * n + ((x + 1) % n)] - h[y * n + ((x - 1 + n) % n)];
            const hy = h[((y + 1) % n) * n + x] - h[((y - 1 + n) % n) * n + x];
            const v = new THREE.Vector3(-hx * 2, -hy * 2, 1).normalize();
            const i = (y * n + x) * 4;
            data[i] = (v.x * 0.5 + 0.5) * 255;
            data[i + 1] = (v.y * 0.5 + 0.5) * 255;
            data[i + 2] = (v.z * 0.5 + 0.5) * 255;
            data[i + 3] = 255;
        }
    }
    const t = new THREE.DataTexture(data, n, n);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.generateMipmaps = true;
    t.minFilter = THREE.LinearMipmapLinearFilter;
    t.magFilter = THREE.LinearFilter;
    t.needsUpdate = true;
    return t;
}

@Component({
    selector: 'room-360',
    imports: [Icon, ArView],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div #root [class]="'relative w-full overflow-hidden rounded-lg border bg-neutral-900 select-none ' + (fullscreen() ? 'h-full' : height())">
            <div
                #host
                class="absolute inset-0 cursor-grab touch-none active:cursor-grabbing"
                [style.opacity]="shown() ? 1 : 0"
                style="transition: opacity 0.5s"
            ></div>

            @if (switching(); as title) {
                <div
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-neutral-900/70 text-sm text-white backdrop-blur-sm"
                >
                    <svg [icon]="LoaderCircle" class="size-5 animate-spin" />
                    Loading {{ title }}…
                </div>
            }

            @if (!shown()) {
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-sm text-white/80">
                    <svg [icon]="LoaderCircle" class="size-5 animate-spin" />
                    {{ error() || 'Preparing the 360° room…' }}
                </div>
            }

            <!-- rooms -->
            <div class="absolute top-2 left-2 flex max-w-[calc(100%-1rem)] [scrollbar-width:none] gap-1 overflow-x-auto">
                @for (r of rooms(); track r.key) {
                    <button
                        type="button"
                        [class]="
                            'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap backdrop-blur transition ' +
                            (r.key === roomKey() ? 'bg-white text-neutral-900 shadow' : 'bg-black/45 text-white hover:bg-black/60')
                        "
                        [title]="r.kind === 'photo' ? r.title + ' — a real photographed room' : r.title"
                        (click)="chooseRoom(r.key)"
                    >
                        @if (r.kind === 'photo') {
                            <svg [icon]="Camera" class="-mt-px mr-0.5 inline size-3" />
                        }
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
                @if (paintable()) {
                    <div
                        class="flex items-center gap-1 rounded-full bg-black/45 px-2 py-1 backdrop-blur"
                        [title]="isPhoto() ? 'Paint the artwork wall' : 'Wall colour'"
                    >
                        @if (isPhoto()) {
                            <button
                                type="button"
                                title="The wall as photographed"
                                [class]="
                                    'rounded-full border px-2 text-[11px] leading-5 text-white transition ' +
                                    (wall() === 'original' ? 'border-white bg-white/25' : 'border-white/40')
                                "
                                (click)="wall.set('original')"
                            >
                                Original
                            </button>
                        }
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
                }
                @if (credit()) {
                    <div class="pointer-events-none absolute -top-5 left-1 text-[10px] text-white/70 [text-shadow:0_1px_2px_rgb(0_0_0/0.6)]">
                        Real photographed room · {{ credit() }}
                    </div>
                }
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
                class="pointer-events-none absolute bottom-14 left-1/2 hidden max-w-[80%] -translate-x-1/2 truncate rounded-full bg-black/40 px-2.5 py-1 text-[11px] text-white/80 transition-opacity sm:block"
                [style.opacity]="hint() ? 1 : 0"
            >
                @if (inspecting()) {
                    Drag to walk round · scroll to move closer · right-drag to slide
                } @else {
                    Drag to look around · drag the artwork to move it · zoom in to inspect
                }
            </div>

            @if (inspecting()) {
                <button
                    type="button"
                    class="absolute top-11 left-2 z-10 flex items-center gap-1 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-neutral-900 shadow"
                    (click)="exitInspect()"
                >
                    <svg [icon]="ArrowLeft" class="size-3.5" /> Back to the room
                </button>
            }
        </div>
        <ar-view [(open)]="arOpen" [glb]="arModel()" [title]="arTitle()" />
    `,
})
export class Room360 implements OnDestroy {
    readonly pieces = input.required<WallPiece[]>();
    readonly wallColour = input.required<string>();
    readonly height = input('h-[420px]');

    protected readonly LoaderCircle = LoaderCircle;
    protected readonly colours = WALL_COLOURS;
    protected rooms = signal<{ key: string; title: string; kind?: string }[]>([]);
    protected roomKey = signal<string>(this.savedRoom());
    protected wall = signal<string>('#EDE8E1');
    protected shown = signal(false);
    protected loadingHd = signal(false);
    protected error = signal('');
    protected hint = signal(true);
    protected fullscreen = signal(false);
    protected notice = signal('');
    protected paintable = signal(true);
    protected isPhoto = signal(false);
    /** Title of the room being switched to (an overlay blocks the old room until the new one is shown). */
    protected switching = signal('');
    protected credit = signal('');
    protected arOpen = signal(false);
    /** Close-up 3D inspection of one artwork (true 3D camera), instead of the fixed-eye 360° view. */
    protected inspecting = signal(false);
    protected readonly ArrowLeft = ArrowLeft;
    protected readonly Camera = Camera;
    private inspectKey: string | null = null;
    /** True while the camera glides to the artwork (the orbit controls wait until it has arrived). */
    private gliding = false;
    private controls: OrbitControls | null = null;
    private wallPlane: THREE.Mesh<THREE.PlaneGeometry, THREE.ShaderMaterial> | null = null;
    private weave: THREE.Texture | null = null;
    protected arModel = signal<Blob | null>(null);
    protected arTitle = computed(() => {
        const title = this.pieces()[0]?.title;
        return this.pieces().length > 1 ? 'your gallery wall' : title ? `“${title}”` : 'your artwork';
    });
    /** Where the customer dragged each artwork (metres on the art wall), by piece key. */
    private offsets = signal<Record<string, { x: number; y: number }>>({});
    private pieceGroups = new Map<string, THREE.Group>();
    private raycaster = new THREE.Raycaster();

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
        { label: 'Look at the artwork', icon: Eye, run: () => (this.inspecting() ? this.exitInspect() : this.lookAtArt(true)) },
        { label: 'Inspect up close in 3D', icon: ScanSearch, run: () => this.enterInspect(this.pieceGroups.keys().next().value ?? null) },
        { label: 'Zoom in', icon: ZoomIn, run: () => this.zoomBy(0.8) },
        { label: 'Zoom out', icon: ZoomOut, run: () => this.zoomBy(1.25) },
        { label: 'Reset view', icon: RotateCcw, run: () => this.lookAtArt(false) },
        { label: 'Save 4K picture', icon: Download, run: () => this.saveImage() },
        { label: 'View on your wall (AR)', icon: Box, run: () => void this.openAr() },
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
            // The hint fades on its own after a few seconds (and on first interaction).
            const hintTimer = setTimeout(() => this.hint.set(false), 6000);
            this.cleanup.push(() => clearTimeout(hintTimer));
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
            this.wall();
            this.updateWallRatio();
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
            const keys = new Set(this.dims().map((p) => p.key));
            this.pictureVersion();
            untracked(() => {
                const kept = Object.fromEntries(Object.entries(this.offsets()).filter(([k]) => keys.has(k)));
                if (Object.keys(kept).length !== Object.keys(this.offsets()).length) this.offsets.set(kept);
            });
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
            const list = (await res.json()) as { key: string; title: string; kind?: string }[];
            if (!list.length) throw new Error('no rooms');
            this.rooms.set(list);
            if (!list.some((r) => r.key === this.roomKey())) this.roomKey.set(list[0].key);
        } catch {
            this.error.set('The 360° rooms are not available right now.');
        }
    }

    protected chooseRoom(key: string): void {
        if (key === this.roomKey()) return;
        this.exitInspect();
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
        if (this.meta) this.switching.set(this.rooms().find((r) => r.key === key)?.title ?? 'the room');
        try {
            const meta = (await (await fetch(`${dir}/meta.json`)).json()) as RoomMeta;
            // Small faces first: the room appears within a moment, then sharpens.
            const [light, ...small] = await Promise.all([
                this.loadTexture(`${dir}/${meta.light.file}`, false),
                ...FACE_ORDER.flatMap((f) => [this.loadTexture(`${dir}/${f}-sm.webp`, true), this.loadTexture(`${dir}/${f}-mask-sm.webp`, false)]),
            ]);
            if (token !== this.loadToken || this.destroyed) return;

            this.switching.set('');
            const firstLoad = !this.meta;
            this.exitInspect();
            this.camera.position.set(0, 0, 0);
            this.meta = meta;
            this.paintable.set(meta.paintable !== false);
            this.isPhoto.set(meta.kind === 'photo');
            // A photographed room opens as photographed; the customer can then repaint its art wall.
            if (meta.kind === 'photo') this.wall.set('original');
            else if (this.wall() === 'original') this.wall.set(this.wallColour() || '#EDE8E1');
            this.credit.set(meta.credit ?? '');
            const [xa, xb, za, zb] = meta.light.rect;
            this.uniforms.roomLight.value?.dispose();
            this.uniforms.roomLight.value = light;
            this.uniforms.roomRect.value.set(xa, xb, za, zb);
            this.uniforms.roomLightScale.value = meta.light.scale;
            this.uniforms.roomKnee.value = meta.knee;
            this.updateWallRatio();

            this.buildSky(meta, (f) => {
                const i = FACE_ORDER.indexOf(f);
                return { map: small[2 * i], mask: small[2 * i + 1] };
            });
            this.buildEnvironment();
            this.buildPieces();
            this.offsets.set({});
            this.buildPieces();
            this.lookAtArt(false, firstLoad);
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
            if (meta.detail) {
                const [map, mask] = await Promise.all([
                    this.loadTexture(`${dir}/${meta.detail.file}`, true),
                    this.loadTexture(`${dir}/${meta.detail.mask}`, false),
                ]);
                if (token !== this.loadToken || this.destroyed) {
                    map.dispose();
                    mask.dispose();
                    return;
                }
                this.renderer.initTexture(map);
                this.renderer.initTexture(mask);
                this.addDetail(meta, map, mask);
            }
            if (token === this.loadToken) this.loadingHd.set(false);
            this.loadedRoom = key;
        } catch {
            if (token !== this.loadToken) return;
            this.switching.set('');
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

    /** The sharper art-wall render, exactly over its part of the forward face. */
    private addDetail(meta: RoomMeta, map: THREE.Texture, mask: THREE.Texture): void {
        const d = meta.detail!;
        const info = meta.faces['f'];
        const dir = new THREE.Vector3(...info.dir);
        const up = new THREE.Vector3(...info.up);
        const right = new THREE.Vector3().crossVectors(dir, up);
        const at = (u: number, v: number) =>
            dir
                .clone()
                .add(right.clone().multiplyScalar(2 * u - 1))
                .add(up.clone().multiplyScalar(2 * v - 1))
                .multiplyScalar(SKY_RADIUS);
        const corners = [at(d.u0, d.v0), at(d.u1, d.v0), at(d.u1, d.v1), at(d.u0, d.v1)];
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
        const mesh = new THREE.Mesh(geometry, this.skyMaterial(map, mask));
        mesh.name = 'detail';
        mesh.renderOrder = -9; // over the forward face
        mesh.frustumCulled = false;
        this.sky.add(mesh);
        this.requestFrame();
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
        const offsets = this.offsets();
        const placed = dims.map(({ key, d, piece }) => {
            const centre = x + d.outerW / 2;
            x += d.outerW + GAP;
            return { key, d, piece, x: offsets[key]?.x ?? centre, y: offsets[key]?.y ?? y };
        });
        const tooWide = !Object.keys(offsets).length && rowW > free + 0.01;
        const tooTall = y + maxH / 2 > zone.maxTop + 0.01 || y - maxH / 2 < zone.minBottom - 0.01;
        // Bounds of the arrangement (after any dragging), for framing the view.
        const minX = Math.min(...placed.map((p) => p.x - p.d.outerW / 2));
        const maxX = Math.max(...placed.map((p) => p.x + p.d.outerW / 2));
        const minY = Math.min(...placed.map((p) => p.y - p.d.outerH / 2));
        const maxY = Math.max(...placed.map((p) => p.y + p.d.outerH / 2));
        return {
            placed,
            tooBig: tooWide || (!Object.keys(offsets).length && tooTall),
            rowW: placed.length ? maxX - minX : rowW,
            maxH: placed.length ? maxY - minY : maxH,
            y: placed.length ? (minY + maxY) / 2 : y,
            cx: placed.length ? (minX + maxX) / 2 : cx,
        };
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
        this.pieceGroups.clear();
        for (const { piece, d, x, y, key } of placed) {
            const group = buildArtwork(d, {
                frame: piece.frame,
                mat: piece.mat,
                glazing: piece.glazing,
                depthMm: piece.depthMm,
                edgeStyle: piece.edgeStyle,
                texture: this.pictureTexture((this.inspecting() && piece.detailUrl) || piece.imageUrl, d.picW, d.picH, piece.imageUrl),
            });
            group.position.set(x, y, -meta.artWall.distance + 0.002);
            group.userData['pieceKey'] = key;
            this.pieceGroups.set(key, group);
            group.traverse((o) => {
                const mesh = o as THREE.Mesh;
                if (!mesh.isMesh) return;
                mesh.castShadow = mesh.receiveShadow = false;
                // A material can be shared by several faces of one mesh (canvas sides): patch it once.
                const mats = new Set(Array.isArray(mesh.material) ? mesh.material : [mesh.material]);
                mats.forEach((m) => this.lightByRoom(m, mesh.name));
            });
            this.art.add(group);
            if (this.inspecting() && (piece.depthMm > 0 || !piece.frame)) this.addCanvasWeave(group);
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

    private pictureTexture(url: string | null, picW: number, picH: number, fallback: string | null = null): THREE.Texture {
        if (!url) return this.placeholder;
        if (fallback && fallback !== url && !this.textures.has(url) && this.textures.has(fallback)) {
            this.pictureTexture(url, picW, picH); // start loading the sharper one
            return this.pictureTexture(fallback, picW, picH);
        }
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
        return THREE.MathUtils.clamp(fov, this.minFov(), 95);
    }

    /** Pixels per 90° of the image under the centre of the view: the art-wall detail is ~4× the room. */
    private sharpnessInView(): number {
        const meta = this.meta;
        const face = meta?.faceSize ?? 1536;
        const d = meta?.detail;
        if (!d || !this.sky.getObjectByName('detail')) return face;
        const dir = new THREE.Vector3(0, 0, -1).applyEuler(new THREE.Euler(this.pitch, -this.yaw, 0, 'YXZ'));
        if (dir.z >= -0.05) return face;
        const u = (dir.x / -dir.z + 1) / 2;
        const v = (dir.y / -dir.z + 1) / 2;
        const inside = u > d.u0 && u < d.u1 && v > d.v0 && v < d.v1;
        return inside ? d.size / (d.u1 - d.u0) : face;
    }

    private minFov(): number {
        // CSS pixels (a little extra on high-DPI screens), as photo tours do: limiting by device pixels
        // would forbid any zoom on a retina display.
        const css = this.renderer?.domElement.clientHeight || 600;
        const screenPx = css * Math.min(window.devicePixelRatio || 1, 1.25);
        return THREE.MathUtils.clamp(screenPx / ((this.sharpnessInView() / 90) * 1.1), 10, 75);
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
        let drag: { key: string; dx: number; dy: number } | null = null;
        const wallPoint = (e: PointerEvent): THREE.Vector3 | null => {
            if (!this.meta) return null;
            const rect = el.getBoundingClientRect();
            const ndc = new THREE.Vector2(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
            this.raycaster.setFromCamera(ndc, this.camera);
            const plane = new THREE.Plane(new THREE.Vector3(0, 0, 1), this.meta.artWall.distance);
            return this.raycaster.ray.intersectPlane(plane, new THREE.Vector3());
        };
        const pickPiece = (e: PointerEvent): string | null => {
            const rect = el.getBoundingClientRect();
            const ndc = new THREE.Vector2(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
            this.raycaster.setFromCamera(ndc, this.camera);
            for (const hit of this.raycaster.intersectObject(this.art, true)) {
                let o: THREE.Object3D | null = hit.object;
                while (o && !o.userData['pieceKey']) o = o.parent;
                if (o) return o.userData['pieceKey'] as string;
            }
            return null;
        };
        const down = (e: PointerEvent) => {
            if (this.inspecting()) return; // the orbit controls have it
            el.setPointerCapture(e.pointerId);
            if (pointers.size === 0) {
                const key = pickPiece(e);
                const group = key ? this.pieceGroups.get(key) : null;
                const p = group ? wallPoint(e) : null;
                if (key && group && p) {
                    drag = { key, dx: p.x - group.position.x, dy: p.y - group.position.y };
                    el.style.cursor = 'move';
                    this.hint.set(false);
                    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
                    return;
                }
            }
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
            if (this.inspecting()) return;
            const prev = pointers.get(e.pointerId);
            if (!prev) return;
            if (drag) {
                const p = wallPoint(e);
                if (p) this.movePiece(drag.key, p.x - drag.dx, p.y - drag.dy);
                return;
            }
            const now = { x: e.clientX, y: e.clientY };
            pointers.set(e.pointerId, now);
            if (pointers.size === 2) {
                const [a, b] = [...pointers.values()];
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                if (pinch > 0) {
                    const want = this.fov * (pinch / d);
                    // Pinching in past the panorama's sharpness on an artwork: walk up to it in 3D.
                    if (want < this.minFov() - 0.5 && pinch < d) {
                        const mid = { clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 } as PointerEvent;
                        const key = pickPiece(mid);
                        if (key) {
                            pointers.clear();
                            this.enterInspect(key);
                            return;
                        }
                    }
                    this.fov = this.clampFov(want);
                }
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
            if (drag) {
                drag = null;
                el.style.cursor = '';
                return;
            }
            pinch = 0;
            if (pointers.size === 0 && performance.now() - last < 80) this.coast();
        };
        const wheel = (e: WheelEvent) => {
            if (this.inspecting()) return;
            e.preventDefault();
            this.hint.set(false);
            const want = this.fov * Math.exp(e.deltaY * 0.0012);
            // Zooming in past the panorama's sharpness on an artwork: walk up to it in 3D.
            if (e.deltaY < 0 && want < this.minFov() - 0.5) {
                const key = pickPiece(e as unknown as PointerEvent);
                if (key) {
                    this.enterInspect(key);
                    return;
                }
            }
            this.fov = this.clampFov(want);
            this.requestFrame();
        };
        const dbl = (e: MouseEvent) => {
            if (this.inspecting()) return;
            const key = pickPiece(e as PointerEvent);
            if (key) this.enterInspect(key);
            else this.lookAtArt(true);
        };
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

    /** Moves one artwork on the wall: snaps to the others' edges and centres and to the wall centre,
     *  and stays inside the free part of the wall. */
    private movePiece(key: string, x: number, y: number): void {
        const meta = this.meta;
        const group = this.pieceGroups.get(key);
        const me = this.dims().find((p) => p.key === key);
        if (!meta || !group || !me) return;
        const { outerW: w, outerH: h } = me.d;
        const others = [...this.pieceGroups.entries()].filter(([k]) => k !== key);
        const xs: number[] = [0];
        const ys: number[] = [];
        for (const [k, g] of others) {
            const d = this.dims().find((p) => p.key === k)?.d;
            if (!d) continue;
            xs.push(g.position.x - d.outerW / 2, g.position.x, g.position.x + d.outerW / 2);
            ys.push(g.position.y - d.outerH / 2, g.position.y, g.position.y + d.outerH / 2);
        }
        const snap = (value: number, half: number, lines: number[]) => {
            let best = value;
            let gap = SNAP;
            for (const line of lines) {
                for (const edge of [-half, 0, half]) {
                    const delta = Math.abs(line - (value + edge));
                    if (delta < gap) {
                        gap = delta;
                        best = line - edge;
                    }
                }
            }
            return best;
        };
        const zone = meta.artWall;
        x = THREE.MathUtils.clamp(snap(x, w / 2, xs), zone.left + w / 2, Math.max(zone.left + w / 2, zone.right - w / 2));
        y = THREE.MathUtils.clamp(snap(y, h / 2, ys), zone.minBottom + h / 2, Math.max(zone.minBottom + h / 2, zone.maxTop - h / 2));
        group.position.x = x;
        group.position.y = y;
        this.offsets.update((o) => ({ ...o, [key]: { x, y } }));
        this.requestFrame();
    }

    // ------------------------------------------------------------------ close-up inspection (true 3D)

    /**
     * Walks up to one artwork: a free 3D camera orbits it, so its edges, sides, glass and frame and the
     * texture of the picture can be seen from any angle. The art wall behind it is a real plane carrying the
     * room's render, projected from the original eye — exact for the wall, so it stays right as you move.
     */
    enterInspect(key: string | null): void {
        const meta = this.meta;
        const group = key ? this.pieceGroups.get(key) : null;
        if (!meta || !group || !key) return;
        // Already up close (e.g. the button pressed again): start over from the room, never stack sessions.
        if (this.inspecting()) this.exitInspect();
        cancelAnimationFrame(this.animation);
        this.inspectKey = key;
        this.inspecting.set(true);
        this.hint.set(true);
        this.buildWallPlane(meta);
        this.buildPieces(); // swaps in the 4096 px picture and the canvas weave

        const piece = this.dims().find((p) => p.key === key)!.d;
        const g = this.pieceGroups.get(key)!;
        const target = new THREE.Vector3(g.position.x, g.position.y, g.position.z + 0.02);
        const fov = 40;
        const fill = 0.72;
        const dist =
            Math.max(piece.outerH / fill, piece.outerW / (fill * Math.max(0.6, this.camera.aspect))) /
            2 /
            Math.tan(THREE.MathUtils.degToRad(fov / 2));
        // Never start further back than the room allows (in small rooms the wall can be closer than the framing).
        const F = meta.artWall.distance;
        const startDist = Math.min(dist, F - 0.3);

        const controls = new OrbitControls(this.camera, this.renderer.domElement);
        controls.target.copy(target);
        controls.enableDamping = true;
        controls.dampingFactor = 0.12;
        controls.rotateSpeed = 0.6;
        controls.zoomSpeed = 0.9;
        controls.screenSpacePanning = true;
        controls.minDistance = 0.05; // nose to the canvas
        // Close enough that the (true 3D) art wall always fills the view.
        controls.maxDistance = Math.max(startDist + 0.25, Math.min(F - 0.05, Math.max(1.3, startDist * 1.5)));
        // Stay in front of the wall (and away from the furniture baked into the wall picture).
        // Enough to see the frame's edges and depth; further round, the room behind would show its seams.
        controls.minAzimuthAngle = -THREE.MathUtils.degToRad(40);
        controls.maxAzimuthAngle = THREE.MathUtils.degToRad(40);
        controls.minPolarAngle = THREE.MathUtils.degToRad(65);
        controls.maxPolarAngle = THREE.MathUtils.degToRad(115);
        const bounds = { x: piece.outerW / 2 + 0.1, y: piece.outerH / 2 + 0.1 };
        controls.addEventListener('change', () => {
            // Sliding across the picture stays on the picture.
            controls.target.x = THREE.MathUtils.clamp(controls.target.x, g.position.x - bounds.x, g.position.x + bounds.x);
            controls.target.y = THREE.MathUtils.clamp(controls.target.y, g.position.y - bounds.y, g.position.y + bounds.y);
            controls.target.z = target.z;
            if (this.gliding) return;
            // Backing right off returns to the room.
            if (this.camera.position.distanceTo(controls.target) > controls.maxDistance - 0.05) {
                queueMicrotask(() => this.exitInspect());
                return;
            }
            this.requestFrame();
        });
        controls.addEventListener('start', () => this.hint.set(false));
        this.controls = controls;

        // Glide from the eye to the artwork.
        const from = new THREE.Vector3(0, 0, 0);
        const to = target.clone().add(new THREE.Vector3(0, 0, startDist));
        const startFov = this.camera.fov;
        const t0 = performance.now();
        this.gliding = true;
        controls.enabled = false;
        const step = (now: number) => {
            const t = Math.min(1, (now - t0) / 650);
            const e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            this.camera.position.lerpVectors(from, to, e);
            this.camera.fov = startFov + (fov - startFov) * e;
            this.camera.updateProjectionMatrix();
            this.camera.lookAt(target);
            this.sky.position.copy(this.camera.position);
            this.renderer.render(this.scene, this.camera);
            if (t < 1) {
                this.animation = requestAnimationFrame(step);
                return;
            }
            this.gliding = false;
            controls.enabled = true;
            controls.update();
            this.requestFrame();
        };
        this.animation = requestAnimationFrame(step);
    }

    exitInspect(): void {
        if (!this.inspecting()) return;
        cancelAnimationFrame(this.animation);
        this.gliding = false;
        this.controls?.dispose();
        this.controls = null;
        this.inspecting.set(false);
        this.removeWallPlane();
        this.camera.position.set(0, 0, 0);
        this.sky.position.set(0, 0, 0);
        this.buildPieces();
        const key = this.inspectKey;
        const g = key ? this.pieceGroups.get(key) : null;
        this.inspectKey = null;
        if (g && this.meta) {
            // Back at the eye, looking at the artwork, as close as the room's picture stays sharp.
            this.yaw = Math.atan2(g.position.x, this.meta.artWall.distance);
            this.pitch = Math.atan2(g.position.y, this.meta.artWall.distance);
            this.fov = this.clampFov(this.minFov() * 1.15);
            this.requestFrame();
            this.lookAtArt(false);
            return;
        }
        this.requestFrame();
    }

    private removeWallPlane(): void {
        // Every inspection wall (not just the last one), so nothing from another room can linger.
        for (const o of this.scene.children.filter((c) => c.name === 'inspect-wall')) {
            const mesh = o as THREE.Mesh<THREE.PlaneGeometry, THREE.ShaderMaterial>;
            this.scene.remove(mesh);
            mesh.geometry.dispose();
            mesh.material.dispose();
        }
        this.wallPlane = null;
    }

    /** The art wall as a real plane, textured with the room's render (the sharp detail where there is one). */
    private buildWallPlane(meta: RoomMeta): void {
        this.removeWallPlane();
        const face = this.sky.getObjectByName('f') as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial> | undefined;
        if (!face) return;
        const detail = this.sky.getObjectByName('detail') as THREE.Mesh<THREE.BufferGeometry, THREE.ShaderMaterial> | undefined;
        const F = meta.artWall.distance;
        const W = meta.room?.width ?? 2 * F;
        const left = Math.max(-W / 2, -F);
        const right = Math.min(W / 2, F);
        const bottom = Math.max(meta.artWall.floor, -F);
        const top = Math.min(meta.artWall.ceiling, F);
        const d = meta.detail;
        const material = new THREE.ShaderMaterial({
            uniforms: {
                faceMap: { value: face.material.uniforms['map'].value },
                faceMask: { value: face.material.uniforms['mask'].value },
                detailMap: { value: detail?.material.uniforms['map'].value ?? null },
                detailMask: { value: detail?.material.uniforms['mask'].value ?? null },
                detailRect: { value: d && detail ? new THREE.Vector4(d.u0, d.u1, d.v0, d.v1) : new THREE.Vector4(2, 2, 2, 2) },
                distance: { value: F },
                roomLight: this.uniforms.roomLight,
                roomRect: this.uniforms.roomRect,
                roomLightScale: this.uniforms.roomLightScale,
                wallBase: { value: new THREE.Vector3(...(meta.wallColour ?? [meta.wallAlbedo, meta.wallAlbedo, meta.wallAlbedo])) },
                paintable: { value: meta.paintable === false ? 0 : 1 },
                wallRatio: this.uniforms.wallRatio,
                roomKnee: this.uniforms.roomKnee,
            },
            vertexShader: /* glsl */ `
                varying vec3 vPos;
                void main() {
                    vPos = (modelMatrix * vec4(position, 1.0)).xyz;
                    gl_Position = projectionMatrix * viewMatrix * vec4(vPos, 1.0);
                }
            `,
            fragmentShader: /* glsl */ `
                uniform sampler2D faceMap;
                uniform sampler2D faceMask;
                uniform sampler2D detailMap;
                uniform sampler2D detailMask;
                uniform vec4 detailRect;
                uniform sampler2D roomLight;
                uniform vec4 roomRect;
                uniform float roomLightScale;
                uniform vec3 wallBase;
                uniform float paintable;
                uniform float distance;
                uniform vec3 wallRatio;
                varying vec3 vPos;
                ${TONE_GLSL}
                void main() {
                    // Where the original eye saw this point of the wall.
                    vec2 uv = (vPos.xy / distance + 1.0) * 0.5;
                    vec3 c;
                    float m;
                    if (uv.x > detailRect.x && uv.x < detailRect.y && uv.y > detailRect.z && uv.y < detailRect.w) {
                        vec2 d = (uv - detailRect.xz) / (detailRect.yw - detailRect.xz);
                        c = texture2D(detailMap, d).rgb;
                        m = texture2D(detailMask, d).r;
                    } else {
                        c = texture2D(faceMap, uv).rgb;
                        m = texture2D(faceMask, uv).r;
                    }
                    vec3 lin = r360ToneInverse(r360SrgbToLinear(c));
                    lin = mix(lin, lin * wallRatio, m);
                    if (paintable > 0.5) {
                        vec2 ruv = clamp(vec2((vPos.x - roomRect.x) / (roomRect.y - roomRect.x), (vPos.y - roomRect.z) / (roomRect.w - roomRect.z)), 0.0, 1.0);
                        vec3 plain = r360SrgbToLinear(texture2D(roomLight, ruv).rgb) * roomLightScale * wallBase * wallRatio;
                        lin = mix(plain, lin, smoothstep(0.35, 0.9, m));
                    }
                    gl_FragColor = vec4(r360LinearToSrgb(r360Tone(lin)), 1.0);
                }
            `,
        });
        const plane = new THREE.Mesh(new THREE.PlaneGeometry(right - left, top - bottom), material);
        plane.position.set((left + right) / 2, (bottom + top) / 2, -F);
        plane.renderOrder = -5;
        plane.name = 'inspect-wall';
        this.wallPlane = plane;
        this.scene.add(plane);
    }

    /** A fine canvas weave in the light, so the picture's surface reads as canvas up close. */
    private addCanvasWeave(group: THREE.Group): void {
        this.weave ??= weaveNormalMap();
        group.traverse((o) => {
            const mesh = o as THREE.Mesh;
            if (!mesh.isMesh) return;
            for (const m of Array.isArray(mesh.material) ? mesh.material : [mesh.material]) {
                const std = m as THREE.MeshStandardMaterial;
                if (!std.isMeshStandardMaterial || !std.map || std.normalMap) continue;
                const t = this.weave!.clone();
                // About 18 threads per centimetre over the picture.
                const geo = mesh.geometry as THREE.BufferGeometry;
                geo.computeBoundingBox();
                const size = geo.boundingBox!.getSize(new THREE.Vector3());
                t.repeat.set(Math.max(1, size.x / 0.012), Math.max(1, size.y / 0.012));
                t.needsUpdate = true;
                std.normalMap = t;
                std.normalScale = new THREE.Vector2(0.35, 0.35);
                std.needsUpdate = true;
                this.pieceTextures.push(t);
            }
        });
    }

    /** Exports the artwork(s) — not the room — as a GLB for the AR viewer, centred on the arrangement. */
    private async openAr(): Promise<void> {
        this.arOpen.set(true);
        const { GLTFExporter } = await import('three/examples/jsm/exporters/GLTFExporter.js');
        const groups = [...this.pieceGroups.values()];
        if (!groups.length) return;
        const cx = groups.reduce((s, g) => s + g.position.x, 0) / groups.length;
        const cy = groups.reduce((s, g) => s + g.position.y, 0) / groups.length;
        const root = new THREE.Group();
        for (const g of groups) {
            const copy = g.clone(true);
            copy.position.set(g.position.x - cx, g.position.y - cy, 0);
            root.add(copy);
        }
        const glb = (await new GLTFExporter().parseAsync(root, { binary: true, onlyVisible: true, maxTextureSize: 2048 })) as ArrayBuffer;
        this.arModel.set(new Blob([glb], { type: 'model/gltf-binary' }));
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
        if (this.inspecting() && this.controls) {
            if (this.gliding) return; // the glide draws its own frames
            this.controls.update();
            // The panorama stays "at infinity" around the moving camera; the art wall itself is real geometry.
            this.sky.position.copy(this.camera.position);
            this.renderer.render(this.scene, this.camera);
            return;
        }
        this.sky.position.set(0, 0, 0);
        this.pitch = THREE.MathUtils.clamp(this.pitch, -1.45, 1.45);
        const min = this.minFov();
        if (this.fov < min - 0.05) {
            this.fov += (min - this.fov) * 0.25;
            this.requestFrame();
        }
        this.camera.fov = this.fov;
        this.camera.updateProjectionMatrix();
        this.camera.rotation.set(0, 0, 0);
        this.camera.rotation.order = 'YXZ';
        this.camera.rotation.y = -this.yaw;
        this.camera.rotation.x = this.pitch;
        this.renderer.render(this.scene, this.camera);
    }

    /** New colour ÷ the colour the wall was rendered or photographed in ("original" = unchanged). */
    private updateWallRatio(): void {
        const meta = this.meta;
        const wall = this.wall();
        if (wall === 'original' || !meta) {
            this.uniforms.wallRatio.value.set(1, 1, 1);
            return;
        }
        const c = hexToLinear(wall);
        const base = meta.wallColour ?? [meta.wallAlbedo, meta.wallAlbedo, meta.wallAlbedo];
        this.uniforms.wallRatio.value.set(c.x / base[0], c.y / base[1], c.z / base[2]);
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
        this.controls?.dispose();
        this.wallPlane?.geometry.dispose();
        this.wallPlane?.material.dispose();
        this.weave?.dispose();
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
