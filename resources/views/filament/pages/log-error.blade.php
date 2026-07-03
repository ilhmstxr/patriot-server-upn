<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex justify-between items-center gap-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-4 gap-y-2">
                    <span>
                        File aktif: 
                        <span class="font-mono bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded text-gray-800 dark:text-gray-200">
                            {{ $activeLogFile ?? 'tidak ada' }}
                        </span>
                    </span>
                    @if($fileLastModified)
                        <span>
                            Terakhir diubah: 
                            <span class="font-mono bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded text-gray-800 dark:text-gray-200">
                                {{ $fileLastModified }}
                            </span>
                        </span>
                    @endif
                    @if($lastRefreshedAt)
                        <span>
                            Terakhir dimuat: 
                            <span class="font-mono bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded text-gray-800 dark:text-gray-200">
                                {{ $lastRefreshedAt }}
                            </span>
                        </span>
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                <x-filament::button wire:click="loadLog" color="gray" size="sm" icon="heroicon-m-arrow-path">
                    Refresh
                </x-filament::button>
                <x-filament::button 
                    wire:click="clearLog" 
                    color="danger" 
                    size="sm" 
                    icon="heroicon-m-trash"
                    wire:confirm="Apakah Anda yakin ingin mengosongkan log ini?"
                >
                    Bersihkan Log
                </x-filament::button>
            </div>
        </div>

        <div class="p-4 bg-gray-950 dark:bg-black rounded-xl border border-gray-200 dark:border-gray-800 shadow-inner">
            <pre class="text-xs text-emerald-400 font-mono overflow-x-auto whitespace-pre-wrap leading-relaxed" style="max-height: 600px; min-height: 200px;">{{ $logContent }}</pre>
        </div>
    </div>
</x-filament-panels::page>
