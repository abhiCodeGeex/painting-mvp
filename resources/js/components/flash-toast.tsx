import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Shows the server's "success" flash message briefly at the top-right.
 */
export function FlashToast() {
    const { flash } = usePage<SharedData>().props;
    const [message, setMessage] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setMessage(flash.success);
            const timer = setTimeout(() => setMessage(null), 3500);
            return () => clearTimeout(timer);
        }
    }, [flash]);

    if (!message) return null;

    return (
        <div className="fixed top-4 right-4 z-[100] flex max-w-sm items-start gap-3 rounded-lg border bg-white p-4 text-sm shadow-lg dark:bg-neutral-900">
            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-green-600" />
            <p className="flex-1">{message}</p>
            <button type="button" onClick={() => setMessage(null)} className="text-muted-foreground hover:text-foreground">
                <X className="size-4" />
            </button>
        </div>
    );
}
