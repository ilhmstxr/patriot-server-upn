# Panduan Maintenance & Checklist Konfigurasi Server Linux
## Fitur: Antrean Asinkron Penilaian Reviewer (Queue FIFO)

Dokumen ini berisi panduan checklist langkah demi langkah untuk deployment pada sesi maintenance server di malam hari.

---

## 📋 Checklist Ringkas

- [ ] 1. Backup Database Production
- [ ] 2. Pull Kode Terbaru (`git pull`)
- [ ] 3. Cek Tabel Antrean (Cukup pastikan tabel 'jobs' ada, tidak perlu migrate ulang jika sudah ada)
- [ ] 4. Verifikasi Konfigurasi `.env` (`QUEUE_CONNECTION=database`)
- [ ] 5. Pasang & Konfigurasi Supervisor Queue Worker
- [ ] 6. Segarkan Cache Konfigurasi Server (`config:cache`, tidak menghapus cache laptop user)
- [ ] 7. Restart Queue Worker
- [ ] 8. Uji Coba Penyimpanan Nilai & Cek Log
- [ ] 9. SOP Rollback Darurat (jika dibutuhkan)

---

## 🛠️ Langkah-Langkah Eksekusi Detail

### Step 1: Backup Database (Safety First)
Sebelum melakukan pembaruan apa pun, buat backup database:
```bash
mysqldump -u [username_db] -p [nama_database] > backup_sebelum_maintenance_$(date +%F_%H%M).sql
```

---

### Step 2: Masuk ke Direktori Proyek & Pull Kode
```bash
cd /path/ke/proyek/patriot-server-upn
git pull origin main
```

---

### Step 3: Verifikasi Tabel Antrean (Opsional / Hanya Pengecekan)
Cukup pastikan tabel `jobs` sudah ada di database MySQL server:
```sql
SHOW TABLES LIKE 'jobs';
```
- **Jika tabel `jobs` SUDAH ADA**: Anda **TIDAK PERLU** menjalankan migrate sama sekali. Lewati langkah ini (*skip*).
- **Jika tabel `jobs` BELUM ADA**: Baru jalankan:
  ```bash
  php artisan migrate --force
  ```
> **⚠️ PENTING TENTANG DATA PRODUCTION:**
> Perintah `php artisan migrate` biasa **TIDAK AKAN** menghapus atau mereset data Anda yang sudah ada. Laravel hanya akan menjalankan file migrasi yang belum pernah dieksekusi (yang menghapus data adalah `migrate:fresh` atau `migrate:refresh` — dan kedua perintah tersebut **DILARANG** dijalankan di server production).

---

### Step 4: Cek File `.env`
Buka file `.env`:
```bash
nano .env
```
Pastikan baris berikut sudah bernilai `database`:
```dotenv
QUEUE_CONNECTION=database
```
Simpan perubahan (`Ctrl + O`, `Enter`, lalu `Ctrl + X`).

---

### Step 5: Konfigurasi Supervisor (Background Queue Worker)

Agar antrean berjalan otomatis, stabil, dan otomatis menyala kembali jika server reboot/crash:

1. **Install Supervisor** (jika belum terpasang):
   ```bash
   sudo apt-get update
   sudo apt-get install supervisor -y
   ```

2. **Buat file konfigurasi worker**:
   ```bash
   sudo nano /etc/supervisor/conf.d/patriot-worker.conf
   ```

3. **Paste konfigurasi berikut** *(sesuaikan path folder proyek dan user linux Anda jika bukan www-data)*:
   ```ini
   [program:patriot-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /var/www/patriot-server-upn/artisan queue:work --queue=penilaian,default --sleep=1 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   user=www-data
   numprocs=2
   redirect_stderr=true
   stdout_logfile=/var/www/patriot-server-upn/storage/logs/worker.log
   stopwaitsecs=3600
   ```

4. **Pastikan file log worker dapat ditulis oleh user web**:
   ```bash
   sudo touch /var/www/patriot-server-upn/storage/logs/worker.log
   sudo chown -R www-data:www-data /var/www/patriot-server-upn/storage
   sudo chmod -R 775 /var/www/patriot-server-upn/storage
   ```

5. **Muat dan Jalankan Konfigurasi Supervisor**:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start patriot-worker:*
   ```

6. **Cek Status Worker**:
   ```bash
   sudo supervisorctl status
   ```
   *Output yang diharapkan:*
   ```text
   patriot-worker:patriot-worker_00   RUNNING   pid 12345, uptime 0:00:10
   patriot-worker:patriot-worker_01   RUNNING   pid 12346, uptime 0:00:10
   ```

---

### Step 6: Segarkan Cache Konfigurasi & Rute Laravel
> **Catatan Keamanan Cache:**
> Langkah ini **HANYA** menyegarkan file konfigurasi (`.env`) dan rute internal di server. Perintah ini **TIDAK AKAN** menyentuh atau menghapus cache di laptop/browser pengguna (`sessionStorage` / `localStorage`).
```bash
php artisan config:clear
php artisan config:cache
php artisan route:cache
```

---

### Step 7: Restart Queue Worker (Wajib setelah update kode)
```bash
php artisan queue:restart
sudo supervisorctl restart patriot-worker:*
```

---

## 🔍 Verifikasi & Testing Pasca-Maintenance

1. **Pantau Log Worker secara Live**:
   ```bash
   tail -f storage/logs/worker.log
   ```
2. **Cek Log Error Laravel**:
   ```bash
   tail -f storage/logs/laravel.log
   ```
3. **Uji dari Browser Reviewer**:
   - Buka salah satu halaman penilaian reviewer di browser.
   - Ubah salah satu nilai indikator atau catatan.
   - Amati di terminal: Log worker akan mencatat eksekusi `SaveReviewerScoreJob` secara sukses.
   - Cek database: Nilai di tabel `respon_penugasans` dan rekap di `penugasans` terupdate dengan benar.

---

## 🚨 SOP Rollback Darurat (Emergency Rollback)

Jika saat maintenance malam hari terdapat kendala teknis yang mendesak dan Anda ingin mengembalikan sistem ke mode langsung tanpa queue worker:

1. Buka file `.env`:
   ```bash
   nano .env
   ```
2. Ubah `QUEUE_CONNECTION` kembali ke `sync`:
   ```dotenv
   QUEUE_CONNECTION=sync
   ```
3. Segarkan cache konfigurasi:
   ```bash
   php artisan config:clear
   ```
4. Matikan worker supervisor sementara:
   ```bash
   sudo supervisorctl stop patriot-worker:*
   ```

> *Dengan langkah darurat di atas, semua penyimpanan nilai akan langsung diproses seketika (sinkron) seperti sistem sebelumnya tanpa perlu rollback kode git.*
