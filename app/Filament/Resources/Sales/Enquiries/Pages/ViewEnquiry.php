<?php

namespace App\Filament\Resources\Sales\Enquiries\Pages;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\EnquiryStatus;
use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Resources\Sales\Quotes\QuoteResource;
use App\Models\Enquiry;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

/**
 * One enquiry: artworks with previews, conversation, summary and workflow actions.
 */
class ViewEnquiry extends ViewRecord
{
    protected static string $resource = EnquiryResource::class;

    public function getTitle(): string
    {
        /** @var Enquiry $enquiry */
        $enquiry = $this->record;

        return $enquiry->displayReference().' · '.$enquiry->project_name;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(EnquiryWorkflow::class)->markThreadRead($this->enquiry(), auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            // Same editor the customer uses (artwork options, previews, pricing), opened for staff.
            Action::make('openEditor')
                ->label(fn (): string => $this->enquiry()->status === EnquiryStatus::Draft ? 'Continue in editor' : 'Edit artworks')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->enquiry()->isEditableBy(auth()->user()))
                ->url(fn (): string => route('portal.enquiries.edit', $this->enquiry())),

            Action::make('createQuote')
                ->label(fn (): string => $this->enquiry()->quotes()->exists() ? 'Revise quote' : 'Create quote')
                ->icon('heroicon-o-document-currency-rupee')
                ->color('success')
                ->visible(fn (): bool => in_array($this->enquiry()->status, QuoteBuilder::QUOTABLE, true) && ! $this->activeOrder())
                ->action(function (): void {
                    $quote = app(QuoteBuilder::class)->createFromEnquiry($this->enquiry(), auth()->user());
                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $quote]));
                }),

            // The order placed from this enquiry's quotation.
            Action::make('openOrder')
                ->label(fn (): string => 'Order '.$this->enquiry()->orders()->value('number'))
                ->icon('heroicon-o-shopping-bag')
                ->color('success')
                ->visible(fn (): bool => $this->enquiry()->orders()->exists())
                ->url(fn (): string => OrderResource::getUrl('view', ['record' => $this->enquiry()->orders()->value('id')])),

            Action::make('startReview')
                ->label('Start review')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn (): bool => $this->enquiry()->status === EnquiryStatus::Submitted)
                ->action(function (): void {
                    app(EnquiryWorkflow::class)->startReview($this->enquiry(), auth()->user());
                    $this->notify('Enquiry is now under review and assigned to you.');
                }),

            Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-chat-bubble-left')
                ->color('gray')
                ->visible(fn (): bool => $this->enquiry()->status->isOpen())
                ->modalHeading('Send a message to the customer')
                ->schema($this->messageFields())
                ->action(function (array $data): void {
                    app(EnquiryWorkflow::class)->postMessage($this->enquiry(), auth()->user(), $data['body'], $this->tempPaths($data));
                    $this->notify('Message sent. The customer gets an email.');
                }),

            Action::make('askQuestion')
                ->label('Ask a question')
                ->icon('heroicon-o-question-mark-circle')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->enquiry()->status, [EnquiryStatus::Submitted, EnquiryStatus::UnderReview, EnquiryStatus::NeedsClarification], true))
                ->modalHeading('Ask the customer a question')
                ->modalDescription('The enquiry waits as “Needs clarification” until the customer replies.')
                ->schema($this->messageFields())
                ->action(function (array $data): void {
                    app(EnquiryWorkflow::class)->askQuestion($this->enquiry(), auth()->user(), $data['body'], $this->tempPaths($data));
                    $this->notify('Question sent. The customer gets an email.');
                }),

            ActionGroup::make([
                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        Select::make('assigned_to')
                            ->label('Assign to')
                            ->options(fn () => User::staff()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->placeholder('Unassigned')
                            ->default(fn () => $this->enquiry()->assigned_to),
                    ])
                    ->action(function (array $data): void {
                        app(EnquiryWorkflow::class)->assign($this->enquiry(), $data['assigned_to'] ? User::find($data['assigned_to']) : null, auth()->user());
                        $this->notify('Assignment updated.');
                    }),

                Action::make('internal')
                    ->label('Priority & internal notes')
                    ->icon('heroicon-o-flag')
                    ->fillForm(fn (): array => [
                        'priority' => $this->enquiry()->priority,
                        'internal_notes' => $this->enquiry()->internal_notes,
                    ])
                    ->schema([
                        Select::make('priority')->options(Priority::class)->required()->native(false),
                        Textarea::make('internal_notes')->rows(5)->helperText('Never shown to the customer.'),
                    ])
                    ->action(function (array $data): void {
                        $this->enquiry()->update($data);
                        $this->notify('Saved.');
                    }),

                Action::make('cancel')
                    ->label('Cancel enquiry')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => $this->enquiry()->status->isCancellable())
                    ->requiresConfirmation()
                    ->modalDescription('The customer will see the enquiry as cancelled.')
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->rows(2),
                    ])
                    ->action(function (array $data): void {
                        app(EnquiryWorkflow::class)->cancel($this->enquiry(), auth()->user(), $data['reason']);
                        $this->notify('Enquiry cancelled.');
                    }),
            ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function messageFields(): array
    {
        return [
            Textarea::make('body')->label('Message')->required()->rows(6)->maxLength(5000),
            FileUpload::make('attachments')
                ->multiple()
                ->maxFiles(10)
                ->maxSize(25600)
                ->disk('local')
                ->directory('tmp-message-uploads')
                ->preserveFilenames()
                ->helperText('Optional. Images or PDFs, up to 25 MB each.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function tempPaths(array $data): array
    {
        return collect($data['attachments'] ?? [])
            ->map(fn (string $path): string => Storage::disk('local')->path($path))
            ->values()
            ->all();
    }

    private function enquiry(): Enquiry
    {
        /** @var Enquiry $enquiry */
        $enquiry = $this->record;

        return $enquiry;
    }

    private function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
        $this->record->refresh();
    }

    /** An order that is not cancelled: the enquiry is settled, so no new quotation. */
    private function activeOrder(): bool
    {
        return $this->enquiry()->orders()->where('status', '!=', OrderStatus::Cancelled)->exists();
    }
}
