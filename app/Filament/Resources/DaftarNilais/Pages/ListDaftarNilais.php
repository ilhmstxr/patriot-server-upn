<?php

namespace App\Filament\Resources\DaftarNilais\Pages;

use App\Filament\Resources\DaftarNilaiResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListDaftarNilais extends ListRecords
{
    protected static string $resource = DaftarNilaiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->action(function (): void {
                    $this->dispatch(
                        'daftar-nilai:start-export',
                        format: 'csv',
                        filters: $this->getCurrentExportFilters(),
                    );
                }),

            Action::make('export_excel')
                ->label('Export Excel (.xlsx)')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->action(function (): void {
                    $this->dispatch(
                        'daftar-nilai:start-export',
                        format: 'excel',
                        filters: $this->getCurrentExportFilters(),
                    );
                }),
        ];
    }

    /**
     * Ambil filter aktif pada tabel Daftar Nilai (tahun_periode, status, search)
     * agar export selalu selaras dengan tampilan admin saat ini.
     */
    protected function getCurrentExportFilters(): array
    {
        $filters = [];

        $tahun = $this->tableFilters['tahun_periode']['value'] ?? null;
        if (filled($tahun)) {
            $filters['tahun_periode'] = (int) $tahun;
        }

        $status = $this->tableFilters['status']['value'] ?? null;
        if (filled($status)) {
            $filters['status'] = (string) $status;
        }

        $search = is_string($this->tableSearch) ? trim($this->tableSearch) : '';
        if ($search !== '') {
            $filters['search'] = $search;
        }

        return $filters;
    }

    public function getFooter(): ?View
    {
        return view('filament.resources.daftar-nilais.export-handler', [
            'metaUrl' => route('admin.daftar-nilai.export.meta'),
            'chunkUrl' => route('admin.daftar-nilai.export.chunk'),
        ]);
    }
}
