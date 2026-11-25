<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Pricing\CustomerEstimate;
use App\Enums\AddressKind;
use App\Enums\BudgetRange;
use App\Enums\EnquiryStatus;
use App\Enums\PreferredContact;
use App\Enums\ProjectType;
use App\Http\Presenters\CatalogOptions;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Enquiry;
use App\Models\EnquirySource;
use App\Models\Message;
use App\Models\Quote;
use App\Models\RejectionReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EnquiryController extends PortalController
{
    public function __construct(private readonly EnquiryWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $enquiries = $this->customer($request)->enquiries()
            ->withCount(['lines', 'messages as unread_count' => fn (Builder $q) => $q->where('from_staff', true)->whereNull('read_at')->where('kind', '!=', 'system')])
            ->with(['lines' => fn ($q) => $q->with(['artwork.media', 'media'])])
            ->latest('updated_at')
            ->get();

        return Inertia::render('enquiries/index', [
            'enquiries' => $enquiries->map(fn (Enquiry $e) => EnquiryPresenter::summary($e))->values(),
        ]);
    }

    /**
     * "New enquiry" creates an empty draft straight away so files can be attached to it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Enquiry::class);
        $customer = $this->customer($request);

        $enquiry = $customer->enquiries()->create([
            'project_name' => 'My artwork project',
            'source_id' => $customer->source_id ?? EnquirySource::where('name', 'Website')->value('id'),
            'delivery_address_id' => $customer->addresses()->where('kind', AddressKind::Delivery)->orderByDesc('is_default')->value('id'),
        ]);

        return to_route('portal.enquiries.edit', $enquiry)
            ->with('success', 'Draft created. Add your artworks, then press “Send enquiry” on the Review & send step to send it to us.');
    }

    public function show(Request $request, Enquiry $enquiry): Response|RedirectResponse
    {
        $this->authorize('view', $enquiry);

        if ($enquiry->isEditableByCustomer()) {
            return to_route('portal.enquiries.edit', $enquiry);
        }

        $this->workflow->markThreadRead($enquiry, $request->user());

        return Inertia::render('enquiries/show', [
            'enquiry' => EnquiryPresenter::detail($enquiry),
            'messages' => $enquiry->messages()->with('media', 'author')->get()->map(fn (Message $m) => EnquiryPresenter::message($m))->values(),
            'quotes' => $enquiry->quotes()->whereNotNull('sent_at')->with('lines', 'extras')->get()->map(fn (Quote $q) => EnquiryPresenter::quote($q))->values(),
            'options' => CatalogOptions::forEditor(),
            'rejectionReasons' => RejectionReason::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'requires_comment'])->values(),
        ]);
    }

    public function edit(Request $request, Enquiry $enquiry): Response|RedirectResponse
    {
        $this->authorize('view', $enquiry);

        if (! $enquiry->isEditableByCustomer()) {
            return to_route('portal.enquiries.show', $enquiry);
        }

        $customer = $this->customer($request);

        return Inertia::render('enquiries/edit', [
            'enquiry' => EnquiryPresenter::detail($enquiry),
            'estimate' => app(CustomerEstimate::class)->forEnquiry($enquiry),
            'options' => CatalogOptions::forEditor(),
            'projectOptions' => CatalogOptions::forProject(),
            'addresses' => $customer->addresses()->with('state')->where('kind', AddressKind::Delivery)->orderByDesc('is_default')->get()
                ->map(fn ($a) => EnquiryPresenter::address($a))->values(),
        ]);
    }

    /**
     * Saves the project details of a draft (step 1 of the wizard).
     */
    public function update(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $this->authorize('update', $enquiry);

        $data = $request->validate([
            'project_name' => ['required', 'string', 'max:255'],
            'project_type' => ['required', Rule::enum(ProjectType::class)],
            'delivery_address_id' => ['nullable', Rule::exists('addresses', 'id')->where('customer_id', $enquiry->customer_id)->whereNull('deleted_at')],
            'required_by' => ['nullable', 'date', 'after:today'],
            'budget_range' => ['nullable', Rule::enum(BudgetRange::class)],
            'installation_needed' => ['boolean'],
            'preferred_contact' => ['required', Rule::enum(PreferredContact::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'required_by.after' => 'The date must be in the future.',
        ]);

        $enquiry->update($data);

        return back()->with('success', 'Saved.');
    }

    public function submit(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $this->authorize('update', $enquiry);

        $this->workflow->submit($enquiry, $request->user());

        return to_route('portal.enquiries.show', $enquiry)->with('success', 'Thank you! Your enquiry has been sent. We will be in touch soon.');
    }

    public function cancel(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $this->authorize('cancel', $enquiry);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->workflow->cancel($enquiry, $request->user(), $data['reason'] ?? null);

        return to_route('portal.enquiries.index')->with('success', 'Enquiry cancelled.');
    }

    /**
     * Drafts can be thrown away completely.
     */
    public function destroy(Request $request, Enquiry $enquiry): RedirectResponse
    {
        $this->authorize('update', $enquiry);
        abort_unless($enquiry->status === EnquiryStatus::Draft, 403);

        $enquiry->delete();

        return to_route('portal.enquiries.index')->with('success', 'Draft deleted.');
    }
}
