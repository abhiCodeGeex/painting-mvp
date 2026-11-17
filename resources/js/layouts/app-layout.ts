import { InertiaLink } from '@/inertia/link';
import { sharedProps } from '@/inertia/page';
import { InertiaRouter } from '@/inertia/router';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, NavItem, User } from '@/types';
import { Icon } from '@/ui/icon';
import { UiButton } from '@/ui/primitives';
import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, ElementRef, inject, input, signal, viewChild } from '@angular/core';
import { CheckCircle2, ChevronRight, ChevronsUpDown, Inbox, LayoutGrid, LogOut, MapPin, PanelLeft, Settings, X } from 'lucide';
import { AppLogo } from './app-logo';

const MOBILE_BREAKPOINT = 768;

const MENU_BUTTON =
    'peer/menu-button flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left text-sm outline-hidden ring-sidebar-ring transition-[width,height,padding] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground data-[state=open]:hover:bg-sidebar-accent data-[state=open]:hover:text-sidebar-accent-foreground group-data-[collapsible=icon]:size-8! group-data-[collapsible=icon]:p-2! [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0';

// Quotations are shown inside each enquiry.
const NAV: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'My enquiries', url: '/enquiries', icon: Inbox },
    { title: 'Addresses', url: '/settings/addresses', icon: MapPin },
];

function initials(fullName: string): string {
    const names = fullName.trim().split(' ');
    if (names.length === 0) return '';
    if (names.length === 1) return names[0].charAt(0).toUpperCase();
    return `${names[0].charAt(0)}${names[names.length - 1].charAt(0)}`.toUpperCase();
}

@Component({
    selector: 'user-info',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-full">
            <span class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">{{
                initials()
            }}</span>
        </span>
        <div class="grid flex-1 text-left text-sm leading-tight">
            <span class="truncate font-medium">{{ user().name }}</span>
            @if (showEmail()) {
                <span class="text-muted-foreground truncate text-xs">{{ user().email }}</span>
            }
        </div>
    `,
})
export class UserInfo {
    readonly user = input.required<User>();
    readonly showEmail = input(false);
    protected initials = computed(() => initials(this.user().name));
}

/** The user menu at the bottom of the sidebar (Settings, Log out). */
@Component({
    selector: 'nav-user',
    imports: [UserInfo, Icon, InertiaLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { '(document:click)': 'onDocumentClick($event)', '(document:keydown.escape)': 'open.set(false)' },
    template: `
        <ul data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
            <li data-sidebar="menu-item" class="group/menu-item relative">
                <button
                    #trigger
                    type="button"
                    data-sidebar="menu-button"
                    data-size="lg"
                    [attr.data-state]="open() ? 'open' : 'closed'"
                    [class]="buttonClass"
                    (click)="toggle()"
                >
                    <user-info [user]="user()" />
                    <svg [icon]="ChevronsUpDown" class="ml-auto size-4" />
                </button>
            </li>
        </ul>
        @if (open()) {
            <div
                #menu
                role="menu"
                data-state="open"
                class="bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 fixed z-50 min-w-56 overflow-hidden rounded-lg border p-1 shadow-md"
                [style]="position()"
            >
                <div class="p-0 px-2 py-1.5 text-sm font-normal font-semibold">
                    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                        <user-info [user]="user()" [showEmail]="true" />
                    </div>
                </div>
                <div class="bg-muted -mx-1 my-1 h-px"></div>
                <button type="button" role="menuitem" [link]="profileUrl" [class]="itemClass + ' block w-full'" (click)="open.set(false)">
                    <svg [icon]="Settings" class="mr-2" /> Settings
                </button>
                <div class="bg-muted -mx-1 my-1 h-px"></div>
                <button
                    type="button"
                    role="menuitem"
                    [link]="logoutUrl"
                    method="post"
                    [class]="itemClass + ' block w-full'"
                    (click)="open.set(false)"
                >
                    <svg [icon]="LogOut" class="mr-2" /> Log out
                </button>
            </div>
        }
    `,
})
export class NavUser {
    readonly collapsed = input(false);
    readonly mobile = input(false);
    private props = sharedProps();
    protected user = computed(() => this.props().auth.user);
    protected open = signal(false);
    protected position = signal<Record<string, string>>({});
    private trigger = viewChild<ElementRef<HTMLElement>>('trigger');
    private menu = viewChild<ElementRef<HTMLElement>>('menu');
    protected readonly ChevronsUpDown = ChevronsUpDown;
    protected readonly Settings = Settings;
    protected readonly LogOut = LogOut;
    protected readonly profileUrl = route('profile.edit');
    protected readonly logoutUrl = route('logout');
    protected readonly buttonClass = cn(
        MENU_BUTTON,
        'h-12 text-sm group-data-[collapsible=icon]:p-0!',
        'text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent group',
    );
    protected readonly itemClass =
        'relative flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden transition-colors hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0';

    toggle(): void {
        const rect = this.trigger()?.nativeElement.getBoundingClientRect();
        if (rect) {
            // Like Radix: open above the trigger (no room below), or to its right when the sidebar is collapsed.
            this.position.set(
                this.collapsed() && !this.mobile()
                    ? { left: `${rect.right + 4}px`, bottom: `${window.innerHeight - rect.bottom}px` }
                    : { left: `${rect.left}px`, bottom: `${window.innerHeight - rect.top + 4}px`, width: `${Math.max(rect.width, 224)}px` },
            );
        }
        this.open.update((o) => !o);
    }

    onDocumentClick(event: MouseEvent): void {
        const target = event.target as Node;
        if (this.open() && !this.trigger()?.nativeElement.contains(target) && !this.menu()?.nativeElement.contains(target)) this.open.set(false);
    }
}

@Component({
    selector: 'app-breadcrumbs',
    imports: [Icon, InertiaLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (items().length > 0) {
            <nav aria-label="breadcrumb">
                <ol class="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm break-words sm:gap-2.5">
                    @for (item of items(); track $index; let last = $last) {
                        <li class="inline-flex items-center gap-1.5">
                            @if (last) {
                                <span role="link" aria-disabled="true" aria-current="page" class="text-foreground font-normal">{{ item.title }}</span>
                            } @else {
                                <a [link]="item.href" class="hover:text-foreground transition-colors">{{ item.title }}</a>
                            }
                        </li>
                        @if (!last) {
                            <li role="presentation" aria-hidden="true" class="[&>svg]:h-3.5 [&>svg]:w-3.5"><svg [icon]="ChevronRight" /></li>
                        }
                    }
                </ol>
            </nav>
        }
    `,
})
export class Breadcrumbs {
    readonly items = input<BreadcrumbItem[]>([]);
    protected readonly ChevronRight = ChevronRight;
}

