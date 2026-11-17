import { StatusBadge } from '@/components/enquiry/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/units';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { EnquirySummary } from '@/types/portal';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Brush, Frame, Images, MapPin, MessageCircleQuestion, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface Props {
    stats: { drafts: number; in_review: number; needs_reply: number; total: number };
    actionNeeded: EnquirySummary[];
    recent: EnquirySummary[];
    hasAddress: boolean;
}

export default function Dashboard({ stats, actionNeeded, recent, hasAddress }: Props) {
    const { auth } = usePage<SharedData>().props;
    const startNew = () => router.post(route('portal.enquiries.store'));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Welcome, {auth.user.name.split(' ')[0]}</h1>
                        <p className="text-muted-foreground text-sm">Plan your artworks, preview them on your wall, and get a quotation.</p>
                    </div>
                    <Button size="lg" onClick={startNew}>
                        <Plus className="size-4" /> New enquiry
                    </Button>
                </div>

                {actionNeeded.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300">
                            <MessageCircleQuestion className="size-5" /> We need your reply
                        </h2>
                        {actionNeeded.map((e) => (
                            <Link
                                key={e.id}
                                href={route('portal.enquiries.show', e.id) + '#messages'}
                                className="flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30"
                            >
                                <span>
                                    <strong>{e.project_name}</strong> · {e.display_reference}
                                </span>
                                <span>Reply →</span>
                            </Link>
                        ))}
                    </section>
                )}

                <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Stat label="Drafts" value={stats.drafts} />
                    <Stat label="Being reviewed" value={stats.in_review} />
                    <Stat label="Waiting for you" value={stats.needs_reply} highlight={stats.needs_reply > 0} />
                    <Stat label="Sent in total" value={stats.total} />
                </section>

                {recent.length > 0 ? (
                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Recent enquiries</h2>
                            <Link href={route('portal.enquiries.index')} className="text-muted-foreground text-sm hover:underline">
                                See all
                            </Link>
                        </div>
                        <ul className="divide-y rounded-xl border">
                            {recent.map((e) => (
                                <li key={e.id}>
                                    <Link
                                        href={e.is_draft ? route('portal.enquiries.edit', e.id) : route('portal.enquiries.show', e.id)}
                                        className="hover:bg-muted/50 flex items-center justify-between gap-3 p-4 text-sm"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">{e.project_name}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {e.display_reference} · {e.lines_count} artworks · {formatDate(e.updated_at)}
                                            </span>
                                        </span>
                                        <StatusBadge label={e.status_label} color={e.status_color} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : (
                    <section className="grid gap-4 md:grid-cols-3">
                        <HowCard icon={<Images className="size-6" />} title="Pick from our catalog" text="Browse paintings and prints, choose the size you need." />
                        <HowCard icon={<Brush className="size-6" />} title="Commission something new" text="Share your idea, colours and references." />
                        <HowCard icon={<Frame className="size-6" />} title="Frame your own art" text="Upload a photo, choose a frame and see it on your wall." />
                    </section>
                )}

                {!hasAddress && (
                    <Link href={route('addresses.index')} className="text-muted-foreground flex items-center gap-2 text-sm hover:underline">
                        <MapPin className="size-4" /> Tip: add your delivery address now to speed up your first enquiry.
                    </Link>
                )}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, highlight = false }: { label: string; value: number; highlight?: boolean }) {
    return (
        <div className={highlight ? 'rounded-xl border border-amber-300 bg-amber-50 p-4 dark:bg-amber-950/30' : 'rounded-xl border p-4'}>
            <div className="text-2xl font-semibold">{value}</div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

function HowCard({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) {
    return (
        <div className="rounded-xl border p-5">
            <div className="text-muted-foreground mb-3">{icon}</div>
            <div className="font-medium">{title}</div>
            <p className="text-muted-foreground mt-1 text-sm">{text}</p>
        </div>
    );
}
