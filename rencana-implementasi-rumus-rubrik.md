# Rencana Implementasi — Penyesuaian Rumus Rubrik

Acuan: `revisi-rumustable-rubrik.md` (6 indikator: B.6, B.7, B.18, B.20, C.6, C.10)

---

## 1. Di mana rumus sebenarnya hidup

Rumus berjalan di **3 tahap**, bukan di `app/Traits/CalculatesRubrikScore.php`:

| Tahap | Lokasi | Peran |
|---|---|---|
| 1. FE | `resources/views/dashboard/rubrik.blade.php:421` `computeFormula()` | `persen = (input / pembagi) * 100` untuk B.6, B.7, B.18, B.20, C.5, C.7, C.9 |
| 1b. FE | `rubrik.blade.php:479` `computeAutomatic()` | C.6 auto-isi dari `profil.jml_ukm` (jumlah, bukan %) |
| 2. BE | `app/Services/PenugasanService.php:321` `jawabanAutoSync()` | ambil `calculated_percentage ?? raw_input` → `findMatchingOpsiByValue()` |
| 3. BE | `app/Repositories/PertanyaanRepository.php:43` `findMatchingOpsiByValue()` | **inti rumus**: angka → baris opsi → `skor_sistem = opsi_jawaban` |

Pembagi per indikator (`computeFormula`, `rubrik.blade.php:425-433`):

```
B.6  ÷ jml_dosen          B.20 ÷ jml_agama_aktif
B.7  ÷ jml_prodi          C.5 / C.7 / C.9 ÷ jml_mhs
B.18 ÷ (jml_ormawa + jml_ukm)
```

**Rumus inti** (`PertanyaanRepository.php:43-67`):

```
$input = (int) $inputValue
jika $input <= 0  → opsi dengan value NULL  → skor 0
selain itu        → opsi dengan value TERKECIL yang >= $input
jika tidak ada    → opsi dengan value TERBESAR (fallback)
skor = kolom opsi_jawaban (urutan 0-5)
```

→ Artinya kolom **`opsi_jawaban.value` = batas ATAS bucket**, dan kolom itu satu-satunya sumber kebenaran ambang skor. Tidak ada rumus di kode.

---

## 2. Hasil audit: md vs implementasi

| Kode | Bucket md | `value` tersimpan (urutan 1-5) | Hasil lookup vs md |
|---|---|---|---|
| **B.6** | ≤25 / ≤50 / ≤75 / ≤100 / >100 | 25, 50, 75, 100, 101 | ✅ **SESUAI** |
| **B.7** | ≤1 / ≤2 / ≤3 / ≤4 / >4 | 1, 2, 3, 4, 5 | ✅ **SESUAI** |
| **B.18** | <25 / <50 / <75 / <100 / 100 | 1, 26, 51, 76, 100 | ❌ **TIDAK SESUAI** |
| **B.20** | <25 / <50 / <75 / <100 / 100 | 1, 26, 51, 76, 100 | ❌ **TIDAK SESUAI** |
| **C.6** | 1-5 / 6-10 / 11-15 / 16-20 / >20 | 1, 6, 11, 16, 21 | ❌ **TIDAK SESUAI** |
| **C.10** | 1-20 / 21-60 / 61-120 / 121-200 / >200 | 1, 21, 61, 121, 201 | ❌ **TIDAK SESUAI** |

Label opsi (`keterangan`) di `PertanyaanSeeder.php` **sudah cocok** dengan md. Yang salah hanya kolom `value`.

### Akar masalah

`value` disimpan sebagai **batas BAWAH** pada 4 indikator, sedangkan lookup membaca **batas ATAS**.

Contoh nyata:

| Input | Kode | Skor sekarang | Skor md |
|---|---|---|---|
| 10% | B.18 | 2 | **1** |
| 99% | B.18 | 5 | **4** |
| 3 UKM | C.6 | 2 | **1** |
| 8 UKM | C.6 | 3 | **2** |
| 20 poin | C.10 | 2 | **1** |
| 40 poin | C.10 | 3 | **2** |

B.18 & B.20 paling parah: hanya input tepat `1` yang memberi skor 1; input 2-26 langsung lompat ke skor 2.

---

## 3. Fase 1 — Perbaikan data (WAJIB)

### 3.1 Ubah nilai di `database/seeders/PertanyaanSeeder.php`

| Kode | Baris | `value` lama | `value` baru |
|---|---|---|---|
| B.18 | `:394-398` | `1, 26, 51, 76, 100` | `24, 49, 74, 99, 100` |
| B.20 | `:426-430` | `1, 26, 51, 76, 100` | `24, 49, 74, 99, 100` |
| C.6 | `:514-518` | `1, 6, 11, 16, 21` | `5, 10, 15, 20, 21` |
| C.10 | `:578-582` | `1, 21, 61, 121, 201` | `20, 60, 120, 200, 201` |

B.6 & B.7 **tidak diubah** (sudah benar).

### 3.2 Migration data untuk DB yang sudah jalan

`PertanyaanSeeder::run()` melakukan `truncate()` — kalau dijalankan ulang di DB berisi, seluruh `respon_penugasans` ikut terhapus (FK `onDelete cascade`). Jadi jangan re-seed.

Buat **satu** migration baru, `database/migrations/2026_09_16_000001_fix_opsi_jawaban_threshold_rubrik.php`:

```
up():   untuk tiap (kode_pertanyaan, urutan) → UPDATE opsi_jawaban.value
        di-join lewat pertanyaans.kode_pertanyaan
down(): kembalikan nilai lama
```

Sifat: idempotent, tanpa truncate, aman dijalankan di produksi. Pada instalasi baru, migration ini tidak menemukan baris (seeder belum jalan) → no-op.

