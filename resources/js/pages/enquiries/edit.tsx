import { AddressDialog } from '@/components/address-dialog';
import { LineCard } from '@/components/enquiry/line-card';
import { LineEditor } from '@/components/enquiry/line-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatInrRange } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { Address, CatalogOptions, EnquiryDetail, EnquiryEstimate, EnquiryLine, ProjectOptions } from '@/types/portal';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Copy, LoaderCircle, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

interface Props {
    enquiry: EnquiryDetail;
    options: CatalogOptions;
    projectOptions: ProjectOptions;
    addresses: Address[];
    estimate: EnquiryEstimate;
}

const STEPS = ['Project', 'Artworks', 'Review & send'];

export default function EditEnquiry({ enquiry, options, projectOptions, addresses, estimate }: Props) {
    const [step, setStep] = useState(enquiry.lines.length > 0 ? 1 : 0);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'My enquiries', href: route('portal.enquiries.index') },
                { title: enquiry.display_reference, href: route('portal.enquiries.edit', enquiry.id) },
            ]}
        >
            <Head title="New enquiry" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{enquiry.project_name}</h1>
                        <p className="text-muted-foreground text-sm">Draft — saved automatically as you go. Nothing is sent until you press “Send enquiry”.</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                        onClick={() => confirm('Delete this draft?') && router.delete(route('portal.enquiries.destroy', enquiry.id))}
                    >
                        <Trash2 className="size-4" /> Delete draft
                    </Button>
                </div>

                <Stepper step={step} onStep={setStep} />

                {step === 0 && <ProjectStep enquiry={enquiry} projectOptions={projectOptions} addresses={addresses} onDone={() => setStep(1)} />}
                {step === 1 && <ArtworksStep enquiry={enquiry} options={options} estimate={estimate} onBack={() => setStep(0)} onNext={() => setStep(2)} />}
                {step === 2 && <ReviewStep enquiry={enquiry} options={options} estimate={estimate} onBack={() => setStep(1)} onEdit={setStep} />}
            </div>
        </AppLayout>
    );
}

function Stepper({ step, onStep }: { step: number; onStep: (s: number) => void }) {
    return (
        <ol className="grid grid-cols-3 gap-2">
            {STEPS.map((label, i) => (
                <li key={label}>
                    <button
                        type="button"
                        onClick={() => onStep(i)}
                        className={cn(
                            'flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition',
                            i === step ? 'border-primary bg-primary/5 font-medium' : 'text-muted-foreground hover:bg-muted',
                        )}
                    >
                        <span
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs',
                                i < step ? 'bg-green-600 text-white' : i === step ? 'bg-primary text-primary-foreground' : 'bg-muted',
                            )}
                        >
                            {i < step ? <Check className="size-3.5" /> : i + 1}
                        </span>
                        <span className="truncate">{label}</span>
                    </button>
                </li>
            ))}
        </ol>
    );
}

/* ---------------------------- Step 1 ---------------------------- */

