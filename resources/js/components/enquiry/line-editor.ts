import { InertiaForm } from '@/inertia/form';
import { fits, mmToUnit, unitToMm } from '@/lib/units';
import type { CatalogArtwork, CatalogOptions, EnquiryLine, FrameLayerInput, FrameSplitMode } from '@/types/portal';
import { UiCheckbox } from '@/ui/checkbox';
import { Confirm } from '@/ui/confirm';
import { UiDialog } from '@/ui/dialog';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import {
    ChangeDetectionStrategy,
    Component,
    computed,
    DestroyRef,
    effect,
    ElementRef,
    inject,
    input,
    model,
    signal,
    untracked,
    viewChild,
} from '@angular/core';
import { ArrowLeft, ArrowRight, Brush, Check, Frame as FrameIcon, Images, LoaderCircle, TriangleAlert } from 'lucide';
import { CatalogPicker, ChoiceRow, FileGallery, FrameTiles, PaletteInput, SizeFields } from './editor-parts';
import { initialLineForm, type LineForm } from './line-form';
import { framesWidth, layerWidth, previewParts } from './preview-helpers';
import { liveEstimate, PriceEstimateBox } from './price-estimate';
import { WallPreview } from './wall-preview';

const OPTION_TILE = 'flex flex-col items-center gap-1 rounded-lg border px-2 py-2 text-center transition';
const SWATCH = 'flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1 text-xs transition';
/** Thin, token-coloured scrollbars (Firefox + Chromium) so stacked scroll areas stay quiet. */
const THIN_SCROLL = '[scrollbar-width:thin] [scrollbar-color:var(--color-border)_transparent]';
/** A quiet, full-width disclosure row: label left, current choice and a chevron right. */
const DISCLOSURE =
    'flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-sm select-none hover:bg-muted/60 [&::-webkit-details-marker]:hidden after:content-["›"] after:text-muted-foreground after:transition group-open:after:rotate-90';

const STEPS = [
    { n: 1, label: 'Artwork' },
    { n: 2, label: 'Size' },
    { n: 3, label: 'Materials' },
    { n: 4, label: 'Frame' },
    { n: 5, label: 'Wall & notes' },
] as const;

/**
 * The form shows the piece as it hangs (a turned painting's width and height are swapped); the server keeps the
 * painting's own size plus the rotation, so catalogue size prices and limits work unchanged.
 */
function unturnedSize(d: { width_mm: number; height_mm: number; image_rotation?: number; source_type?: string | null }): [number, number] {
    const turned = d.source_type === 'catalog' && ((d.image_rotation ?? 0) / 90) % 2 === 1;
    return turned ? [d.height_mm, d.width_mm] : [d.width_mm, d.height_mm];
}

/** Which step a validation error belongs to (by the field's name). */
function stepOfField(key: string): number {
    const k = key.split('.')[0];
    if (['width_mm', 'height_mm', 'quantity', 'unit', 'size_reference', 'image_rotation'].includes(k)) return 2;
    if (['quality_tier_id', 'medium_id', 'substrate_id', 'edge_style'].includes(k)) return 3;
    if (['frame_id', 'frame_layers', 'frame_advice_requested', 'mat_id', 'mat_border_mm', 'glazing_id', 'finishing_ids'].includes(k)) return 4;
    if (['placement', 'wall_width_mm', 'wall_height_mm', 'wall_colour', 'notes'].includes(k)) return 5;
    return 1;
}

/**
 * Add or edit one artwork on a draft enquiry, with a live, true-to-scale wall preview.
 */
