<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Pricing\CustomerEstimate;
use App\Enums\AddressKind;
use App\Enums\BudgetRange;
use App\Enums\EnquiryStatus;
use App\Enums\PreferredContact;
use App\Enums\ProjectType;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Http\Presenters\CatalogOptions;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Enquiry;
use App\Models\Message;
use App\Models\Quote;
use App\Models\RejectionReason;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
        $enquiry = $this->workflow->createDraft($this->customer($request));

        return to_route('portal.enquiries.edit', $enquiry)
            ->with('success', 'Draft created. Add your artworks, then press “Send enquiry” on the Review & send step to send it to us.');
    }

    public function show(Request $request, Enquiry $enquiry): Response|SymfonyResponse
    {
        $this->authorize('view', $enquiry);

        // Staff read enquiries in the admin panel; the portal is only their editor.
        if ($request->user()->isStaff()) {
            return Inertia::location(self::adminUrl($enquiry));
        }

        if ($enquiry->isEditableByCustomer()) {
            return to_route('portal.enquiries.edit', $enquiry);
        }

        $this->workflow->markThreadRead($enquiry, $request->user());

        return Inertia::render('enquiries/show', [
            'enquiry' => EnquiryPresenter::detail($enquiry),
            'messages' => $enquiry->messages()->with('media', 'author')->get()->map(fn (Message $m) => EnquiryPresenter::message($m))->values(),
            'quotes' => ($quotes = $enquiry->quotes()->whereNotNull('sent_at')->with('lines', 'extras')->get())
                ->map(fn (Quote $q) => EnquiryPresenter::quote($q, (int) $quotes->max('version')))->values(),
            'options' => CatalogOptions::forEditor(),
            'rejectionReasons' => RejectionReason::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'requires_comment'])->values(),
        ]);
    }

    public function edit(Request $request, Enquiry $enquiry): Response|SymfonyResponse
    {
        $this->authorize('view', $enquiry);
        $user = $request->user();

        if (! $enquiry->isEditableBy($user)) {
            if ($user->isStaff()) {
                self::notifyStaff('This enquiry can no longer be changed in the editor.', 'warning');

                return Inertia::location(self::adminUrl($enquiry));
            }

            return to_route('portal.enquiries.show', $enquiry);
        }

        // Always the enquiry's own customer: staff may be editing it on their behalf.
        $customer = $enquiry->customer;

        return Inertia::render('enquiries/edit', [
            'staff' => $user->isStaff() ? [
                'customer_name' => $customer->displayName(),
                'customer_email' => $customer->user?->email,
                'admin_url' => self::adminUrl($enquiry),
                'admin_list_url' => EnquiryResource::getUrl('index', panel: 'admin'),
                'is_draft' => $enquiry->status === EnquiryStatus::Draft,
                'status_label' => $enquiry->status->getLabel(),
            ] : null,
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
        $this->authorizeEditor($request, $enquiry);

        $data = $request->validate([
            'project_name' => ['required', 'string', 'max:255'],
            'project_type' => ['required', Rule::enum(ProjectType::class)],
            // A delivery address of this customer (never a billing one: it drives GST and the delivery).
            'delivery_address_id' => ['nullable', Rule::exists('addresses', 'id')->where('customer_id', $enquiry->customer_id)
                ->where('kind', AddressKind::Delivery->value)->whereNull('deleted_at')],
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

    public function submit(Request $request, Enquiry $enquiry): SymfonyResponse
    {
        $this->authorizeEditor($request, $enquiry);

        $this->workflow->submit($enquiry, $request->user());

        if ($request->user()->isStaff()) {
            self::notifyStaff('Enquiry sent on behalf of the customer. They get the usual confirmation email.');

            return Inertia::location(self::adminUrl($enquiry));
        }

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
    public function destroy(Request $request, Enquiry $enquiry): SymfonyResponse
    {
        $this->authorizeEditor($request, $enquiry);
        abort_unless($enquiry->status === EnquiryStatus::Draft, 403);

        $enquiry->delete();

        if ($request->user()->isStaff()) {
            self::notifyStaff('Draft deleted.');

            return Inertia::location(EnquiryResource::getUrl('index', panel: 'admin'));
        }

        return to_route('portal.enquiries.index')->with('success', 'Draft deleted.');
    }

    /** A notice shown on the admin page staff return to. */
    private static function notifyStaff(string $title, string $status = 'success'): void
    {
        Notification::make()->title($title)->status($status)->send();
    }

    /** The enquiry's screen in the admin panel (where staff come from and go back to). */
    public static function adminUrl(Enquiry $enquiry): string
    {
        return EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin');
    }
}
