<?php

namespace App\Domain\Pricing;

use App\Models\EnquiryLine;

/**
 * Everything the pricing engine needs to know about one artwork line (sizes in mm).
 */
final readonly class LineConfig
{
    /**
     * @param  list<int>  $finishingIds
     */
    public function __construct(
        public string $sourceType,
        public ?int $artworkId,
        public string $sizeReference,
        public int $widthMm,
        public int $heightMm,
        public int $quantity = 1,
        public ?int $qualityTierId = null,
        public ?int $mediumId = null,
        public ?int $substrateId = null,
        public ?int $frameId = null,
        public bool $frameAdvice = false,
        public ?int $matId = null,
        public ?int $matBorderMm = null,
        public ?int $glazingId = null,
        public array $finishingIds = [],
        public ?string $ownService = null,
        public ?int $complexityLevelId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $d  same keys as the portal line form
     */
    public static function fromArray(array $d): self
    {
        $int = fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null ? (int) $d[$k] : null;

        return new self(
            sourceType: (string) ($d['source_type'] ?? 'catalog'),
            artworkId: $int('artwork_id'),
            sizeReference: (string) ($d['size_reference'] ?? 'image'),
            widthMm: max(1, (int) ($d['width_mm'] ?? 0)),
            heightMm: max(1, (int) ($d['height_mm'] ?? 0)),
            quantity: max(1, (int) ($d['quantity'] ?? 1)),
            qualityTierId: $int('quality_tier_id'),
            mediumId: $int('medium_id'),
            substrateId: $int('substrate_id'),
            frameId: $int('frame_id'),
            frameAdvice: filter_var($d['frame_advice_requested'] ?? false, FILTER_VALIDATE_BOOLEAN),
            matId: $int('mat_id'),
            matBorderMm: $int('mat_border_mm'),
            glazingId: $int('glazing_id'),
            finishingIds: array_values(array_map('intval', (array) ($d['finishing_ids'] ?? []))),
            ownService: $d['own']['service'] ?? $d['own_service'] ?? null,
            complexityLevelId: $int('complexity_level_id'),
        );
    }

    public static function fromLine(EnquiryLine $line, ?int $complexityLevelId = null): self
    {
        $line->loadMissing('finishings');

        return new self(
            sourceType: $line->source_type->value,
            artworkId: $line->artwork_id,
            sizeReference: $line->size_reference->value,
            widthMm: $line->width_mm,
            heightMm: $line->height_mm,
            quantity: $line->quantity,
            qualityTierId: $line->quality_tier_id,
            mediumId: $line->medium_id,
            substrateId: $line->substrate_id,
            frameId: $line->frame_id,
            frameAdvice: $line->frame_advice_requested,
            matId: $line->mat_id,
            matBorderMm: $line->mat_border_mm,
            glazingId: $line->glazing_id,
            finishingIds: $line->finishings->pluck('id')->all(),
            ownService: $line->own_artwork['service'] ?? null,
            complexityLevelId: $complexityLevelId,
        );
    }
}
