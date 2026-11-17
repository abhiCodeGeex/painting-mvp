<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Pricing\CustomerEstimate;
use App\Domain\Pricing\LineConfig;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Live price estimate for the line being edited in the portal (called as the customer changes options).
 */
class EstimateController extends Controller
{
    public function __invoke(Request $request, Enquiry $enquiry, CustomerEstimate $estimate): JsonResponse
    {
        Gate::authorize('view', $enquiry);

        $data = $request->validate([
            'source_type' => ['required', 'in:catalog,custom_creation,own_artwork'],
            'artwork_id' => ['nullable', 'integer'],
            'size_reference' => ['required', 'in:image,outer'],
            'width_mm' => ['required', 'integer', 'min:50', 'max:6000'],
            'height_mm' => ['required', 'integer', 'min:50', 'max:6000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'quality_tier_id' => ['nullable', 'integer'],
            'medium_id' => ['nullable', 'integer'],
            'substrate_id' => ['nullable', 'integer'],
            'frame_id' => ['nullable', 'integer'],
            'frame_advice_requested' => ['boolean'],
            'mat_id' => ['nullable', 'integer'],
            'mat_border_mm' => ['nullable', 'integer', 'min:10', 'max:300'],
            'glazing_id' => ['nullable', 'integer'],
            'finishing_ids' => ['nullable', 'array'],
            'finishing_ids.*' => ['integer'],
            'own.service' => ['nullable', 'string'],
        ]);

        return response()->json($estimate->forLine(LineConfig::fromArray($data), $enquiry));
    }
}
