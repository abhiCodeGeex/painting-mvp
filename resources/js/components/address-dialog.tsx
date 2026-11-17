import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { Address, Option } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    address: Address | null;
    states: Option<number>[];
    defaultKind?: 'billing' | 'delivery';
    onSaved?: () => void;
}

export function AddressDialog({ open, onClose, address, states, defaultKind = 'delivery', onSaved }: Props) {
    const form = useForm({
        kind: address?.kind ?? defaultKind,
        label: address?.label ?? '',
        contact_name: address?.contact_name ?? '',
        phone: address?.phone ?? '',
        line1: address?.line1 ?? '',
        line2: address?.line2 ?? '',
        city: address?.city ?? '',
        pin_code: address?.pin_code ?? '',
        state_id: address?.state_id ? String(address.state_id) : '',
        access_notes: address?.access_notes ?? '',
        is_default: address?.is_default ?? false,
    });
    const { data, setData, errors, processing } = form;

    useEffect(() => {
        if (open) {
            form.setDefaults({
                kind: address?.kind ?? defaultKind,
                label: address?.label ?? '',
                contact_name: address?.contact_name ?? '',
                phone: address?.phone ?? '',
                line1: address?.line1 ?? '',
                line2: address?.line2 ?? '',
                city: address?.city ?? '',
                pin_code: address?.pin_code ?? '',
                state_id: address?.state_id ? String(address.state_id) : '',
                access_notes: address?.access_notes ?? '',
                is_default: address?.is_default ?? false,
            });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, address?.id]);

    function submit(e: FormEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        };
        if (address) {
            form.put(route('addresses.update', address.id), options);
        } else {
            form.post(route('addresses.store'), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{address ? 'Edit address' : 'New address'}</DialogTitle>
                    <DialogDescription>Where should we deliver, or who should we bill?</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Row label="Type" error={errors.kind}>
                        <NativeSelect value={data.kind} onChange={(e) => setData('kind', e.target.value as 'billing' | 'delivery')}>
                            <option value="delivery">Delivery</option>
                            <option value="billing">Billing</option>
                        </NativeSelect>
                    </Row>
                    <Row label="Label (optional)" error={errors.label}>
                        <Input value={data.label} onChange={(e) => setData('label', e.target.value)} placeholder="Home, Office, Hotel site" />
                    </Row>
                    <Row label="Contact person" error={errors.contact_name}>
                        <Input value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                    </Row>
                    <Row label="Phone" error={errors.phone}>
                        <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+91 98765 43210" />
                    </Row>
                    <Row label="Address line 1" error={errors.line1} full>
                        <Input value={data.line1} onChange={(e) => setData('line1', e.target.value)} required />
                    </Row>
                    <Row label="Address line 2 (optional)" error={errors.line2} full>
                        <Input value={data.line2} onChange={(e) => setData('line2', e.target.value)} />
                    </Row>
                    <Row label="City" error={errors.city}>
                        <Input value={data.city} onChange={(e) => setData('city', e.target.value)} required />
                    </Row>
                    <Row label="PIN code" error={errors.pin_code}>
                        <Input value={data.pin_code} onChange={(e) => setData('pin_code', e.target.value)} inputMode="numeric" maxLength={6} required />
                    </Row>
                    <Row label="State" error={errors.state_id} full>
                        <NativeSelect value={data.state_id} onChange={(e) => setData('state_id', e.target.value)} required>
                            <option value="">Choose a state</option>
                            {states.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Row>
                    <Row label="Access notes (optional)" error={errors.access_notes} full>
                        <Input value={data.access_notes} onChange={(e) => setData('access_notes', e.target.value)} placeholder="e.g. No lift, 3rd floor" />
                    </Row>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <Checkbox checked={data.is_default} onCheckedChange={(v) => setData('is_default', v === true)} />
                        Use as my default {data.kind} address
                    </label>
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Save address
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Row({ label, error, full, children }: { label: string; error?: string; full?: boolean; children: React.ReactNode }) {
    return (
        <div className={full ? 'grid gap-1.5 sm:col-span-2' : 'grid gap-1.5'}>
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
