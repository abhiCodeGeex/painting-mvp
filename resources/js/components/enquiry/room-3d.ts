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
import { Camera, Download, Eye, Lamp, Maximize2, Minimize2, Move, RotateCcw, Sofa, X, ZoomIn, ZoomOut } from 'lucide';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import type { PreviewFrame, PreviewMat } from './preview-helpers';
import { ContactShadows } from './three/contact-shadows';
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
    SOFA,
    woodTexture,
} from './three/scene-parts';

/**
 * Interactive 3D room preview (plain Three.js). Everything is in metres and true to scale:
 * the artwork hangs on the customer's wall colour above a 2 m sofa.
 *
 * Controls: drag = look around · scroll / pinch = zoom · right-drag / two fingers = move ·
 * drag the artwork itself = reposition it on the wall.
 */
@Component({
    selector: 'room-3d',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'block' },
    template: `
        <div #container [class]="'relative overflow-hidden rounded-lg border bg-neutral-900 ' + (fullscreen() ? 'h-screen' : 'h-[360px]')">
            <div #canvasHost class="absolute inset-0" style="touch-action: none"></div>

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
                    <span>Drag: look around · Scroll/pinch: zoom · Right-drag: move · Drag the artwork to move it</span>
                    <svg [icon]="XIcon" class="size-3 shrink-0 opacity-70" />
                </button>
            }
        </div>
    `,
})
export class Room3D implements OnDestroy {
    readonly imageUrl = input<string | null>(null);
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    readonly edgeStyle = input<string | null>(null);
    readonly glazing = input<'none' | 'standard' | 'low'>('none');
    readonly wallColour = input.required<string>();
    readonly wallWidthMm = input<number | null>(null);
    readonly wallHeightMm = input<number | null>(null);

    protected readonly CameraIcon = Camera;
    protected readonly XIcon = X;

    protected showSofa = signal(true);
    protected pictureLight = signal(false);
    protected fullscreen = signal(false);
    protected showHint = signal(true);
    private offset = signal<{ x: number; y: number } | null>(null);
    private dragging = false;
    private ready = signal(false);
    private textureVersion = signal(0);

    private container = viewChild.required<ElementRef<HTMLDivElement>>('container');
    private canvasHost = viewChild.required<ElementRef<HTMLDivElement>>('canvasHost');

    // ---- real sizes (metres) ----
    private dims = computed(() => {
        const picW = M(Math.max(50, this.widthMm() || 0));
        const picH = M(Math.max(50, this.heightMm() || 0));
        const mat = this.mat();
        const frame = this.frame();
        const matB = mat ? M(mat.border_mm) : 0;
        const matBottom = mat ? M(mat.bottom_weight_mm ?? 0) : 0;
        const frameW = frame ? M(frame.width_mm) : 0;
        return { picW, picH, matB, matBottom, frameW, outerW: picW + 2 * matB + 2 * frameW, outerH: picH + 2 * matB + matBottom + 2 * frameW };
    });
    private wallW = computed(() => Math.max(M(this.wallWidthMm() || 0) || 3.6, this.dims().outerW + 1.2, SOFA.w + 1.2));
    private wallH = computed(() => Math.max(M(this.wallHeightMm() || 0) || 2.7, this.dims().outerH + SOFA.h + 1.0));
    // Default hanging: centre at ~1.5 m, at least 20 cm above the sofa back.
    private art = computed(() => {
        const defaultY = Math.max(1.5, SOFA.h + 0.2 + this.dims().outerH / 2);
        const o = this.offset();
        return { x: o?.x ?? 0, y: o?.y ?? defaultY };
    });

