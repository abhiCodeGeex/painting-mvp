<?php

namespace App\Http\Presenters;

use App\Enums\MessageKind;
use App\Enums\QuoteStatus;
use App\Models\Address;
use App\Models\Enquiry;
use App\Models\EnquiryLine;
use App\Models\Message;
use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Models\QuoteLine;
use App\Support\MediaUrl;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns enquiries into plain arrays for the customer portal. Only customer-safe fields:
 * internal notes, assignment and priority are never included.
 */
class EnquiryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Enquiry $enquiry): array
    {
        return [
            'id' => $enquiry->id,
            'reference' => $enquiry->reference,
            'display_reference' => $enquiry->displayReference(),
            'project_name' => $enquiry->project_name,
            'status' => $enquiry->status->value,
            'status_label' => $enquiry->status->customerLabel(),
            'status_color' => $enquiry->status->getColor(),
            'lines_count' => $enquiry->lines_count ?? $enquiry->lines()->count(),
            'unread_count' => (int) ($enquiry->unread_count ?? 0),
            'thumbnail' => $enquiry->relationLoaded('lines') ? $enquiry->lines->first()?->previewImageUrl() : null,
            'submitted_at' => $enquiry->submitted_at?->toIso8601String(),
            'updated_at' => $enquiry->updated_at?->toIso8601String(),
            'is_draft' => $enquiry->isEditableByCustomer(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Enquiry $enquiry): array
    {
        $enquiry->loadMissing(['lines.artwork.media', 'lines.artwork.artist', 'lines.qualityTier', 'lines.medium', 'lines.substrate',
            'lines.frame.media', 'lines.mat', 'lines.glazing', 'lines.finishings', 'lines.media', 'deliveryAddress.state']);

        return [
            ...self::summary($enquiry),
            'project_type' => $enquiry->project_type?->value,
            'project_type_label' => $enquiry->project_type?->getLabel(),
            'delivery_address_id' => $enquiry->delivery_address_id,
            'delivery_address' => $enquiry->deliveryAddress ? self::address($enquiry->deliveryAddress) : null,
            'required_by' => $enquiry->required_by?->toDateString(),
            'budget_range' => $enquiry->budget_range?->value,
            'budget_range_label' => $enquiry->budget_range?->getLabel(),
            'installation_needed' => $enquiry->installation_needed,
            'preferred_contact' => $enquiry->preferred_contact?->value,
            'notes' => $enquiry->notes,
            'can_cancel' => $enquiry->status->isCancellable(),
            'can_message' => $enquiry->status->isOpen(),
            'lines' => $enquiry->lines->map(fn (EnquiryLine $line) => self::line($line))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(EnquiryLine $line): array
    {
        return [
            'id' => $line->id,
            'position' => $line->position,
            'source_type' => $line->source_type->value,
            'source_label' => $line->source_type->getLabel(),
            'display_title' => $line->displayTitle(),
            'artwork_id' => $line->artwork_id,
            'artwork' => $line->artwork ? [
                'code' => $line->artwork->code,
                'title' => $line->artwork->title,
                'artist' => $line->artwork->artist?->name,
            ] : null,
            'title' => $line->title,
            'brief' => $line->custom_brief ?? (object) [],
            'own' => $line->own_artwork ?? (object) [],
            'rights_confirmed' => $line->rights_confirmed_at !== null,
            'unit' => $line->unit->value,
            'size_reference' => $line->size_reference->value,
            'size_label' => $line->sizeLabel(),
            'width_mm' => $line->width_mm,
            'height_mm' => $line->height_mm,
            'quantity' => $line->quantity,
            'quality_tier_id' => $line->quality_tier_id,
            'medium_id' => $line->medium_id,
            'substrate_id' => $line->substrate_id,
            'edge_style' => $line->edge_style?->value,
            'frame_id' => $line->frame_id,
            'frame_advice_requested' => $line->frame_advice_requested,
            'mat_id' => $line->mat_id,
            'mat_border_mm' => $line->mat_border_mm,
            'glazing_id' => $line->glazing_id,
            'finishing_ids' => $line->finishings->pluck('id')->all(),
            'placement' => $line->placement,
            'wall_width_mm' => $line->wall_width_mm,
            'wall_height_mm' => $line->wall_height_mm,
            'wall_colour' => $line->wall_colour,
            'notes' => $line->notes,
            'labels' => [
                'quality' => $line->qualityTier?->name,
                'medium' => $line->medium?->name,
                'substrate' => $line->substrate?->name,
                'frame' => $line->frame?->name,
                'mat' => $line->mat?->name,
                'glazing' => $line->glazing?->name,
                'finishings' => $line->finishings->pluck('name')->all(),
            ],
            'preview_image' => $line->previewImageUrl(),
            'references' => $line->getMedia('references')->map(fn (Media $m) => self::media($m))->values()->all(),
            'artwork_photo' => ($m = $line->getFirstMedia('artwork_photo')) ? self::media($m) : null,
            'hi_res' => ($m = $line->getFirstMedia('hi_res')) ? self::media($m) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'kind' => $message->kind->value,
            'is_question' => $message->kind === MessageKind::Question,
            'is_system' => $message->kind === MessageKind::System,
            'from_staff' => $message->from_staff,
            'author' => $message->from_staff ? ($message->author?->name ?? 'Our team') : 'You',
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
            'attachments' => $message->getMedia('attachments')->map(fn (Media $m) => self::media($m))->values()->all(),
        ];
    }

    /**
     * A sent quotation as the customer sees it (no costs or margins).
     *
     * @return array<string, mixed>
     */
    public static function quote(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->displayNumber(),
            'version' => $quote->version,
            'status' => $quote->status->value,
            'status_label' => $quote->status->getLabel(),
            'status_color' => $quote->status->getColor(),
            'is_current' => ! in_array($quote->status, [QuoteStatus::Superseded, QuoteStatus::Expired], true),
            'issue_date' => $quote->issue_date?->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'lead_time' => $quote->lead_time,
            'payment_terms' => $quote->payment_terms,
            'note_to_customer' => $quote->note_to_customer,
            'lines' => $quote->lines->map(fn (QuoteLine $l) => [
                'id' => $l->id,
                'title' => $l->title,
                'spec' => $l->spec,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_amount' => $l->discount_amount,
                'line_total' => $l->line_total,
                'is_optional' => $l->is_optional,
            ])->values()->all(),
            'extras' => $quote->extras->map(fn (QuoteExtra $e) => [
                'name' => $e->name,
                'inclusion' => $e->inclusion,
                'inclusion_label' => $e->inclusionLabel(),
                'amount' => $e->amount,
            ])->values()->all(),
            'items_total' => $quote->items_total,
            'extras_total' => $quote->extras_total,
            'discount_amount' => $quote->discount_amount,
            'taxable' => $quote->taxable,
            'intra_state' => $quote->intra_state,
            'tax_rate' => (float) $quote->tax_rate,
            'cgst' => $quote->cgst,
            'sgst' => $quote->sgst,
            'igst' => $quote->igst,
            'grand_total' => $quote->grand_total,
            'pdf_url' => route('portal.enquiries.quotes.pdf', [$quote->enquiry_id, $quote->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function address(Address $address): array
    {
        return [
            'id' => $address->id,
            'kind' => $address->kind->value,
            'label' => $address->label,
            'contact_name' => $address->contact_name,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'pin_code' => $address->pin_code,
            'state_id' => $address->state_id,
            'state' => $address->state?->name,
            'access_notes' => $address->access_notes,
            'is_default' => $address->is_default,
            'one_line' => $address->oneLine(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function media(Media $media): array
    {
        $isImage = MediaUrl::isImage($media);

        return [
            'id' => $media->id,
            'name' => $media->file_name,
            'url' => MediaUrl::for($media),
            'thumb' => $isImage ? MediaUrl::for($media, 'thumb') : null,
            'is_image' => $isImage,
            'size' => $media->human_readable_size,
        ];
    }
}
