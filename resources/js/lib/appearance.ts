import { signal } from '@angular/core';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

function applyTheme(appearance: Appearance): void {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', isDark);
}

function saved(): Appearance {
    try {
        return (localStorage.getItem('appearance') as Appearance | null) || 'system';
    } catch {
        return 'system';
    }
}

/** Current light / dark / system choice (shared by the appearance tabs). */
export const appearance = signal<Appearance>('system');

export function initializeTheme(): void {
    appearance.set(saved());
    applyTheme(appearance());
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(saved()));
}

export function updateAppearance(mode: Appearance): void {
    appearance.set(mode);
    try {
        localStorage.setItem('appearance', mode);
    } catch {
        /* private mode: theme still applies for this visit */
    }
    applyTheme(mode);
}
