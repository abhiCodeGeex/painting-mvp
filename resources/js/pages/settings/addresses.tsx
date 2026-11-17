import { AddressDialog } from '@/components/address-dialog';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { Address, Option } from '@/types/portal';
import { Head, router } from '@inertiajs/react';
import { MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export default function Addresses({ addresses, states }: { addresses: Address[]; states: Option<number>[] }) {
    const [editing, setEditing] = useState<Address | null>(null);
    const [open, setOpen] = useState(false);

    const openDialog = (a: Address | null) => {
        setEditing(a);
        setOpen(true);
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Addresses', href: route('addresses.index') }]}>
            <Head title="Addresses" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-3">
                        <HeadingSmall title="Addresses" description="Delivery and billing addresses for your enquiries and quotations" />
                        <Button size="sm" onClick={() => openDialog(null)}>
                            <Plus className="size-4" /> Add
                        </Button>
                    </div>

                    {addresses.length === 0 && (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-8 text-sm">
                            <MapPin className="size-6" />
                            No addresses yet.
                        </div>
                    )}

                    <ul className="space-y-3">
                        {addresses.map((a) => (
                            <li key={a.id} className="flex items-start justify-between gap-3 rounded-lg border p-4">
                                <div className="text-sm">
                                    <div className="flex items-center gap-2 font-medium">
                                        {a.label || (a.kind === 'billing' ? 'Billing address' : 'Delivery address')}
                                        <span className="bg-muted rounded px-1.5 py-0.5 text-[11px] capitalize">{a.kind}</span>
                                        {a.is_default && <span className="rounded bg-green-100 px-1.5 py-0.5 text-[11px] text-green-800">Default</span>}
                                    </div>
                                    <div className="text-muted-foreground">{a.one_line}</div>
                                    {a.contact_name && (
                                        <div className="text-muted-foreground text-xs">
                                            {a.contact_name} {a.phone && `· ${a.phone}`}
                                        </div>
                                    )}
                                </div>
                                <div className="flex gap-1">
                                    <Button size="icon" variant="ghost" onClick={() => openDialog(a)} title="Edit">
                                        <Pencil className="size-4" />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        title="Remove"
                                        onClick={() => confirm('Remove this address?') && router.delete(route('addresses.destroy', a.id), { preserveScroll: true })}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </SettingsLayout>
            <AddressDialog open={open} onClose={() => setOpen(false)} address={editing} states={states} />
        </AppLayout>
    );
}
