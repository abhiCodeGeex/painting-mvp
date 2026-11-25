<?php

namespace App\Filament\Resources\Sales\Enquiries\Actions;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\EnquirySource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff raise an enquiry for a customer: creates the customer's draft and opens it in the
 * same enquiry editor customers use (every artwork option, previews and pricing), where staff
 * add artworks and send it on the customer's behalf.
 */
class RaiseEnquiryAction
{
    /**
     * @param  Closure(): ?Customer|null  $customer  fixed customer (e.g. on the customer's page); null shows a picker
     */
    public static function make(?Closure $customer = null): Action
    {
        return Action::make('raiseEnquiry')
            ->label('New enquiry')
            ->icon('heroicon-o-plus')
            ->modalHeading('New enquiry for a customer')
            ->modalDescription('Creates a draft for the customer and opens it in the enquiry editor, with every option the customer has. Nothing is sent until you press “Send enquiry”.')
            ->modalSubmitActionLabel('Create & open editor')
            ->visible(fn (): bool => self::allowed())
            ->schema(fn (): array => [
                Select::make('customer_id')
                    ->label('Customer')
                    ->required()
                    ->searchable()
                    ->hidden($customer !== null)
                    ->getSearchResultsUsing(fn (string $search): array => self::search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => Customer::with('user')->find($value)?->displayName())
                    ->live()
                    ->afterStateUpdated(fn ($state, $set) => $set('source_id', Customer::find($state)?->source_id ?? self::websiteSource())),
                Select::make('source_id')
                    ->label('Source')
                    ->options(fn () => EnquirySource::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => ($customer ? $customer()?->source_id : null) ?? self::websiteSource())
                    ->helperText('How the customer reached us (e.g. phone or showroom).'),
                TextInput::make('project_name')
                    ->label('Project name')
                    ->maxLength(255)
                    ->placeholder('My artwork project'),
            ])
            ->action(function (array $data, Action $action) use ($customer): void {
                abort_unless(self::allowed(), 403);

                $target = $customer ? $customer() : Customer::find($data['customer_id'] ?? null);
                abort_if($target === null, 404);

                $enquiry = app(EnquiryWorkflow::class)->createDraft(
                    $target,
                    isset($data['source_id']) ? (int) $data['source_id'] : null,
                    $data['project_name'] ?? null,
                );

                $action->redirect(route('portal.enquiries.edit', $enquiry));
            });
    }

    /** Raising needs create and update permission (the editor saves through updates). */
    public static function allowed(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isStaff() && $user->can('create', Enquiry::class) && $user->can('Update:Enquiry');
    }

    /**
     * @return array<int, string>
     */
    private static function search(string $search): array
    {
        $like = '%'.$search.'%';

        return Customer::query()
            ->with('user')
            ->where(fn (Builder $q) => $q
                ->where('company_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $c) => [$c->id => trim($c->displayName().($c->user?->email ? ' · '.$c->user->email : ''))])
            ->all();
    }

    private static function websiteSource(): ?int
    {
        return EnquirySource::where('name', 'Website')->value('id');
    }
}
