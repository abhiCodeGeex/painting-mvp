import type { CatalogOptions, EnquiryLine, LineEstimate } from '@/types/portal';
import { type ReactNode } from 'react';
import { previewParts } from './preview-helpers';
import { LineEstimateTag } from './price-estimate';
import { FramedArt, outerSize } from './wall-preview';

/**
 * Compact summary of one artwork line with a small framed thumbnail.
 */
export function LineCard({
    line,
    options,
    index,
    actions,
    estimate,
}: {
    line: EnquiryLine;
    options: CatalogOptions;
    index: number;
    actions?: ReactNode;
    estimate?: LineEstimate;
}) {
    const parts = previewParts(options, line);
    const outer = outerSize(line.width_mm, line.height_mm, parts.frame, parts.mat);
    const scale = 110 / Math.max(outer.width, outer.height);

    const details = [
        line.size_label + (line.size_reference === 'outer' ? ' (outer)' : ''),
        line.quantity > 1 ? `×${line.quantity}` : null,
        line.labels.medium,
        line.labels.substrate,
        line.labels.frame ? `Frame: ${line.labels.frame}` : line.frame_advice_requested ? 'Frame: advise me' : 'No frame',
        line.labels.mat ? `Mat: ${line.labels.mat}` : null,
        line.labels.glazing,
    ].filter(Boolean);

    return (
        <div className="flex gap-4 rounded-xl border p-4">
            <div
                className="flex size-32 shrink-0 items-center justify-center rounded-lg"
                style={{ background: line.wall_colour ?? '#EDE8E1' }}
            >
                <FramedArt
                    imageUrl={line.preview_image}
                    widthMm={line.width_mm}
                    heightMm={line.height_mm}
                    frame={parts.frame}
                    mat={parts.mat}
                    depthMm={parts.depthMm}
                    scale={scale}
                />
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-muted-foreground text-xs">
                    #{index + 1} · {line.source_label}
                </div>
                <div className="truncate font-medium">{line.display_title}</div>
                {line.artwork?.artist && <div className="text-muted-foreground text-xs">by {line.artwork.artist}</div>}
                <p className="text-muted-foreground mt-2 text-sm leading-relaxed">{details.join(' · ')}</p>
                {line.placement && <p className="text-muted-foreground mt-1 text-xs">For: {line.placement}</p>}
                <LineEstimateTag estimate={estimate} />
                {actions && <div className="mt-3 flex flex-wrap gap-2">{actions}</div>}
            </div>
        </div>
    );
}
