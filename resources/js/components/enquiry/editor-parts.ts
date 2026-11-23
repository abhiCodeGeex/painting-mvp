import type { InertiaForm } from '@/inertia/form';
import { mmToUnit, sizeLabel, unitToMm, type Unit } from '@/lib/units';
import type { CatalogArtwork, CatalogOptions, MediaFile, Option } from '@/types/portal';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, inject, input, output, signal } from '@angular/core';
import { Check, ImagePlus, Search, Upload, X } from 'lucide';
import type { LineForm } from './line-form';

/* ------------------------------------------------------------------ */

@Component({
    selector: 'choice-row',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap gap-2">
            @for (o of options(); track o.value) {
                <button
                    type="button"
                    [class]="
                        'rounded-full border px-3 py-1.5 text-sm transition ' +
                        (value() === o.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')
                    "
                    (click)="changed.emit(o.value)"
                >
                    {{ o.label }}
                </button>
            }
        </div>
    `,
})
export class ChoiceRow {
    readonly value = input<string | null>(null);
    readonly options = input.required<Option[]>();
    readonly changed = output<string>();
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'file-thumb',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="relative w-24">
            @if (src()) {
                <img [src]="src()" alt="" class="size-24 rounded-lg border object-cover" />
            } @else {
                <div class="bg-muted flex size-24 items-center justify-center rounded-lg border text-xs font-semibold uppercase">
                    {{ extension() }}
                </div>
            }
            <p class="mt-1 truncate text-[11px]">{{ name() }}</p>
            @if (removable()) {
                <button
                    type="button"
                    class="bg-background absolute -top-2 -right-2 rounded-full border p-1 shadow-sm"
                    title="Remove"
                    (click)="removed.emit()"
                >
                    <svg [icon]="X" class="size-3" />
                </button>
            }
        </div>
    `,
})
export class FileThumb {
    readonly name = input.required<string>();
    readonly src = input<string | null>(null);
    readonly removable = input(true);
    readonly removed = output<void>();
    protected extension = computed(() => this.name().split('.').pop());
    protected readonly X = X;
}

/** Thumbnail for a file picked on this device (object URL preview). */
@Component({
    selector: 'local-thumb',
    imports: [FileThumb],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `<file-thumb [name]="file().name" [src]="src()" (removed)="removed.emit()" />`,
})
export class LocalThumb {
    readonly file = input.required<File>();
    readonly removed = output<void>();
    protected src = signal<string | null>(null);

    constructor() {
        let url: string | null = null;
        effect(() => {
            if (url) URL.revokeObjectURL(url);
            const file = this.file();
            url = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
            this.src.set(url);
        });
        inject(DestroyRef).onDestroy(() => url && URL.revokeObjectURL(url));
    }
}

@Component({
    selector: 'file-gallery',
    imports: [Icon, FileThumb, LocalThumb],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap gap-3">
            @for (m of existing(); track m.id) {
                <file-thumb [name]="m.name" [src]="m.thumb" [removable]="removableExisting()" (removed)="removeExisting.emit(m.id)" />
            }
            @for (f of added(); track $index) {
                <local-thumb [file]="f" (removed)="removeAdded.emit($index)" />
            }
            <label
                class="hover:bg-muted flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-xs"
            >
                <svg [icon]="multiple() ? ImagePlus : Upload" class="size-5" />
                {{ multiple() ? 'Add files' : existing().length + added().length ? 'Replace' : 'Upload' }}
                <input type="file" class="hidden" [accept]="accept()" [multiple]="multiple()" (change)="pick($event)" />
            </label>
        </div>
    `,
})
export class FileGallery {
    readonly existing = input<MediaFile[]>([]);
    readonly added = input<File[]>([]);
    readonly multiple = input(false);
    readonly accept = input('image/*,.pdf');
    readonly removableExisting = input(false);
    readonly add = output<File[]>();
    readonly removeAdded = output<number>();
    readonly removeExisting = output<number>();
    protected readonly ImagePlus = ImagePlus;
    protected readonly Upload = Upload;

    pick(event: Event): void {
        const input = event.target as HTMLInputElement;
        this.add.emit(Array.from(input.files ?? []));
        input.value = '';
    }
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'catalog-picker',
    imports: [Icon, UI],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="space-y-3">
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="relative flex-1">
                    <svg [icon]="Search" class="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <input
                        ui-input
                        class="pl-8"
                        placeholder="Search title or artist"
                        [value]="query()"
                        (input)="query.set($any($event.target).value); limit.set(pageSize)"
                    />
                </div>
                <select ui-select class="sm:w-60" [value]="category()" (change)="category.set($any($event.target).value); limit.set(pageSize)">
                    <option value="">All categories</option>
                    @for (c of options().categories; track c) {
                        <option [value]="c">
                            @if (c.includes(' › ')) {
                                &nbsp;&nbsp;↳ {{ c.split(' › ')[1] }}
                            } @else {
                                {{ c }}
                            }
                        </option>
                    }
                </select>
            </div>
            @if (list().length === 0) {
                <p class="text-muted-foreground text-sm">No artworks match your search.</p>
            }
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @for (a of visible(); track a.id) {
                    <button
                        type="button"
                        [class]="
                            'group relative overflow-hidden rounded-lg border text-left transition ' +
                            (selectedId() === a.id ? 'border-primary ring-primary ring-2' : 'hover:shadow-md')
                        "
                        (click)="selected.emit(a)"
                    >
                        <div class="bg-muted aspect-square w-full">
                            @if (a.thumb) {
                                <img [src]="a.thumb" [alt]="a.title" class="size-full object-cover" loading="lazy" />
                            } @else {
                                <div class="size-full" style="background: linear-gradient(135deg, #f6d365, #fda085, #a1c4fd)"></div>
                            }
                        </div>
                        <div class="p-2">
                            <div class="truncate text-sm font-medium">{{ a.title }}</div>
                            <div class="text-muted-foreground truncate text-xs">{{ a.artist ?? 'Studio' }} · {{ a.type_label }}</div>
                        </div>
                        @if (selectedId() === a.id) {
                            <span class="bg-primary text-primary-foreground absolute top-2 right-2 rounded-full p-1"
                                ><svg [icon]="Check" class="size-3"
                            /></span>
                        }
                    </button>
                }
            </div>
            @if (list().length > visible().length) {
                <button ui-button type="button" variant="outline" class="w-full" (click)="limit.set(limit() + pageSize * 2)">
                    Show more artworks
                    <span class="text-muted-foreground font-normal">({{ list().length - visible().length }} more)</span>
                </button>
            }
        </div>
    `,
})
export class CatalogPicker {
    readonly options = input.required<CatalogOptions>();
    readonly selectedId = input<number | null>(null);
    readonly selected = output<CatalogArtwork>();
    protected query = signal('');
    protected category = signal('');
    protected readonly Search = Search;
    protected readonly Check = Check;
    /** The grid grows with "Show more" instead of scrolling inside the dialog. */
    protected readonly pageSize = 6;
    protected limit = signal(this.pageSize);
    protected list = computed(() => {
        const q = this.query().toLowerCase();
        const c = this.category();
        return this.options().artworks.filter(
            (a) => (!q || `${a.title} ${a.artist ?? ''} ${a.code}`.toLowerCase().includes(q)) && (!c || a.categories.includes(c)),
        );
    });
    /** Always include the selected artwork, so the choice stays visible when the grid is collapsed. */
    protected visible = computed(() => {
        const shown = this.list().slice(0, this.limit());
        const chosen = this.list().find((a) => a.id === this.selectedId());
        return chosen && !shown.includes(chosen) ? [...shown.slice(0, -1), chosen] : shown;
    });
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'palette-input',
    imports: [Icon, UI],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap items-center gap-2">
            @for (c of value(); track $index) {
                <span class="relative">
                    <input type="color" class="size-9 cursor-pointer rounded border" [value]="c" (input)="setAt($index, $any($event.target).value)" />
                    <button type="button" class="bg-background absolute -top-1.5 -right-1.5 rounded-full border p-0.5" (click)="removeAt($index)">
                        <svg [icon]="X" class="size-3" />
                    </button>
                </span>
            }
            @if (value().length < 6) {
                <button ui-button type="button" variant="outline" size="sm" (click)="changed.emit([...value(), '#6B8E9F'])">Add colour</button>
                @if (!value().includes(wall())) {
                    <button ui-button type="button" variant="ghost" size="sm" (click)="changed.emit([...value(), wall()])">Match my wall</button>
                }
            }
        </div>
    `,
})
export class PaletteInput {
    readonly value = input.required<string[]>();
    readonly wall = input.required<string>();
    readonly changed = output<string[]>();
    protected readonly X = X;

    setAt(i: number, colour: string): void {
        this.changed.emit(this.value().map((x, j) => (j === i ? colour.toUpperCase() : x)));
    }

    removeAt(i: number): void {
        this.changed.emit(this.value().filter((_, j) => j !== i));
    }
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'size-fields',
    imports: [UI, ChoiceRow],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-4">
                <choice-row [value]="data().unit" [options]="options().enums.units" (changed)="form().set('unit', $any($event))" />
                <div class="text-muted-foreground text-xs">Sizes are saved exactly; switch units any time.</div>
            </div>

            <div>
                <label ui-label class="mb-1.5 block">{{ fixedSizes() ? 'Available sizes' : 'Popular sizes' }}</label>
                <div class="flex flex-wrap gap-2">
                    @for (s of presets(); track s.width_mm + 'x' + s.height_mm) {
                        <button
                            type="button"
                            [class]="
                                'rounded-md border px-2.5 py-1 text-xs transition ' +
                                (s.width_mm === data().width_mm && s.height_mm === data().height_mm
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted')
                            "
                            (click)="setSize(s.width_mm, s.height_mm)"
                        >
                            {{ data().unit === 'cm' && s.label ? s.label : label(s.width_mm, s.height_mm) }}
                        </button>
                    }
                </div>
            </div>

            @if (!fixedSizes()) {
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="grid gap-1.5">
                        <label ui-label>Width ({{ unitLabel() }})</label>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            step="0.1"
                            [value]="w()"
                            (input)="w.set($any($event.target).value)"
                            (blur)="form().set('width_mm', toMm(w()))"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Height ({{ unitLabel() }})</label>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            step="0.1"
                            [value]="h()"
                            (input)="h.set($any($event.target).value)"
                            (blur)="form().set('height_mm', toMm(h()))"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Quantity</label>
                        <input ui-input type="number" min="1" max="1000" [value]="data().quantity" (input)="setQuantity($any($event.target).value)" />
                    </div>
                    <div class="flex items-end">
                        <button ui-button type="button" variant="ghost" size="sm" (click)="setSize(data().height_mm, data().width_mm)">
                            ⟲ Rotate
                        </button>
                    </div>
                </div>
            } @else {
                <div class="grid w-40 gap-1.5">
                    <label ui-label>Quantity</label>
                    <input ui-input type="number" min="1" max="1000" [value]="data().quantity" (input)="setQuantity($any($event.target).value)" />
                </div>
            }

            <div>
                <label ui-label class="mb-1.5 block">These measurements are…</label>
                <choice-row
                    [value]="data().size_reference"
                    [options]="options().enums.size_references"
                    (changed)="form().set('size_reference', $any($event))"
                />
                <p class="text-muted-foreground mt-1.5 text-xs">
                    “Artwork / image size” is the picture only. “Outer size” includes the mat and frame — choose it if you have a fixed space on the
                    wall.
                </p>
            </div>
        </div>
    `,
})
export class SizeFields {
    readonly form = input.required<InertiaForm<LineForm>>();
    readonly artwork = input<CatalogArtwork | null>(null);
    readonly options = input.required<CatalogOptions>();

    protected data = computed(() => this.form().data());
    protected fixedSizes = computed(() => !!this.artwork() && !this.artwork()!.allow_custom_size);
    protected presets = computed<{ width_mm: number; height_mm: number; label?: string }[]>(() =>
        this.artwork()?.sizes.length ? this.artwork()!.sizes : this.options().standard_sizes,
    );
    protected unitLabel = computed(() => (this.data().unit === 'cm' ? 'cm' : 'in'));
    protected w = signal('');
    protected h = signal('');

    constructor() {
        // Keep the text boxes in sync when size changes from elsewhere (preset, artwork, unit).
        effect(() => {
            const d = this.data();
            this.w.set(mmToUnit(d.width_mm, d.unit));
            this.h.set(mmToUnit(d.height_mm, d.unit));
        });
    }

    label(w: number, h: number): string {
        return sizeLabel(w, h, this.data().unit);
    }

    toMm(value: string): number {
        return unitToMm(value, this.data().unit as Unit);
    }

    setSize(width_mm: number, height_mm: number): void {
        this.form().update((d) => ({ ...d, width_mm, height_mm }));
    }

    setQuantity(value: string): void {
        this.form().set('quantity', Math.max(1, Number(value) || 1));
    }
}
