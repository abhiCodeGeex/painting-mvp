/** A thin progress bar at the top of the page during visits (shown after 250 ms, like Inertia's). */
export class Progress {
    private bar: HTMLDivElement | null = null;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private trickle: ReturnType<typeof setInterval> | null = null;
    private value = 0;

    constructor(private readonly colour: string) {}

    start(): void {
        this.clear();
        this.timer = setTimeout(() => {
            this.bar = document.createElement('div');
            this.bar.style.cssText = `position:fixed;top:0;left:0;height:2px;z-index:100000;background:${this.colour};transition:width .2s ease,opacity .3s ease;width:0`;
            document.body.appendChild(this.bar);
            this.set(0.08);
            this.trickle = setInterval(() => this.set(this.value + (1 - this.value) * 0.08), 200);
        }, 250);
    }

    done(): void {
        if (this.timer) clearTimeout(this.timer);
        if (this.trickle) clearInterval(this.trickle);
        const bar = this.bar;
        if (bar) {
            this.set(1);
            setTimeout(() => {
                bar.style.opacity = '0';
                setTimeout(() => bar.remove(), 300);
            }, 150);
        }
        this.bar = null;
    }

    private set(value: number): void {
        this.value = Math.min(0.994, value);
        if (this.bar) this.bar.style.width = `${this.value * 100}%`;
    }

    private clear(): void {
        if (this.timer) clearTimeout(this.timer);
        if (this.trickle) clearInterval(this.trickle);
        this.bar?.remove();
        this.bar = null;
    }
}
