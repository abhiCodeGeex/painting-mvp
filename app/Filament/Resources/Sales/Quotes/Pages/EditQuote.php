<?php

namespace App\Filament\Resources\Sales\Quotes\Pages;

use App\Domain\Orders\OrderService;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\OrderStatus;
use App\Enums\PreferredContact;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Resources\Sales\Quotes\QuoteResource;
use App\Filament\Support\MoneyInput;
use App\Models\Quote;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Edit a draft quote, preview the PDF and send it by email, WhatsApp and/or the portal.
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    public function getTitle(): string
    {
        return 'Quote '.$this->quote()->displayNumber();
    }

    protected function afterSave(): void
    {
        app(QuoteBuilder::class)->recalculate($this->quote());
        $this->quote()->refresh();
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save & recalculate')->visible(fn () => $this->quote()->status->isEditable());
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->visible(fn () => $this->quote()->status->isEditable());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('placeOrder')
                ->label('Place order')
                ->icon('heroicon-o-shopping-bag')
                ->color('success')
                ->visible(fn () => $this->quote()->status === QuoteStatus::Approved && ! $this->quote()->order()->exists())
                ->requiresConfirmation()
                ->modalHeading('Place the order for the customer?')
                ->modalDescription(fn () => 'Order total '.Money::format($this->quote()->grand_total)
                    .'. The token amount is due now and the rest on delivery.')
                ->fillForm(fn () => ['token_amount' => app(OrderService::class)->minimumToken((int) $this->quote()->grand_total)])
                ->schema([
                    MoneyInput::make('token_amount')->label('Token amount')->required()
                        ->minValue(fn () => app(OrderService::class)->minimumToken((int) $this->quote()->grand_total) / 100)
                        ->maxValue(fn () => $this->quote()->grand_total / 100)
                        ->helperText(fn () => 'At least '.Money::format(app(OrderService::class)->minimumToken((int) $this->quote()->grand_total))
                            .', at most the order total.'),
                ])
                ->action(function (array $data): void {
                    $order = app(OrderService::class)->place($this->quote(), auth()->user(), (int) $data['token_amount']);
                    $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
                }),

            Action::make('openOrder')
                ->label(fn () => 'Order '.$this->quote()->order?->number)
                ->icon('heroicon-o-shopping-bag')
                ->color('success')
                ->visible(fn () => $this->quote()->order()->exists())
                ->url(fn () => OrderResource::getUrl('view', ['record' => $this->quote()->order])),

            Action::make('pdf')
                ->label(fn () => $this->quote()->status->isEditable() ? 'Preview PDF' : 'PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('admin.quotes.pdf', $this->quote()), shouldOpenInNewTab: true),

            Action::make('send')
                ->label('Send to customer')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => $this->quote()->status->isEditable())
                ->modalHeading('Send quotation')
                ->modalDescription('The quote is locked once sent. Save any changes first.')
                ->modalSubmitActionLabel('Send')
                ->schema([
                    CheckboxList::make('channels')
                        ->label('Send by')
                        ->options([
                            'email' => 'Email (PDF attached)',
                            'whatsapp' => 'WhatsApp (opens WhatsApp with the message ready)',
                            'portal' => 'Message in the customer portal (PDF attached)',
                        ])
                        ->descriptions(fn () => [
                            'email' => $this->quote()->enquiry->customer->user?->email ?? 'No email on file',
                            'whatsapp' => $this->quote()->enquiry->customer->phone ?? 'No phone number on file',
                            'portal' => 'Always available in “My enquiries”',
                        ])
                        ->default(fn () => array_values(array_unique(array_filter([
                            'email',
                            'portal',
                            $this->quote()->enquiry->preferred_contact === PreferredContact::WhatsApp ? 'whatsapp' : null,
                        ]))))
                        ->required(),
                    Textarea::make('message')
                        ->rows(6)
                        ->placeholder('Leave empty for the standard message with the total, validity date and link.'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $result = app(QuoteBuilder::class)->send($this->quote(), auth()->user(), $data['channels'], $data['message'] ?? null);
                    } catch (ValidationException $e) {
                        // The errors are about the quote (unfinished lines, already sent), not the modal's
                        // fields, so they would not show next to any input: list them in a notification.
                        Notification::make()->title('The quote was not sent')
                            ->body(new HtmlString(collect(Arr::flatten($e->errors()))->map(fn ($m) => e($m))->implode('<br>')))
                            ->danger()->persistent()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title('Quote sent')->body('The enquiry is now “Quoted”.')->success()->send();

                    if (in_array('whatsapp', $data['channels'], true)) {
                        if ($result['whatsapp_url']) {
                            $this->js('window.open('.json_encode($result['whatsapp_url']).', "_blank")');
                        } else {
                            Notification::make()->title('No phone number for WhatsApp')->body('Add the customer’s phone number to send by WhatsApp.')->warning()->send();
                        }
                    }

                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $this->quote()]));
                }),

            Action::make('whatsappAgain')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->visible(fn () => in_array($this->quote()->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true) && $this->quote()->enquiry->customer->phone)
                ->url(fn () => app(QuoteBuilder::class)->whatsappUrl($this->quote(), app(QuoteBuilder::class)->defaultMessage($this->quote())), shouldOpenInNewTab: true),

            Action::make('newVersion')
                ->label('New version')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn () => ! $this->quote()->status->isEditable() && ! $this->quote()->enquiry->quotes()->where('status', QuoteStatus::Draft)->exists()
                    && ! $this->quote()->enquiry->orders()->where('status', '!=', OrderStatus::Cancelled)->exists())
                ->requiresConfirmation()
                ->modalDescription('Copies this quote into a new draft. When you send it, this version is marked as superseded.')
                ->action(function (): void {
                    $copy = app(QuoteBuilder::class)->newVersion($this->quote(), auth()->user());
                    $this->redirect(QuoteResource::getUrl('edit', ['record' => $copy]));
                }),
        ];
    }

    private function quote(): Quote
    {
        /** @var Quote $quote */
        $quote = $this->record;

        return $quote;
    }
}
