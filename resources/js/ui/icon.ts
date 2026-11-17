import { ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, Renderer2 } from '@angular/core';
import type { IconNode } from 'lucide';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Lucide icon drawn as a real <svg>, exactly like lucide-react:
 *   <svg [icon]="Plus" class="size-4" />
 * Tailwind classes on the element size and colour it the same way.
 */
@Component({
    selector: 'svg[icon]',
    template: '',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {
        xmlns: SVG_NS,
        width: '24',
        height: '24',
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        'stroke-width': '2',
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        'aria-hidden': 'true',
        class: 'lucide',
    },
})
export class Icon {
    readonly icon = input.required<IconNode>();
    private el: ElementRef<SVGElement> = inject(ElementRef);
    private renderer = inject(Renderer2);

    constructor() {
        effect(() => {
            const svg = this.el.nativeElement;
            while (svg.firstChild) svg.removeChild(svg.firstChild);
            for (const [tag, attrs] of this.icon()) {
                const child = this.renderer.createElement(tag, 'svg') as SVGElement;
                for (const [name, value] of Object.entries(attrs)) {
                    if (value !== undefined) child.setAttribute(name, String(value));
                }
                svg.appendChild(child);
            }
        });
    }
}
