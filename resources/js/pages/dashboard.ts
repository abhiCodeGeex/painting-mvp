import { InertiaHead } from '@/inertia/head';
import { InertiaLink } from '@/inertia/link';
import { pageProps } from '@/inertia/page';
import { InertiaRouter } from '@/inertia/router';
import { AppLayout } from '@/layouts/app-layout';
import { formatDate } from '@/lib/units';
import type { EnquirySummary } from '@/types/portal';
import { Icon } from '@/ui/icon';
import { UiButton } from '@/ui/primitives';
import { StatusBadge } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { Brush, Frame, Images, MapPin, MessageCircleQuestion, Plus } from 'lucide';

interface Props {
    stats: { drafts: number; in_review: number; needs_reply: number; total: number };
    actionNeeded: EnquirySummary[];
    recent: EnquirySummary[];
    hasAddress: boolean;
}

@Component({
    imports: [InertiaHead, InertiaLink, AppLayout, Icon, UiButton, StatusBadge],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'Dashboard', href: '/dashboard' }]">
            <inertia-head title="Dashboard" />
            <div class="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Welcome, {{ firstName() }}</h1>
                        <p class="text-muted-foreground text-sm">Plan your artworks, preview them on your wall, and get a quotation.</p>
                    </div>
                    <button ui-button size="lg" type="button" (click)="startNew()"><svg [icon]="Plus" class="size-4" /> New enquiry</button>
                </div>

                @if (props().actionNeeded.length > 0) {
                    <section class="space-y-2">
                        <h2 class="flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300">
                            <svg [icon]="MessageCircleQuestion" class="size-5" /> We need your reply
                        </h2>
                        @for (e of props().actionNeeded; track e.id) {
                            <a
                                [link]="r('portal.enquiries.show', e.id) + '#messages'"
                                class="flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30"
                            >
                                <span
                                    ><strong>{{ e.project_name }}</strong> · {{ e.display_reference }}</span
                                >
                                <span>Reply →</span>
                            </a>
                        }
                    </section>
                }

                <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @for (s of stats(); track s.label) {
                        <div
                            [class]="
                                s.highlight ? 'rounded-xl border border-amber-300 bg-amber-50 p-4 dark:bg-amber-950/30' : 'rounded-xl border p-4'
                            "
                        >
                            <div class="text-2xl font-semibold">{{ s.value }}</div>
                            <div class="text-muted-foreground text-xs">{{ s.label }}</div>
                        </div>
                    }
                </section>

                @if (props().recent.length > 0) {
                    <section class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h2 class="font-semibold">Recent enquiries</h2>
                            <a [link]="r('portal.enquiries.index')" class="text-muted-foreground text-sm hover:underline">See all</a>
                        </div>
                        <ul class="divide-y rounded-xl border">
                            @for (e of props().recent; track e.id) {
                                <li>
                                    <a
                                        [link]="e.is_draft ? r('portal.enquiries.edit', e.id) : r('portal.enquiries.show', e.id)"
                                        class="hover:bg-muted/50 flex items-center justify-between gap-3 p-4 text-sm"
                                    >
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium">{{ e.project_name }}</span>
                                            <span class="text-muted-foreground text-xs">
                                                {{ e.display_reference }} · {{ e.lines_count }} artworks · {{ formatDate(e.updated_at) }}
                                            </span>
                                        </span>
                                        <status-badge [label]="e.status_label" [color]="e.status_color" />
                                    </a>
                                </li>
                            }
                        </ul>
                    </section>
                } @else {
                    <section class="grid gap-4 md:grid-cols-3">
                        @for (c of howCards; track c.title) {
                            <div class="rounded-xl border p-5">
                                <div class="text-muted-foreground mb-3"><svg [icon]="c.icon" class="size-6" /></div>
                                <div class="font-medium">{{ c.title }}</div>
                                <p class="text-muted-foreground mt-1 text-sm">{{ c.text }}</p>
                            </div>
                        }
                    </section>
                }

                @if (!props().hasAddress) {
                    <a [link]="r('addresses.index')" class="text-muted-foreground flex items-center gap-2 text-sm hover:underline">
                        <svg [icon]="MapPin" class="size-4" /> Tip: add your delivery address now to speed up your first enquiry.
                    </a>
                }
            </div>
        </app-layout>
    `,
})
export default class Dashboard {
    protected props = pageProps<Props>();
    private router = inject(InertiaRouter);
    protected firstName = computed(() => this.props().auth.user.name.split(' ')[0]);
    protected stats = computed(() => {
        const s = this.props().stats;
        return [
            { label: 'Drafts', value: s.drafts, highlight: false },
            { label: 'Being reviewed', value: s.in_review, highlight: false },
            { label: 'Waiting for you', value: s.needs_reply, highlight: s.needs_reply > 0 },
            { label: 'Sent in total', value: s.total, highlight: false },
        ];
    });
    protected readonly howCards = [
        { icon: Images, title: 'Pick from our catalog', text: 'Browse paintings and prints, choose the size you need.' },
        { icon: Brush, title: 'Commission something new', text: 'Share your idea, colours and references.' },
        { icon: Frame, title: 'Frame your own art', text: 'Upload a photo, choose a frame and see it on your wall.' },
    ];
    protected readonly Plus = Plus;
    protected readonly MapPin = MapPin;
    protected readonly MessageCircleQuestion = MessageCircleQuestion;
    protected readonly formatDate = formatDate;
    protected r = (name: string, id?: number) => route(name, id);

    startNew(): void {
        this.router.post(route('portal.enquiries.store'));
    }
}
