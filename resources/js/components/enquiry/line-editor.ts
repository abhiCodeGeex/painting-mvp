import { InertiaForm } from '@/inertia/form';
import { fits, mmToUnit, unitToMm } from '@/lib/units';
import type { CatalogArtwork, CatalogOptions, EnquiryLine } from '@/types/portal';
import { UiCheckbox } from '@/ui/checkbox';
import { UiDialog } from '@/ui/dialog';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, inject, input, model, signal, untracked } from '@angular/core';
import { Brush, Frame as FrameIcon, Images, LoaderCircle } from 'lucide';
import { CatalogPicker, ChoiceRow, FileGallery, PaletteInput, SizeFields } from './editor-parts';
import { FramedArt } from './framed-art';
import { initialLineForm, type LineForm } from './line-form';
import { previewParts, WALL_COLOURS } from './preview-helpers';
import { liveEstimate, PriceEstimateBox } from './price-estimate';
import { WallPreview } from './wall-preview';

const OPTION_TILE = 'flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition';
const SWATCH = 'flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1 text-xs transition';
const STEP_NUMBER = 'bg-primary text-primary-foreground flex size-6 items-center justify-center rounded-full text-xs';

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
        FramedArt,
        WallPreview,
        PriceEstimateBox,
    ],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [(open)]="open" contentClass="flex h-[95vh] max-h-[95vh] w-[98vw] max-w-6xl flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl">
            @if (open() && d().source_type) {
                <div class="flex flex-col space-y-1.5 border-b px-6 py-4 text-center sm:text-left">
                    <h2 class="text-lg leading-none font-semibold tracking-tight">{{ line() ? 'Edit artwork' : 'Add an artwork' }}</h2>
                    <p class="text-muted-foreground text-sm">Choose the artwork, size and framing. The preview on the right updates as you go.</p>
                </div>

                <form class="flex min-h-0 flex-1 flex-col lg:flex-row" (submit)="submit($event)">
                    <!-- left: the form -->
                    <div class="min-h-0 flex-1 space-y-8 overflow-y-auto px-6 py-6">
                        <section class="space-y-4">
                            <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">1</span>What would you like?</h3>
                            <div class="grid gap-3 sm:grid-cols-3">
                                @for (s of sources; track s.value) {
                                    <button
                                        type="button"
                                        [class]="
                                            'flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition ' +
                                            (d().source_type === s.value ? 'border-primary ring-primary bg-primary/5 ring-2' : 'hover:bg-muted')
                                        "
                                        (click)="form.set('source_type', s.value)"
                                    >
                                        <svg [icon]="s.icon" class="size-6" />
                                        <span class="font-medium">{{ s.title }}</span>
                                        <span class="text-muted-foreground text-xs">{{ s.text }}</span>
                                    </button>
                                }
                            </div>
                        </section>

                        @switch (d().source_type) {
                            @case ('catalog') {
                                <section class="space-y-4">
                                    <h3 class="flex items-center gap-2 text-base font-semibold">
                                        <span [class]="stepNumber">2</span>Choose an artwork
                                    </h3>
                                    <catalog-picker [options]="options()" [selectedId]="d().artwork_id" (selected)="chooseArtwork($event)" />
                                    <input-error [message]="form.error('artwork_id')" />
                                </section>
                            }
                            @case ('custom_creation') {
                                <section class="space-y-4">
                                    <h3 class="flex items-center gap-2 text-base font-semibold">
                                        <span [class]="stepNumber">2</span>Tell us about the artwork
                                    </h3>
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
                                            <select ui-select [value]="d().brief.style" (change)="setBrief('style', $any($event.target).value)">
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
                                        <palette-input [value]="d().brief.palette" [wall]="d().wall_colour" (changed)="setBrief('palette', $event)" />
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
                                    <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">2</span>Your artwork</h3>
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
                                        <ui-checkbox [checked]="d().rights_confirmed" (checkedChange)="form.set('rights_confirmed', $event)" />
                                        <span>I own the rights to this image or have permission to reproduce it.</span>
                                    </label>
                                    <input-error [message]="form.error('rights_confirmed')" />
                                </section>
                            }
                        }

                        <section class="space-y-4">
                            <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">3</span>Size & quantity</h3>
                            <size-fields [form]="form" [artwork]="d().source_type === 'catalog' ? artwork() : null" [options]="options()" />
                            <input-error [message]="form.error('width_mm') ?? form.error('height_mm')" />
                        </section>

                        <section class="space-y-4">
                            <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">4</span>Quality & materials</h3>
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
                                    <choice-row [value]="d().edge_style ?? ''" [options]="edgeOptions()" (changed)="form.set('edge_style', $event)" />
                                </div>
                            }
                        </section>

                        <section class="space-y-4">
                            <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">5</span>Framing</h3>
                            <div class="grid gap-1.5">
                                <label ui-label>Frame</label>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                    <button
                                        type="button"
                                        [class]="tile(!d().frame_id && !d().frame_advice_requested)"
                                        (click)="setFrame(null, false)"
                                    >
                                        <span class="flex h-14 items-center justify-center">
                                            <span
                                                class="block h-12 w-9 rounded-sm"
                                                style="background: linear-gradient(135deg, #f6d365, #a1c4fd)"
                                            ></span>
                                        </span>
                                        <span class="text-xs leading-tight font-medium">No frame</span>
                                    </button>
                                    <button type="button" [class]="tile(d().frame_advice_requested && !d().frame_id)" (click)="setFrame(null, true)">
                                        <span class="flex h-14 items-center justify-center"
                                            ><span class="text-muted-foreground flex h-12 items-center text-2xl">?</span></span
                                        >
                                        <span class="text-xs leading-tight font-medium">Advise me</span>
                                    </button>
                                    @for (f of frames(); track f.id) {
                                        <button type="button" [class]="tile(d().frame_id === f.id)" (click)="setFrame(f.id, false)">
                                            <span class="flex h-14 items-center justify-center">
                                                <framed-art
                                                    [imageUrl]="null"
                                                    [widthMm]="300"
                                                    [heightMm]="400"
                                                    [frame]="{ colour: f.colour, width_mm: f.width_mm, finish: f.finish }"
                                                    [mat]="null"
                                                    [scale]="48 / (400 + 2 * f.width_mm)"
                                                />
                                            </span>
                                            <span class="text-xs leading-tight font-medium">{{ f.name }}</span>
                                            <span class="text-muted-foreground -mt-1 text-[11px]">{{ f.width_mm }} mm · {{ f.material }}</span>
                                        </button>
                                    }
                                </div>
                                <input-error [message]="form.error('frame_id')" />
                            </div>

                            @if (matsAllowed()) {
                                <div class="grid gap-1.5">
                                    <label ui-label>Mat (border inside the frame)</label>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" title="None" [class]="swatch(!d().mat_id)" (click)="form.set('mat_id', null)">
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
                                    <input-error [message]="form.error('mat_id')" />
                                </div>
                            }

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <label ui-label>Glass / glazing</label>
                                    <select ui-select (change)="setId('glazing_id', $any($event.target).value)">
                                        <option value="" [selected]="!d().glazing_id">Not sure — advise me</option>
                                        @for (g of glazings(); track g.id) {
                                            <option [value]="g.id" [selected]="d().glazing_id === g.id">{{ g.name }}</option>
                                        }
                                    </select>
                                    <input-error [message]="form.error('glazing_id')" />
                                </div>
                                @if (finishings().length > 0) {
                                    <div class="grid gap-1.5">
                                        <label ui-label>Hanging & finishing</label>
                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
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
                                    </div>
                                }
                            </div>
                        </section>

                        <section class="space-y-4">
                            <h3 class="flex items-center gap-2 text-base font-semibold"><span [class]="stepNumber">6</span>Where will it hang?</h3>
                            <div class="grid gap-1.5">
                                <label ui-label>Wall colour</label>
                                <div class="flex flex-wrap items-center gap-2">
                                    @for (c of wallColours; track c.hex) {
                                        <button
                                            type="button"
                                            [title]="c.name"
                                            [class]="swatch(d().wall_colour === c.hex)"
                                            (click)="form.set('wall_colour', c.hex)"
                                        >
                                            <span class="size-6 rounded-full border" [style.background]="c.hex"></span>
                                            {{ c.name }}
                                        </button>
                                    }
                                    <label class="flex items-center gap-1 text-xs">
                                        <input
                                            type="color"
                                            class="size-8 cursor-pointer rounded border"
                                            [value]="d().wall_colour"
                                            (input)="form.set('wall_colour', $any($event.target).value.toUpperCase())"
                                        />
                                        Custom
                                    </label>
                                </div>
                                <p class="text-muted-foreground text-xs">Used for the preview only.</p>
                            </div>
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
                                </div>
                            </div>
                            <div class="grid gap-1.5">
                                <label ui-label>Anything else we should know? (optional)</label>
                                <textarea ui-textarea rows="2" [value]="d().notes" (input)="form.set('notes', $any($event.target).value)"></textarea>
                            </div>
                        </section>
                    </div>

                    <!-- right: live preview. The panel scrolls on its own; the action buttons stay pinned at the bottom. -->
                    <aside
                        class="bg-muted/30 flex max-h-[55%] shrink-0 flex-col border-t lg:max-h-none lg:min-h-0 lg:w-[440px] lg:border-t-0 lg:border-l"
                    >
                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4 lg:p-6">
                            <wall-preview
                                [imageUrl]="previewImage()"
                                [widthMm]="pictureW()"
                                [heightMm]="pictureH()"
                                [frame]="parts().frame"
                                [mat]="d().mat_id && matsAllowed() ? parts().mat : null"
                                [depthMm]="parts().depthMm"
                                [edgeStyle]="d().edge_style"
                                [glazing]="parts().glazing"
                                [wallColour]="d().wall_colour"
                                [wallWidthMm]="d().wall_width_mm"
                                [wallHeightMm]="d().wall_height_mm"
                                [unit]="d().unit"
                                [quantity]="d().quantity"
                            />
                            <p class="text-muted-foreground hidden text-xs lg:block">
                                Preview is indicative: colours and textures will differ slightly from the real piece.
                            </p>

                            <price-estimate-box [estimate]="live.estimate()" [loading]="live.loading()" />

                            @if (warnings().length > 0) {
                                <ul
                                    class="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                                >
                                    @for (w of warnings(); track w) {
                                        <li>• {{ w }}</li>
                                    }
                                </ul>
                            }

                            @if (errorCount() > 0) {
                                <p class="rounded-md border border-red-300 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                                    Please check the {{ errorCount() === 1 ? 'highlighted field' : errorCount() + ' highlighted fields' }}.
                                </p>
                            }
                        </div>

                        <div class="bg-background/80 flex gap-2 border-t px-4 py-3 lg:px-6">
                            <button ui-button type="button" variant="outline" class="flex-1" (click)="open.set(false)">Cancel</button>
                            <button ui-button type="submit" class="flex-1" [disabled]="form.processing()">
                                @if (form.processing()) {
                                    <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                                }
                                {{ line() ? 'Save changes' : 'Add to enquiry' }}
                            </button>
                        </div>
                    </aside>
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
    protected readonly wallColours = WALL_COLOURS;
    protected readonly stepNumber = STEP_NUMBER;
    protected readonly sources = [
        { value: 'catalog' as const, icon: Images, title: 'From our catalog', text: 'Pick a painting or print we offer.' },
        { value: 'custom_creation' as const, icon: Brush, title: 'A new creation', text: 'We paint something new for you.' },
        { value: 'own_artwork' as const, icon: FrameIcon, title: 'My own artwork', text: 'Frame or reproduce a piece you have.' },
    ];

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
    protected frames = computed(() => {
        const d = this.d();
        const b = this.matBorder();
        return this.options().frames.filter(
            (f) =>
                (!f.substrate_ids.length || (d.substrate_id !== null && f.substrate_ids.includes(d.substrate_id))) &&
                fits(d.width_mm + 2 * b, d.height_mm + 2 * b, f.max_inner_width_mm, f.max_inner_height_mm),
        );
    });
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
        if (d.medium_id && !this.mediums().some((m) => m.id === d.medium_id)) w.push('The chosen medium is not available for this artwork.');
        if (d.substrate_id && !this.substrates().some((x) => x.id === d.substrate_id)) w.push('The chosen surface does not suit this medium.');
        if (d.frame_id && !this.frames().some((f) => f.id === d.frame_id)) w.push('The chosen frame does not fit this size or surface.');
        if (d.glazing_id && !this.glazings().some((g) => g.id === d.glazing_id))
            w.push('The chosen glazing is not available for this size or surface.');
        if (d.mat_id && !this.matsAllowed()) w.push(`${s?.name} cannot have a mat.`);
        if (s?.requires_frame && !d.frame_id && !d.frame_advice_requested) w.push(`${s.name} needs a frame — pick one or ask for our advice.`);
        return w;
    });
    protected errorCount = computed(() => Object.keys(this.form.errors()).length);

    // ---- preview image ----
    private objectUrl = signal<string | null>(null);
    protected existingReferences = computed(() => (this.line()?.references ?? []).filter((m) => !this.d().remove_media.includes(m.id)));
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
    private surround = computed(
        () => 2 * ((this.parts().frame?.width_mm ?? 0) + (this.d().mat_id && this.matsAllowed() ? (this.parts().mat?.border_mm ?? 0) : 0)),
    );
    protected pictureW = computed(() =>
        this.d().size_reference === 'outer' ? Math.max(50, this.d().width_mm - this.surround()) : this.d().width_mm,
    );
    protected pictureH = computed(() =>
        this.d().size_reference === 'outer' ? Math.max(50, this.d().height_mm - this.surround()) : this.d().height_mm,
    );

    // ---- live price (calculated on the server) ----
    protected live = liveEstimate(
        this.enquiryId,
        computed(() => {
            const d = this.d();
            return {
                source_type: d.source_type,
                artwork_id: d.artwork_id,
                size_reference: d.size_reference,
                width_mm: d.width_mm,
                height_mm: d.height_mm,
                quantity: d.quantity,
                quality_tier_id: d.quality_tier_id,
                medium_id: d.medium_id,
                substrate_id: d.substrate_id,
                frame_id: d.frame_id,
                frame_advice_requested: d.frame_advice_requested,
                mat_id: this.matsAllowed() ? d.mat_id : null,
                mat_border_mm: d.mat_id ? d.mat_border_mm : null,
                glazing_id: d.glazing_id,
                finishing_ids: d.finishing_ids,
                own: { service: d.own?.service },
            };
        }),
        computed(() => this.open() && !!this.d().source_type),
    );

    constructor() {
        // Reset whenever the dialog opens (for a new line or another line).
        effect(() => {
            if (!this.open()) return;
            const fresh = initialLineForm(this.line(), this.options());
            untracked(() => {
                this.form.setDefaults(fresh);
                this.form.setAll(fresh);
                this.form.clearErrors();
            });
        });

        // Object URL for a freshly picked image (own artwork photo / first reference).
        let url: string | null = null;
        effect(() => {
            const d = this.d();
            const file = d.source_type === 'own_artwork' ? d.artwork_photo : d.source_type === 'custom_creation' ? (d.references?.[0] ?? null) : null;
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
            width_mm: size?.width_mm ?? d.width_mm,
            height_mm: size?.height_mm ?? d.height_mm,
            medium_id: a.default_medium_id ?? d.medium_id,
        }));
    }

    applyTier(id: number): void {
        const tier = this.options().quality_tiers.find((t) => t.id === id);
        if (!tier) return;
        this.form.update((d) => ({
            ...d,
            quality_tier_id: id,
            medium_id: tier.default_medium_id ?? d.medium_id,
            substrate_id: tier.default_substrate_id ?? d.substrate_id,
            glazing_id: tier.default_glazing_id ?? d.glazing_id,
            mat_id: tier.default_mat_id ?? d.mat_id,
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

    setOwn(key: 'service' | 'condition_notes', value: string): void {
        this.form.update((d) => ({ ...d, own: { ...d.own, [key]: value } }));
    }

    setId(key: 'medium_id' | 'substrate_id' | 'glazing_id', value: string): void {
        this.form.set(key, value ? Number(value) : null);
    }

    setFrame(id: number | null, advice: boolean): void {
        this.form.update((d) => ({ ...d, frame_id: id, frame_advice_requested: advice }));
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
        this.form.transform((d) => ({
            ...d,
            mat_id: matsAllowed ? d.mat_id : null,
            frame_advice_requested: d.frame_id ? false : d.frame_advice_requested,
        }));
        this.form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => this.open.set(false),
        });
    }
}
