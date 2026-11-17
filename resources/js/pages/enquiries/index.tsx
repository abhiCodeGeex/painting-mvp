import { StatusBadge } from '@/components/enquiry/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/units';
import type { EnquirySummary } from '@/types/portal';
import { Head, Link, router } from '@inertiajs/react';
import { Inbox, MessageCircle, Plus } from 'lucide-react';

export default function EnquiriesIndex({ enquiries }: { enquiries: EnquirySummary[] }) {
    const startNew = () => router.post(route('portal.enquiries.store'));

    return (
        <AppLayout breadcrumbs={[{ title: 'My enquiries', href: route('portal.enquiries.index') }]}>
            <Head title="My enquiries" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">My enquiries</h1>
                        <p className="text-muted-foreground text-sm">Requests you've sent us and drafts you're still working on.</p>
                    </div>
                    <Button onClick={startNew}>
                        <Plus className="size-4" /> New enquiry
                    </Button>
                </div>

                {enquiries.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <Inbox className="text-muted-foreground size-10" />
                        <div className="font-medium">No enquiries yet</div>
                        <p className="text-muted-foreground max-w-sm text-sm">
                            Start an enquiry to choose artworks, sizes and frames — and see them on your wall before you ask for a quote.
                        </p>
                        <Button onClick={startNew}>
                            <Plus className="size-4" /> Start your first enquiry
                        </Button>
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {enquiries.map((e) => (
                            <li key={e.id}>
                                <Link
                                    href={e.is_draft ? route('portal.enquiries.edit', e.id) : route('portal.enquiries.show', e.id)}
                                    className="hover:bg-muted/50 flex items-center gap-4 p-4 transition"
                                >
                                    <div className="bg-muted size-14 shrink-0 overflow-hidden rounded-lg">
                                        {e.thumbnail ? (
                                            <img src={e.thumbnail} alt="" className="size-full object-cover" />
                                        ) : (
                                            <div className="size-full" style={{ background: 'linear-gradient(135deg,#f6d365,#fda085,#a1c4fd)' }} />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate font-medium">{e.project_name}</span>
                                            <StatusBadge label={e.status_label} color={e.status_color} />
                                            {e.unread_count > 0 && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-xs text-white">
                                                    <MessageCircle className="size-3" /> {e.unread_count} new
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {e.display_reference} · {e.lines_count} {e.lines_count === 1 ? 'artwork' : 'artworks'} ·{' '}
                                            {e.is_draft ? `edited ${formatDate(e.updated_at)}` : `sent ${formatDate(e.submitted_at)}`}
                                        </div>
                                    </div>
                                    <span className="text-muted-foreground hidden text-sm sm:block">{e.is_draft ? 'Continue →' : 'View →'}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
