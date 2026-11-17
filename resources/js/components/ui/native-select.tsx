import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * A plain <select> styled like the other inputs. Works with empty ("none") values and is
 * friendlier on phones than a custom dropdown.
 */
const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, children, ...props }, ref) => (
    <select
        ref={ref}
        className={cn(
            'border-input focus-visible:ring-ring flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-base shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-neutral-900',
            className,
        )}
        {...props}
    >
        {children}
    </select>
));
NativeSelect.displayName = 'NativeSelect';

export { NativeSelect };
