<?php

namespace App\Filament\Resources\Sales\Customers\RelationManagers;

use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnquiriesRelationManager extends RelationManager
{
    protected static string $relationship = 'enquiries';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('project_name')
            ->columns([
                TextColumn::make('reference')->placeholder('Draft'),
                TextColumn::make('project_name')->label('Project'),
                TextColumn::make('lines_count')->counts('lines')->label('Artworks')->alignEnd(),
                TextColumn::make('status')->badge(),
                TextColumn::make('submitted_at')->label('Submitted')->date('d M Y')->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => EnquiryResource::getUrl('view', ['record' => $record])),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', 'draft'));
    }
}
