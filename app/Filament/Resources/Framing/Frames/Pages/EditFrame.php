<?php

namespace App\Filament\Resources\Framing\Frames\Pages;

use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Jobs\BuildFrameStrip;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFrame extends EditRecord
{
    protected static string $resource = FrameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuildStrip')
                ->label('Rebuild moulding strip')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()->getFirstMedia('photo'))
                ->action(function (): void {
                    // Run now (not queued) so the preview and any error show straight away.
                    BuildFrameStrip::dispatchSync($this->getRecord()->getKey(), force: true);
                    $this->refreshStrip();

                    $error = $this->getRecord()->strip_error;
                    $error
                        ? Notification::make()->title("Couldn't build the frame from this photo")->body($error)->danger()->send()
                        : Notification::make()->title('Moulding strip rebuilt')->success()->send();
                }),
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }

    /**
     * Reload the strip media / error so the form's strip views re-render (also polled while a queued build runs).
     */
    public function refreshStrip(): void
    {
        $this->getRecord()->refresh();
        $this->getRecord()->unsetRelation('media');
    }

    protected function afterSave(): void
    {
        // Builds only when the photo, photo type or flip changed (or removes a stale strip).
        BuildFrameStrip::dispatch($this->getRecord()->getKey())->afterCommit();
    }
}