function ProjectStep({
    enquiry,
    projectOptions,
    addresses,
    onDone,
}: {
    enquiry: EnquiryDetail;
    projectOptions: ProjectOptions;
    addresses: Address[];
    onDone: () => void;
}) {
    const { flash } = usePage<{ flash: { created_address_id: number | null } }>().props;
    const [addressOpen, setAddressOpen] = useState(false);

    const { data, setData, put, errors, processing } = useForm({
        project_name: enquiry.project_name,
        project_type: enquiry.project_type ?? 'home',
        delivery_address_id: enquiry.delivery_address_id ? String(enquiry.delivery_address_id) : addresses[0] ? String(addresses[0].id) : '',
        required_by: enquiry.required_by ?? '',
        budget_range: enquiry.budget_range ?? '',
        installation_needed: enquiry.installation_needed,
        preferred_contact: enquiry.preferred_contact ?? 'email',
        notes: enquiry.notes ?? '',
    });

    // A newly added address is selected automatically.
    useEffect(() => {
        if (flash.created_address_id) setData('delivery_address_id', String(flash.created_address_id));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.created_address_id]);

    function submit(e: FormEvent) {
        e.preventDefault();
        put(route('portal.enquiries.update', enquiry.id), { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="space-y-6 rounded-xl border p-5 md:p-6">
            <div>
                <h2 className="text-lg font-semibold">Tell us about your project</h2>
                <p className="text-muted-foreground text-sm">This helps us prepare the right quotation.</p>
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                <Field label="Project name" error={errors.project_name}>
                    <Input value={data.project_name} onChange={(e) => setData('project_name', e.target.value)} placeholder="e.g. New apartment, Hotel lobby" required />
                </Field>
                <Field label="Type of space" error={errors.project_type}>
                    <NativeSelect value={data.project_type} onChange={(e) => setData('project_type', e.target.value)}>
                        {projectOptions.project_types.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>

                <div className="md:col-span-2">
                    <Field label="Delivery address" error={errors.delivery_address_id}>
                        {addresses.length > 0 ? (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {addresses.map((a) => (
                                    <button
                                        type="button"
                                        key={a.id}
                                        onClick={() => setData('delivery_address_id', String(a.id))}
                                        className={cn(
                                            'rounded-lg border p-3 text-left text-sm transition',
                                            data.delivery_address_id === String(a.id)
                                                ? 'border-primary ring-primary ring-2'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        <div className="font-medium">{a.label || a.city}</div>
                                        <div className="text-muted-foreground text-xs">{a.one_line}</div>
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => setAddressOpen(true)}
                                    className="text-muted-foreground hover:bg-muted flex items-center justify-center gap-2 rounded-lg border border-dashed p-3 text-sm"
                                >
                                    <Plus className="size-4" /> Add another address
                                </button>
                            </div>
                        ) : (
                            <Button type="button" variant="outline" onClick={() => setAddressOpen(true)}>
                                <Plus className="size-4" /> Add a delivery address
                            </Button>
                        )}
                    </Field>
                </div>

                <Field label="When do you need it? (optional)" error={errors.required_by}>
                    <Input type="date" value={data.required_by} onChange={(e) => setData('required_by', e.target.value)} />
                </Field>
                <Field label="Budget (optional)" error={errors.budget_range}>
                    <NativeSelect value={data.budget_range} onChange={(e) => setData('budget_range', e.target.value)}>
                        <option value="">Prefer not to say</option>
                        {projectOptions.budget_ranges.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field label="How should we contact you?" error={errors.preferred_contact}>
                    <NativeSelect value={data.preferred_contact} onChange={(e) => setData('preferred_contact', e.target.value)}>
                        {projectOptions.preferred_contacts.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <Checkbox checked={data.installation_needed} onCheckedChange={(v) => setData('installation_needed', v === true)} />
                    Please include installation in the quote
                </label>
                <div className="md:col-span-2">
                    <Field label="Notes for our team (optional)" error={errors.notes}>
                        <Textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Deadlines, site access, style ideas…" />
                    </Field>
                </div>
            </div>

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Save & continue <ArrowRight className="size-4" />
                </Button>
            </div>

            <AddressDialog
                open={addressOpen}
                onClose={() => setAddressOpen(false)}
                address={null}
                states={projectOptions.states}
            />
        </form>
    );
}

/* ---------------------------- Step 2 ---------------------------- */

function ArtworksStep({
    enquiry,
    options,
    estimate,
    onBack,
    onNext,
}: {
    enquiry: EnquiryDetail;
    options: CatalogOptions;
    estimate: EnquiryEstimate;
    onBack: () => void;
    onNext: () => void;
}) {
    const [editing, setEditing] = useState<EnquiryLine | null>(null);
    const [open, setOpen] = useState(false);

    const openEditor = (line: EnquiryLine | null) => {
        setEditing(line);
        setOpen(true);
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">Artworks in this enquiry</h2>
                    <p className="text-muted-foreground text-sm">Add every piece you need — each can be a catalog artwork, a new creation or your own artwork.</p>
                </div>
                <Button onClick={() => openEditor(null)}>
                    <Plus className="size-4" /> Add artwork
                </Button>
            </div>

            {enquiry.lines.length === 0 ? (
                <button
                    type="button"
                    onClick={() => openEditor(null)}
                    className="text-muted-foreground hover:bg-muted flex w-full flex-col items-center gap-2 rounded-xl border border-dashed p-10"
                >
                    <Plus className="size-8" />
                    <span className="font-medium">Add your first artwork</span>
                    <span className="text-xs">You'll see a live preview on a wall as you choose.</span>
                </button>
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {enquiry.lines.map((line, i) => (
                        <LineCard
                            key={line.id}
                            line={line}
                            options={options}
                            index={i}
                            estimate={estimate.lines[line.id]}
                            actions={
                                <>
                                    <Button size="sm" variant="outline" onClick={() => openEditor(line)}>
                                        <Pencil className="size-3.5" /> Edit
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.post(route('portal.enquiries.lines.duplicate', [enquiry.id, line.id]), {}, { preserveScroll: true })}
                                    >
                                        <Copy className="size-3.5" /> Duplicate
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-red-600"
                                        onClick={() =>
                                            confirm('Remove this artwork?') &&
                                            router.delete(route('portal.enquiries.lines.destroy', [enquiry.id, line.id]), { preserveScroll: true })
                                        }
                                    >
                                        <Trash2 className="size-3.5" /> Remove
                                    </Button>
                                </>
                            }
                        />
                    ))}
                </div>
            )}

            {enquiry.lines.length > 0 && <EstimateTotal estimate={estimate} />}

            <div className="flex justify-between pt-2">
                <Button variant="outline" onClick={onBack}>
                    <ArrowLeft className="size-4" /> Project
                </Button>
                <Button onClick={onNext} disabled={enquiry.lines.length === 0}>
                    Review <ArrowRight className="size-4" />
                </Button>
            </div>

            <LineEditor open={open} onClose={() => setOpen(false)} enquiryId={enquiry.id} line={editing} options={options} />
        </div>
    );
}

/* ---------------------------- Step 3 ---------------------------- */

function ReviewStep({
    enquiry,
    options,
    estimate,
    onBack,
    onEdit,
}: {
    enquiry: EnquiryDetail;
    options: CatalogOptions;
    estimate: EnquiryEstimate;
    onBack: () => void;
    onEdit: (s: number) => void;
}) {
    const [sending, setSending] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const missing: string[] = [];
    if (!enquiry.delivery_address) missing.push('a delivery address (step 1)');
    if (enquiry.lines.length === 0) missing.push('at least one artwork (step 2)');

    function send() {
        setSending(true);
        router.post(route('portal.enquiries.submit', enquiry.id), {}, { onFinish: () => setSending(false) });
    }

    return (
        <div className="space-y-5">
            <div className="grid gap-4 rounded-xl border p-5 md:grid-cols-2">
                <Summary label="Project" value={`${enquiry.project_name} · ${enquiry.project_type_label ?? ''}`} onEdit={() => onEdit(0)} />
                <Summary label="Deliver to" value={enquiry.delivery_address?.one_line ?? 'Not chosen yet'} onEdit={() => onEdit(0)} />
                <Summary label="Needed by" value={enquiry.required_by ? formatDate(enquiry.required_by) : 'Flexible'} onEdit={() => onEdit(0)} />
                <Summary label="Budget" value={enquiry.budget_range_label ?? 'Not given'} onEdit={() => onEdit(0)} />
                <Summary label="Installation" value={enquiry.installation_needed ? 'Yes, please quote it' : 'No'} onEdit={() => onEdit(0)} />
                {enquiry.notes && <Summary label="Notes" value={enquiry.notes} onEdit={() => onEdit(0)} />}
            </div>

            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">
                        {enquiry.lines.length} {enquiry.lines.length === 1 ? 'artwork' : 'artworks'}
                    </h2>
                    <Button variant="ghost" size="sm" onClick={() => onEdit(1)}>
                        <Pencil className="size-3.5" /> Change
                    </Button>
                </div>
                <div className="grid gap-3 lg:grid-cols-2">
                    {enquiry.lines.map((line, i) => (
                        <LineCard key={line.id} line={line} options={options} index={i} estimate={estimate.lines[line.id]} />
                    ))}
                </div>
                <EstimateTotal estimate={estimate} withTax={!!enquiry.delivery_address} />
            </div>

            {(missing.length > 0 || Object.keys(errors).length > 0) && (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {missing.length > 0 && <p>Before sending, please add {missing.join(' and ')}.</p>}
                    {Object.values(errors).map((e) => (
                        <InputError key={e} message={e} />
                    ))}
                </div>
            )}

            <div className="flex flex-col-reverse justify-between gap-3 border-t pt-4 sm:flex-row sm:items-center">
                <Button variant="outline" onClick={onBack}>
                    <ArrowLeft className="size-4" /> Artworks
                </Button>
                <div className="flex flex-col items-end gap-1">
                    <Button size="lg" onClick={send} disabled={sending || missing.length > 0}>
                        {sending ? <LoaderCircle className="size-4 animate-spin" /> : <Send className="size-4" />}
                        Send enquiry
                    </Button>
                    <span className="text-muted-foreground text-xs">We'll review it and reply, usually within one working day.</span>
                </div>
            </div>
        </div>
    );
}

function EstimateTotal({ estimate, withTax = false }: { estimate: EnquiryEstimate; withTax?: boolean }) {
    if (estimate.mode === 'hidden' || !estimate.subtotal_high) return null;
    const half = (estimate.tax_rate ?? 0) / 2;

    return (
        <div className="bg-muted/40 ml-auto w-full max-w-sm space-y-1 rounded-xl border p-4 text-sm">
            <div className="flex justify-between gap-4">
                <span className="text-muted-foreground">Estimated subtotal</span>
                <span className="tabular-nums">{formatInrRange(estimate.subtotal_low, estimate.subtotal_high)}</span>
            </div>
            {withTax && (
                <>
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">
                            {estimate.intra_state ? `CGST ${half}% + SGST ${half}%` : `IGST ${estimate.tax_rate}%`}
                        </span>
                        <span className="tabular-nums">{formatInrRange(estimate.tax_low, estimate.tax_high)}</span>
                    </div>
                    <div className="flex justify-between gap-4 border-t pt-1 font-semibold">
                        <span>Estimated total</span>
                        <span className="tabular-nums">{formatInrRange(estimate.total_low, estimate.total_high)}</span>
                    </div>
                </>
            )}
            <p className="text-muted-foreground pt-1 text-xs">
                Indicative only{withTax ? '' : ', before GST'}. Delivery, packing and installation are added in your quotation, which is final.
            </p>
        </div>
    );
}

function Summary({ label, value, onEdit }: { label: string; value: string; onEdit: () => void }) {
    return (
        <div className="flex items-start justify-between gap-2">
            <div>
                <div className="text-muted-foreground text-xs">{label}</div>
                <div className="text-sm">{value}</div>
            </div>
            <button type="button" onClick={onEdit} className="text-muted-foreground hover:text-foreground" title="Change">
                <Pencil className="size-3.5" />
            </button>
        </div>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
