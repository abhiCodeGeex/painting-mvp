import { cn } from '@/lib/utils';
import { sizeLabel, type Unit } from '@/lib/units';
import { ImageIcon, LoaderCircle } from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState, type CSSProperties } from 'react';
import type { GlazingLook } from './preview-helpers';

export const WALL_COLOURS: { name: string; hex: string }[] = [
    { name: 'Warm white', hex: '#EDE8E1' },
    { name: 'Pure white', hex: '#F7F7F5' },
    { name: 'Greige', hex: '#CFC6BA' },
    { name: 'Sage', hex: '#B7C2A8' },
    { name: 'Terracotta', hex: '#C77B5A' },
    { name: 'Dusty pink', hex: '#D9B3AC' },
    { name: 'Navy', hex: '#2E3A55' },
    { name: 'Charcoal', hex: '#3C3C3C' },
];

export const SAMPLE_GRADIENT = 'linear-gradient(135deg,#f6d365 0%,#fda085 35%,#a1c4fd 70%,#5b7db1 100%)';

export interface PreviewFrame {
    colour: string;
    width_mm: number;
    depth_mm?: number;
    finish: string;
}

// The 3D room (Three.js) is loaded only when it is first shown, to keep pages fast.
const Room3D = lazy(() => import('./room-3d'));

