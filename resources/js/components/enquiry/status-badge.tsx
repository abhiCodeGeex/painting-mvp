import { cn } from '@/lib/utils';

const COLORS: Record<string, string> = {
    gray: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    primary: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

export function StatusBadge({ label, color, className }: { label: string; color: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', COLORS[color] ?? COLORS.gray, className)}>
            {label}
        </span>
    );
}
