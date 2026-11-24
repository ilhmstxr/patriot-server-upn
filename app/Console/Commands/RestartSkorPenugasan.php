<?php

namespace App\Console\Commands;

use App\Services\PenugasanService;
use Illuminate\Console\Command;

class RestartSkorPenugasan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'penugasan:restart-skor 
                            {--penugasan_id= : ID Penugasan tertentu (opsional, default: semua penugasan)} 
                            {--dry-run : Jalankan simulasi tanpa mengubah database}
                            {--force : Jalankan langsung tanpa pertanyaan konfirmasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart dan kalkulasi ulang skor penugasan dari tabel respon_penugasans dengan pengalian bobot kategori';

    protected PenugasanService $penugasanService;

    public function __construct(PenugasanService $penugasanService)
    {
        parent::__construct();
        $this->penugasanService = $penugasanService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $penugasanId = $this->option('penugasan_id') ? (int) $this->option('penugasan_id') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->newLine();
        $this->info('====================================================================');
        $this->info(' RESTART & KALKULASI ULANG SKOR PENUGASAN');
        $this->info('====================================================================');
        $this->line(' 1. Mengambil jawaban asli per rubrik dari tabel respon_penugasans');
        $this->line(' 2. Mereset nilai skor di tabel penugasans');
        $this->line(' 3. Menghitung ulang skor jawaban (rumus rubrik & syarat kelengkapan bukti)');
        $this->line(' 4. Menyimpan skor sistem yang valid ke tabel respon_penugasans');
        $this->line(' 5. Menghitung rekap bobot per kategori (A: 20%, B: 30%, C: 50%)');
        $this->line(' 6. Memperbarui tabel penugasans (total_skor_sistem & skor_rekap_json)');
        $this->info('====================================================================');

        if ($dryRun) {
            $this->warn(' [MODE DRY-RUN] Simulasi aktif: Database TIDAK akan diubah.');
        }

        if ($penugasanId) {
            $this->line(" Target: Penugasan ID #{$penugasanId}");
        } else {
            $this->line(' Target: SEMUA Penugasan di database');
        }

        if (!$dryRun && !$force) {
            if (!$this->confirm('Apakah Anda yakin ingin melanjutkan proses restart skor ini?', false)) {
                $this->warn('Proses dibatalkan oleh pengguna.');
                return 0;
            }
        }

        $this->newLine();
        $this->info('Sedang memproses kalkulasi...');

        $startTime = microtime(true);
        $results = $this->penugasanService->restartAndRecalculateScores($penugasanId, $dryRun);
        $duration = round(microtime(true) - $startTime, 2);

        if (empty($results)) {
            $this->warn('Tidak ada data penugasan yang ditemukan untuk diproses.');
            return 0;
        }

        $tableRows = [];
        foreach ($results as $res) {
            $katInfo = [];
            foreach ($res['kategori'] as $prefix => $k) {
                $katInfo[] = "{$prefix}: {$k['capaian_skor']}/{$k['bobot']}";
            }

            $revInfo = "-";
            if ($res['nilai_reviewer_1'] > 0 || $res['nilai_reviewer_2'] > 0 || $res['nilai_reviewer_3'] > 0) {
                $revInfo = "R1: {$res['nilai_reviewer_1']} | R2: {$res['nilai_reviewer_2']}";
                if ($res['nilai_reviewer_3'] > 0) {
                    $revInfo .= " | R3: {$res['nilai_reviewer_3']}";
                }
            }

            $tableRows[] = [
                $res['penugasan_id'],
                mb_strimwidth($res['institusi'], 0, 25, '...'),
                $res['total_jawaban'],
                $res['jawaban_diubah'],
                implode("\n", $katInfo),
                $res['total_skor_sistem'],
                $revInfo,
                $res['total_skor_akhir'],
            ];
        }

        $this->table(
            ['ID', 'Institusi', 'Total Soal', 'Soal Disinkron', 'Capaian Kategori', 'Skor Sistem', 'Nilai Reviewer', 'Skor Akhir'],
            $tableRows
        );

        $this->newLine();
        if ($dryRun) {
            $this->warn("Simulasi selesai dalam {$duration} detik. Jalankan tanpa --dry-run untuk menyimpan perubahan.");
        } else {
            $this->info("Berhasil! {$duration} detik. Seluruh skor di respon_penugasans dan penugasans telah disinkronkan.");
        }

        return 0;
    }
}
