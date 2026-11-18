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
import {
    Camera,
    Download,
    Eye,
    Lamp,
    Maximize2,
    Minimize2,
    Moon,
    Move,
    RotateCcw,
    Smartphone,
    Sofa,
    Sun,
    Sunrise,
    Sunset,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { ArView } from './ar-view';
import type { WallPiece } from './preview-helpers';
import { ContactShadows } from './three/contact-shadows';
import { loadFurniture, loadRoomBase, ROOM_STYLES, type FloorMaps, type Furniture, type RoomStyle } from './three/room-assets';
import {
    buildArtwork,
    buildEnvironment,
    buildLamp,
    buildRoom,
    buildSofa,
    coverTexture,
    disposeTree,
    EYE,
    gradientTexture,
    M,
    ROOM_DEPTH,
    skyTexture,
    SOFA,
    WINDOW,
    woodTexture,
} from './three/scene-parts';

/** Distance within which a dragged piece snaps to another piece's edge or centre line (m). */
const SNAP = 0.025;
/** Gap between pieces in the default gallery layout (m). */
const GALLERY_GAP = 0.12;
const STYLE_KEY = 'room-style';

type TimeOfDay = 'morning' | 'noon' | 'evening' | 'night';

interface TimeLook {
    label: string;
    elevation: number;
    azimuth: number;
    sun: string;
    sunIntensity: number;
    fill: number;
    env: number;
    exposure: number;
    sky: [string, string, string?];
}

/** Daylight through the window per time of day: sun height, colour, strength and the room's overall brightness. */
const TIMES: Record<TimeOfDay, TimeLook> = {
    morning: {
        label: 'Morning',
        elevation: 16,
        azimuth: -25,
        sun: '#ffd8a6',
        sunIntensity: 3.4,
        fill: 0.5,
        env: 0.5,
        exposure: 1.0,
        sky: ['#9cc6ec', '#ffd9b0', '#fbe7cc'],
    },
    noon: {
        label: 'Midday',
        elevation: 52,
        azimuth: -10,
        sun: '#fff7ea',
        sunIntensity: 3.0,
        fill: 0.6,
        env: 0.6,
        exposure: 1.0,
        sky: ['#5d9fe0', '#cfe6fa'],
    },
    evening: {
        label: 'Evening',
        elevation: 8,
        azimuth: 20,
        sun: '#ff9550',
        sunIntensity: 2.8,
        fill: 0.38,
        env: 0.35,
        exposure: 1.05,
        sky: ['#3c4f86', '#ff9a62', '#f6b77e'],
    },
    night: {
        label: 'Night',
        elevation: -10,
        azimuth: 0,
        sun: '#9fb4ff',
        sunIntensity: 0,
        fill: 0.12,
        env: 0.1,
        exposure: 1.15,
        sky: ['#060a18', '#1b2647'],
    },
};
const TIME_ICONS = { morning: Sunrise, noon: Sun, evening: Sunset, night: Moon };

/** Most pixels drawn per frame (full HD worth): keeps full screen smooth on integrated graphics. */
const MAX_PIXELS = 1920 * 1080;
/** Picture lights are a fixed pool (one per artwork, up to this many). */
const PICTURE_LIGHTS = 4;

/**
 * Render resolution (device-pixel multiplier): exactly the screen's own density (1:1 pixels, so the
 * browser never rescales the image), max 2×, within the pixel budget. One constant resolution:
 * changing it while the user moves (re-allocating the drawing buffer) caused dropped frames.
 */
function renderScale(width: number, height: number, lite: boolean): number {
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const area = Math.max(1, width * height);
    return Math.max(0.75, Math.min(dpr, lite ? 1.5 : 2, Math.sqrt(MAX_PIXELS / area)));
}

/** Phones and small laptops render fewer pixels and smaller shadow maps. */
function liteDevice(): boolean {
    try {
        // Manual override for support / testing: localStorage['room-quality'] = 'high' | 'lite'.
        const forced = localStorage.getItem('room-quality');
        if (forced === 'high' || forced === 'lite') return forced === 'lite';
    } catch {
        /* ignore */
    }
    return window.matchMedia('(pointer: coarse)').matches || (navigator.hardwareConcurrency ?? 8) < 4;
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

function dimsOf(p: WallPiece): PieceDims {
    const picW = M(Math.max(50, p.widthMm || 0));
    const picH = M(Math.max(50, p.heightMm || 0));
    const matB = p.mat ? M(p.mat.border_mm) : 0;
    const matBottom = p.mat ? M(p.mat.bottom_weight_mm ?? 0) : 0;
    const frameW = p.frame ? M(p.frame.width_mm) : 0;
    return { picW, picH, matB, matBottom, frameW, outerW: picW + 2 * matB + 2 * frameW, outerH: picH + 2 * matB + matBottom + 2 * frameW };
}

function savedStyle(): RoomStyle {
    try {
        const s = localStorage.getItem(STYLE_KEY) as RoomStyle | null;
        return s && ROOM_STYLES.some((r) => r.value === s) ? s : 'living';
    } catch {
        return 'living';
    }
}

/**
 * Interactive 3D room preview (plain Three.js). Everything is in metres and true to scale.
 * One piece = a single artwork; several pieces = a gallery wall with snapping guides.
 *
 * Controls: drag = look around · scroll / pinch = zoom · right-drag / two fingers = move ·
 * drag an artwork = reposition it on the wall.
 */
@Component({
    selector: 'room-3d',
    imports: [Icon, ArView],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'block' },
    template: `
        <div #container [class]="'relative overflow-hidden rounded-lg border bg-neutral-900 ' + (fullscreen() ? 'h-screen' : height())">
            <div #canvasHost class="absolute inset-0" style="touch-action: none"></div>
            @if (!shown()) {
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 bg-stone-200 text-xs text-stone-600">
                    <span class="size-6 animate-spin rounded-full border-2 border-stone-400 border-t-transparent"></span>
                    Preparing the 3D room…
                </div>
            }

            <div class="absolute top-2 left-2 flex items-center gap-2">
                <select
                    class="h-7 rounded-md border border-white/15 bg-black/55 px-2 text-xs text-white backdrop-blur"
                    title="Room style"
                    aria-label="Room style"
                    (change)="chooseStyle($any($event.target).value)"
                >
                    @for (s of styles; track s.value) {
                        <option [value]="s.value" [selected]="s.value === style()">{{ s.label }}</option>
                    }
                </select>
                <div class="flex rounded-md border border-white/15 bg-black/55 p-0.5 backdrop-blur" role="group" aria-label="Time of day">
                    @for (t of times; track t.value) {
                        <button
                            type="button"
                            [title]="t.label"
                            [attr.aria-label]="t.label"
                            [attr.aria-pressed]="timeOfDay() === t.value"
                            [class]="
                                'flex size-6 items-center justify-center rounded text-white transition ' +
                                (timeOfDay() === t.value ? 'bg-amber-500/80' : 'hover:bg-white/15')
                            "
                            (click)="setTime(t.value)"
                        >
                            <svg [icon]="t.icon" class="size-3.5" />
                        </button>
                    }
                </div>
                @if (loading()) {
                    <span class="flex items-center gap-1.5 rounded bg-black/55 px-2 py-1 text-[11px] text-white">
                        <span class="size-2 animate-pulse rounded-full bg-amber-400"></span> Loading furniture…
                    </span>
                }
            </div>

            <div class="absolute top-2 right-2 flex flex-col gap-1">
                @for (b of buttons(); track b.label) {
                    <button
                        type="button"
                        [title]="b.label"
                        [attr.aria-label]="b.label"
                        [class]="
                            'flex size-8 items-center justify-center rounded-md border border-white/15 text-white shadow backdrop-blur transition ' +
                            (b.active ? 'bg-amber-500/80 hover:bg-amber-500' : 'bg-black/50 hover:bg-black/70')
                        "
                        (click)="b.run()"
                    >
                        <svg [icon]="b.icon" class="size-4" />
                    </button>
                }
            </div>

            @if (showHint()) {
                <button
                    type="button"
                    title="Hide tips"
                    class="absolute bottom-2 left-2 flex max-w-[calc(100%-3.5rem)] items-center gap-1.5 rounded bg-black/55 px-2 py-1 text-left text-[11px] text-white"
                    (click)="showHint.set(false)"
                >
                    <svg [icon]="CameraIcon" class="size-3.5 shrink-0" />
                    <span
                        >Drag: look around · Scroll/pinch: zoom · Right-drag: move · Drag {{ pieces().length > 1 ? 'an artwork' : 'the artwork' }} to
                        move it</span
                    >
                    <svg [icon]="XIcon" class="size-3 shrink-0 opacity-70" />
                </button>
            }
        </div>

        <ar-view [(open)]="arOpen" [glb]="arModel()" [title]="arTitle()" />
    `,
})
export class Room3D implements OnDestroy {
    readonly pieces = input.required<WallPiece[]>();
    readonly wallColour = input.required<string>();
    readonly wallWidthMm = input<number | null>(null);
    readonly wallHeightMm = input<number | null>(null);
    /** Tailwind height class when not in full screen. */
    readonly height = input('h-[360px]');

    protected readonly CameraIcon = Camera;
    protected readonly XIcon = X;
    protected readonly styles = ROOM_STYLES;
    protected readonly times = (Object.keys(TIMES) as TimeOfDay[]).map((value) => ({ value, label: TIMES[value].label, icon: TIME_ICONS[value] }));
    protected timeOfDay = signal<TimeOfDay>('noon');

    protected showFurniture = signal(true);
    protected pictureLight = signal(false);
    protected fullscreen = signal(false);
    protected showHint = signal(true);
    protected style = signal<RoomStyle>(savedStyle());
    protected loading = signal(true);
    /** True once the room is loaded, compiled and faded in. */
    protected shown = signal(false);
    protected arOpen = signal(false);
    protected arModel = signal<Blob | null>(null);
    /** Piece positions the customer dragged (by piece id); others use the default layout. */
    private offsets = signal<Record<string, { x: number; y: number }>>({});
    private dragging: { id: string; dx: number; dy: number } | null = null;
    private ready = signal(false);
    private textureVersion = signal(0);
    private furniture = signal<Furniture | null>(null);
    private floor = signal<FloorMaps | null>(null);

    private container = viewChild.required<ElementRef<HTMLDivElement>>('container');
    private canvasHost = viewChild.required<ElementRef<HTMLDivElement>>('canvasHost');

    // ---- sizes & layout (metres) ----
    private dims = computed(() => this.pieces().map((p) => ({ piece: p, key: String(p.id), d: dimsOf(p) })));
    private rowWidth = computed(() => this.dims().reduce((sum, x) => sum + x.d.outerW, 0) + GALLERY_GAP * Math.max(0, this.dims().length - 1));
    private maxOuterH = computed(() => Math.max(0.05, ...this.dims().map((x) => x.d.outerH)));
    private hangAbove = computed(() => this.furniture()?.hangAbove ?? SOFA.h);
    private wallW = computed(() =>
        Math.max(M(this.wallWidthMm() || 0) || 3.6, this.rowWidth() + 1.2, (this.furniture()?.width ?? SOFA.w) + (this.furniture() ? 0.5 : 1.2)),
    );
    private wallH = computed(() => Math.max(M(this.wallHeightMm() || 0) || 2.7, this.maxOuterH() + this.hangAbove() + 1.0));
    /** Default layout: one row, centred, at least 20 cm above the furniture, centre ~1.5 m. */
    private layout = computed(() => {
        const y = Math.max(1.5, this.hangAbove() + 0.2 + this.maxOuterH() / 2);
        let x = -this.rowWidth() / 2;
        const offsets = this.offsets();
        return this.dims().map(({ key, d }) => {
            const centre = x + d.outerW / 2;
            x += d.outerW + GALLERY_GAP;
            return { key, d, x: offsets[key]?.x ?? centre, y: offsets[key]?.y ?? y };
        });
    });

    protected arTitle = computed(() => {
        const title = this.pieces()[0]?.title;
        return this.pieces().length > 1 ? 'your gallery wall' : title ? `“${title}”` : 'your artwork';
    });

    protected buttons = computed(() => [
        { label: 'Reset view', icon: RotateCcw, active: false, run: () => this.resetView() },
        { label: this.pieces().length > 1 ? 'Look at the artworks' : 'Look at the artwork', icon: Eye, active: false, run: () => this.closeUp() },
        { label: 'Zoom in', icon: ZoomIn, active: false, run: () => this.zoom(0.75) },
        { label: 'Zoom out', icon: ZoomOut, active: false, run: () => this.zoom(1.33) },
        {
            label: this.pictureLight() ? 'Picture light off' : 'Picture light on',
            icon: Lamp,
            active: this.pictureLight(),
            run: () => this.pictureLight.update((v) => !v),
        },
        {
            label: this.showFurniture() ? 'Hide furniture' : 'Show furniture',
            icon: Sofa,
            active: this.showFurniture(),
            run: () => this.showFurniture.update((v) => !v),
        },
        { label: this.pieces().length > 1 ? 'Reset the layout' : 'Centre the artwork', icon: Move, active: false, run: () => this.offsets.set({}) },
        { label: 'View on your wall (AR)', icon: Smartphone, active: false, run: () => void this.openAr() },
        { label: 'Save image', icon: Download, active: false, run: () => this.saveImage() },
        {
            label: this.fullscreen() ? 'Exit full screen' : 'Full screen',
            icon: this.fullscreen() ? Minimize2 : Maximize2,
            active: false,
            run: () => this.toggleFullscreen(),
        },
    ]);

    // ---- three.js objects ----
    private renderer!: THREE.WebGLRenderer;
    private scene = new THREE.Scene();
    private camera = new THREE.PerspectiveCamera(45, 1, 0.05, 50);
    private controls!: OrbitControls;
    private roomGroup: THREE.Group | null = null;
    private furnitureGroup: THREE.Group | null = null;
    private piecesGroup = new THREE.Group();
    private pieceGroups = new Map<string, THREE.Group>();
    private lightsGroup = new THREE.Group();
    private guidesGroup = new THREE.Group();
    private ambient = new THREE.AmbientLight('#ffffff', 0.55);
    private hemi = new THREE.HemisphereLight('#fff7ed', '#7c6a58', 0.6);
    /** Daylight coming in through the window. */
    private sun = new THREE.DirectionalLight('#ffffff', 3);
    private ceilingLamp = new THREE.PointLight('#ffd7a0', 0, 6, 1.6);
    private lite = liteDevice();
    /** Shadows and contact shadows are only re-rendered when something in the scene changed. */
    private sceneDirty = true;
    /** Objects replaced in the scene, freed after the next frame. */
    private retired: (() => void)[] = [];
    private shadowsWidth = 0;
    private piecesKey = '';
    /** Warm-up objects whose compiled shaders stay alive for the visit. */
    private warmKeep: THREE.Object3D | null = null;
    /** Furniture volumes (padded) the camera may not enter. */
    private obstacles: THREE.Box3[] = [];
    /** Fixed pool of picture lights: a constant light count means shaders never recompile. */
    private pictureSpots: THREE.SpotLight[] = [];
    private viewSize = { width: 1, height: 1 };
    private envBase = 0.55;
    private skies = new Map<TimeOfDay, THREE.Texture>();
    private intro: { start: number; fromPos: THREE.Vector3; toPos: THREE.Vector3; fromTarget: THREE.Vector3; toTarget: THREE.Vector3 } | null = null;
    private shadows: ContactShadows | null = null;
    private dragPlane = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial());
    private floorTexture = woodTexture();
    private placeholder = gradientTexture();
    private textures = new Map<string, THREE.Texture>();
    private raycaster = new THREE.Raycaster();
    private pointer = new THREE.Vector2();
    private needsRender = true;
    private frameId = 0;
    private destroyed = false;
    private styleRequest = 0;
    private cleanup: (() => void)[] = [];

    constructor() {
        afterNextRender(() => this.init());

        // A different set or size of artworks starts from the default layout again.
        effect(() => {
            this.dims()
                .map((x) => `${x.key}:${x.d.outerW.toFixed(3)}x${x.d.outerH.toFixed(3)}`)
                .join('|');
            untracked(() => this.offsets.set({}));
        });

        // The room is rebuilt only when its size or floor changes…
        effect(() => {
            if (!this.ready()) return;
            this.floor();
            this.buildRoom(
                this.wallW(),
                this.wallH(),
                untracked(() => this.wallColour()),
            );
        });
        // …a new wall colour just repaints the walls (no rebuild, no shadow work).
        effect(() => {
            if (!this.ready()) return;
            const colour = this.wallColour();
            this.roomGroup?.traverse((child) => {
                const material = (child as THREE.Mesh).material as THREE.MeshStandardMaterial | undefined;
                if (material?.name === 'wall') material.color.set(colour);
            });
            this.invalidate();
        });
        effect(() => {
            if (!this.ready()) return;
            this.furniture();
            if (this.furnitureGroup) this.furnitureGroup.visible = this.showFurniture();
            this.markSceneDirty();
        });
        // Artworks are rebuilt only when something about them changed (size, frame, mat, glass,
        // image…). The form passes a fresh copy on every edit — e.g. a wall colour change — and
        // rebuilding then re-uploaded geometry and redrew all shadows for nothing (~80 ms).
        effect(() => {
            if (!this.ready()) return;
            const key = `${this.textureVersion()}|${JSON.stringify(
                this.pieces().map((p) => [p.id, p.imageUrl, p.widthMm, p.heightMm, p.frame, p.mat, p.depthMm, p.edgeStyle, p.glazing]),
            )}`;
            if (key === this.piecesKey) return;
            this.piecesKey = key;
            this.buildPieces();
        });
        effect(() => {
            if (!this.ready()) return;
            this.floor();
            this.wallW();
            this.applyTime(this.timeOfDay());
        });
        effect(() => {
            if (!this.ready()) return;
            const layout = this.layout();
            for (const p of layout) this.pieceGroups.get(p.key)?.position.set(p.x, p.y, 0);
            this.buildLights(this.pictureLight(), layout);
            // While an artwork is dragged its own wall shadow moves with it; the room's shadows
            // are redrawn once when the drag ends (not on every pointer move).
            if (this.dragging) this.invalidate();
            else this.markSceneDirty();
        });
    }

    // ---- setup ----

    private init(): void {
        const host = this.canvasHost().nativeElement;
        // Rendered straight to the screen with hardware multisampling (MSAA): clean, stable edges and
        // no post-processing noise. preserveDrawingBuffer keeps "Save image" working.
        this.renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
        // Layout size (clientWidth/Height), not getBoundingClientRect(): the latter includes CSS
        // transforms such as the dialog's opening zoom, which left the canvas ~5% too small and stretched.
        this.viewSize = { width: Math.max(1, host.clientWidth), height: Math.max(1, host.clientHeight) };
        this.renderer.setPixelRatio(renderScale(this.viewSize.width, this.viewSize.height, this.lite));
        this.renderer.setSize(this.viewSize.width, this.viewSize.height, false);
        this.camera.aspect = this.viewSize.width / this.viewSize.height;
        this.camera.updateProjectionMatrix();
        this.renderer.shadowMap.enabled = true;
        // three r187 replaced PCFSoftShadowMap with a softer PCFShadowMap.
        this.renderer.shadowMap.type = THREE.PCFShadowMap;
        // Shadow maps are redrawn only when the scene changes (not on every camera move).
        this.renderer.shadowMap.autoUpdate = false;
        // Khronos "PBR Neutral": made for product imagery, keeps the artwork's real colours
        // (ACES desaturated and darkened them).
        this.renderer.toneMapping = THREE.NeutralToneMapping;
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        // The canvas always fills its box exactly; the drawing buffer follows the box size.
        // Hidden until the room, furniture and shaders are ready, then faded in (no half-loaded frames).
        Object.assign(this.renderer.domElement.style, {
            display: 'block',
            width: '100%',
            height: '100%',
            opacity: '0',
            transition: 'opacity 450ms ease',
        });
        host.appendChild(this.renderer.domElement);

        this.scene.environment = buildEnvironment(this.renderer);
        this.camera.position.set(0, EYE, 3);

        this.sun.castShadow = true;
        this.sun.shadow.mapSize.set(this.lite ? 1024 : 2048, this.lite ? 1024 : 2048);
        this.sun.shadow.bias = -0.0005;
        this.sun.shadow.normalBias = 0.03;
        this.sun.shadow.radius = 3;
        // Tight shadow frustum around the room = sharper shadows from the same map size.
        Object.assign(this.sun.shadow.camera, { left: -3.5, right: 3.5, top: 3.5, bottom: -3.5, near: 0.5, far: 14 });
        this.sun.shadow.camera.updateProjectionMatrix();
        this.ceilingLamp.position.set(0, 2.4, ROOM_DEPTH / 2);
        this.scene.add(this.ambient, this.hemi, this.sun, this.sun.target, this.ceilingLamp, this.lightsGroup, this.piecesGroup, this.guidesGroup);

        this.dragPlane.visible = false;
        this.scene.add(this.dragPlane);

        // Simple sofa until the real furniture has loaded.
        this.furnitureGroup = buildSofa();
        this.furnitureGroup.visible = this.showFurniture();
        this.scene.add(this.furnitureGroup);
        this.obstacles = [new THREE.Box3().setFromObject(this.furnitureGroup).expandByScalar(0.22)];

        // Stay in front of the wall and above the floor.
        const controls = new OrbitControls(this.camera, this.renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.12;
        controls.screenSpacePanning = true;
        controls.minDistance = 0.35;
        controls.maxDistance = ROOM_DEPTH - 0.1;
        controls.minAzimuthAngle = -Math.PI * 0.42;
        controls.maxAzimuthAngle = Math.PI * 0.42;
        controls.minPolarAngle = Math.PI * 0.18;
        controls.maxPolarAngle = Math.PI * 0.62;
        controls.addEventListener('change', () => {
            const wallW = this.wallW();
            const wallH = this.wallH();
            controls.target.x = THREE.MathUtils.clamp(controls.target.x, -wallW / 2, wallW / 2);
            controls.target.y = THREE.MathUtils.clamp(controls.target.y, 0.2, wallH - 0.1);
            controls.target.z = THREE.MathUtils.clamp(controls.target.z, 0, 1.5);
            // Stay inside the room: never through the side walls, the back wall or the far end.
            this.camera.position.x = THREE.MathUtils.clamp(this.camera.position.x, -wallW / 2 + 0.15, wallW / 2 - 0.15);
            this.camera.position.z = THREE.MathUtils.clamp(this.camera.position.z, 0.15, ROOM_DEPTH - 0.05);
            this.camera.position.y = THREE.MathUtils.clamp(this.camera.position.y, 0.15, wallH - 0.05);
            // ...and never inside the furniture: step out in front of it.
            if (this.furnitureGroup?.visible) {
                for (const box of this.obstacles) {
                    if (box.containsPoint(this.camera.position)) this.camera.position.z = box.max.z + 0.01;
                }
            }
            this.invalidate();
        });
        this.controls = controls;

        this.listenPointer();
        this.listenResize();

        const onFullscreen = () => {
            this.fullscreen.set(document.fullscreenElement === this.container().nativeElement);
            this.invalidate();
        };
        document.addEventListener('fullscreenchange', onFullscreen);
        this.cleanup.push(() => document.removeEventListener('fullscreenchange', onFullscreen));

        // Support / QA hook: localStorage['room-debug'] = '1' exposes the renderer for measurements.
        try {
            if (localStorage.getItem('room-debug') === '1') {
                (window as unknown as Record<string, unknown>)['__room3d'] = {
                    renderer: this.renderer,
                    scene: this.scene,
                    camera: this.camera,
                    render: () => this.renderFrame(),
                };
            }
        } catch {
            /* ignore */
        }

        // Lights never come and go (that recompiles every shader — over a second on integrated
        // graphics): picture lights and the sun stay in the scene and are dimmed to 0 instead.
        for (let i = 0; i < PICTURE_LIGHTS; i++) {
            const spot = new THREE.SpotLight('#ffe6c4', 0, 3.2, 0.85, 0.9, 1.5);
            this.scene.add(spot, spot.target);
            this.pictureSpots.push(spot);
        }

        this.ready.set(true);
        this.buildRoom(this.wallW(), this.wallH(), this.wallColour());
        this.applyTime(this.timeOfDay());
        this.resetView();
        // Load lighting, furniture and compile every shader first; then fade in and glide, so the
        // opening animation never stutters on downloads or shader compiles.
        void Promise.allSettled([this.loadBase(), this.loadStyle(this.style()), this.warmUp()]).then(async () => {
            if (this.destroyed) return;
            await this.precompile(this.scene);
            if (this.destroyed) return;
            this.renderFrame();
            this.renderer.domElement.style.opacity = '1';
            this.shown.set(true);
            this.startIntro();
        });

        const loop = (now: number) => {
            this.frameId = requestAnimationFrame(loop);
            const moving = this.stepIntro(now);
            // update() returns true while the camera is still moving (damping); render only then.
            const cameraMoving = this.controls.update() || moving;
            if (cameraMoving || this.needsRender) {
                this.needsRender = false;
                this.renderFrame();
            }
        };
        this.frameId = requestAnimationFrame(loop);
    }

    /** Something visible changed (camera, colour, light…): draw a new frame. */
    private invalidate(): void {
        this.needsRender = true;
    }

    /** The scene's contents changed: shadows need redrawing too. */
    private markSceneDirty(): void {
        this.sceneDirty = true;
        this.invalidate();
    }

    /**
     * Compiles the shaders of new objects in the background (KHR_parallel_shader_compile) before
     * they are shown, so adding a frame, glass or furniture never freezes the view.
     */
    private async precompile(object: THREE.Object3D): Promise<void> {
        try {
            await this.renderer.compileAsync(object, this.camera, this.scene);
        } catch {
            /* shown anyway; compiled on first draw */
        }
    }

    /** Sends an object's textures to the GPU a few per frame, so the first draw doesn't stall on uploads. */
    private async uploadTextures(object: THREE.Object3D): Promise<void> {
        const textures = new Set<THREE.Texture>();
        object.traverse((child) => {
            const mesh = child as THREE.Mesh;
            if (!mesh.isMesh) return;
            for (const material of Array.isArray(mesh.material) ? mesh.material : [mesh.material]) {
                for (const value of Object.values(material)) {
                    if ((value as THREE.Texture)?.isTexture) textures.add(value as THREE.Texture);
                }
            }
        });
        let i = 0;
        for (const texture of textures) {
            if (this.destroyed) return;
            this.renderer.initTexture(texture);
            // Yield between uploads (a timer, not requestAnimationFrame: keeps loading in background tabs).
            if (++i % 2 === 0) await new Promise((resolve) => setTimeout(resolve, 0));
        }
    }

    /**
     * One-off warm-up: every artwork look (print, canvas, floater, mat, ornate / wooden mouldings,
     * glass with room reflections, lamp) compiled up front while the room loads.
     */
    private async warmUp(): Promise<void> {
        // Sky gradients for every time of day, drawn and uploaded now (not on the first click).
        for (const [time, t] of Object.entries(TIMES) as [TimeOfDay, (typeof TIMES)[TimeOfDay]][]) {
            if (!this.skies.has(time)) {
                const sky = skyTexture(t.sky[0], t.sky[1], t.sky[2]);
                this.renderer.initTexture(sky);
                this.skies.set(time, sky);
            }
        }
        const dims = { picW: 0.4, picH: 0.5, matB: 0.05, matBottom: 0, frameW: 0.03, outerW: 0.56, outerH: 0.66 } as PieceDims;
        const frame = (profile: string, material: string, finish: string) =>
            ({ colour: '#8a6a4a', width_mm: 30, depth_mm: 30, finish, profile, material }) as unknown as WallPiece['frame'];
        const group = new THREE.Group();
        const variants: Parameters<typeof buildArtwork>[1][] = [
            {
                frame: frame('ornate', 'Polymer', 'metallic'),
                mat: { colour: '#fff', core_colour: '#fff', border_mm: 50 } as WallPiece['mat'],
                glazing: 'standard',
                depthMm: 0,
                edgeStyle: null,
                texture: this.placeholder,
            },
            { frame: frame('scoop', 'Wood', 'satin'), mat: null, glazing: 'low', depthMm: 0, edgeStyle: null, texture: this.placeholder },
            { frame: frame('floater', 'Wood', 'matte'), mat: null, glazing: 'none', depthMm: 38, edgeStyle: 'image_wrap', texture: this.placeholder },
            { frame: null, mat: null, glazing: 'none', depthMm: 20, edgeStyle: 'solid_colour', texture: this.placeholder },
        ];
        for (const v of variants) group.add(buildArtwork(dims, v));
        group.add(buildLamp(0.5));
        group.position.set(0, -50, 0); // compiled, never seen
        this.scene.add(group);
        await this.precompile(group);
        this.scene.remove(group);
        // Kept (not disposed) for the whole visit: it holds the compiled shaders alive, so the first
        // real frame / glass / canvas never compiles anything.
        this.warmKeep = group;
    }

    // ---- rendering ----

    private renderFrame(): void {
        if (this.sceneDirty) {
            // Shadows depend on the scene, not on the camera: redraw them only after a change.
            this.sceneDirty = false;
            this.renderer.shadowMap.needsUpdate = true;
            this.shadows?.update(this.renderer, this.scene);
        }
        this.renderer.render(this.scene, this.camera);
        // Replaced objects are freed only now, after their replacements were drawn with the same
        // shaders: freeing first would delete those shaders and force a recompile (a visible hitch).
        if (this.retired.length) {
            for (const free of this.retired.splice(0)) free();
        }
    }

    /** Frees an object after the next frame (see renderFrame). */
    private retire(free: () => void): void {
        this.retired.push(free);
        this.invalidate();
    }

    // ---- time of day ----

    setTime(time: TimeOfDay): void {
        this.timeOfDay.set(time);
        // Evenings and nights look best with the picture light on.
        if (time === 'night' || time === 'evening') this.pictureLight.set(true);
    }

    /** Sun through the window, sky colour, room fill and exposure for the chosen time. */
    private applyTime(time: TimeOfDay): void {
        const t = TIMES[time];
        const wallW = untracked(() => this.wallW());
        // Light comes from outside the right-hand window, shining in and down towards the back wall.
        const target = new THREE.Vector3(-0.4, 0.4, 1.2);
        const elevation = THREE.MathUtils.degToRad(Math.max(4, t.elevation));
        const azimuth = THREE.MathUtils.degToRad(t.azimuth);
        const dir = new THREE.Vector3(
            Math.cos(elevation) * Math.cos(azimuth),
            Math.sin(elevation),
            Math.cos(elevation) * Math.sin(azimuth) + 0.25,
        ).normalize();
        this.sun.position.copy(new THREE.Vector3(wallW / 2, WINDOW.sill + WINDOW.height / 2, WINDOW.z).add(dir.multiplyScalar(6)));
        this.sun.target.position.copy(target);
        this.sun.target.updateMatrixWorld();
        this.sun.color.set(t.sun);
        this.sun.intensity = t.sunIntensity;
        // Kept visible (intensity 0 at night): hiding it would change the light count and recompile every shader.
        this.sun.visible = true;
        this.envBase = t.env;
        this.scene.environmentIntensity = t.env;
        this.hemi.intensity = t.fill;
        this.ambient.intensity = t.fill * 0.7;
        this.ceilingLamp.intensity = time === 'night' ? 1.6 : 0;
        this.renderer.toneMappingExposure = t.exposure;

        let sky = this.skies.get(time);
        if (!sky) {
            sky = skyTexture(t.sky[0], t.sky[1], t.sky[2]);
            this.skies.set(time, sky);
        }
        this.roomGroup?.traverse((child) => {
            if (child.name === 'sky') {
                const material = (child as THREE.Mesh).material as THREE.MeshBasicMaterial;
                material.map = sky;
                material.color.set('#ffffff');
                material.needsUpdate = true;
            }
        });
        this.queueReflections();
        this.markSceneDirty();
    }

    // ---- scene changes ----

    /**
     * Something in the room changed: shadows are redrawn with the next frame. (The glass reflects
     * the room's lighting environment; a live cube-camera capture cost 6 extra renders plus
     * re-filtering after every change — ~80 ms hitches on integrated graphics.)
     */
    private queueReflections(): void {
        this.sceneDirty = true;
    }

    // ---- cinematic intro ----

    /** The camera glides in from the doorway to the normal view (skipped for reduced motion). */
    private startIntro(): void {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const toPos = this.camera.position.clone();
        const toTarget = this.controls.target.clone();
        const wallW = this.wallW();
        const fromPos = new THREE.Vector3(wallW / 2 - 0.45, EYE + 0.15, ROOM_DEPTH - 0.08);
        const fromTarget = toTarget.clone().add(new THREE.Vector3(0.6, 0.15, 0));
        this.intro = { start: performance.now(), fromPos, toPos, fromTarget, toTarget };
        this.controls.enabled = false;
        this.camera.position.copy(fromPos);
        this.controls.target.copy(fromTarget);
        const stop = () => this.endIntro();
        this.renderer.domElement.addEventListener('pointerdown', stop, { once: true });
        this.renderer.domElement.addEventListener('wheel', stop, { once: true, passive: true });
    }

    private stepIntro(now: number): boolean {
        if (!this.intro) return false;
        const p = Math.min(1, (now - this.intro.start) / 2600);
        const e = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; // ease in-out
        this.camera.position.lerpVectors(this.intro.fromPos, this.intro.toPos, e);
        this.controls.target.lerpVectors(this.intro.fromTarget, this.intro.toTarget, e);
        this.camera.lookAt(this.controls.target);
        if (p >= 1) this.endIntro();
        return true;
    }

    private endIntro(): void {
        if (!this.intro) return;
        this.camera.position.copy(this.intro.toPos);
        this.controls.target.copy(this.intro.toTarget);
        this.intro = null;
        this.controls.enabled = true;
        this.controls.update();
        this.invalidate();
    }

    // ---- real assets ----

    /** Wood floor and interior lighting (shared by all styles). */
    private async loadBase(): Promise<void> {
        try {
            const base = await loadRoomBase(this.renderer);
            if (this.destroyed) {
                base.environment.dispose();
                return;
            }
            this.scene.environment?.dispose();
            this.scene.environment = base.environment;
            this.scene.environmentIntensity = this.envBase;
            this.floor.set(base.floor);
        } catch (error) {
            console.warn('3D room: floor/lighting could not be loaded, using the simple room.', error);
        }
        this.invalidate();
    }

    chooseStyle(style: RoomStyle): void {
        this.style.set(style);
        try {
            localStorage.setItem(STYLE_KEY, style);
        } catch {
            /* ignore */
        }
        this.offsets.set({});
        void this.loadStyle(style);
    }

    private async loadStyle(style: RoomStyle): Promise<void> {
        const request = ++this.styleRequest;
        this.loading.set(true);
        try {
            const furniture = await loadFurniture(style);
            if (this.destroyed || request !== this.styleRequest) return;
            // Compile its materials off the main thread first, then swap it in.
            furniture.group.visible = false;
            this.scene.add(furniture.group);
            furniture.group.visible = true;
            await this.precompile(furniture.group);
            this.scene.remove(furniture.group);
            if (this.destroyed || request !== this.styleRequest) return;
            await this.uploadTextures(furniture.group);
            if (this.destroyed || request !== this.styleRequest) return;
            if (this.furnitureGroup) {
                this.scene.remove(this.furnitureGroup);
                const old = this.furnitureGroup;
                if (!this.furniture()) this.retire(() => disposeTree(old)); // only the placeholder sofa owns its materials
            }
            this.furnitureGroup = furniture.group;
            this.furnitureGroup.visible = this.showFurniture();
            this.scene.add(this.furnitureGroup);
            this.furniture.set(furniture);
            this.obstacles = furniture.group.children.map((piece) => new THREE.Box3().setFromObject(piece).expandByScalar(0.22));
            this.queueReflections();
        } catch (error) {
            console.warn(`3D room: "${style}" furniture could not be loaded, using the simple room.`, error);
        } finally {
            if (request === this.styleRequest) this.loading.set(false);
            this.invalidate();
        }
    }

    // ---- scene parts ----

    private buildRoom(wallW: number, wallH: number, colour: string): void {
        if (this.roomGroup) {
            const old = this.roomGroup;
            this.scene.remove(old);
            this.retire(() => disposeTree(old));
        }
        this.roomGroup = buildRoom(
            wallW,
            wallH,
            colour,
            this.floorTexture,
            this.floor() ?? undefined,
            this.skies.get(untracked(() => this.timeOfDay())),
        );
        this.scene.add(this.roomGroup);

        // Contact shadows only depend on the room width: kept (with their buffers) while it is unchanged.
        if (!this.shadows || this.shadowsWidth !== wallW) {
            if (this.shadows) {
                const old = this.shadows;
                this.scene.remove(old.group);
                this.retire(() => old.dispose());
            }
            this.shadows = new ContactShadows(wallW, 3, { opacity: 0.35, blur: 2.4, far: 1.2, resolution: 512 });
            this.shadows.group.position.set(0, 0.001, 1.2);
            this.scene.add(this.shadows.group);
            this.shadowsWidth = wallW;
        }

        this.dragPlane.geometry.dispose();
        this.dragPlane.geometry = new THREE.PlaneGeometry(wallW * 3, wallH * 3);
        this.dragPlane.position.set(0, wallH / 2, 0.05);
        this.queueReflections();
        this.invalidate();
    }

    private buildPieces(): void {
        for (const group of this.pieceGroups.values()) {
            this.piecesGroup.remove(group);
            this.retire(() => disposeTree(group));
        }
        this.pieceGroups.clear();
        const layout = untracked(() => this.layout());
        for (const { piece, key, d } of this.dims()) {
            const group = buildArtwork(d, {
                frame: piece.frame,
                mat: piece.mat,
                glazing: piece.glazing,
                depthMm: piece.depthMm,
                edgeStyle: piece.edgeStyle,
                texture: this.pictureTexture(piece.imageUrl, d.picW, d.picH),
            });
            group.userData['pieceKey'] = key;
            const at = layout.find((l) => l.key === key);
            if (at) group.position.set(at.x, at.y, 0);
            this.piecesGroup.add(group);
            this.pieceGroups.set(key, group);
        }
        this.queueReflections();
        this.invalidate();
    }

    private pictureTexture(url: string | null, picW: number, picH: number): THREE.Texture {
        if (!url) return this.placeholder;
        const loaded = this.textures.get(url);
        if (loaded) return coverTexture(loaded, picW, picH);
        new THREE.TextureLoader().load(url, (texture) => {
            // Sharp at grazing angles and up close.
            texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();
            this.textures.set(url, texture);
            this.textureVersion.update((v) => v + 1);
        });
        return this.placeholder;
    }

    private buildLights(on: boolean, layout: { d: PieceDims; x: number; y: number }[]): void {
        // Picture lights read as a gentle accent, so dim the room fill a little when they are on.
        const look = TIMES[untracked(() => this.timeOfDay())];
        this.hemi.intensity = on ? look.fill * 0.7 : look.fill;
        this.ambient.intensity = this.hemi.intensity * 0.7;
        for (const child of [...this.lightsGroup.children]) {
            this.lightsGroup.remove(child);
            this.retire(() => disposeTree(child));
        }
        // Gentle, even wash from the fixed pool of picture lights (one per artwork): bright enough
        // to feel like a gallery, not so strong that it bleaches the colours.
        const lit = on ? layout.slice(0, PICTURE_LIGHTS) : [];
        this.pictureSpots.forEach((spot, i) => {
            const piece = lit[i];
            spot.intensity = piece ? 2.4 : 0;
            if (!piece) return;
            spot.position.set(piece.x, piece.y + piece.d.outerH / 2 + 0.25, 0.45);
            spot.target.position.set(piece.x, piece.y, 0);
            spot.target.updateMatrixWorld();
        });
        for (const { d, x, y } of lit) {
            const lamp = buildLamp(d.outerW);
            lamp.position.set(x, y + d.outerH / 2 + 0.07, 0);
            this.lightsGroup.add(lamp);
        }
        // Shadows are refreshed by the caller (immediately, or once a drag ends).
        this.invalidate();
    }

    // ---- interaction ----

    private listenPointer(): void {
        const canvas = this.renderer.domElement;
        const toPointer = (e: PointerEvent) => {
            const rect = canvas.getBoundingClientRect();
            this.pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
            this.raycaster.setFromCamera(this.pointer, this.camera);
        };
        const pieceUnderPointer = (): string | null => {
            const hit = this.raycaster.intersectObject(this.piecesGroup, true)[0];
            let object: THREE.Object3D | null = hit?.object ?? null;
            while (object && object.userData['pieceKey'] === undefined) object = object.parent;
            return (object?.userData['pieceKey'] as string | undefined) ?? null;
        };

        // Capture phase, so the orbit controls don't start rotating when an artwork is grabbed.
        const down = (e: PointerEvent) => {
            if (e.button !== 0) return;
            toPointer(e);
            const key = pieceUnderPointer();
            const wallHit = this.raycaster.intersectObject(this.dragPlane, false)[0];
            const piece = this.layout().find((l) => l.key === key);
            if (key && wallHit && piece) {
                // Keep the grab point under the finger (no jump to the centre).
                this.dragging = { id: key, dx: piece.x - wallHit.point.x, dy: piece.y - wallHit.point.y };
                this.controls.enabled = false;
                canvas.style.cursor = 'grabbing';
            }
        };
        const move = (e: PointerEvent) => {
            toPointer(e);
            if (this.dragging) {
                const hit = this.raycaster.intersectObject(this.dragPlane, false)[0];
                if (hit) this.moveDragged(hit.point.x + this.dragging.dx, hit.point.y + this.dragging.dy);
            } else if (e.target === canvas) {
                canvas.style.cursor = pieceUnderPointer() ? 'grab' : 'auto';
            }
        };
        const up = () => {
            if (!this.dragging) return;
            this.dragging = null;
            this.controls.enabled = true;
            canvas.style.cursor = 'auto';
            this.clearGuides();
            this.markSceneDirty();
        };

        canvas.addEventListener('pointerdown', down, { capture: true });
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        this.cleanup.push(() => {
            canvas.removeEventListener('pointerdown', down, { capture: true });
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        });
    }

    /** Moves the dragged piece, keeps it on the wall, and snaps it to the other pieces' edges and centres. */
    private moveDragged(x: number, y: number): void {
        if (!this.dragging) return;
        const layout = this.layout();
        const me = layout.find((l) => l.key === this.dragging!.id);
        if (!me) return;
        const wallW = this.wallW();
        const wallH = this.wallH();
        const halfW = me.d.outerW / 2;
        const halfH = me.d.outerH / 2;

        let bestX: { delta: number; line: number } | null = null;
        let bestY: { delta: number; line: number } | null = null;
        for (const other of layout) {
            if (other.key === me.key) continue;
            // vertical lines: left edge, centre, right edge; horizontal lines: bottom, centre, top
            const xs = [other.x - other.d.outerW / 2, other.x, other.x + other.d.outerW / 2];
            const ys = [other.y - other.d.outerH / 2, other.y, other.y + other.d.outerH / 2];
            for (const line of xs) {
                for (const mine of [x - halfW, x, x + halfW]) {
                    const delta = line - mine;
                    if (Math.abs(delta) < SNAP && (!bestX || Math.abs(delta) < Math.abs(bestX.delta))) bestX = { delta, line };
                }
            }
            for (const line of ys) {
                for (const mine of [y - halfH, y, y + halfH]) {
                    const delta = line - mine;
                    if (Math.abs(delta) < SNAP && (!bestY || Math.abs(delta) < Math.abs(bestY.delta))) bestY = { delta, line };
                }
            }
        }
        if (bestX) x += bestX.delta;
        if (bestY) y += bestY.delta;

        x = THREE.MathUtils.clamp(x, -wallW / 2 + halfW + 0.02, wallW / 2 - halfW - 0.02);
        y = THREE.MathUtils.clamp(y, halfH + 0.1, wallH - halfH - 0.05);
        this.offsets.update((o) => ({ ...o, [me.key]: { x, y } }));
        this.showGuides(bestX ? [bestX.line] : [], bestY ? [bestY.line] : [], wallW, wallH);
    }

    /** Dashed amber alignment lines across the wall while dragging. */
    private showGuides(xs: number[], ys: number[], wallW: number, wallH: number): void {
        this.clearGuides();
        if (!xs.length && !ys.length) return;
        const material = new THREE.LineDashedMaterial({ color: '#f59e0b', dashSize: 0.03, gapSize: 0.02, depthTest: false, transparent: true });
        const add = (a: THREE.Vector3, b: THREE.Vector3) => {
            const line = new THREE.Line(new THREE.BufferGeometry().setFromPoints([a, b]), material);
            line.computeLineDistances();
            line.renderOrder = 10;
            line.userData['guide'] = true;
            this.guidesGroup.add(line);
        };
        for (const x of xs) add(new THREE.Vector3(x, 0, 0.06), new THREE.Vector3(x, wallH, 0.06));
        for (const y of ys) add(new THREE.Vector3(-wallW / 2, y, 0.06), new THREE.Vector3(wallW / 2, y, 0.06));
        this.invalidate();
    }

    private clearGuides(): void {
        for (const child of [...this.guidesGroup.children]) {
            this.guidesGroup.remove(child);
            disposeTree(child);
        }
        this.invalidate();
    }

    private listenResize(): void {
        const host = this.canvasHost().nativeElement;
        const observer = new ResizeObserver(() => {
            const width = host.clientWidth;
            const height = host.clientHeight;
            if (!width || !height) return;
            this.viewSize = { width, height };
            this.renderer.setPixelRatio(renderScale(width, height, this.lite));
            this.renderer.setSize(width, height, false);
            this.camera.aspect = width / height;
            this.camera.updateProjectionMatrix();
            this.invalidate();
        });
        observer.observe(host);
        this.cleanup.push(() => observer.disconnect());
    }

    // ---- camera commands ----

    private place(distance: number, lookAt: THREE.Vector3, height: number): void {
        this.camera.position.set(lookAt.x, height, distance);
        this.controls.target.copy(lookAt);
        this.controls.update();
        this.invalidate();
    }

    resetView(): void {
        this.intro = null;
        if (this.controls && !this.dragging) this.controls.enabled = true;
        // Frame the artwork(s) so they read clearly — about 40% of the view height — with the
        // furniture below for scale. Big pieces push the camera back; small ones bring it closer.
        const layout = this.layout();
        const fov = THREE.MathUtils.degToRad(this.camera.fov);
        const aspect = Math.max(0.5, this.camera.aspect || 1);
        let centre = new THREE.Vector3(0, Math.min(1.2, this.wallH() / 2), 0);
        let distance = Math.min(ROOM_DEPTH - 0.15, Math.max(3.4, this.wallW() * 1.05));
        if (layout.length) {
            const minX = Math.min(...layout.map((l) => l.x - l.d.outerW / 2));
            const maxX = Math.max(...layout.map((l) => l.x + l.d.outerW / 2));
            const minY = Math.min(...layout.map((l) => l.y - l.d.outerH / 2));
            const maxY = Math.max(...layout.map((l) => l.y + l.d.outerH / 2));
            const viewH = Math.max((maxY - minY) / 0.4, (maxX - minX) / (0.6 * aspect), 1.5);
            distance = THREE.MathUtils.clamp(viewH / (2 * Math.tan(fov / 2)), 1.5, ROOM_DEPTH - 0.15);
            // Look slightly below the art so the top of the furniture stays in the picture.
            centre = new THREE.Vector3((minX + maxX) / 2, (minY + maxY) / 2 - 0.12 * viewH, 0);
        }
        this.place(distance, centre, Math.min(EYE, centre.y + 0.25));
    }

    closeUp(): void {
        this.endIntro();
        const layout = this.layout();
        if (!layout.length) return;
        const minX = Math.min(...layout.map((l) => l.x - l.d.outerW / 2));
        const maxX = Math.max(...layout.map((l) => l.x + l.d.outerW / 2));
        const minY = Math.min(...layout.map((l) => l.y - l.d.outerH / 2));
        const maxY = Math.max(...layout.map((l) => l.y + l.d.outerH / 2));
        const centre = new THREE.Vector3((minX + maxX) / 2, (minY + maxY) / 2, 0);
        this.place(Math.max(0.6, Math.max(maxX - minX, maxY - minY) * 1.6), centre, centre.y);
    }

    zoom(factor: number): void {
        const c = this.controls;
        const dir = new THREE.Vector3().subVectors(this.camera.position, c.target);
        const len = THREE.MathUtils.clamp(dir.length() * factor, c.minDistance, c.maxDistance);
        this.camera.position.copy(c.target).add(dir.setLength(len));
        c.update();
        this.invalidate();
    }

    /** Saves a 4K picture (3840 px wide) of the current view, whatever the size on screen. */
    saveImage(): void {
        const { width, height } = this.viewSize;
        this.renderer.setPixelRatio(3840 / Math.max(1, width));
        this.renderer.setSize(width, height, false);
        this.renderFrame();
        const link = document.createElement('a');
        link.download = 'artwork-preview-4k.png';
        link.href = this.renderer.domElement.toDataURL('image/png');
        this.renderer.setPixelRatio(renderScale(width, height, this.lite));
        this.renderer.setSize(width, height, false);
        this.renderFrame();
        link.click();
    }

    toggleFullscreen(): void {
        const el = this.container().nativeElement;
        if (document.fullscreenElement) void document.exitFullscreen();
        else void el.requestFullscreen?.();
    }

    /** Exports the artwork(s) — not the room — as a GLB for the AR viewer, centred on the arrangement. */
    async openAr(): Promise<void> {
        this.arOpen.set(true);
        const { GLTFExporter } = await import('three/examples/jsm/exporters/GLTFExporter.js');
        const layout = this.layout();
        const cx = layout.reduce((s, l) => s + l.x, 0) / Math.max(1, layout.length);
        const cy = layout.reduce((s, l) => s + l.y, 0) / Math.max(1, layout.length);
        const root = new THREE.Group();
        for (const l of layout) {
            const source = this.pieceGroups.get(l.key);
            if (!source) continue;
            const copy = source.clone(true);
            copy.position.set(l.x - cx, l.y - cy, 0);
            root.add(copy);
        }
        const glb = (await new GLTFExporter().parseAsync(root, { binary: true, onlyVisible: true, maxTextureSize: 2048 })) as ArrayBuffer;
        this.arModel.set(new Blob([glb], { type: 'model/gltf-binary' }));
    }

    ngOnDestroy(): void {
        this.destroyed = true;
        cancelAnimationFrame(this.frameId);
        this.cleanup.forEach((fn) => fn());
        if (!this.renderer) return;
        this.controls.dispose();
        this.skies.forEach((t) => t.dispose());
        if (this.furnitureGroup && this.furniture()) this.scene.remove(this.furnitureGroup); // shared, cached models
        disposeTree(this.scene);
        this.retired.splice(0).forEach((free) => free());
        if (this.warmKeep) disposeTree(this.warmKeep);
        this.shadows?.dispose();
        this.textures.forEach((t) => t.dispose());
        this.floorTexture.dispose();
        this.placeholder.dispose();
        this.scene.environment?.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}
