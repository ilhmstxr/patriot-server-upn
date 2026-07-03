<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use BackedEnum;
use Illuminate\Support\Facades\File;
use Filament\Notifications\Notification;

class LogError extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected string $view = 'filament.pages.log-error';

    protected static ?string $navigationLabel = 'Log Error';

    protected static ?string $title = 'Log Error';

    protected static ?int $navigationSort = 100;

    public string $logContent = '';
    public ?string $activeLogFile = null;

    public function mount(): void
    {
        $this->loadLog();
    }

    public function loadLog(): void
    {
        $logPath = $this->getLatestLogPath();

        if ($logPath && File::exists($logPath)) {
            $this->activeLogFile = basename($logPath);
            $fileSize = File::size($logPath);
            
            if ($fileSize === 0) {
                $this->logContent = 'File log kosong.';
                return;
            }

            // Membaca 80 KB terakhir untuk efisiensi memori RAM
            $readLength = min($fileSize, 80000);
            $handle = fopen($logPath, 'r');
            fseek($handle, -$readLength, SEEK_END);
            $chunk = fread($handle, $readLength);
            fclose($handle);

            $this->logContent = $chunk;
        } else {
            $this->activeLogFile = null;
            $this->logContent = 'Tidak ada file log ditemukan di storage/logs.';
        }
    }

    public function clearLog(): void
    {
        $logPath = $this->getLatestLogPath();

        if ($logPath && File::exists($logPath)) {
            File::put($logPath, '');
            $this->loadLog();

            Notification::make()
                ->title('Log berhasil dibersihkan')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Gagal membersihkan log')
                ->body('File log tidak ditemukan.')
                ->danger()
                ->send();
        }
    }

    protected function getLatestLogPath(): ?string
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            return null;
        }

        // Mencari berkas laravel*.log
        $files = glob($logDir . '/laravel*.log');
        if (empty($files)) {
            return null;
        }

        // Urutkan berdasarkan waktu modifikasi terbaru
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }
}