    protected buttons = computed(() => [
        { label: 'Reset view', icon: RotateCcw, active: false, run: () => this.resetView() },
        { label: 'Look at the artwork', icon: Eye, active: false, run: () => this.closeUp() },
        { label: 'Zoom in', icon: ZoomIn, active: false, run: () => this.zoom(0.75) },
        { label: 'Zoom out', icon: ZoomOut, active: false, run: () => this.zoom(1.33) },
        {
            label: this.pictureLight() ? 'Picture light off' : 'Picture light on',
            icon: Lamp,
            active: this.pictureLight(),
            run: () => this.pictureLight.update((v) => !v),
        },
        { label: this.showSofa() ? 'Hide sofa' : 'Show sofa', icon: Sofa, active: this.showSofa(), run: () => this.showSofa.update((v) => !v) },
        { label: 'Centre the artwork', icon: Move, active: false, run: () => this.offset.set(null) },
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
    private sofaGroup: THREE.Group | null = null;
    private artGroup = new THREE.Group();
    private artContent: THREE.Group | null = null;
    private lightsGroup = new THREE.Group();
    private ambient = new THREE.AmbientLight('#ffffff', 0.55);
    private hemi = new THREE.HemisphereLight('#fff7ed', '#7c6a58', 0.6);
    private sun = new THREE.DirectionalLight('#ffffff', 1.4);
    private shadows: ContactShadows | null = null;
    private dragPlane = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial());
    private floorTexture = woodTexture();
    private placeholder = gradientTexture();
    private textures = new Map<string, THREE.Texture>();
    private raycaster = new THREE.Raycaster();
    private pointer = new THREE.Vector2();
    private needsRender = true;
    private frameId = 0;
    private cleanup: (() => void)[] = [];

    constructor() {
        afterNextRender(() => this.init());

        // Reset position when the size changes (e.g. another artwork chosen).
        effect(() => {
            this.widthMm();
            this.heightMm();
            untracked(() => this.offset.set(null));
        });

        effect(() => {
            if (!this.ready()) return;
            this.buildRoom(this.wallW(), this.wallH(), this.wallColour());
        });
        effect(() => {
            if (!this.ready()) return;
            if (this.sofaGroup) this.sofaGroup.visible = this.showSofa();
            this.invalidate();
        });
        effect(() => {
            if (!this.ready()) return;
            this.textureVersion();
            this.buildArtwork();
        });
        effect(() => {
            if (!this.ready()) return;
            const { x, y } = this.art();
            this.artGroup.position.set(x, y, 0);
            this.buildLights(this.pictureLight(), x, y);
        });
    }

    // ---- setup ----

    private init(): void {
        const host = this.canvasHost().nativeElement;
        this.renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.shadowMap.enabled = true;
        // three r187 replaced PCFSoftShadowMap with a softer PCFShadowMap.
        this.renderer.shadowMap.type = THREE.PCFShadowMap;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.domElement.style.display = 'block';
        host.appendChild(this.renderer.domElement);

        this.scene.environment = buildEnvironment(this.renderer);
        this.camera.position.set(0, EYE, 3);

        this.sun.position.set(1.8, 3.2, 3.2);
        this.sun.castShadow = true;
        this.sun.shadow.mapSize.set(2048, 2048);
        this.sun.shadow.bias = -0.0004;
        this.sun.shadow.normalBias = 0.02;
        Object.assign(this.sun.shadow.camera, { left: -4, right: 4, top: 4, bottom: -1 });
        this.sun.shadow.camera.updateProjectionMatrix();
        this.scene.add(this.ambient, this.hemi, this.sun, this.lightsGroup, this.artGroup);

        this.dragPlane.visible = false;
        this.scene.add(this.dragPlane);

        this.sofaGroup = buildSofa();
        this.sofaGroup.visible = this.showSofa();
        this.scene.add(this.sofaGroup);

        // Orbit controls with the same limits as the React version: stay in front of the wall and above the floor.
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
            // Keep panning inside the room.
            controls.target.x = THREE.MathUtils.clamp(controls.target.x, -wallW / 2, wallW / 2);
            controls.target.y = THREE.MathUtils.clamp(controls.target.y, 0.2, wallH - 0.1);
            controls.target.z = THREE.MathUtils.clamp(controls.target.z, 0, 1.5);
            this.camera.position.z = Math.max(this.camera.position.z, 0.15);
            this.camera.position.y = THREE.MathUtils.clamp(this.camera.position.y, 0.15, wallH - 0.05);
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

        this.ready.set(true);
        // Build the scene now so the first view is placed correctly.
        this.buildRoom(this.wallW(), this.wallH(), this.wallColour());
        this.resetView();

        const loop = () => {
            this.frameId = requestAnimationFrame(loop);
            // update() returns true while the camera is moving (damping); render only then.
            if (this.controls.update() || this.needsRender) {
                this.needsRender = false;
                this.shadows?.update(this.renderer, this.scene);
                this.renderer.render(this.scene, this.camera);
            }
        };
        loop();
    }

    private invalidate(): void {
        this.needsRender = true;
    }

    private buildRoom(wallW: number, wallH: number, colour: string): void {
        if (this.roomGroup) {
            this.scene.remove(this.roomGroup);
            disposeTree(this.roomGroup);
        }
        this.roomGroup = buildRoom(wallW, wallH, colour, this.floorTexture);
        this.scene.add(this.roomGroup);

        if (this.shadows) {
            this.scene.remove(this.shadows.group);
            this.shadows.dispose();
        }
        this.shadows = new ContactShadows(wallW, 3, { opacity: 0.35, blur: 2.4, far: 1.2, resolution: 512 });
        this.shadows.group.position.set(0, 0.001, 1.2);
        this.scene.add(this.shadows.group);

        this.dragPlane.geometry.dispose();
        this.dragPlane.geometry = new THREE.PlaneGeometry(wallW * 3, wallH * 3);
        this.dragPlane.position.set(0, wallH / 2, 0.05);
        this.invalidate();
    }

    private buildArtwork(): void {
        const d = this.dims();
        if (this.artContent) {
            this.artGroup.remove(this.artContent);
            disposeTree(this.artContent);
        }
        this.artContent = buildArtwork(d, {
            frame: this.frame(),
            mat: this.mat(),
            glazing: this.glazing(),
            depthMm: this.depthMm(),
            edgeStyle: this.edgeStyle(),
            texture: this.pictureTexture(this.imageUrl(), d.picW, d.picH),
        });
        this.artGroup.add(this.artContent);
        this.invalidate();
    }

    private pictureTexture(url: string | null, picW: number, picH: number): THREE.Texture {
        if (!url) return this.placeholder;
        const loaded = this.textures.get(url);
        if (loaded) return coverTexture(loaded, picW, picH);
        new THREE.TextureLoader().load(url, (texture) => {
            this.textures.set(url, texture);
            this.textureVersion.update((v) => v + 1);
        });
        return this.placeholder;
    }

    private buildLights(on: boolean, x: number, y: number): void {
        const outerW = this.dims().outerW;
        const outerH = this.dims().outerH;
        this.ambient.intensity = on ? 0.35 : 0.55;
        this.hemi.intensity = on ? 0.35 : 0.6;
        this.sun.intensity = on ? 0.6 : 1.4;

        for (const child of [...this.lightsGroup.children]) {
            this.lightsGroup.remove(child);
            disposeTree(child);
        }
        if (on) {
            const spot = new THREE.SpotLight('#ffe2b8', 6, 3, 0.75, 0.6, 1.5);
            spot.position.set(x, y + outerH / 2 + 0.25, 0.45);
            const target = new THREE.Object3D();
            target.position.set(x, y, 0);
            spot.target = target;
            const lamp = buildLamp(outerW);
            lamp.position.set(x, y + outerH / 2 + 0.07, 0);
            this.lightsGroup.add(spot, target, lamp);
        }
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
        const overArtwork = () => this.raycaster.intersectObject(this.artGroup, true).length > 0;

        // Capture phase, so the orbit controls don't start rotating when the artwork is grabbed.
        const down = (e: PointerEvent) => {
            if (e.button !== 0) return;
            toPointer(e);
            if (overArtwork()) {
                this.dragging = true;
                this.controls.enabled = false;
                canvas.style.cursor = 'grabbing';
            }
        };
        const move = (e: PointerEvent) => {
            toPointer(e);
            if (this.dragging) {
                const hit = this.raycaster.intersectObject(this.dragPlane, false)[0];
                if (!hit) return;
                const { outerW, outerH } = this.dims();
                const wallW = this.wallW();
                const wallH = this.wallH();
                this.offset.set({
                    x: THREE.MathUtils.clamp(hit.point.x, -wallW / 2 + outerW / 2 + 0.02, wallW / 2 - outerW / 2 - 0.02),
                    y: THREE.MathUtils.clamp(hit.point.y, outerH / 2 + 0.1, wallH - outerH / 2 - 0.05),
                });
            } else if (e.target === canvas) {
                canvas.style.cursor = overArtwork() ? 'grab' : 'auto';
            }
        };
        const up = () => {
            if (!this.dragging) return;
            this.dragging = false;
            this.controls.enabled = true;
            canvas.style.cursor = 'auto';
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

    private listenResize(): void {
        const host = this.canvasHost().nativeElement;
        const observer = new ResizeObserver(() => {
            const { width, height } = host.getBoundingClientRect();
            if (!width || !height) return;
            this.renderer.setSize(width, height);
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
        // Stand near the back of the room so wall, floor and sofa are all in view, at eye height.
        const distance = Math.min(ROOM_DEPTH - 0.15, Math.max(3.4, this.wallW() * 1.05));
        this.place(distance, new THREE.Vector3(0, Math.min(1.2, this.wallH() / 2), 0), EYE);
    }

    closeUp(): void {
        const { outerW, outerH } = this.dims();
        const { x, y } = this.art();
        this.place(Math.max(0.6, Math.max(outerW, outerH) * 1.6), new THREE.Vector3(x, y, 0), y);
    }

    zoom(factor: number): void {
        const c = this.controls;
        const dir = new THREE.Vector3().subVectors(this.camera.position, c.target);
        const len = THREE.MathUtils.clamp(dir.length() * factor, c.minDistance, c.maxDistance);
        this.camera.position.copy(c.target).add(dir.setLength(len));
        c.update();
        this.invalidate();
    }

    saveImage(): void {
        this.shadows?.update(this.renderer, this.scene);
        this.renderer.render(this.scene, this.camera);
        const link = document.createElement('a');
        link.download = 'artwork-preview.png';
        link.href = this.renderer.domElement.toDataURL('image/png');
        link.click();
    }

    toggleFullscreen(): void {
        const el = this.container().nativeElement;
        if (document.fullscreenElement) void document.exitFullscreen();
        else void el.requestFullscreen?.();
    }

    ngOnDestroy(): void {
        cancelAnimationFrame(this.frameId);
        this.cleanup.forEach((fn) => fn());
        if (!this.renderer) return;
        this.controls.dispose();
        disposeTree(this.scene);
        this.shadows?.dispose();
        this.textures.forEach((t) => t.dispose());
        this.floorTexture.dispose();
        this.placeholder.dispose();
        this.scene.environment?.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}
