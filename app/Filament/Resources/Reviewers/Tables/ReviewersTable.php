<?php

namespace App\Filament\Resources\Reviewers\Tables;

use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(\App\Models\Penugasan::query())
            ->columns([
                TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('No'),
                TextColumn::make('tahun_periode')
                    ->label('Periode')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('institusi.nama_institusi')
                    ->label('Nama Instansi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nama_pic')
                    ->label('Nama PIC')
                    ->searchable(),
                SelectColumn::make('reviewer_1_id')
                    ->label('Reviewer')
                    ->options(\App\Models\Reviewer::pluck('nama_lengkap', 'id'))
                    ->searchable()
                    ->searchableOptions()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ACTIVE'      => 'gray',
                        'IN_PROGRESS' => 'info',
                        'SUBMITTED'   => 'warning',
                        'GRADED'      => 'success',
                        'PUBLISHED'   => 'success',
                        default       => 'gray',
                    })
                    ->searchable(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
