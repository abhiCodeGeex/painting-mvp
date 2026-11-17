<?php

namespace Database\Seeders;

use App\Enums\EdgeStyle;
use App\Enums\ExtraChargeBasis;
use App\Enums\ExtraChargeType;
use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Enums\GlazingMaterial;
use App\Enums\MediumKind;
use App\Enums\PricingType;
use App\Enums\ReflectionLevel;
use App\Models\ComplexityLevel;
use App\Models\EnquirySource;
use App\Models\ExtraCharge;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\QualityTier;
use App\Models\RejectionReason;
use App\Models\StandardSize;
use App\Models\Substrate;
use App\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Starter masters so every screen has something to work with. Idempotent: existing rows
 * (matched by name/code) are left untouched, so edits made in the admin panel survive.
 * Costs are indicative INR figures — replace with your real supplier prices.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->lists();
        $this->pricing();
        $substrates = $this->substrates();
        $mediums = $this->mediums($substrates);
        $this->sizes();
        $this->frames($substrates);
        $mats = $this->mats();
        $glazings = $this->glazings();
        $this->finishings($substrates);
        $this->qualityTiers($mediums, $substrates, $glazings, $mats);
    }

    private function lists(): void
    {
        foreach (['Website', 'Phone', 'Email', 'Showroom walk-in', 'Interior designer', 'Referral', 'Instagram / social'] as $i => $name) {
            EnquirySource::firstOrCreate(['name' => $name], ['sort_order' => $i]);
        }

        $reasons = [
            ['Price too high', false],
            ['Lead time too long', false],
            ['Design not suitable', false],
            ['Went with another supplier', false],
            ['Project cancelled or postponed', false],
            ['Other', true],
        ];
        foreach ($reasons as $i => [$name, $comment]) {
            RejectionReason::firstOrCreate(['name' => $name], ['requires_comment' => $comment, 'sort_order' => $i]);
        }
    }

    private function pricing(): void
    {
        $taxes = [
            ['GST 18%', 18, '9701', true],
            ['GST 12%', 12, '9701', false],
            ['GST 5%', 5, null, false],
        ];
        foreach ($taxes as $i => [$name, $rate, $hsn, $default]) {
            Tax::firstOrCreate(['name' => $name], [
                'rate_percent' => $rate, 'hsn_sac_code' => $hsn, 'is_default' => $default, 'sort_order' => $i,
            ]);
        }

        $levels = [
            ['Simple', 1.0, 'Abstract or minimal compositions, few elements.'],
            ['Medium', 1.35, 'Landscapes, still life, moderate detail.'],
            ['Complex', 1.8, 'Portraits, figurative or highly detailed work.'],
        ];
        foreach ($levels as $i => [$name, $factor, $description]) {
            ComplexityLevel::firstOrCreate(['name' => $name], [
                'price_factor' => $factor, 'description' => $description, 'sort_order' => $i,
            ]);
        }

        $charges = [
            ['Standard packing', ExtraChargeType::Packing, ExtraChargeBasis::PerPiece, 25000],
            ['Wooden crate', ExtraChargeType::Packing, ExtraChargeBasis::PerPiece, 250000],
            ['Local delivery', ExtraChargeType::Shipping, ExtraChargeBasis::PerQuote, 150000],
            ['Installation', ExtraChargeType::Installation, ExtraChargeBasis::PerPiece, 50000],
        ];
        foreach ($charges as $i => [$name, $type, $basis, $cost]) {
            ExtraCharge::firstOrCreate(['name' => $name], [
                'type' => $type, 'pricing_type' => $basis, 'cost' => $cost, 'sort_order' => $i,
            ]);
        }
    }

    /**
     * @return array<string, Substrate>
     */
    private function substrates(): array
    {
        $rows = [
            'canvas38' => ['Stretched canvas 38 mm', 38, [EdgeStyle::WrappedImage, EdgeStyle::Mirrored, EdgeStyle::SolidColour], false, false, false, 90000, 12000],
            'canvas20' => ['Stretched canvas 20 mm', 20, [EdgeStyle::WrappedImage, EdgeStyle::Mirrored, EdgeStyle::SolidColour], false, false, true, 80000, 9000],
            'canvasRoll' => ['Canvas roll (unstretched)', 0, [], false, false, true, 70000, 0],
            'paper' => ['Fine-art paper 310 gsm', 0, [], true, true, true, 60000, 0],
            'panel' => ['Wood panel 18 mm', 18, [EdgeStyle::SolidColour], true, false, false, 150000, 0],
        ];

        $substrates = [];
        $i = 0;
        foreach ($rows as $key => [$name, $depth, $edges, $glazing, $mat, $frame, $m2, $perimeter]) {
            $substrates[$key] = Substrate::firstOrCreate(['name' => $name], [
                'depth_mm' => $depth,
                'edge_styles' => array_map(fn (EdgeStyle $e) => $e->value, $edges),
                'allows_glazing' => $glazing,
                'allows_mat' => $mat,
                'requires_frame' => $frame,
                'cost_per_m2' => $m2,
                'cost_per_perimeter_m' => $perimeter,
                'max_width_mm' => 2400,
                'max_height_mm' => 1600,
                'sort_order' => $i++,
            ]);
        }

        return $substrates;
    }

    /**
     * @param  array<string, Substrate>  $s
     * @return array<string, Medium>
     */
    private function mediums(array $s): array
    {
        $canvas = [$s['canvas38']->id, $s['canvas20']->id, $s['canvasRoll']->id];
        $rows = [
            'oil' => ['Oil on canvas', MediumKind::HandPainted, 1.5, null, [...$canvas, $s['panel']->id]],
            'acrylic' => ['Acrylic on canvas', MediumKind::HandPainted, 1.3, null, [...$canvas, $s['panel']->id]],
            'watercolour' => ['Watercolour on paper', MediumKind::HandPainted, 1.2, null, [$s['paper']->id]],
            'giclee' => ['Giclée print', MediumKind::Print, 1.0, 150, [...$canvas, $s['paper']->id]],
            'photo' => ['Photographic print', MediumKind::Print, 0.9, 200, [$s['paper']->id]],
        ];

        $mediums = [];
        $i = 0;
        foreach ($rows as $key => [$name, $kind, $factor, $ppi, $substrateIds]) {
            $medium = Medium::firstOrCreate(['name' => $name], [
                'kind' => $kind, 'price_factor' => $factor, 'min_resolution_ppi' => $ppi, 'sort_order' => $i++,
            ]);
            if ($medium->wasRecentlyCreated) {
                $medium->substrates()->sync($substrateIds);
            }
            $mediums[$key] = $medium;
        }

        return $mediums;
    }

    private function sizes(): void
    {
        $sizes = [
            ['A4', 210, 297], ['A3', 297, 420], ['A2', 420, 594], ['A1', 594, 841],
            [null, 300, 400], [null, 400, 500], [null, 500, 700], [null, 600, 900],
            [null, 700, 1000], [null, 900, 1200], [null, 1000, 1500], [null, 1200, 1800],
        ];
        foreach ($sizes as $i => [$label, $w, $h]) {
            StandardSize::firstOrCreate(['width_mm' => $w, 'height_mm' => $h], [
                'label' => $label ?? StandardSize::defaultLabel($w, $h), 'sort_order' => $i,
            ]);
        }
    }

    /**
     * @param  array<string, Substrate>  $s
     */
    private function frames(array $s): void
    {
        $frames = [
            ['FR-BLK-20', 'Black flat 20 mm', FrameMaterial::Wood, FrameFinish::Matte, '#1A1A1A', 20, 30, 38000, false],
            ['FR-WHT-20', 'White flat 20 mm', FrameMaterial::Wood, FrameFinish::Satin, '#F4F4F2', 20, 30, 38000, false],
            ['FR-OAK-25', 'Natural oak 25 mm', FrameMaterial::Wood, FrameFinish::Matte, '#C8A26B', 25, 35, 52000, false],
            ['FR-WAL-30', 'Walnut 30 mm', FrameMaterial::Wood, FrameFinish::Satin, '#5C4033', 30, 40, 68000, false],
            ['FR-GLD-40', 'Antique gold ornate 40 mm', FrameMaterial::Polymer, FrameFinish::Metallic, '#B8913A', 40, 35, 85000, false],
            ['FR-ALU-15', 'Brushed aluminium 15 mm', FrameMaterial::Aluminium, FrameFinish::Metallic, '#B5B8BA', 15, 25, 42000, false],
            ['FR-FLT-BLK', 'Black floater for canvas', FrameMaterial::Wood, FrameFinish::Matte, '#1A1A1A', 10, 45, 60000, true],
        ];

        foreach ($frames as $i => [$code, $name, $material, $finish, $colour, $width, $depth, $price, $floater]) {
            $frame = Frame::firstOrCreate(['code' => $code], [
                'name' => $name,
                'material' => $material,
                'finish' => $finish,
                'colour_hex' => $colour,
                'profile_width_mm' => $width,
                'profile_depth_mm' => $depth,
                'rebate_depth_mm' => $floater ? null : 10,
                'price_per_m' => $price,
                'waste_percent' => 10,
                'max_inner_width_mm' => 1500,
                'max_inner_height_mm' => 2000,
                'is_floater' => $floater,
                'sort_order' => $i,
            ]);

            if ($frame->wasRecentlyCreated && $floater) {
                $frame->substrates()->sync([$s['canvas38']->id, $s['canvas20']->id]);
            }
        }
    }

    /**
     * @return array<string, Mat>
     */
    private function mats(): array
    {
        $rows = [
            'white' => ['MAT-WHT', 'Bright white', '#FFFFFF', '#FFFFFF'],
            'ivory' => ['MAT-IVR', 'Ivory', '#F3EEDC', '#FFFFFF'],
            'black' => ['MAT-BLK', 'Black core black', '#111111', '#111111'],
            'grey' => ['MAT-GRY', 'Warm grey', '#9C978F', '#FFFFFF'],
        ];

        $mats = [];
        $i = 0;
        foreach ($rows as $key => [$code, $name, $face, $core]) {
            $mats[$key] = Mat::firstOrCreate(['code' => $code], [
                'name' => $name, 'colour_hex' => $face, 'core_colour_hex' => $core,
                'thickness_mm' => 1.4, 'default_border_mm' => 60, 'cost_per_m2' => 120000, 'cut_fee' => 10000,
                'max_sheet_width_mm' => 1020, 'max_sheet_height_mm' => 1520, 'sort_order' => $i++,
            ]);
        }

        return $mats;
    }

    /**
     * @return array<string, Glazing>
     */
    private function glazings(): array
    {
        $rows = [
            'none' => ['No glazing', GlazingMaterial::None, ReflectionLevel::None, 0, null],
            'glass' => ['Float glass 2 mm', GlazingMaterial::Glass, ReflectionLevel::Standard, 110000, 1.5],
            'acrylic' => ['Clear acrylic 3 mm', GlazingMaterial::Acrylic, ReflectionLevel::Standard, 180000, 3.0],
            'uv' => ['UV-protective acrylic', GlazingMaterial::Acrylic, ReflectionLevel::Standard, 350000, 3.0],
            'museum' => ['Museum glass (anti-reflective, UV)', GlazingMaterial::Glass, ReflectionLevel::Low, 900000, 1.5],
        ];

        $glazings = [];
        $i = 0;
        foreach ($rows as $key => [$name, $material, $reflection, $cost, $maxArea]) {
            $glazings[$key] = Glazing::firstOrCreate(['name' => $name], [
                'material' => $material, 'reflection_level' => $reflection, 'cost_per_m2' => $cost,
                'max_area_m2' => $maxArea, 'sort_order' => $i++,
            ]);
        }

        return $glazings;
    }

    /**
     * @param  array<string, Substrate>  $s
     */
    private function finishings(array $s): void
    {
        $rows = [
            ['D-rings + wire', PricingType::Fixed, 8000, true, []],
            ['French cleat', PricingType::Fixed, 25000, false, []],
            ['Protective varnish', PricingType::PerSquareMetre, 40000, false, [$s['canvas38']->id, $s['canvas20']->id, $s['panel']->id]],
            ['Dust cover backing', PricingType::PerSquareMetre, 15000, true, []],
            ['Security hangers', PricingType::Fixed, 30000, false, []],
        ];
        foreach ($rows as $i => [$name, $type, $cost, $default, $substrateIds]) {
            $finishing = Finishing::firstOrCreate(['name' => $name], [
                'pricing_type' => $type, 'cost' => $cost, 'is_default' => $default, 'sort_order' => $i,
            ]);
            if ($finishing->wasRecentlyCreated && $substrateIds) {
                $finishing->substrates()->sync($substrateIds);
            }
        }
    }

    /**
     * @param  array<string, Medium>  $m
     * @param  array<string, Substrate>  $s
     * @param  array<string, Glazing>  $g
     * @param  array<string, Mat>  $mat
     */
    private function qualityTiers(array $m, array $s, array $g, array $mat): void
    {
        $tiers = [
            // Defaults must be compatible: canvas takes no glass or mat; paper does.
            ['Standard', 1.0, 'Good everyday quality on canvas.', $m['giclee'], $s['canvas20'], $g['none'], null],
            ['Premium', 1.25, 'Fine-art paper, mat and UV-protective acrylic.', $m['giclee'], $s['paper'], $g['uv'], $mat['white']],
            ['Museum', 1.6, 'Conservation materials and museum glass.', $m['giclee'], $s['paper'], $g['museum'], $mat['ivory']],
        ];
        foreach ($tiers as $i => [$name, $factor, $description, $medium, $substrate, $glazing, $defaultMat]) {
            QualityTier::firstOrCreate(['name' => $name], [
                'price_factor' => $factor,
                'description' => $description,
                'default_medium_id' => $medium->id,
                'default_substrate_id' => $substrate->id,
                'default_glazing_id' => $glazing->id,
                'default_mat_id' => $defaultMat?->id,
                'sort_order' => $i,
            ]);
        }
    }
}