function supportsWebGL(): boolean {
    try {
        const canvas = document.createElement('canvas');
        return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

export interface PreviewMat {
    colour: string;
    core_colour: string;
    border_mm: number;
    bottom_weight_mm?: number;
}

interface FramedArtProps {
    imageUrl: string | null;
    widthMm: number;
    heightMm: number;
    frame: PreviewFrame | null;
    mat: PreviewMat | null;
    depthMm?: number;
    /** pixels per millimetre */
    scale: number;
}

function frameBackground(colour: string, finish: string): string {
    switch (finish) {
        case 'gloss':
            return `linear-gradient(135deg, rgba(255,255,255,.35) 0%, rgba(255,255,255,0) 40%), ${colour}`;
        case 'metallic':
            return `linear-gradient(135deg, rgba(255,255,255,.55) 0%, rgba(255,255,255,.05) 30%, rgba(0,0,0,.15) 55%, rgba(255,255,255,.35) 80%), ${colour}`;
        case 'satin':
            return `linear-gradient(135deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,0) 50%), ${colour}`;
        default:
            return colour;
    }
}

/** Outer size (mm) of the finished piece: picture + mat + frame. */
export function outerSize(widthMm: number, heightMm: number, frame: PreviewFrame | null, mat: PreviewMat | null) {
    const m = mat?.border_mm ?? 0;
    const f = frame?.width_mm ?? 0;
    return {
        width: widthMm + 2 * m + 2 * f,
        height: heightMm + 2 * m + (mat?.bottom_weight_mm ?? 0) + 2 * f,
    };
}

/** The framed artwork itself, drawn to scale. */
export function FramedArt({ imageUrl, widthMm, heightMm, frame, mat, depthMm = 0, scale }: FramedArtProps) {
    const px = (mm: number, min = 0) => `${Math.max(min, mm * scale)}px`;

    const frameStyle: CSSProperties = frame
        ? {
              display: 'inline-block',
              padding: px(frame.width_mm, 2),
              background: frameBackground(frame.colour, frame.finish),
              boxShadow:
                  'inset 0 0 0 1px rgba(0,0,0,.25), inset 2px 2px 3px rgba(255,255,255,.25), inset -2px -2px 3px rgba(0,0,0,.3), 0 10px 24px rgba(0,0,0,.35)',
          }
        : {
              display: 'inline-block',
              boxShadow: depthMm > 0 ? `${px(depthMm / 3, 1)} ${px(depthMm / 3, 1)} 0 rgba(0,0,0,.3), 0 10px 22px rgba(0,0,0,.3)` : '0 8px 18px rgba(0,0,0,.25)',
          };

    const matStyle: CSSProperties = mat
        ? {
              display: 'block',
              background: mat.colour,
              padding: `${px(mat.border_mm, 2)} ${px(mat.border_mm, 2)} ${px(mat.border_mm + (mat.bottom_weight_mm ?? 0), 2)}`,
          }
        : { display: 'block' };

    const imageStyle: CSSProperties = {
        display: 'block',
        width: px(widthMm, 4),
        height: px(heightMm, 4),
        background: imageUrl ? `#ddd url("${imageUrl}") center / cover no-repeat` : SAMPLE_GRADIENT,
        boxShadow: mat ? `0 0 0 ${px(2, 1)} ${mat.core_colour}` : undefined,
    };

    return (
        <span style={frameStyle}>
            <span style={matStyle}>
                <span style={imageStyle} className="relative">
                    {!imageUrl && (
                        <span className="absolute inset-0 flex items-center justify-center text-white/80">
                            <ImageIcon className="size-1/4 max-h-10 max-w-10" />
                        </span>
                    )}
                </span>
            </span>
        </span>
    );
}

interface WallPreviewProps {
    imageUrl: string | null;
    widthMm: number;
    heightMm: number;
    frame: PreviewFrame | null;
    mat: PreviewMat | null;
    depthMm?: number;
    edgeStyle?: string | null;
    glazing?: GlazingLook;
    wallColour: string;
    wallWidthMm?: number | null;
    wallHeightMm?: number | null;
    unit?: Unit;
    quantity?: number;
    className?: string;
}

type View = '3d' | 'room' | 'closeup';

const VIEW_LABELS: Record<View, string> = { '3d': '3D room', room: 'Flat wall', closeup: 'Close-up' };

const SOFA_WIDTH = 2000;
const SOFA_HEIGHT = 850;

/**
 * True-to-scale preview: the framed piece hung on a wall above a 2 m sofa, so customers
 * can judge the size. "Close-up" shows the framing details larger.
 */
export function WallPreview({
    imageUrl,
    widthMm,
    heightMm,
    frame,
    mat,
    depthMm = 0,
    edgeStyle = null,
    glazing = 'none',
    wallColour,
    wallWidthMm,
    wallHeightMm,
    unit = 'cm',
    quantity = 1,
    className,
}: WallPreviewProps) {
    const ref = useRef<HTMLDivElement>(null);
    const [containerWidth, setContainerWidth] = useState(480);
    const [webgl] = useState(supportsWebGL);
    const [view, setView] = useState<View>(() => (webgl ? '3d' : 'room'));
    const views: View[] = webgl ? ['3d', 'room', 'closeup'] : ['room', 'closeup'];

    useEffect(() => {
        if (!ref.current) return;
        const observer = new ResizeObserver(([entry]) => setContainerWidth(Math.max(240, entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);

    const w = Math.max(50, widthMm || 0);
    const h = Math.max(50, heightMm || 0);
    const outer = outerSize(w, h, frame, mat);

    // Wall is at least the given size, and always wide/tall enough to show the piece comfortably.
    const wallW = Math.max(wallWidthMm || 3200, outer.width + 800, SOFA_WIDTH + 600);
    const wallH = Math.max(wallHeightMm || 2600, outer.height + SOFA_HEIGHT + 900);
    const floorMm = 250;
    const scale = containerWidth / wallW;

    // Hang with the centre at ~1500 mm, but keep at least 200 mm above the sofa back.
    const centreFromFloor = Math.max(1500, SOFA_HEIGHT + 200 + outer.height / 2);
    const bottomPx = (centreFromFloor - outer.height / 2 + floorMm) * scale;

    const lightWall = isLight(wallColour);

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div className="flex items-center justify-between text-xs">
                <div className="bg-muted inline-flex rounded-md p-0.5">
                    {views.map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => setView(v)}
                            className={cn('rounded px-2.5 py-1 font-medium transition', view === v ? 'bg-background shadow-sm' : 'text-muted-foreground')}
                        >
                            {VIEW_LABELS[v]}
                        </button>
                    ))}
                </div>
                <span className="text-muted-foreground">
                    Finished size ≈ {sizeLabel(outer.width, outer.height, unit)}
                    {quantity > 1 ? ` · ×${quantity}` : ''}
                </span>
            </div>

            {view === '3d' && (
                <Suspense
                    fallback={
                        <div className="text-muted-foreground flex h-[360px] items-center justify-center gap-2 rounded-lg border text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Preparing the 3D room…
                        </div>
                    }
                >
                    <Room3D
                        imageUrl={imageUrl}
                        widthMm={w}
                        heightMm={h}
                        frame={frame}
                        mat={mat}
                        depthMm={depthMm}
                        edgeStyle={edgeStyle}
                        glazing={glazing}
                        wallColour={wallColour}
                        wallWidthMm={wallWidthMm}
                        wallHeightMm={wallHeightMm}
                        unit={unit}
                    />
                </Suspense>
            )}

            <div ref={ref} className={cn('w-full overflow-hidden rounded-lg border', view === '3d' && 'hidden')}>
                {view === 'room' ? (
                    <div className="relative" style={{ height: (wallH + floorMm) * scale, background: wallColour }}>
                        {/* floor */}
                        <div
                            className="absolute inset-x-0 bottom-0"
                            style={{ height: floorMm * scale, background: 'linear-gradient(#a98463, #8a6a4f)', borderTop: '3px solid rgba(0,0,0,.12)' }}
                        />
                        {/* sofa for scale */}
                        <svg
                            className="absolute left-1/2 -translate-x-1/2"
                            style={{ bottom: floorMm * scale * 0.55, width: SOFA_WIDTH * scale, height: SOFA_HEIGHT * scale }}
                            viewBox="0 0 200 85"
                            preserveAspectRatio="none"
                            aria-hidden
                        >
                            <rect x="0" y="22" width="200" height="50" rx="8" fill={lightWall ? '#6b7280' : '#9ca3af'} opacity=".55" />
                            <rect x="8" y="5" width="184" height="35" rx="8" fill={lightWall ? '#4b5563' : '#d1d5db'} opacity=".5" />
                            <rect x="10" y="72" width="6" height="13" fill="#374151" opacity=".6" />
                            <rect x="184" y="72" width="6" height="13" fill="#374151" opacity=".6" />
                        </svg>
                        {/* artwork */}
                        <div className="absolute left-1/2 flex -translate-x-1/2 flex-col items-center" style={{ bottom: bottomPx }}>
                            <FramedArt imageUrl={imageUrl} widthMm={w} heightMm={h} frame={frame} mat={mat} depthMm={depthMm} scale={scale} />
                        </div>
                        <span className={cn('absolute top-2 left-2 text-[10px]', lightWall ? 'text-black/50' : 'text-white/60')}>
                            Sofa 2 m wide for scale
                        </span>
                    </div>
                ) : (
                    <CloseUp {...{ imageUrl, w, h, frame, mat, depthMm, wallColour, outer, containerWidth }} />
                )}
            </div>
        </div>
    );
}

function CloseUp({
    imageUrl,
    w,
    h,
    frame,
    mat,
    depthMm,
    wallColour,
    outer,
    containerWidth,
}: {
    imageUrl: string | null;
    w: number;
    h: number;
    frame: PreviewFrame | null;
    mat: PreviewMat | null;
    depthMm: number;
    wallColour: string;
    outer: { width: number; height: number };
    containerWidth: number;
}) {
    const box = Math.min(containerWidth - 48, 420);
    const scale = box / Math.max(outer.width, outer.height);

    return (
        <div className="flex items-center justify-center p-6" style={{ background: wallColour, minHeight: box + 48 }}>
            <FramedArt imageUrl={imageUrl} widthMm={w} heightMm={h} frame={frame} mat={mat} depthMm={depthMm} scale={scale} />
        </div>
    );
}

function isLight(hex: string): boolean {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0, 2), 16);
    const g = parseInt(c.substring(2, 4), 16);
    const b = parseInt(c.substring(4, 6), 16);
    return (r * 299 + g * 587 + b * 114) / 1000 > 140;
}
