<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DaftarNilais\Pages\ListDaftarNilais;
use App\Models\Penugasan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class DaftarNilaiResource extends Resource
{
    protected static ?string $model = Penugasan::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationLabel = 'Daftar Nilai';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('index')
                    ->label('No')
                    ->rowIndex(),

                TextColumn::make('institusi.nama_institusi')
                    ->label('Institusi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'UNVERIFIED'  => 'danger',
                        'ACTIVE'      => 'gray',
                        'IN_PROGRESS' => 'info',
                        'SUBMITTED'   => 'warning',
                        'GRADED'      => 'success',
                        'VALIDATING'  => 'info',
                        'FINALIZED'   => 'warning',
                        'PUBLISHED'   => 'success',
                        default       => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('nilai_reviewer_1')
                    ->label('Nilai R1')
                    ->sortable(),

                TextColumn::make('nilai_reviewer_2')
                    ->label('Nilai R2')
                    ->sortable(),

                TextColumn::make('nilai_reviewer_3')
                    ->label('Nilai R3')
                    ->sortable(),

                SelectColumn::make('total_skor_akhir')
                    ->label('Nilai Final')
                    ->options(function ($record) {
                        if (!$record) return [];
                        $r1 = (float) ($record->nilai_reviewer_1 ?? 0);
                        $r2 = (float) ($record->nilai_reviewer_2 ?? 0);
                        $r3 = (float) ($record->nilai_reviewer_3 ?? 0);
                        $rata = (float) ($record->nilai_rata_rata ?? 0);

                        $options = [];
                        if ($r1 > 0) {
                            $r1Str = number_format($r1, 2);
                            $options[$r1Str] = "Nilai R1: {$r1Str}";
                        }
                        if ($r2 > 0) {
                            $r2Str = number_format($r2, 2);
                            $options[$r2Str] = "Nilai R2: {$r2Str}";
                        }
                        if ($r3 > 0) {
                            $r3Str = number_format($r3, 2);
                            $options[$r3Str] = "Nilai R3: {$r3Str}";
                        }
                        if ($rata > 0) {
                            $rataStr = number_format($rata, 2);
                            $options[$rataStr] = "Rata-rata: {$rataStr}";
                        }

                        return $options;
                    })
                    ->selectablePlaceholder(false)
                    ->sortable(),
            ])
            ->recordClasses(function (Penugasan $record) {
                $nilai_1 = (float) ($record->nilai_reviewer_1 ?? 0);
                $nilai_2 = (float) ($record->nilai_reviewer_2 ?? 0);
                $threshold = (float) config('rubrik.reviewer_dispute_threshold', 50);

                if (abs($nilai_1 - $nilai_2) >= $threshold) {
                    return 'bg-red-50 dark:bg-red-950/20';
                }

                return null;
            })
            ->filters([
                SelectFilter::make('tahun_periode')
                    ->label('Tahun Periode')
                    ->options(fn (): array => Penugasan::query()
                        ->select('tahun_periode')
                        ->distinct()
                        ->orderByDesc('tahun_periode')
                        ->pluck('tahun_periode', 'tahun_periode')
                        ->toArray()),
                SelectFilter::make('status')
                    ->label('Status PT')
                    ->options([
                        'UNVERIFIED'  => 'UNVERIFIED',
                        'ACTIVE'      => 'ACTIVE',
                        'IN_PROGRESS' => 'IN_PROGRESS',
                        'SUBMITTED'   => 'SUBMITTED',
                        'GRADED'      => 'GRADED',
                        'VALIDATING'  => 'VALIDATING',
                        'FINALIZED'   => 'FINALIZED',
                        'PUBLISHED'   => 'PUBLISHED',
                    ]),
            ])
            ->actions([
                Action::make('detail_nilai')
                    ->label('Detail')
                    ->icon('heroicon-o-information-circle')
                    ->color('info')
                    ->modalHeading('Detail Reviewer & Nilai')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->infolist([
                        Section::make('Detail Institusi & Reviewer')
                            ->schema([
                                TextEntry::make('institusi.nama_institusi')->label('Institusi'),
                                TextEntry::make('reviewer1.nama_lengkap')->label('Nama R1')->placeholder('-'),
                                TextEntry::make('reviewer2.nama_lengkap')->label('Nama R2')->placeholder('-'),
                                TextEntry::make('reviewer3.nama_lengkap')->label('Nama R3')->placeholder('-'),
                            ])->columns(2),
                        Section::make('Detail Nilai')
                            ->schema([
                                TextEntry::make('nilai_reviewer_1')->label('Nilai R1')->placeholder('0.00'),
                                TextEntry::make('nilai_reviewer_2')->label('Nilai R2')->placeholder('0.00'),
                                TextEntry::make('nilai_reviewer_3')->label('Nilai R3')->placeholder('0.00'),
                                TextEntry::make('total_skor_akhir')->label('Nilai Final')->placeholder('0.00'),
                            ])->columns(2),
                    ]),
                Action::make('export_pdf')
                    ->label('Export PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->url(fn (Penugasan $record): string => route('penugasan.resume.pdf', $record->id))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('detail_nilai')
                    ->label('Detail')
                    ->icon('heroicon-o-information-circle')
                    ->color('info')
                    ->modalHeading('Detail Reviewer & Nilai')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->infolist([
                        Section::make('Detail Institusi & Reviewer')
                            ->schema([
                                TextEntry::make('institusi.nama_institusi')->label('Institusi'),
                                TextEntry::make('reviewer1.nama_lengkap')->label('Nama R1')->placeholder('-'),
                                TextEntry::make('reviewer2.nama_lengkap')->label('Nama R2')->placeholder('-'),
                                TextEntry::make('reviewer3.nama_lengkap')->label('Nama R3')->placeholder('-'),
                            ])->columns(2),
                        Section::make('Detail Nilai')
                            ->schema([
                                TextEntry::make('nilai_reviewer_1')->label('Nilai R1')->placeholder('0.00'),
                                TextEntry::make('nilai_reviewer_2')->label('Nilai R2')->placeholder('0.00'),
                                TextEntry::make('nilai_reviewer_3')->label('Nilai R3')->placeholder('0.00'),
                                TextEntry::make('total_skor_akhir')->label('Nilai Final')->placeholder('0.00'),
                            ])->columns(2),
                    ]),
                Action::make('export_pdf')
                    ->label('Export PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->url(fn (Penugasan $record): string => route('penugasan.resume.pdf', $record->id))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDaftarNilais::route('/'),
        ];
    }
}
