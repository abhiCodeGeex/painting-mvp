import { provideZonelessChangeDetection } from '@angular/core';
import { createApplication } from '@angular/platform-browser';
import { InertiaRoot } from './inertia/root';
import { InertiaRouter } from './inertia/router';
import type { Page } from './inertia/types';
import { initializeTheme } from './lib/appearance';

async function boot(): Promise<void> {
    const el = document.getElementById('app');
    if (!el?.dataset['page']) return;
    const initial = JSON.parse(el.dataset['page']) as Page;

    const app = await createApplication({ providers: [provideZonelessChangeDetection()] });
    await app.injector.get(InertiaRouter).init(initial);

    // Mount the Angular root on the Inertia container.
    el.innerHTML = '<inertia-root></inertia-root>';
    app.bootstrap(InertiaRoot, el.firstElementChild as Element);
}

initializeTheme();
void boot();
