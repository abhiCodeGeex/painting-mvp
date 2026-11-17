import { LineCard } from '@/components/enquiry/line-card';
import { previewParts } from '@/components/enquiry/preview-helpers';
import { StatusBadge } from '@/components/enquiry/status-badge';
import { WallPreview } from '@/components/enquiry/wall-preview';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatInr } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { CatalogOptions, CustomerQuote, EnquiryDetail, EnquiryLine, EnquiryMessage } from '@/types/portal';
import { Head, router, useForm } from '@inertiajs/react';
import { Download, Eye, FileText, LoaderCircle, Paperclip, Send, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Props {
    enquiry: EnquiryDetail;
    messages: EnquiryMessage[];
    options: CatalogOptions;
    quotes: CustomerQuote[];
}

export default function ShowEnquiry({ enquiry, messages, options, quotes }: Props) {
    const [preview, setPreview] = useState<EnquiryLine | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const waitingForReply = enquiry.status === 'needs_clarification';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'My enquiries', href: route('portal.enquiries.index') },
                { title: enquiry.display_reference, href: route('portal.enquiries.show', enquiry.id) },
            ]}
        >
            <Head title={enquiry.display_reference} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="text-muted-foreground text-sm">{enquiry.display_reference}</div>
                        <h1 className="text-2xl font-semibold tracking-tight">{enquiry.project_name}</h1>
                        <div className="mt-1 flex items-center gap-2">
                            <StatusBadge label={enquiry.status_label} color={enquiry.status_color} />
                            <span className="text-muted-foreground text-xs">Sent {formatDate(enquiry.submitted_at)}</span>
                        </div>
                    </div>
                    {enquiry.can_cancel && (
                        <Button variant="ghost" className="text-muted-foreground" onClick={() => setCancelOpen(true)}>
                            Cancel enquiry
                        </Button>
                    )}
                </div>

                {waitingForReply && (
                    <a href="#messages" className="block rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <strong>We have a question for you.</strong> Please reply below so we can prepare your quotation.
                    </a>
                )}

                {quotes
                    .filter((q) => q.is_current)
                    .map((q) => (
                        <QuoteCard key={q.id} quote={q} />
                    ))}
                {quotes.some((q) => !q.is_current) && (
                    <p className="text-muted-foreground text-xs">
                        Earlier versions:{' '}
                        {quotes
                            .filter((q) => !q.is_current)
                            .map((q) => (
                                <a key={q.id} href={q.pdf_url} target="_blank" rel="noreferrer" className="mr-2 underline">
                                    {q.number} ({q.status_label.toLowerCase()})
                                </a>
                            ))}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-5">
                    <div className="min-w-0 space-y-3 lg:col-span-3">
                        <h2 className="text-lg font-semibold">Artworks</h2>
                        {enquiry.lines.map((line, i) => (
                            <LineCard
                                key={line.id}
                                line={line}
                                options={options}
                                index={i}
                                actions={
                                    <Button size="sm" variant="outline" onClick={() => setPreview(line)}>
                                        <Eye className="size-3.5" /> See on wall
                                    </Button>
                                }
                            />
                        ))}
                        <div className="text-muted-foreground grid gap-1 rounded-xl border p-4 text-sm sm:grid-cols-2">
                            <div>
                                <span className="text-xs">Deliver to</span>
                                <div className="text-foreground">{enquiry.delivery_address?.one_line ?? '—'}</div>
                            </div>
                            <div>
                                <span className="text-xs">Needed by</span>
                                <div className="text-foreground">{enquiry.required_by ? formatDate(enquiry.required_by) : 'Flexible'}</div>
                            </div>
                        </div>
                    </div>

                    <div id="messages" className="min-w-0 space-y-3 lg:col-span-2">
                        <h2 className="text-lg font-semibold">Conversation</h2>
                        <Conversation enquiry={enquiry} messages={messages} />
                    </div>
                </div>
            </div>

            <Dialog open={!!preview} onOpenChange={(o) => !o && setPreview(null)}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{preview?.display_title}</DialogTitle>
                        <DialogDescription>Preview to scale. Colours and textures are indicative.</DialogDescription>
                    </DialogHeader>
                    {preview && (
                        <WallPreview
                            imageUrl={preview.preview_image}
                            widthMm={preview.width_mm}
                            heightMm={preview.height_mm}
                            {...(() => {
                                const p = previewParts(options, preview);
                                return { frame: p.frame, mat: p.mat, depthMm: p.depthMm, glazing: p.glazing, edgeStyle: preview.edge_style };
                            })()}
                            wallColour={preview.wall_colour ?? '#EDE8E1'}
                            wallWidthMm={preview.wall_width_mm}
                            wallHeightMm={preview.wall_height_mm}
                            unit={preview.unit}
                            quantity={preview.quantity}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <CancelDialog open={cancelOpen} onClose={() => setCancelOpen(false)} enquiryId={enquiry.id} />
        </AppLayout>
    );
}

function QuoteCard({ quote }: { quote: CustomerQuote }) {
    const half = quote.tax_rate / 2;
    const lines = [...quote.lines.filter((l) => !l.is_optional), ...quote.lines.filter((l) => l.is_optional)];

    return (
        <section id="quote" className="overflow-hidden rounded-xl border-2 border-emerald-300 dark:border-emerald-800">
            <div className="flex flex-wrap items-center justify-between gap-3 bg-emerald-50 px-4 py-4 sm:px-5 dark:bg-emerald-950/40">
                <div className="flex min-w-0 items-center gap-3">
                    <FileText className="size-6 shrink-0 text-emerald-700 dark:text-emerald-300" />
                    <div className="min-w-0">
                        <div className="font-semibold break-words">Your quotation {quote.number}</div>
                        <div className="text-muted-foreground text-xs">
                            Issued {formatDate(quote.issue_date)} · valid until <strong>{formatDate(quote.valid_until)}</strong>
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <div className="text-right">
                        <div className="text-muted-foreground text-xs">Total incl. GST</div>
                        <div className="text-xl font-semibold tabular-nums">{formatInr(quote.grand_total, true)}</div>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <a href={quote.pdf_url} target="_blank" rel="noreferrer">
                            <Download className="size-4" /> PDF
                        </a>
                    </Button>
                </div>
            </div>

            <div className="px-4 py-4 sm:px-5">
                <table className="w-full text-sm">
                    <thead className="text-muted-foreground text-left text-xs">
                        <tr>
                            <th className="pb-2 font-normal">Artwork</th>
                            <th className="pb-2 text-right font-normal">Qty</th>
                            <th className="hidden pb-2 text-right font-normal sm:table-cell">Unit price</th>
                            <th className="pb-2 text-right font-normal">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id} className="border-t align-top">
                                <td className="py-2 pr-3">
                                    <div className="font-medium">
                                        {l.title}{' '}
                                        {l.is_optional && <span className="rounded bg-amber-100 px-1.5 text-[10px] text-amber-800">Optional</span>}
                                    </div>
                                    {l.spec && <div className="text-muted-foreground text-xs">{l.spec}</div>}
                                </td>
                                <td className="py-2 pl-2 text-right tabular-nums">{l.quantity}</td>
                                <td className="hidden py-2 text-right tabular-nums sm:table-cell">{formatInr(l.unit_price, true)}</td>
                                <td className="py-2 pl-3 text-right whitespace-nowrap tabular-nums">{formatInr(l.line_total, true)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="mt-3 grid gap-4 sm:grid-cols-2">
                    <div className="text-muted-foreground space-y-1 text-xs">
                        {quote.extras.map((e) => (
                            <div key={e.name} className="flex justify-between gap-2">
                                <span>{e.name}</span>
                                <span>{e.inclusion === 'charged' ? formatInr(e.amount, true) : e.inclusion_label}</span>
                            </div>
                        ))}
                        {quote.lead_time && <div className="pt-2">Lead time: {quote.lead_time}</div>}
                        {quote.payment_terms && <div>Payment: {quote.payment_terms}</div>}
                    </div>
                    <div className="space-y-1 text-sm">
                        <TotalRow label="Artworks" value={quote.items_total} />
                        {quote.extras_total > 0 && <TotalRow label="Extras" value={quote.extras_total} />}
                        {quote.discount_amount > 0 && <TotalRow label="Discount" value={-quote.discount_amount} />}
                        <TotalRow label="Taxable value" value={quote.taxable} />
                        {quote.intra_state ? (
                            <>
                                <TotalRow label={`CGST ${half}%`} value={quote.cgst} />
                                <TotalRow label={`SGST ${half}%`} value={quote.sgst} />
                            </>
                        ) : (
                            <TotalRow label={`IGST ${quote.tax_rate}%`} value={quote.igst} />
                        )}
                        <div className="flex justify-between border-t pt-1 font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatInr(quote.grand_total, true)}</span>
                        </div>
                    </div>
                </div>

                {quote.note_to_customer && <p className="bg-muted mt-4 rounded-lg p-3 text-sm whitespace-pre-line">{quote.note_to_customer}</p>}
                <p className="text-muted-foreground mt-3 text-xs">Questions about this quotation? Reply in the conversation below.</p>
            </div>
        </section>
    );
}

function TotalRow({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between gap-2">
            <span className="text-muted-foreground">{label}</span>
            <span className="tabular-nums">{value < 0 ? `−${formatInr(-value, true)}` : formatInr(value, true)}</span>
        </div>
    );
}

function Conversation({ enquiry, messages }: { enquiry: EnquiryDetail; messages: EnquiryMessage[] }) {
    const { data, setData, post, processing, errors, reset } = useForm<{ body: string; attachments: File[] }>({ body: '', attachments: [] });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('portal.enquiries.messages.store', enquiry.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    return (
        <div className="flex flex-col rounded-xl border">
            <div className="max-h-[60vh] space-y-3 overflow-y-auto p-4">
                {messages.map((m) =>
                    m.is_system ? (
                        <div key={m.id} className="text-muted-foreground text-center text-xs">
                            {m.body} · {formatDateTime(m.created_at)}
                        </div>
                    ) : (
                        <div key={m.id} className={cn('flex', m.from_staff ? 'justify-start' : 'justify-end')}>
                            <div
                                className={cn(
                                    'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm',
                                    m.from_staff ? 'bg-muted' : 'bg-primary text-primary-foreground',
                                    m.is_question && 'ring-2 ring-amber-400',
                                )}
                            >
                                <div className={cn('mb-1 text-xs', m.from_staff ? 'text-muted-foreground' : 'text-primary-foreground/70')}>
                                    {m.author} · {formatDateTime(m.created_at)}
                                    {m.is_question && ' · Question'}
                                </div>
                                <div className="whitespace-pre-line [overflow-wrap:anywhere]">{m.body}</div>
                                {m.attachments.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {m.attachments.map((a) => (
                                            <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-xs underline">
                                                <Paperclip className="size-3" /> {a.name}
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    ),
                )}
            </div>

            {enquiry.can_message ? (
                <form onSubmit={submit} className="space-y-2 border-t p-3">
                    <Textarea rows={3} placeholder="Write a message…" value={data.body} onChange={(e) => setData('body', e.target.value)} />
                    <InputError message={errors.body} />
                    {data.attachments.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {data.attachments.map((f, i) => (
                                <span key={i} className="bg-muted flex items-center gap-1 rounded px-2 py-1 text-xs">
                                    {f.name}
                                    <button type="button" onClick={() => setData('attachments', data.attachments.filter((_, j) => j !== i))}>
                                        <X className="size-3" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                    <div className="flex items-center justify-between">
                        <label className="text-muted-foreground hover:text-foreground flex cursor-pointer items-center gap-1 text-sm">
                            <Paperclip className="size-4" /> Attach
                            <input
                                type="file"
                                multiple
                                className="hidden"
                                accept="image/*,.pdf,.doc,.docx"
                                onChange={(e) => {
                                    setData('attachments', [...data.attachments, ...Array.from(e.target.files ?? [])].slice(0, 10));
                                    e.target.value = '';
                                }}
                            />
                        </label>
                        <Button type="submit" size="sm" disabled={processing || !data.body.trim()}>
                            {processing ? <LoaderCircle className="size-4 animate-spin" /> : <Send className="size-4" />} Send
                        </Button>
                    </div>
                </form>
            ) : (
                <p className="text-muted-foreground border-t p-3 text-center text-xs">This enquiry is closed for messages.</p>
            )}
        </div>
    );
}

function CancelDialog({ open, onClose, enquiryId }: { open: boolean; onClose: () => void; enquiryId: number }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this enquiry?</DialogTitle>
                    <DialogDescription>We will stop working on it. You can always start a new one.</DialogDescription>
                </DialogHeader>
                <Textarea rows={3} placeholder="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} />
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onClose}>
                        Keep it
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(route('portal.enquiries.cancel', enquiryId), { reason }, { onFinish: () => setBusy(false) });
                        }}
                    >
                        Cancel enquiry
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
