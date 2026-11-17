import { AddressDialog } from '@/components/address-dialog';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { InertiaRouter } from '@/inertia/router';
import { AppLayout } from '@/layouts/app-layout';
import { SettingsLayout } from '@/layouts/settings-layout';
import type { Address, Option } from '@/types/portal';
import { Icon } from '@/ui/icon';
import { UiButton } from '@/ui/primitives';
import { HeadingSmall } from '@/ui/text';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MapPin, Pencil, Plus, Trash2 } from 'lucide';

@Component({
    imports: [InertiaHead, AppLayout, SettingsLayout, AddressDialog, Icon, UiButton, HeadingSmall],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'Addresses', href: indexUrl }]">
            <inertia-head title="Addresses" />
            <settings-layout>
                <div class="space-y-6">
                    <div class="flex items-start justify-between gap-3">
                        <heading-small title="Addresses" description="Delivery and billing addresses for your enquiries and quotations" />
                        <button ui-button size="sm" type="button" (click)="openDialog(null)"><svg [icon]="Plus" class="size-4" /> Add</button>
                    </div>

                    @if (props().addresses.length === 0) {
                        <div class="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-8 text-sm">
                            <svg [icon]="MapPin" class="size-6" />
                            No addresses yet.
                        </div>
                    }

                    <ul class="space-y-3">
                        @for (a of props().addresses; track a.id) {
                            <li class="flex items-start justify-between gap-3 rounded-lg border p-4">
                                <div class="text-sm">
                                    <div class="flex items-center gap-2 font-medium">
                                        {{ a.label || (a.kind === 'billing' ? 'Billing address' : 'Delivery address') }}
                                        <span class="bg-muted rounded px-1.5 py-0.5 text-[11px] capitalize">{{ a.kind }}</span>
                                        @if (a.is_default) {
                                            <span class="rounded bg-green-100 px-1.5 py-0.5 text-[11px] text-green-800">Default</span>
                                        }
                                    </div>
                                    <div class="text-muted-foreground">{{ a.one_line }}</div>
                                    @if (a.contact_name) {
                                        <div class="text-muted-foreground text-xs">{{ a.contact_name }} {{ a.phone ? '· ' + a.phone : '' }}</div>
                                    }
                                </div>
                                <div class="flex gap-1">
                                    <button ui-button size="icon" variant="ghost" type="button" title="Edit" (click)="openDialog(a)">
                                        <svg [icon]="Pencil" class="size-4" />
                                    </button>
                                    <button ui-button size="icon" variant="ghost" type="button" title="Remove" (click)="remove(a)">
                                        <svg [icon]="Trash2" class="size-4" />
                                    </button>
                                </div>
                            </li>
                        }
                    </ul>
                </div>
            </settings-layout>
            <address-dialog [(open)]="dialogOpen" [address]="editing()" [states]="props().states" />
        </app-layout>
    `,
})
export default class Addresses {
    protected props = pageProps<{ addresses: Address[]; states: Option<number>[] }>();
    private router = inject(InertiaRouter);
    protected editing = signal<Address | null>(null);
    protected dialogOpen = signal(false);
    protected readonly indexUrl = route('addresses.index');
    protected readonly Plus = Plus;
    protected readonly MapPin = MapPin;
    protected readonly Pencil = Pencil;
    protected readonly Trash2 = Trash2;

    openDialog(a: Address | null): void {
        this.editing.set(a);
        this.dialogOpen.set(true);
    }

    remove(a: Address): void {
        if (confirm('Remove this address?')) this.router.delete(route('addresses.destroy', a.id), { preserveScroll: true });
    }
}