### 3.3 Tidak ada perubahan kode

`findMatchingOpsiByValue()` sudah benar untuk semantik batas-atas. Fase 1 murni perbaikan data.

---

## 4. Fase 2 — Hapus sumber kebenaran kedua (OPSIONAL)

Dead code yang menyimpan ambang skor **berbeda** dari md. Kalau nanti seseorang "memperbaiki" guard-nya, ambang salah ini akan aktif:

1. **`app/Traits/CalculatesRubrikScore.php`** — seluruh file.
   Mapping hardcoded-nya salah: id `13,14` → `kuantitas_skala` (aslinya B.8 & B.9), id `20` → `persentase_kelembagaan` (aslinya B.15), id `7` → `persentase_patriotisme` (aslinya B.2), id `2` → `benchmark` (aslinya A.2; yang benar C.2).
   Guard `calculateCategorySubtotal()` membandingkan `$categoryType === 'kelembagaan'`, padahal yang dikirim adalah `"B. VARIABEL PATRIOTISME KELEMBAGAAN"` → **tidak pernah cocok**, semua indikator jatuh ke `$type = 'statis'`.

2. **Pemakainya — keduanya tanpa pemanggil** (sudah dicek di `app/`, `routes/`, `resources/`):
   - `app/Services/ReviewService.php:59` `calculateVerifiedFinalScore()`
   - `app/Services/SubmissionService.php:61` `calculateLivePreview()`

3. **`config/rubrik.php:39-62`** `auto_calculation` (semua dikomentari) dan `:90-93` `percentage_thresholds` — tidak pernah dibaca. Hapus supaya tidak terlihat otoritatif.

   **Jangan hapus** `reviewer_dispute_threshold` (`DaftarNilaiResource.php:108`) dan `accordion_default_open` (`rubrik.blade.php:568`, `reviewer/detail.blade.php:150`) — keduanya dipakai.

4. `app/Services/RubrikService.php:34` `getCategoryMetadata()`, `:52` `validateRubrikConsistency()`, dan `app/Repositories/RubrikRepository.php:24` `getCategoryWeight()` — tanpa pemanggil, dan akan error karena tabel `kategoris` **tidak punya kolom `bobot`** (model `Kategori.php:13` malah mengisi `bobot_presentase`, juga tidak ada di migration). Bobot riil datang dari `PenugasanService::getBobotKategori()` yang hardcode A=20 / B=30 / C=50.

---

## 5. Verifikasi

Buat `check_rumus_rubrik.php` di root — mengikuti pola `check_skor_reviewer.php` / `check_skor_admin.php` yang sudah ada. Isi: untuk tiap (kode, input) di bawah, panggil `PertanyaanRepository::findMatchingOpsiByValue()` dan `assert` skornya.

| Kode | Input | Skor harapan |
|---|---|---|
| B.6 | 0 / 25 / 50 / 75 / 100 / 150 | 0 / 1 / 2 / 3 / 4 / 5 |
| B.7 | 0 / 1 / 2 / 3 / 4 / 10 | 0 / 1 / 2 / 3 / 4 / 5 |
| B.18 | 0 / 10 / 30 / 60 / 90 / 100 | 0 / 1 / 2 / 3 / 4 / 5 |
| B.20 | 0 / 10 / 30 / 60 / 90 / 100 | 0 / 1 / 2 / 3 / 4 / 5 |
| C.6 | 0 / 3 / 8 / 13 / 18 / 40 | 0 / 1 / 2 / 3 / 4 / 5 |
| C.10 | 0 / 10 / 40 / 100 / 150 / 300 | 0 / 1 / 2 / 3 / 4 / 5 |

Jalankan: `php check_rumus_rubrik.php`

Uji end-to-end setelahnya: isi rubrik satu peserta → cek `skor_sistem` per indikator di `respon_penugasans` vs tabel di atas.

---

## 6. Catatan di luar scope md

- **Pemotongan desimal**: `findMatchingOpsiByValue()` memakai `(int) $inputValue`, jadi `100.4%` di B.6 → 100 → skor 4 (md: >100 → 5), dan `99.6%` di B.18 → 99 → skor 4 (md: <100 → 4 ✓). Efeknya saling meniadakan antar indikator; memperbaikinya butuh aturan pembulatan per-indikator. **Tidak diubah** kecuali diminta.
- **B.18 pembagi**: FE memakai `jml_ormawa + jml_ukm`, md menulis "terhadap jumlah organisasi mahasiswa". Perlu konfirmasi mana yang dimaksud.
- **Dua sumber bobot**: `getBobotKategori()` hardcode (dipakai) vs kolom `kategoris.bobot` (tidak ada). Perlu disatukan, terpisah dari revisi rumus ini.

---

## Ringkasan perubahan

| Fase | File | Aksi |
|---|---|---|
| 1 | `database/seeders/PertanyaanSeeder.php` | ubah 16 nilai `value` (4 indikator) |
| 1 | `database/migrations/2026_09_16_000001_fix_opsi_jawaban_threshold_rubrik.php` | baru — UPDATE data |
| 2 | `app/Traits/CalculatesRubrikScore.php` | hapus |
| 2 | `app/Services/ReviewService.php`, `app/Services/SubmissionService.php` | hapus 2 metode + `use` trait |
| 2 | `config/rubrik.php` | hapus `auto_calculation` + `percentage_thresholds` |
| 2 | `app/Services/RubrikService.php`, `app/Repositories/RubrikRepository.php` | hapus 3 metode mati |
| uji | `check_rumus_rubrik.php` | baru — assert 36 kasus |