/** Shows the server's "success" flash message briefly at the top-right. */
@Component({
    selector: 'flash-toast',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (message(); as text) {
            <div
                class="fixed top-4 right-4 z-[100] flex max-w-sm items-start gap-3 rounded-lg border bg-white p-4 text-sm shadow-lg dark:bg-neutral-900"
            >
                <svg [icon]="CheckCircle2" class="mt-0.5 size-5 shrink-0 text-green-600" />
                <p class="flex-1">{{ text }}</p>
                <button type="button" class="text-muted-foreground hover:text-foreground" (click)="message.set(null)">
                    <svg [icon]="X" class="size-4" />
                </button>
            </div>
        }
    `,
})
export class FlashToast {
    private router = inject(InertiaRouter);
    protected message = signal<string | null>(null);
    protected readonly CheckCircle2 = CheckCircle2;
    protected readonly X = X;

    constructor() {
        let timer: ReturnType<typeof setTimeout> | null = null;
        effect(() => {
            const flash = (this.router.page().props as { flash?: { success?: string | null } }).flash;
            if (flash?.success) {
                this.message.set(flash.success);
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => this.message.set(null), 3500);
            }
        });
        inject(DestroyRef).onDestroy(() => timer && clearTimeout(timer));
    }
}

/**
 * The portal shell: collapsible inset sidebar (icons only when collapsed, a sheet on phones),
 * header with breadcrumbs, and the page content. Same classes as the shadcn sidebar.
 */
@Component({
    selector: 'app-layout',
    imports: [NgTemplateOutlet, Icon, InertiaLink, AppLogo, NavUser, Breadcrumbs, FlashToast, UiButton],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { '(window:keydown)': 'onKey($event)', '(window:resize)': 'checkMobile()', '(window:mobile-navigation)': 'openMobile.set(false)' },
    template: `
        <div
            class="group/sidebar-wrapper has-data-[variant=inset]:bg-sidebar flex min-h-svh w-full"
            style="--sidebar-width: 16rem; --sidebar-width-icon: 3rem"
        >
            @if (!isMobile()) {
                <div
                    class="group peer text-sidebar-foreground hidden md:block"
                    [attr.data-state]="open() ? 'expanded' : 'collapsed'"
                    [attr.data-collapsible]="open() ? '' : 'icon'"
                    data-variant="inset"
                    data-side="left"
                >
                    <div
                        class="relative h-svh w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]"
                    ></div>
                    <div
                        class="fixed inset-y-0 left-0 z-10 hidden h-svh w-(--sidebar-width) p-2 transition-[left,right,width] duration-200 ease-linear group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)] md:flex"
                    >
                        <div data-sidebar="sidebar" class="bg-sidebar flex h-full w-full flex-col">
                            <ng-container *ngTemplateOutlet="sidebarContent" />
                        </div>
                    </div>
                </div>
            } @else if (openMobile()) {
                <div
                    class="data-[state=open]:animate-in data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/80"
                    data-state="open"
                    (click)="openMobile.set(false)"
                ></div>
                <div
                    data-sidebar="sidebar"
                    data-mobile="true"
                    data-state="open"
                    class="bg-sidebar text-sidebar-foreground data-[state=open]:animate-in data-[state=open]:slide-in-from-left fixed inset-y-0 left-0 z-50 h-full w-(--sidebar-width) border-r p-0 shadow-lg transition ease-in-out data-[state=open]:duration-500 sm:max-w-sm"
                    style="--sidebar-width: 18rem"
                >
                    <span class="sr-only">Sidebar Navigation</span>
                    <div class="flex h-full w-full flex-col"><ng-container *ngTemplateOutlet="sidebarContent" /></div>
                </div>
            }

            <ng-template #sidebarContent>
                <div data-sidebar="header" class="flex flex-col gap-2 p-2">
                    <ul data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                        <li data-sidebar="menu-item" class="group/menu-item relative">
                            <a [link]="'/dashboard'" data-sidebar="menu-button" data-size="lg" [class]="logoButtonClass"><app-logo /></a>
                        </li>
                    </ul>
                </div>
                <div data-sidebar="content" class="flex min-h-0 flex-1 flex-col gap-2 overflow-auto group-data-[collapsible=icon]:overflow-hidden">
                    <div data-sidebar="group" class="relative flex w-full min-w-0 flex-col p-2 px-2 py-0">
                        <div
                            data-sidebar="group-label"
                            class="text-sidebar-foreground/70 ring-sidebar-ring flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium outline-hidden transition-[margin,opa] duration-200 ease-linear group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0 focus-visible:ring-2"
                        >
                            Platform
                        </div>
                        <ul data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">
                            @for (item of nav; track item.url) {
                                <li data-sidebar="menu-item" class="group/menu-item relative">
                                    <a
                                        [link]="item.url"
                                        data-sidebar="menu-button"
                                        data-size="default"
                                        [attr.data-active]="item.url === currentPath()"
                                        [attr.title]="open() || isMobile() ? null : item.title"
                                        [class]="navButtonClass"
                                    >
                                        @if (item.icon) {
                                            <svg [icon]="item.icon" />
                                        }
                                        <span>{{ item.title }}</span>
                                    </a>
                                </li>
                            }
                        </ul>
                    </div>
                </div>
                <div data-sidebar="footer" class="flex flex-col gap-2 p-2">
                    <nav-user [collapsed]="!open()" [mobile]="isMobile()" />
                </div>
            </ng-template>

            <main
                class="bg-background relative flex min-h-svh flex-1 flex-col peer-data-[variant=inset]:min-h-[calc(100svh-(--spacing(4)))] md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-sm md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-2"
            >
                <header
                    class="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
                >
                    <div class="flex items-center gap-2">
                        <button ui-button variant="ghost" size="icon" type="button" data-sidebar="trigger" class="-ml-1 h-7 w-7" (click)="toggle()">
                            <svg [icon]="PanelLeft" />
                            <span class="sr-only">Toggle Sidebar</span>
                        </button>
                        <app-breadcrumbs [items]="breadcrumbs()" />
                    </div>
                </header>
                <ng-content />
            </main>
            <flash-toast />
        </div>
    `,
})
export class AppLayout {
    readonly breadcrumbs = input<BreadcrumbItem[]>([]);
    private router = inject(InertiaRouter);

    protected readonly nav = NAV;
    protected readonly PanelLeft = PanelLeft;
    protected readonly navButtonClass = cn(MENU_BUTTON, 'h-8 text-sm');
    protected readonly logoButtonClass = cn(MENU_BUTTON, 'h-12 text-sm group-data-[collapsible=icon]:p-0!');

    protected open = signal(readOpen());
    protected openMobile = signal(false);
    protected isMobile = signal(window.innerWidth < MOBILE_BREAKPOINT);
    protected currentPath = computed(() => new URL(this.router.page().url, window.location.origin).pathname);

    toggle(): void {
        if (this.isMobile()) {
            this.openMobile.update((o) => !o);
            return;
        }
        this.open.update((o) => !o);
        try {
            localStorage.setItem('sidebar', String(this.open()));
        } catch {
            /* ignore */
        }
        document.cookie = `sidebar:state=${this.open()}; path=/; max-age=${60 * 60 * 24 * 7}`;
    }

    checkMobile(): void {
        this.isMobile.set(window.innerWidth < MOBILE_BREAKPOINT);
    }

    onKey(event: KeyboardEvent): void {
        if (event.key === 'b' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            this.toggle();
        }
    }
}

function readOpen(): boolean {
    try {
        return localStorage.getItem('sidebar') !== 'false';
    } catch {
        return true;
    }
}