@Component({
    selector: 'line-editor',
    imports: [
        UiDialog,
        UI,
        UiCheckbox,
        Icon,
        InputError,
        ChoiceRow,
        FileGallery,
        CatalogPicker,
        PaletteInput,
        SizeFields,
        FrameTiles,
        WallPreview,
        PriceEstimateBox,
    ],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog
            [(open)]="open"
            [beforeClose]="confirmClose"
            contentClass="flex h-dvh max-h-dvh w-full max-w-none flex-col gap-0 overflow-hidden p-0 sm:h-[calc(100dvh-2rem)] sm:max-h-[60rem] sm:w-[calc(100vw-2rem)] sm:max-w-7xl sm:rounded-xl"
        >
            @if (open() && d().source_type) {
                <div class="flex shrink-0 flex-col gap-3 border-b px-5 pt-4 pr-12 pb-3 sm:px-6">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 class="text-lg leading-none font-semibold tracking-tight">{{ line() ? 'Edit artwork' : 'Add an artwork' }}</h2>
                        <p class="text-muted-foreground text-sm">The preview updates as you go.</p>
                    </div>
                    <!-- Step bar: one step on screen at a time; any step can be opened directly. -->
                    <nav aria-label="Steps" [class]="'-mx-1 flex gap-1 overflow-x-auto px-1 pb-0.5 ' + thinScroll">
                        @for (s of steps(); track s.n) {
                            <button
                                type="button"
                                [attr.aria-current]="step() === s.n ? 'step' : null"
                                [class]="
                                    'flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition ' +
                                    (step() === s.n
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : s.error
                                          ? 'border-red-400 text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/40'
                                          : 'hover:bg-muted')
                                "
                                (click)="goTo(s.n)"
                            >
                                <span
                                    [class]="
                                        'flex size-5 items-center justify-center rounded-full text-[11px] font-semibold ' +
                                        (step() === s.n
                                            ? 'bg-primary-foreground text-primary'
                                            : s.error
                                              ? 'bg-red-500 text-white'
                                              : s.done
                                                ? 'bg-emerald-600 text-white'
                                                : 'bg-muted text-muted-foreground')
                                    "
                                >
                                    @if (s.error) {
                                        !
                                    } @else if (s.done && step() !== s.n) {
                                        <svg [icon]="Check" class="size-3" />
                                    } @else {
                                        {{ s.n }}
                                    }
                                </span>
                                {{ s.label }}
                            </button>
                        }
                    </nav>
                </div>

                <form class="flex min-h-0 flex-1 flex-col" (submit)="submit($event)" (keydown.enter)="enterNext($event)">
                    <!-- One scroll area on small screens; on large screens each column scrolls on its own and the preview stays put. -->
                    <div #scrollArea [class]="'min-h-0 flex-1 overflow-y-auto lg:grid lg:grid-cols-12 lg:overflow-hidden ' + thinScroll">
                        <!-- left: the form -->
                        <div
                            #stepPane
                            tabindex="-1"
                            style="outline: none"
                            [class]="'space-y-6 px-5 py-5 sm:px-6 lg:col-span-5 lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain ' + thinScroll"
                        >
                            @if (step() === 1) {
                                <section class="space-y-3">
                                    <h3 class="text-base font-semibold">What would you like?</h3>
                                    <!-- Compact segmented choice: one line, the explanation as a tooltip and below. -->
                                    <div class="bg-muted/60 grid grid-cols-3 gap-1 rounded-xl p-1" role="radiogroup">
                                        @for (s of sources; track s.value) {
                                            <button
                                                type="button"
                                                role="radio"
                                                [attr.aria-checked]="d().source_type === s.value"
                                                [title]="s.text"
                                                [class]="
                                                    'flex flex-col items-center justify-center gap-1 rounded-lg px-1.5 py-1.5 text-xs leading-tight font-medium transition sm:flex-row sm:gap-2 sm:px-2 sm:py-2 sm:text-sm ' +
                                                    (d().source_type === s.value
                                                        ? 'bg-background shadow-sm'
                                                        : 'text-muted-foreground hover:text-foreground')
                                                "
                                                (click)="chooseSource(s.value)"
                                            >
                                                <svg [icon]="s.icon" class="size-4 shrink-0" />
                                                <span class="text-center">{{ s.short }}</span>
                                            </button>
                                        }
                                    </div>
                                    <p class="text-muted-foreground text-xs">{{ sourceText() }}</p>
                                </section>

                                @switch (d().source_type) {
                                    @case ('catalog') {
                                        <section class="space-y-3">
                                            <h3 class="text-base font-semibold">Choose an artwork</h3>
                                            <catalog-picker [options]="options()" [selectedId]="d().artwork_id" (selected)="chooseArtwork($event)" />
                                            <input-error [message]="form.error('artwork_id')" />
                                        </section>
                                    }
                                    @case ('custom_creation') {
                                        <section class="space-y-4">
                                            <h3 class="text-base font-semibold">Tell us about the artwork</h3>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Working title (optional)</label>
                                                <input
                                                    ui-input
                                                    placeholder="e.g. Lobby triptych"
                                                    [value]="d().title"
                                                    (input)="form.set('title', $any($event.target).value)"
                                                />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Describe what you would like</label>
                                                <textarea
                                                    ui-textarea
                                                    rows="4"
                                                    placeholder="Subject, feeling, colours, anything that inspires you…"
                                                    [value]="d().brief.description"
                                                    (input)="setBrief('description', $any($event.target).value)"
                                                ></textarea>
                                                <input-error [message]="form.error('brief.description')" />
                                            </div>
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <div class="grid gap-1.5">
                                                    <label ui-label>Style</label>
                                                    <select
                                                        ui-select
                                                        [value]="d().brief.style"
                                                        (change)="setBrief('style', $any($event.target).value)"
                                                    >
                                                        <option value="">No preference</option>
                                                        @for (c of options().categories; track c) {
                                                            <option [value]="c" [selected]="d().brief.style === c">
                                                                @if (c.includes(' › ')) {
                                                                    &nbsp;&nbsp;↳ {{ c.split(' › ')[1] }}
                                                                } @else {
                                                                    {{ c }}
                                                                }
                                                            </option>
                                                        }
                                                    </select>
                                                </div>
                                                <div class="grid gap-1.5">
                                                    <label ui-label>Mood words (comma separated)</label>
                                                    <input
                                                        ui-input
                                                        placeholder="calm, warm, bold"
                                                        [value]="d().brief.mood.join(', ')"
                                                        (blur)="setMood($any($event.target).value)"
                                                    />
                                                </div>
                                            </div>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Colour palette (up to 6)</label>
                                                <palette-input
                                                    [value]="d().brief.palette"
                                                    [wall]="d().wall_colour"
                                                    (changed)="setBrief('palette', $event)"
                                                />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Where will it be displayed?</label>
                                                <choice-row
                                                    [value]="d().brief.usage_rights"
                                                    [options]="options().enums.usage_rights"
                                                    (changed)="setBrief('usage_rights', $event)"
                                                />
                                                <input-error [message]="form.error('brief.usage_rights')" />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Sketches or reference images</label>
                                                <file-gallery
                                                    [existing]="existingReferences()"
                                                    [added]="d().references"
                                                    [multiple]="true"
                                                    [removableExisting]="true"
                                                    (add)="form.set('references', [...d().references, ...$event].slice(0, 10))"
                                                    (removeAdded)="form.set('references', without(d().references, $event))"
                                                    (removeExisting)="form.set('remove_media', [...d().remove_media, $event])"
                                                />
                                                <input-error [message]="form.error('references')" />
                                            </div>
                                        </section>
                                    }
                                    @case ('own_artwork') {
                                        <section class="space-y-4">
                                            <h3 class="text-base font-semibold">Your artwork</h3>
                                            <div class="grid gap-1.5">
                                                <label ui-label>What should we do?</label>
                                                <choice-row
                                                    [value]="d().own.service"
                                                    [options]="options().enums.own_services"
                                                    (changed)="setOwn('service', $event)"
                                                />
                                                <input-error [message]="form.error('own.service')" />
                                            </div>
                                            <div class="grid gap-1.5">
                                                <label ui-label>Photo of the artwork</label>
                                                <file-gallery
                                                    [existing]="line()?.artwork_photo ? [line()!.artwork_photo!] : []"
                                                    [added]="d().artwork_photo ? [d().artwork_photo!] : []"
                                                    (add)="form.set('artwork_photo', $event.length ? $event[0] : null)"
                                                    (removeAdded)="form.set('artwork_photo', null)"
                                                />
                                                <input-error [message]="form.error('artwork_photo')" />
                                            </div>
                                            @if (d().own.service === 'reproduce_and_frame') {
                                                <div class="grid gap-1.5">
                                                    <label ui-label>High-resolution file for printing (optional now)</label>
                                                    <file-gallery
                                                        accept=".jpg,.jpeg,.png,.tif,.tiff,.pdf,.psd,.ai"
                                                        [existing]="line()?.hi_res ? [line()!.hi_res!] : []"
                                                        [added]="d().hi_res ? [d().hi_res!] : []"
                                                        (add)="form.set('hi_res', $event.length ? $event[0] : null)"
                                                        (removeAdded)="form.set('hi_res', null)"
                                                    />
                                                    <p class="text-muted-foreground text-xs">TIFF, PNG, JPG or PDF up to 200 MB.</p>
                                                    <input-error [message]="form.error('hi_res')" />
                                                </div>
                                            }
                                            <div class="grid gap-1.5">
                                                <label ui-label>Condition notes (optional)</label>
                                                <textarea
                                                    ui-textarea
                                                    rows="2"
                                                    placeholder="e.g. Rolled canvas, slight crease at the corner"
                                                    [value]="d().own.condition_notes"
                                                    (input)="setOwn('condition_notes', $any($event.target).value)"
                                                ></textarea>
                                            </div>
                                            <label class="flex items-start gap-3 text-sm">
                                                <ui-checkbox
                                                    [checked]="d().rights_confirmed"
                                                    (checkedChange)="form.set('rights_confirmed', $event)"
                                                />
                                                <span>I own the rights to this image or have permission to reproduce it.</span>
                                            </label>
                                            <input-error [message]="form.error('rights_confirmed')" />
                                        </section>
                                    }
                                }
                            }

                            @if (step() === 2) {
                                <section class="space-y-4">
                                    <h3 class="text-base font-semibold">Size & quantity</h3>
                                    <size-fields [form]="form" [artwork]="d().source_type === 'catalog' ? artwork() : null" [options]="options()" />
                                    <input-error [message]="form.error('width_mm') ?? form.error('height_mm') ?? form.error('image_rotation')" />
                                    <input-error [message]="form.error('quantity')" />
                                </section>
                            }

                            @if (step() === 3) {
                                <section class="space-y-4">
                                    <h3 class="text-base font-semibold">Quality & materials</h3>
                                    <div class="grid gap-1.5">
                                        <label ui-label>Quality</label>
                                        <div class="grid gap-2 sm:grid-cols-3">
                                            @for (t of options().quality_tiers; track t.id) {
                                                <button
                                                    type="button"
                                                    [class]="
                                                        'rounded-lg border p-3 text-left text-sm transition ' +
                                                        (d().quality_tier_id === t.id ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')
                                                    "
                                                    (click)="applyTier(t.id)"
                                                >
                                                    <div class="font-medium">{{ t.name }}</div>
                                                    <div class="text-muted-foreground text-xs">{{ t.description }}</div>
                                                </button>
                                            }
                                        </div>
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div class="grid gap-1.5">
                                            <label ui-label>Medium</label>
                                            <select ui-select (change)="setId('medium_id', $any($event.target).value)">
                                                <option value="" [selected]="!d().medium_id">Not sure — advise me</option>
                                                @for (m of mediums(); track m.id) {
                                                    <option [value]="m.id" [selected]="d().medium_id === m.id">{{ m.name }}</option>
                                                }
                                            </select>
                                            <input-error [message]="form.error('medium_id')" />
                                        </div>
                                        <div class="grid gap-1.5">
                                            <label ui-label>Surface</label>
                                            <select ui-select (change)="setId('substrate_id', $any($event.target).value)">
                                                <option value="" [selected]="!d().substrate_id">Not sure — advise me</option>
                                                @for (s of substrates(); track s.id) {
                                                    <option [value]="s.id" [selected]="d().substrate_id === s.id">{{ s.name }}</option>
                                                }
                                            </select>
                                            <input-error [message]="form.error('substrate_id')" />
                                        </div>
                                    </div>
                                    @if (edgeOptions().length > 0) {
                                        <div class="grid gap-1.5">
                                            <label ui-label>Canvas edges</label>
                                            <choice-row
                                                [value]="d().edge_style ?? ''"
                                                [options]="edgeOptions()"
                                                (changed)="form.set('edge_style', $event)"
                                            />
                                        </div>
                                    }
                                </section>
                            }

                            @if (step() === 4) {
                                <section class="space-y-4">
                                    <h3 class="text-base font-semibold">Frame</h3>
                                    <p class="text-muted-foreground -mt-1 text-xs">
                                        Want more than one frame? Pick a frame, then scroll down: <strong>Split this frame</strong> uses two mouldings
                                        (top &amp; bottom / sides, or top half / bottom half), and <strong>+ Add a frame around</strong> stacks up to 3.
                                    </p>
                                    <div class="grid gap-1.5">
                                        <frame-tiles [frames]="frames()" [selected]="d().frame_id" [imageUrl]="previewImage()" [ratio]="pictureW() / pictureH()" (picked)="setFrame($event, false)">
                                            <button
                                                type="button"
                                                [class]="tile(!d().frame_id && !d().frame_advice_requested)"
                                                (click)="setFrame(null, false)"
                                            >
                                                <span class="flex h-11 items-center justify-center">
                                                    <span
                                                        class="block h-10 w-7 rounded-sm bg-cover bg-center"
                                                        [style.background-image]="previewImage() ? 'url(' + previewImage() + ')' : 'linear-gradient(135deg, #f6d365, #a1c4fd)'"
                                                    ></span>
                                                </span>
                                                <span class="text-xs leading-tight font-medium">No frame</span>
                                            </button>
                                            <button
                                                type="button"
                                                [class]="tile(d().frame_advice_requested && !d().frame_id)"
                                                (click)="setFrame(null, true)"
                                            >
                                                <span class="flex h-11 items-center justify-center"
                                                    ><span class="text-muted-foreground flex h-10 items-center text-xl">?</span></span
                                                >
                                                <span class="text-xs leading-tight font-medium">Advise me</span>
                                            </button>
                                        </frame-tiles>
                                        <input-error [message]="form.error('frame_id')" />
                                        <input-error [message]="layerError()" />
                                    </div>

                                    @if (d().frame_id) {
                                        <!-- More frames: split a frame between two mouldings, or add a frame around it (up to 3). -->
                                        <div class="space-y-3">
                                            @for (layer of d().frame_layers; track $index; let i = $index) {
                                                <div class="space-y-2.5 rounded-lg border p-3">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-sm font-medium">{{ layerTitle(i) }}</span>
                                                        @if (i > 0) {
                                                            <button
                                                                type="button"
                                                                class="text-muted-foreground hover:text-foreground rounded-md px-2 text-lg leading-none"
                                                                [attr.aria-label]="'Remove ' + layerTitle(i)"
                                                                (click)="removeLayer(i)"
                                                            >
                                                                ×
                                                            </button>
                                                        }
                                                    </div>
                                                    @if (i > 0) {
                                                        <frame-tiles [frames]="framesFor(i)" [selected]="layer.frame_id" [imageUrl]="previewImage()" [ratio]="pictureW() / pictureH()" (picked)="setLayerFrame(i, $event)" />
                                                    } @else {
                                                        <p class="text-muted-foreground text-xs">{{ frameName(layer.frame_id) }}, chosen above.</p>
                                                    }
                                                    <label class="flex items-center gap-2 text-sm" [class.opacity-50]="!layer.split && !framesExcept(layer.frame_id, i).length">
                                                        <input
                                                            type="checkbox"
                                                            class="accent-primary size-4"
                                                            [checked]="!!layer.split"
                                                            [disabled]="!layer.split && !framesExcept(layer.frame_id, i).length"
                                                            (change)="toggleSplit(i, $any($event.target).checked)"
                                                        />
                                                        Split this frame (two mouldings)
                                                    </label>
                                                    @if (layer.split) {
                                                        <div class="grid grid-cols-2 gap-2">
                                                            @for (m of splitModes; track m.value) {
                                                                <button type="button" [class]="tile(layer.split.mode === m.value)" (click)="setSplitMode(i, m.value)">
                                                                    <span class="text-xs leading-tight font-medium">{{ m.label }}</span>
                                                                    <span class="text-muted-foreground text-[11px]">{{ m.hint }}</span>
                                                                </button>
                                                            }
                                                        </div>
                                                        <p class="text-muted-foreground text-xs">
                                                            {{ layer.split.mode === 'halves' ? 'Bottom half' : 'Left & right sides' }} in:
                                                        </p>
                                                        <frame-tiles [frames]="framesExcept(layer.frame_id, i)" [selected]="layer.split.frame_id" [imageUrl]="previewImage()" [ratio]="pictureW() / pictureH()" (picked)="setSplitFrame(i, $event)" />
                                                    }
                                                </div>
                                            }
                                            @if (d().frame_layers.length < maxLayers) {
                                                <button ui-button type="button" variant="outline" size="sm" (click)="addLayer()">+ Add a frame around</button>
                                            }
                                        </div>
                                    }

                                    @if (unframed()) {
                                        <p class="text-muted-foreground rounded-lg border border-dashed px-3 py-2 text-xs">
                                            Glass and a mat come with a frame. Pick a frame or “Advise me” to add them.
                                        </p>
                                    }
                                    @if (matsAllowed() && !unframed()) {
                                        <details class="group rounded-lg border" [open]="!!d().mat_id || !!form.error('mat_id') || !!form.error('mat_border_mm')">
                                            <summary [class]="disclosure">
                                                <span class="font-medium">Mat</span>
                                                <span class="text-muted-foreground truncate text-xs">{{ matSummary() }}</span>
                                            </summary>
                                            <div class="grid gap-1.5 border-t p-3">
                                                <p class="text-muted-foreground text-xs">A border inside the frame, around the picture.</p>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        title="None"
                                                        [class]="swatch(!d().mat_id)"
                                                        (click)="form.set('mat_id', null)"
                                                    >
                                                        <span
                                                            class="size-6 rounded-full border"
                                                            style="background: repeating-linear-gradient(45deg, #ddd 0 4px, #fff 4px 8px)"
                                                        ></span>
                                                        None
                                                    </button>
                                                    @for (m of options().mats; track m.id) {
                                                        <button
                                                            type="button"
                                                            [title]="m.name"
                                                            [class]="swatch(d().mat_id === m.id)"
                                                            (click)="form.set('mat_id', m.id)"
                                                        >
                                                            <span class="size-6 rounded-full border" [style.background]="m.colour"></span>
                                                            {{ m.name }}
                                                        </button>
                                                    }
                                                    @if (d().mat_id) {
                                                        <div class="flex items-center gap-2 text-sm">
                                                            <span class="text-muted-foreground">Border</span>
                                                            <input
                                                                ui-input
                                                                type="number"
                                                                class="w-20"
                                                                min="1"
                                                                [value]="matBorderText()"
                                                                (input)="setMatBorder($any($event.target).value)"
                                                            />
                                                            <span class="text-muted-foreground">{{ d().unit === 'cm' ? 'cm' : 'in' }}</span>
                                                        </div>
                                                    }
                                                </div>
                                                <input-error [message]="form.error('mat_id') ?? form.error('mat_border_mm')" />
                                            </div>
                                        </details>
                                    }

                                    @if (!unframed()) {
                                    <details class="group rounded-lg border" [open]="!!form.error('glazing_id')">
                                        <summary [class]="disclosure">
                                            <span class="font-medium">Glass</span>
                                            <span class="text-muted-foreground truncate text-xs">{{ glazingSummary() }}</span>
                                        </summary>
                                        <div class="grid gap-1.5 border-t p-3">
                                            <select ui-select aria-label="Glass" (change)="setId('glazing_id', $any($event.target).value)">
                                                <option value="" [selected]="!d().glazing_id">Not sure — advise me</option>
                                                @for (g of glazings(); track g.id) {
                                                    <option [value]="g.id" [selected]="d().glazing_id === g.id">{{ g.name }}</option>
                                                }
                                            </select>
                                            <input-error [message]="form.error('glazing_id')" />
                                        </div>
                                    </details>
                                    }
                                    @if (finishings().length > 0) {
                                        <details class="group rounded-lg border">
                                            <summary [class]="disclosure">
                                                <span class="font-medium">Hanging & finishing</span>
                                                <span class="text-muted-foreground truncate text-xs">{{ finishingSummary() }}</span>
                                            </summary>
                                            <div class="grid grid-cols-2 gap-2 border-t p-3 sm:grid-cols-3">
                                                @for (f of finishings(); track f.id) {
                                                    <label
                                                        class="group relative cursor-pointer overflow-hidden rounded-lg border text-sm transition"
                                                        [class]="
                                                            d().finishing_ids.includes(f.id)
                                                                ? 'border-primary ring-primary ring-2'
                                                                : 'hover:border-foreground/30'
                                                        "
                                                        [title]="f.description ?? f.name"
                                                    >
                                                        @if (f.picture) {
                                                            <img
                                                                [src]="f.picture"
                                                                [alt]="f.name"
                                                                loading="lazy"
                                                                class="bg-muted aspect-[4/3] w-full object-cover"
                                                            />
                                                        } @else {
                                                            <div class="bg-muted aspect-[4/3] w-full"></div>
                                                        }
                                                        <span class="flex items-center gap-2 p-2">
                                                            <ui-checkbox
                                                                [checked]="d().finishing_ids.includes(f.id)"
                                                                (checkedChange)="toggleFinishing(f.id, $event)"
                                                            />
                                                            <span class="leading-tight">{{ f.name }}</span>
                                                        </span>
                                                    </label>
                                                }
                                            </div>
                                        </details>
                                    }
                                </section>
                            }

                            @if (step() === 5) {
                                <!-- Review: everything chosen so far; each row opens its step. -->
                                <section class="space-y-2">
                                    <h3 class="text-base font-semibold">Your choices</h3>
                                    <ul class="divide-y rounded-lg border text-sm">
                                        @for (r of review(); track r.label) {
                                            <li>
                                                <button
                                                    type="button"
                                                    class="hover:bg-muted flex w-full items-center gap-3 px-3 py-2 text-left"
                                                    (click)="goTo(r.step)"
                                                >
                                                    <span class="text-muted-foreground w-20 shrink-0 text-xs">{{ r.label }}</span>
                                                    <span [class]="'min-w-0 flex-1 truncate ' + (r.missing ? 'text-amber-700 dark:text-amber-300' : '')">{{
                                                        r.value
                                                    }}</span>
                                                    <span class="text-muted-foreground text-xs">Edit</span>
                                                </button>
                                            </li>
                                        }
                                    </ul>
                                </section>
                                <section class="space-y-4">
                                    <h3 class="text-base font-semibold">Where will it hang?</h3>
                                    <p class="text-muted-foreground -mt-2 text-xs">
                                        Optional — helps us check the size and suggest framing. Pick the wall colour right on the preview.
                                    </p>
                                    <div class="grid gap-4 sm:grid-cols-3">
                                        <div class="grid gap-1.5">
                                            <label ui-label>Room / placement</label>
                                            <input
                                                ui-input
                                                placeholder="e.g. Living room, above sofa"
                                                [value]="d().placement"
                                                (input)="form.set('placement', $any($event.target).value)"
                                            />
                                        </div>
                                        <div class="grid gap-1.5">
                                            <label ui-label>Wall width ({{ d().unit === 'cm' ? 'cm' : 'in' }}, optional)</label>
                                            <input
                                                ui-input
                                                type="number"
                                                min="0"
                                                [value]="toUnit(d().wall_width_mm)"
                                                (input)="setWall('wall_width_mm', $any($event.target).value)"
                                            />
                                            <input-error [message]="form.error('wall_width_mm')" />
                                        </div>
                                        <div class="grid gap-1.5">
                                            <label ui-label>Wall height ({{ d().unit === 'cm' ? 'cm' : 'in' }}, optional)</label>
                                            <input
                                                ui-input
                                                type="number"
                                                min="0"
                                                [value]="toUnit(d().wall_height_mm)"
                                                (input)="setWall('wall_height_mm', $any($event.target).value)"
                                            />
                                            <input-error [message]="form.error('wall_height_mm')" />
                                        </div>
                                    </div>
                                    <div class="grid gap-1.5">
                                        <label ui-label>Anything else we should know? (optional)</label>
                                        <textarea
                                            ui-textarea
                                            rows="2"
                                            [value]="d().notes"
                                            (input)="form.set('notes', $any($event.target).value)"
                                        ></textarea>
                                    </div>
                                </section>
                            }
                        </div>

                        <!-- right: live preview -->
                        <aside
                            [class]="
                                'bg-muted/30 flex flex-col gap-3 border-t px-5 py-4 sm:px-6 lg:col-span-7 lg:min-h-0 lg:overflow-y-auto lg:overscroll-contain lg:border-t-0 lg:border-l ' +
                                thinScroll
                            "
                        >
                            <wall-preview
                                [fillHeight]="true"
                                [imageUrl]="previewImage()"
                                [detailImageUrl]="detailImage()"
                                [fullImageUrl]="fullImage()"
                                [widthMm]="pictureW()"
                                [heightMm]="pictureH()"
                                [frame]="parts().frame"
                                [layers]="parts().layers"
                                [rebateMm]="rebate()"
                                [title]="previewTitle()"
                                [mat]="hasMat() ? parts().mat : null"
                                [depthMm]="parts().depthMm"
                                [edgeStyle]="d().edge_style"
                                [rotation]="d().source_type === 'catalog' ? d().image_rotation : 0"
                                [glazing]="parts().glazing"
                                [wallColour]="d().wall_colour"
                                (wallColourChange)="form.set('wall_colour', $event)"
                                [wallWidthMm]="d().wall_width_mm"
                                [wallHeightMm]="d().wall_height_mm"
                                [unit]="d().unit"
                                [quantity]="d().quantity"
                            />

                            @if (warnings().length > 0) {
                                <ul class="flex flex-col gap-1.5" aria-live="polite">
                                    @for (w of warnings(); track w) {
                                        <li
                                            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200"
                                        >
                                            <svg [icon]="TriangleAlert" class="mt-px size-3.5 shrink-0" />
                                            <span>{{ w }}</span>
                                        </li>
                                    }
                                </ul>
                            }
                        </aside>
                    </div>

                    <!-- footer: price on the left, actions on the right -->
                    <div
                        class="bg-background flex shrink-0 flex-col gap-3 border-t px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6 sm:py-4"
                    >
                        <div class="min-w-0">
                            <price-estimate-box [inline]="true" [estimate]="live.estimate()" [loading]="live.loading()" />
                            @if (errorCount() > 0) {
                                <p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                                    Please check the {{ errorCount() === 1 ? 'highlighted field' : errorCount() + ' highlighted fields' }}.
                                </p>
                            }
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:gap-3">
                            @if (step() > 1) {
                                <button ui-button type="button" variant="ghost" (click)="goTo(step() - 1)">
                                    <svg [icon]="ArrowLeft" class="size-4" /> Back
                                </button>
                            } @else {
                                <button ui-button type="button" variant="ghost" (click)="cancel()">Cancel</button>
                            }
                            @if (step() < 5) {
                                <button ui-button type="button" variant="outline" class="sm:min-w-32" (click)="goTo(step() + 1)">
                                    Next: {{ steps()[step()].label }} <svg [icon]="ArrowRight" class="size-4" />
                                </button>
                            }
                            <button ui-button type="submit" class="flex-1 sm:min-w-40 sm:flex-none" [disabled]="form.processing()">
                                @if (form.processing()) {
                                    <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                                }
                                {{ line() ? 'Save changes' : 'Add to enquiry' }}
                            </button>
                        </div>
                    </div>
                </form>
            }
        </ui-dialog>
    `,
})
export class LineEditor {
    readonly open = model(false);
    readonly enquiryId = input.required<number>();
    readonly line = input<EnquiryLine | null>(null);
    readonly options = input.required<CatalogOptions>();

    protected form = new InertiaForm<LineForm>({} as LineForm);
    protected d = this.form.data;
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly Check = Check;
    protected readonly ArrowLeft = ArrowLeft;
    protected readonly ArrowRight = ArrowRight;
    protected readonly TriangleAlert = TriangleAlert;
    protected readonly thinScroll = THIN_SCROLL;
    protected readonly disclosure = DISCLOSURE;
    protected readonly sources = [
        { value: 'catalog' as const, icon: Images, short: 'Catalogue', title: 'From our catalog', text: 'Pick a painting or print we offer.' },
        { value: 'custom_creation' as const, icon: Brush, short: 'New creation', title: 'A new creation', text: 'We paint something new for you.' },
        {
            value: 'own_artwork' as const,
            icon: FrameIcon,
            short: 'My artwork',
            title: 'My own artwork',
            text: 'Frame or reproduce a piece you have.',
        },
    ];
    protected sourceText = computed(() => this.sources.find((s) => s.value === this.d().source_type)?.text ?? '');

    // ---- steps: one on screen at a time ----
    protected step = signal(1);
    private visited = signal<Set<number>>(new Set([1]));
    private stepPane = viewChild<ElementRef<HTMLElement>>('stepPane');
    private scrollArea = viewChild<ElementRef<HTMLElement>>('scrollArea');
    protected steps = computed(() => {
        const errors = Object.keys(this.form.errors());
        const visited = this.visited();
        const d = this.d();
        const filled: Record<number, boolean> = {
            1:
                d.source_type === 'catalog'
                    ? !!d.artwork_id
                    : d.source_type === 'custom_creation'
                      ? !!d.brief.description?.trim() && d.references.length + this.existingReferences().length > 0
                      : !!(d.artwork_photo || this.line()?.artwork_photo) && d.rights_confirmed,
            2: d.width_mm > 0 && d.height_mm > 0,
            4: !(this.substrate()?.requires_frame && !d.frame_id && !d.frame_advice_requested),
        };
        return STEPS.map((s) => ({
            ...s,
            error: errors.some((k) => stepOfField(k) === s.n),
            done: (filled[s.n] ?? true) && visited.has(s.n),
        }));
    });

    goTo(n: number): void {
        const next = Math.min(5, Math.max(1, n));
        this.step.set(next);
        this.visited.update((v) => new Set(v).add(next));
        // Phones scroll the whole dialog body, large screens the form column: both back to the top.
        this.scrollArea()?.nativeElement.scrollTo({ top: 0 });
        this.stepPane()?.nativeElement.scrollTo({ top: 0 });
        // The button that was pressed can disappear with the step (Back on step 2, Next on step 4):
        // keep keyboard focus inside the dialog instead of falling to the page behind it.
        setTimeout(() => {
            const a = document.activeElement;
            if (!a || a === document.body || !a.isConnected) this.stepPane()?.nativeElement.focus({ preventScroll: true });
        });
    }

    protected matSummary = computed(() => {
        const m = this.options().mats.find((x) => x.id === this.d().mat_id);
        return m ? `${m.name} · ${this.matBorderText()} ${this.d().unit === 'cm' ? 'cm' : 'in'}` : 'None';
    });
    protected glazingSummary = computed(() => this.glazings().find((g) => g.id === this.d().glazing_id)?.name ?? 'Not sure — advise me');
    protected finishingSummary = computed(() => {
        const names = this.finishings()
            .filter((f) => this.d().finishing_ids.includes(f.id))
            .map((f) => f.name);
        return names.length ? names.join(', ') : 'None chosen';
    });

    // ---- options filtered by what is already chosen (spec §4.3) ----
    protected artwork = computed(() => this.options().artworks.find((a) => a.id === this.d().artwork_id) ?? null);
    private substrate = computed(() => this.options().substrates.find((s) => s.id === this.d().substrate_id) ?? null);
    protected mediums = computed(() => {
        const a = this.artwork();
        const catalog = this.d().source_type === 'catalog';
        return this.options().mediums.filter((m) => !(catalog && a?.medium_ids.length) || a!.medium_ids.includes(m.id));
    });
    private medium = computed(() => this.options().mediums.find((m) => m.id === this.d().medium_id) ?? null);
    protected substrates = computed(() => {
        const m = this.medium();
        const a = this.artwork();
        const catalog = this.d().source_type === 'catalog';
        return this.options().substrates.filter(
            (s) =>
                (!m?.substrate_ids.length || m.substrate_ids.includes(s.id)) &&
                (!catalog || !a?.substrate_ids.length || a.substrate_ids.includes(s.id)),
        );
    });
    private matBorder = computed(() => {
        const d = this.d();
        return d.mat_id ? (d.mat_border_mm ?? this.options().mats.find((m) => m.id === d.mat_id)?.default_border_mm ?? 0) : 0;
    });
    /** Frames that suit the surface and fit around the picture + mat (the inner frame). */
    protected frames = computed(() => this.framesFor(0));

    /**
     * Frames for layer i: they must fit around everything inside them — picture, mat, the rebate allowance
     * and the frames of layers 0…i-1 (as the server checks it).
     */
    protected framesFor(i: number): CatalogOptions['frames'] {
        const d = this.d();
        const b = this.hasMat() ? this.matBorder() : 0;
        const bottom = this.hasMat() ? (this.parts().mat?.bottom_weight_mm ?? 0) : 0;
        const inside = this.parts().layers.slice(0, i).reduce((sum, l) => sum + layerWidth(l), 0);
        const add = 2 * (b + this.rebate() + inside);
        return this.options().frames.filter(
            (f) =>
                (!f.substrate_ids.length || (d.substrate_id !== null && f.substrate_ids.includes(d.substrate_id))) &&
                fits(this.pictureW() + add, this.pictureH() + add + bottom, f.max_inner_width_mm, f.max_inner_height_mm),
        );
    }
    protected glazings = computed(() => {
        const s = this.substrate();
        const area = (this.d().width_mm / 1000) * (this.d().height_mm / 1000);
        return this.options().glazings.filter((g) =>
            g.material === 'none' ? true : (!s || s.allows_glazing) && (!g.max_area_m2 || area <= g.max_area_m2),
        );
    });
    protected finishings = computed(() =>
        this.options().finishings.filter(
            (f) => !f.substrate_ids.length || (this.d().substrate_id && f.substrate_ids.includes(this.d().substrate_id!)),
        ),
    );
    protected matsAllowed = computed(() => !this.substrate() || this.substrate()!.allows_mat);
    protected edgeOptions = computed(() => {
        const s = this.substrate();
        if (!s || s.depth_mm <= 0 || !s.edge_styles.length) return [];
        return this.options().enums.edge_styles.filter((o) => s.edge_styles.includes(o.value));
    });
    protected matBorderText = computed(() => mmToUnit(this.matBorder(), this.d().unit));

    // Friendly warnings instead of silently changing the customer's choices.
    protected warnings = computed(() => {
        const d = this.d();
        const s = this.substrate();
        const w: string[] = [];
        // Said as they type, not only when saving (the preview can't show a size outside these).
        const sides = [d.width_mm, d.height_mm];
        if (sides.some((mm) => !mm || mm < 50)) w.push(d.unit === 'cm' ? 'The minimum size is 5 cm.' : 'The minimum size is 2 in.');
        if (sides.some((mm) => mm > 6000)) w.push(d.unit === 'cm' ? 'The maximum size is 600 cm.' : 'The maximum size is 236 in.');
        const photo = d.source_type === 'own_artwork' ? d.artwork_photo : null;
        if (photo && (/heic|heif/i.test(photo.type) || /\.(heic|heif)$/i.test(photo.name)))
            w.push('Your photo is in HEIC format: we receive it fine, but it can’t be previewed here. For a preview, use a JPEG or PNG.');
        if (d.medium_id && !this.mediums().some((m) => m.id === d.medium_id)) w.push('The chosen medium is not available for this artwork.');
        if (d.substrate_id && !this.substrates().some((x) => x.id === d.substrate_id)) w.push('The chosen surface does not suit this medium.');
        if (d.frame_id && !this.frames().some((f) => f.id === d.frame_id)) w.push('The chosen frame does not fit this size or surface.');
        const misfit = d.frame_id
            ? d.frame_layers.some((l, i) => {
                  const fitting = this.framesFor(i);
                  return (i > 0 && !fitting.some((f) => f.id === l.frame_id)) || (!!l.split && !fitting.some((f) => f.id === l.split!.frame_id));
              })
            : false;
        if (misfit) w.push('One of the extra frames does not fit this size or surface.');
        if (d.glazing_id && !this.glazings().some((g) => g.id === d.glazing_id))
            w.push('The chosen glazing is not available for this size or surface.');
        if (d.mat_id && !this.matsAllowed()) w.push(`${s?.name} cannot have a mat.`);
        if (s?.requires_frame && !d.frame_id && !d.frame_advice_requested && this.visited().has(4)) w.push(`${s.name} needs a frame — pick one or ask for our advice.`);
        return w;
    });
    protected errorCount = computed(() => Object.keys(this.form.errors()).length);
    /** No frame and no frame advice: glass and a mat are not offered (nor charged). */
    protected unframed = computed(() => !this.d().frame_id && !this.d().frame_advice_requested);

    /** Step 5 recap of everything chosen; a row with something missing is highlighted. */
    protected review = computed(() => {
        const d = this.d();
        const o = this.options();
        const unit = d.unit === 'cm' ? 'cm' : 'in';
        const hasPhoto = !!(d.artwork_photo || this.line()?.artwork_photo);
        const artwork =
            d.source_type === 'catalog'
                ? (this.artwork()?.title ?? '')
                : d.source_type === 'custom_creation'
                  ? d.title || (d.brief.description ? 'New creation: ' + d.brief.description : '')
                  : d.title || (hasPhoto ? 'My artwork (photo added)' : '');
        const turned = d.source_type === 'catalog' && ((d.image_rotation ?? 0) / 90) % 2 === 1 ? ' · sides swapped' : '';
        const name = (list: { id: number; name: string }[], id: number | null, fallback: string) => list.find((x) => x.id === id)?.name ?? fallback;
        const frame = d.frame_id ? this.layersSummary() : d.frame_advice_requested ? 'Advise me' : 'No frame';
        const extras = this.unframed() ? '' : [d.mat_id && this.matsAllowed() ? 'Mat ' + this.matSummary() : '', this.glazingSummary()].filter(Boolean).join(' · ');
        const steps = this.steps();
        return [
            { step: 1, label: 'Artwork', value: artwork || 'Not chosen yet', missing: !steps[0].done },
            {
                step: 2,
                label: 'Size',
                value: `${mmToUnit(d.width_mm, d.unit)} × ${mmToUnit(d.height_mm, d.unit)} ${unit}${turned}${d.quantity > 1 ? ' · ×' + d.quantity : ''}`,
                missing: false,
            },
            {
                step: 3,
                label: 'Materials',
                value: [name(o.quality_tiers, d.quality_tier_id, ''), name(o.mediums, d.medium_id, 'Medium: advise me'), name(o.substrates, d.substrate_id, 'Surface: advise me')]
                    .filter(Boolean)
                    .join(' · '),
                missing: false,
            },
            { step: 4, label: 'Frame', value: frame + (extras ? ' · ' + extras : ''), missing: !steps[3].done },
        ];
    });

    // Closing with unsaved changes (backdrop, Escape, X, Cancel) asks first.
    private snapshot = '';
    private serialise(): string {
        return JSON.stringify(this.d(), (_k, v) => (v instanceof File ? `file:${v.name}:${v.size}` : v));
    }
    private confirm = inject(Confirm);
    /**
     * Closing with unsaved changes asks first (in the app's own dialog). Returns true when it can close now;
     * otherwise it asks and closes once the customer agrees.
     */
    protected confirmClose = (): boolean => {
        if (this.form.processing() || this.serialise() === this.snapshot) return true;
        void this.confirm
            .ask({ title: 'Discard your changes?', message: 'The changes to this artwork are not saved yet.', confirm: 'Discard', cancel: 'Keep editing', destructive: true })
            .then((ok) => {
                if (!ok) return;
                this.snapshot = this.serialise();
                this.open.set(false);
            });
        return false;
    };

    /** Enter in a field moves to the next step instead of saving the whole form (Enter on step 5 saves). */
    protected enterNext(event: Event): void {
        const target = event.target as HTMLElement;
        // The previews' own fields (wall photo measurements…) handle Enter themselves: never a step change or a save.
        if (target.closest('wall-preview')) {
            event.preventDefault();
            return;
        }
        if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON' || this.step() >= 5) return;
        event.preventDefault();
        // Commit the field being typed in (sizes apply on change/blur) before its step goes away.
        target.dispatchEvent(new Event('change', { bubbles: true }));
        target.blur();
        this.goTo(this.step() + 1);
    }

    protected cancel(): void {
        if (this.confirmClose()) this.open.set(false);
    }

    // ---- preview image ----
    private objectUrl = signal<string | null>(null);
    protected existingReferences = computed(() => (this.line()?.references ?? []).filter((m) => !this.d().remove_media.includes(m.id)));
    /** Catalogue artworks have a 4096 px version for close-up inspection; uploads use the preview. */
    /** The original picture for the full-page viewer: the catalogue original, or the customer's own upload. */
    protected fullImage = computed(() => (this.d().source_type === 'catalog' ? (this.artwork()?.image_full ?? null) : this.previewImage()));
    protected detailImage = computed(() => (this.d().source_type === 'catalog' ? (this.artwork()?.image_detail ?? null) : null));
    /** The name shown on the full-size viewer: the painting's title, or the customer's own title. */
    protected previewTitle = computed(() => {
        const d = this.d();
        return (d.source_type === 'catalog' ? this.artwork()?.title : d.title) || 'Your artwork';
    });
    protected previewImage = computed(() => {
        const d = this.d();
        if (d.source_type === 'catalog') return this.artwork()?.image ?? null;
        if (this.objectUrl()) return this.objectUrl();
        const line = this.line();
        if (d.source_type === 'own_artwork') return line?.artwork_photo?.is_image ? line.artwork_photo.url : null;
        return this.existingReferences().find((r) => r.is_image)?.url ?? null;
    });
    protected parts = computed(() => previewParts(this.options(), this.d()));
    // With "outer size", the picture is what's left inside the mat and frame.
    /** A mat is drawn, sized and sent only with a frame (or frame advice), on a surface that allows one. */
    protected hasMat = computed(() => !!this.d().mat_id && this.matsAllowed() && !this.unframed());
    /** The rebate gap around the mat/picture when framed (pricing counts it in the outer size). */
    protected rebate = computed(() => (this.parts().layers.length ? (this.options().rebate_allowance_mm ?? 0) : 0));
    private surround = computed(
        () => 2 * (framesWidth(this.parts().frame, this.parts().layers) + this.rebate() + (this.hasMat() ? (this.parts().mat?.border_mm ?? 0) : 0)),
    );
    protected pictureW = computed(() =>
        this.d().size_reference === 'outer' ? Math.max(50, this.d().width_mm - this.surround()) : this.d().width_mm,
    );
    protected pictureH = computed(() =>
        this.d().size_reference === 'outer'
            ? Math.max(50, this.d().height_mm - this.surround() - (this.hasMat() ? (this.parts().mat?.bottom_weight_mm ?? 0) : 0))
            : this.d().height_mm,
    );

    // ---- live price (calculated on the server) ----
    protected live = liveEstimate(
        this.enquiryId,
        computed(() => {
            const d = this.d();
            const [width_mm, height_mm] = unturnedSize(d);
            return {
                source_type: d.source_type,
                artwork_id: d.artwork_id,
                size_reference: d.size_reference,
                width_mm,
                height_mm,
                quantity: d.quantity,
                quality_tier_id: d.quality_tier_id,
                medium_id: d.medium_id,
                substrate_id: d.substrate_id,
                frame_id: d.frame_id,
                frame_layers: d.frame_id ? d.frame_layers : [],
                frame_advice_requested: d.frame_advice_requested,
                mat_id: this.hasMat() ? d.mat_id : null,
                mat_border_mm: this.hasMat() ? d.mat_border_mm : null,
                glazing_id: d.glazing_id,
                finishing_ids: d.finishing_ids,
                own: { service: d.own?.service },
            };
        }),
        computed(() => this.open() && !!this.d().source_type),
    );

    constructor() {
        // Reset whenever the dialog opens (for a new line or another line) — but NOT when the page's data merely
        // reloads (a failed save reloads the props: the form, its errors and the current step must survive that).
        let openedFor: string | null = null;
        effect(() => {
            const key = this.open() ? `line:${this.line()?.id ?? 'new'}` : null;
            if (key === openedFor) return;
            openedFor = key;
            if (!key) return;
            untracked(() => {
                const fresh = initialLineForm(this.line(), this.options());
                this.form.setDefaults(fresh);
                this.form.setAll(fresh);
                this.form.clearErrors();
                this.snapshot = this.serialise();
                // Editing an existing artwork: every step is already filled in.
                this.step.set(1);
                this.visited.set(new Set(this.line() ? [1, 2, 3, 4, 5] : [1]));
            });
        });

        // After a failed save, show the first step that needs attention.
        effect(() => {
            const keys = Object.keys(this.form.errors());
            if (!keys.length) return;
            const first = Math.min(...keys.map(stepOfField));
            untracked(() => {
                if (!keys.some((k) => stepOfField(k) === this.step())) this.goTo(first);
            });
        });

        // Object URL for a freshly picked image (own artwork photo / first reference).
        let url: string | null = null;
        const picked = computed(() => {
            const d = this.d();
            return d.source_type === 'own_artwork' ? d.artwork_photo : d.source_type === 'custom_creation' ? (d.references?.[0] ?? null) : null;
        });
        effect(() => {
            const file = picked(); // reruns only when the File itself changes
            if (url) URL.revokeObjectURL(url);
            url = file && file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
            this.objectUrl.set(url);
        });
        inject(DestroyRef).onDestroy(() => url && URL.revokeObjectURL(url));
    }

    // ---- actions ----

    protected tile(active: boolean): string {
        return OPTION_TILE + ' ' + (active ? 'border-primary ring-primary ring-2' : 'hover:bg-muted');
    }

    protected swatch(active: boolean): string {
        return SWATCH + ' ' + (active ? 'border-primary ring-primary ring-2' : 'hover:bg-muted');
    }

    protected without<T>(list: T[], index: number): T[] {
        return list.filter((_, j) => j !== index);
    }

    protected toUnit(mm: number | null): string {
        return mmToUnit(mm, this.d().unit);
    }

    chooseArtwork(a: CatalogArtwork): void {
        const size = a.sizes[0] ?? (a.native_width_mm && a.native_height_mm ? { width_mm: a.native_width_mm, height_mm: a.native_height_mm } : null);
        this.form.update((d) => ({
            ...d,
            artwork_id: a.id,
            image_rotation: 0,
            // Listed sizes are picture sizes: no outer size for a fixed-size artwork.
            size_reference: a.allow_custom_size ? d.size_reference : 'image',
            width_mm: size?.width_mm ?? d.width_mm,
            height_mm: size?.height_mm ?? d.height_mm,
            medium_id: a.default_medium_id ?? d.medium_id,
        }));
    }

    applyTier(id: number): void {
        const tier = this.options().quality_tiers.find((t) => t.id === id);
        if (!tier) return;
        // No frame (and no frame advice) means no glass and no mat: the tier's defaults apply once framed.
        const unframed = this.unframed();
        this.form.update((d) => ({
            ...d,
            quality_tier_id: id,
            medium_id: tier.default_medium_id ?? d.medium_id,
            substrate_id: tier.default_substrate_id ?? d.substrate_id,
            glazing_id: unframed ? d.glazing_id : (tier.default_glazing_id ?? d.glazing_id),
            mat_id: unframed ? null : (tier.default_mat_id ?? d.mat_id),
        }));
    }

    setBrief<K extends keyof LineForm['brief']>(key: K, value: LineForm['brief'][K]): void {
        this.form.update((d) => ({ ...d, brief: { ...d.brief, [key]: value } }));
    }

    setMood(text: string): void {
        this.setBrief(
            'mood',
            text
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean)
                .slice(0, 10),
        );
    }

    /**
     * The kind of artwork. "My artwork" is about framing the customer's piece: with no frame chosen yet it starts
     * on "Advise me" (we suggest one), instead of the save stopping later on a surface that must be framed.
     */
    chooseSource(value: LineForm['source_type']): void {
        this.form.update((d) => ({
            ...d,
            source_type: value,
            frame_advice_requested: value === 'own_artwork' && !d.frame_id ? true : d.frame_advice_requested,
        }));
    }

    setOwn(key: 'service' | 'condition_notes', value: string): void {
        this.form.update((d) => ({ ...d, own: { ...d.own, [key]: value } }));
    }

    setId(key: 'medium_id' | 'substrate_id' | 'glazing_id', value: string): void {
        this.form.set(key, value ? Number(value) : null);
    }

    setFrame(id: number | null, advice: boolean): void {
        const o = this.options();
        const noGlass = o.glazings.find((g) => g.material === 'none')?.id ?? null;
        const tierGlass = o.quality_tiers.find((t) => t.id === this.d().quality_tier_id)?.default_glazing_id ?? null;
        const unframed = !id && !advice;
        this.form.update((d) => ({
            ...d,
            frame_id: id,
            // The picker above is the inner frame: frames around it (and its split) stay; no frame / advice clears them all.
            frame_layers: id
                ? [{ frame_id: id, split: d.frame_layers[0]?.split && d.frame_layers[0].split.frame_id !== id ? d.frame_layers[0].split : null }, ...d.frame_layers.slice(1)]
                : [],
            frame_advice_requested: advice,
            // Without a frame there is no glass or mat; picking a frame again brings the tier's glass back.
            mat_id: unframed ? null : d.mat_id,
            glazing_id: unframed ? noGlass : d.glazing_id === noGlass && tierGlass ? tierGlass : d.glazing_id,
        }));
    }

    // ---- several frames: stacked layers (innermost first), each optionally split ----
    protected readonly maxLayers = 3;
    protected readonly splitModes: { value: FrameSplitMode; label: string; hint: string }[] = [
        { value: 'top_bottom_sides', label: 'Top & bottom / sides', hint: 'One moulding on top and bottom, another on the sides' },
        { value: 'halves', label: 'Top half / bottom half', hint: 'One moulding on the top half, another on the bottom half' },
    ];

    protected layerTitle(i: number): string {
        return i === 0 ? 'Frame 1 (inner)' : `Frame ${i + 1} (around frame ${i})`;
    }

    protected frameName(id: number | null): string {
        return this.options().frames.find((f) => f.id === id)?.name ?? 'Frame';
    }

    /** e.g. "Blue ribbed + Gold around · Frame 1 split: top half / bottom half Walnut". */
    protected layersSummary = computed(() => {
        const d = this.d();
        const layers: FrameLayerInput[] = d.frame_layers.length ? d.frame_layers : d.frame_id ? [{ frame_id: d.frame_id, split: null }] : [];
        const main = layers.map((l, i) => this.frameName(l.frame_id) + (i > 0 ? ' around' : '')).join(' + ');
        const splits = layers.flatMap((l, i) =>
            l.split
                ? [`Frame ${i + 1} split: ${l.split.mode === 'halves' ? 'top half / bottom half' : 'top & bottom / sides'} ${this.frameName(l.split.frame_id)}`]
                : [],
        );
        return [main, ...splits].join(' · ');
    });

    /** A server error on any layer (e.g. "frame_layers.1.frame_id"). */
    protected layerError = computed(() => {
        const errors = this.form.errors() as Record<string, string | undefined>;
        const key = Object.keys(errors).find((k) => k.startsWith('frame_layers'));
        return key ? errors[key] : undefined;
    });

    /** Changes the layers, keeping frame_id = the inner layer's frame. */
    private setLayers(fn: (layers: FrameLayerInput[]) => FrameLayerInput[]): void {
        this.form.update((d) => {
            const layers = fn(d.frame_layers.map((l) => ({ ...l, split: l.split ? { ...l.split } : null }))).slice(0, this.maxLayers);
            return { ...d, frame_layers: layers, frame_id: layers[0]?.frame_id ?? null };
        });
    }

    addLayer(): void {
        const d = this.d();
        if (!d.frame_id) return;
        // A line saved before layers existed has only frame_id: that is layer 1.
        const base: FrameLayerInput[] = d.frame_layers.length ? d.frame_layers : [{ frame_id: d.frame_id, split: null }];
        if (base.length >= this.maxLayers) return;
        // Starts with the same moulding as the frame inside it; the customer picks another.
        this.setLayers(() => [...base, { frame_id: base[base.length - 1].frame_id, split: null }]);
    }

    removeLayer(i: number): void {
        if (i > 0) this.setLayers((layers) => layers.filter((_, j) => j !== i));
    }

    setLayerFrame(i: number, id: number): void {
        // Picking the split's moulding as the layer's own frame drops the split (it would be one moulding).
        this.setLayers((layers) => layers.map((l, j) => (j === i ? { ...l, frame_id: id, split: l.split && l.split.frame_id !== id ? l.split : null } : l)));
    }

    /** The frames a split can use: any that fits except the moulding being split. */
    protected framesExcept(id: number, layer = 0): CatalogOptions['frames'] {
        return this.framesFor(layer).filter((f) => f.id !== id);
    }

    toggleSplit(i: number, on: boolean): void {
        // The second moulding starts on a different frame than the first, so the split is visible at once.
        const layer = this.d().frame_layers[i];
        const other = layer ? this.framesExcept(layer.frame_id, i)[0]?.id : undefined;
        if (on && !other) return;
        this.setLayers((layers) =>
            layers.map((l, j) => (j === i ? { ...l, split: on && other ? { frame_id: other, mode: 'top_bottom_sides' as FrameSplitMode } : null } : l)),
        );
    }

    setSplitMode(i: number, mode: FrameSplitMode): void {
        this.setLayers((layers) => layers.map((l, j) => (j === i && l.split ? { ...l, split: { ...l.split, mode } } : l)));
    }

    setSplitFrame(i: number, id: number): void {
        this.setLayers((layers) => layers.map((l, j) => (j === i && l.split ? { ...l, split: { ...l.split, frame_id: id } } : l)));
    }

    setMatBorder(value: string): void {
        this.form.set('mat_border_mm', unitToMm(value, this.d().unit) || null);
    }

    setWall(key: 'wall_width_mm' | 'wall_height_mm', value: string): void {
        this.form.set(key, value ? unitToMm(value, this.d().unit) : null);
    }

    toggleFinishing(id: number, on: boolean): void {
        const ids = this.d().finishing_ids;
        this.form.set('finishing_ids', on ? [...ids, id] : ids.filter((x) => x !== id));
    }

    submit(event: Event): void {
        event.preventDefault();
        const line = this.line();
        const url = line
            ? route('portal.enquiries.lines.update', [this.enquiryId(), line.id])
            : route('portal.enquiries.lines.store', this.enquiryId());
        const matsAllowed = this.matsAllowed();
        const edgeValues = this.edgeOptions().map((o) => o.value as string);
        this.form.transform((d) => ({
            ...d,
            // The server keeps the painting's own (unturned) size plus how far it is turned.
            width_mm: unturnedSize(d)[0],
            height_mm: unturnedSize(d)[1],
            image_rotation: d.source_type === 'catalog' ? d.image_rotation : 0,
            mat_id: matsAllowed && (d.frame_id || d.frame_advice_requested) ? d.mat_id : null,
            frame_advice_requested: d.frame_id ? false : d.frame_advice_requested,
            // Only the files (and catalogue artwork) of the chosen kind of artwork go up.
            artwork_id: d.source_type === 'catalog' ? d.artwork_id : null,
            references: d.source_type === 'custom_creation' ? d.references : [],
            artwork_photo: d.source_type === 'own_artwork' ? d.artwork_photo : null,
            hi_res: d.source_type === 'own_artwork' && d.own.service === 'reproduce_and_frame' ? d.hi_res : null,
            // Multipart form: the layers go as JSON text (the server decodes it). Empty without a frame.
            frame_layers: d.frame_id && d.frame_layers.length ? JSON.stringify(d.frame_layers) : '',
            // An edge style only for a surface that offers it.
            edge_style: edgeValues.includes(d.edge_style ?? '') ? d.edge_style : null,
        }));
        this.form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => this.open.set(false),
        });
    }
}
