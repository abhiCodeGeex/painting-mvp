import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { Icon } from '@/ui/icon';
import { UiButton } from '@/ui/primitives';
import { ChangeDetectionStrategy, Component, CUSTOM_ELEMENTS_SCHEMA, DestroyRef, effect, ElementRef, inject, input, model, signal, viewChild } from '@angular/core';
import { LoaderCircle, Smartphone } from 'lucide';

/** Google's free <model-viewer> (Apache-2.0), self-hosted and loaded only when AR is first opened. */
const MODEL_VIEWER = '/3d/vendor/model-viewer-4.4.0.min.js';
let viewerScript: Promise<void> | null = null;

function loadModelViewer(): Promise<void> {
    if (customElements.get('model-viewer')) return Promise.resolve();
    viewerScript ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.type = 'module';
        script.src = MODEL_VIEWER;
        script.onload = () => customElements.whenDefined('model-viewer').then(() => resolve());
        script.onerror = () => {
            viewerScript = null;
            reject(new Error('AR viewer could not be loaded'));
        };
        document.head.appendChild(script);
    });
    return viewerScript;
}

interface ModelViewerElement extends HTMLElement {
    canActivateAR: boolean;
    activateAR(): Promise<void>;
}

/**
 * "View on your wall": the framed artwork as a 3D model that phones can place on a real wall
 * (WebXR / Scene Viewer on Android, Quick Look on iPhone — the USDZ is made on the fly).
 * On a computer it shows the model plus a QR code to continue on the phone.
 */
@Component({
    selector: 'ar-view',
    imports: [UiDialog, Icon, UiButton],
    schemas: [CUSTOM_ELEMENTS_SCHEMA],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [(open)]="open" contentClass="sm:max-w-2xl">
            @if (open()) {
                <div [class]="h">
                    <h2 [class]="t">View on your wall</h2>
                    <p [class]="d">See {{ title() }} at its real size on your own wall, through your phone's camera.</p>
                </div>

                <div class="relative h-[340px] overflow-hidden rounded-lg border bg-neutral-100 dark:bg-neutral-900">
                    @if (url() && ready()) {
                        <model-viewer
                            #viewer
                            class="block h-full w-full"
                            [attr.src]="url()"
                            ar
                            ar-modes="webxr scene-viewer quick-look"
                            ar-placement="wall"
                            ar-scale="fixed"
                            camera-controls
                            touch-action="pan-y"
                            shadow-intensity="0.6"
                            exposure="1"
                            [attr.alt]="title()"
                            (load)="checkAr()"
                        ></model-viewer>
                    } @else {
                        <div class="text-muted-foreground flex h-full items-center justify-center gap-2 text-sm">
                            <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> {{ error() ?? 'Preparing your artwork…' }}
                        </div>
                    }
                </div>

                @if (canAr()) {
                    <button ui-button size="lg" type="button" class="w-full" (click)="startAr()">
                        <svg [icon]="Smartphone" class="size-4" /> Place it on my wall
                    </button>
                    <p class="text-muted-foreground text-xs">Point your camera at the wall and move the phone slowly until the artwork appears. It is shown at its real size.</p>
                } @else if (url() && ready()) {
                    <div class="flex flex-col items-center gap-4 rounded-lg border p-4 sm:flex-row">
                        @if (qr()) {
                            <img [src]="qr()" alt="QR code for this page" class="size-32 shrink-0 rounded bg-white p-1" />
                        }
                        <div class="text-sm">
                            <p class="font-medium">Open this on your phone</p>
                            <ol class="text-muted-foreground mt-1 list-decimal space-y-0.5 pl-4 text-xs">
                                <li>Scan the code with your phone's camera.</li>
                                <li>Log in, open this artwork and tap <strong>View on your wall</strong>.</li>
                                <li>Point the phone at your wall — the artwork appears at its real size.</li>
                            </ol>
                            <p class="text-muted-foreground mt-2 text-xs">Works on most Android phones (Chrome) and iPhones (Safari). You can rotate the model above with your mouse.</p>
                        </div>
                    </div>
                }
            }
        </ui-dialog>
    `,
})
export class ArView {
    readonly open = model(false);
    readonly glb = input<Blob | null>(null);
    readonly title = input('your artwork');

    protected url = signal<string | null>(null);
    protected ready = signal(false);
    protected canAr = signal(false);
    protected qr = signal<string | null>(null);
    protected error = signal<string | null>(null);
    private viewer = viewChild<ElementRef<ModelViewerElement>>('viewer');
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly Smartphone = Smartphone;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;

    constructor() {
        let current: string | null = null;
        effect(() => {
            const blob = this.glb();
            if (current) URL.revokeObjectURL(current);
            current = blob ? URL.createObjectURL(blob) : null;
            this.url.set(current);
            this.canAr.set(false);
        });
        inject(DestroyRef).onDestroy(() => current && URL.revokeObjectURL(current));

        effect(() => {
            if (!this.open()) return;
            loadModelViewer()
                .then(() => this.ready.set(true))
                .catch(() => this.error.set('The AR viewer could not be loaded. Please check your connection.'));
            void this.makeQr();
        });
    }

    checkAr(): void {
        this.canAr.set(!!this.viewer()?.nativeElement.canActivateAR);
    }

    startAr(): void {
        void this.viewer()?.nativeElement.activateAR();
    }

    private async makeQr(): Promise<void> {
        if (this.qr()) return;
        const QRCode = await import('qrcode');
        this.qr.set(await QRCode.toDataURL(window.location.href, { margin: 1, width: 256 }));
    }
}
