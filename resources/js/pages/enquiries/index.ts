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
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Inbox, MessageCircle, Plus } from 'lucide';

@Component({
    imports: [InertiaHead, InertiaLink, AppLayout, Icon, UiButton, StatusBadge],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'My enquiries', href: r('portal.enquiries.index') }]">
            <inertia-head title="My enquiries" />
            <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">My enquiries</h1>
                        <p class="text-muted-foreground text-sm">Requests you've sent us and drafts you're still working on.</p>
                    </div>
                    <button ui-button type="button" (click)="startNew()"><svg [icon]="Plus" class="size-4" /> New enquiry</button>
                </div>

                @if (props().enquiries.length === 0) {
                    <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <svg [icon]="Inbox" class="text-muted-foreground size-10" />
                        <div class="font-medium">No enquiries yet</div>
                        <p class="text-muted-foreground max-w-sm text-sm">
                            Start an enquiry to choose artworks, sizes and frames — and see them on your wall before you ask for a quote.
                        </p>
                        <button ui-button type="button" (click)="startNew()"><svg [icon]="Plus" class="size-4" /> Start your first enquiry</button>
                    </div>
                } @else {
                    <ul class="divide-y rounded-xl border">
                        @for (e of props().enquiries; track e.id) {
                            <li>
                                <a
                                    [link]="e.is_draft ? r('portal.enquiries.edit', e.id) : r('portal.enquiries.show', e.id)"
                                    class="hover:bg-muted/50 flex items-center gap-4 p-4 transition"
                                >
                                    <div class="bg-muted size-14 shrink-0 overflow-hidden rounded-lg">
                                        @if (e.thumbnail) {
                                            <img [src]="e.thumbnail" alt="" class="size-full object-cover" />
                                        } @else {
                                            <div class="size-full" style="background: linear-gradient(135deg, #f6d365, #fda085, #a1c4fd)"></div>
                                        }
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="truncate font-medium">{{ e.project_name }}</span>
                                            <status-badge [label]="e.status_label" [color]="e.status_color" />
                                            @if (e.unread_count > 0) {
                                                <span class="inline-flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-xs text-white">
                                                    <svg [icon]="MessageCircle" class="size-3" /> {{ e.unread_count }} new
                                                </span>
                                            }
                                        </div>
                                        <div class="text-muted-foreground text-xs">
                                            {{ e.display_reference }} · {{ e.lines_count }} {{ e.lines_count === 1 ? 'artwork' : 'artworks' }} ·
                                            {{ e.is_draft ? 'edited ' + formatDate(e.updated_at) : 'sent ' + formatDate(e.submitted_at) }}
                                        </div>
                                    </div>
                                    <span class="text-muted-foreground hidden text-sm sm:block">{{ e.is_draft ? 'Continue →' : 'View →' }}</span>
                                </a>
                            </li>
                        }
                    </ul>
                }
            </div>
        </app-layout>
    `,
})
export default class EnquiriesIndex {
    protected props = pageProps<{ enquiries: EnquirySummary[] }>();
    private router = inject(InertiaRouter);
    protected readonly Plus = Plus;
    protected readonly Inbox = Inbox;
    protected readonly MessageCircle = MessageCircle;
    protected readonly formatDate = formatDate;
    protected r = (name: string, id?: number) => route(name, id);

    startNew(): void {
        this.router.post(route('portal.enquiries.store'));
    }
}
