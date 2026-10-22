# Salsa Apparel

## Docker Compose

Butuh Docker Engine / Docker Desktop dengan Compose v2. Jalankan dari folder
proyek. Buat konfigurasi Docker terpisah dari `.env` lokal:

```powershell
Copy-Item docker/.env.example docker/.env
```

Linux / VPS: `cp docker/.env.example docker/.env`.
Ganti `DB_PASSWORD` pada `docker/.env`, lalu jalankan:

```powershell
docker compose --env-file docker/.env up -d --build
```

Buka http://localhost:8080. Compose menjalankan PostgreSQL, menunggu database
siap, menjalankan CLI migrasi, lalu menyalakan PHP-FPM dan Nginx. Seed contoh
tidak dijalankan otomatis. Container menggunakan database baru di volume Docker;
data PostgreSQL lokal tidak otomatis dipindahkan. File `.env` dan upload lokal
tidak disalin ke image.

Perintah operasional:

```powershell
docker compose --env-file docker/.env logs --tail=100 migrate app web
docker compose --env-file docker/.env run --rm migrate php scripts/migrate.php --status
docker compose --env-file docker/.env run --rm migrate
docker compose --env-file docker/.env down
```

Database, upload, log, dan session disimpan dalam named volume. `down` menyimpan
volume; `down -v` menghapus data tersebut. Jika kode berubah, jalankan kembali
`up -d --build`. Gunakan selalu `--env-file docker/.env` agar konfigurasi
database Compose sama dengan konfigurasi aplikasi. Setelah volume database
dibuat, mengubah password di file env saja tidak mengubah password PostgreSQL;
ubah juga kredensial di PostgreSQL jika diperlukan.

Opsional, isi data contoh termasuk role admin (perintah PowerShell berikut memakai
nama database dan user bawaan contoh env; sesuaikan jika diubah):

```powershell
Get-Content database/seed.sql -Raw | docker compose --env-file docker/.env exec -T db psql -U salsa_app -d salsa_apparel -v ON_ERROR_STOP=1
docker compose --env-file docker/.env exec --user www-data app php scripts/create-admin.php --email=admin@example.com --name="Admin Salsa"
```

Di Linux, ganti `Get-Content database/seed.sql -Raw` dengan `cat database/seed.sql`.
Jalankan seed contoh hanya jika memang menginginkan data demo pada database tersebut.

Untuk VPS, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` ke URL publik,
dan isi konfigurasi SMTP serta Midtrans sesuai lingkungan. Default port web hanya
terikat ke `127.0.0.1`, cocok di belakang reverse proxy HTTPS pada host. Untuk
akses HTTP langsung, set `HTTP_BIND=0.0.0.0` dan `HTTP_PORT=80`; HTTPS perlu
dikonfigurasi terpisah. PostgreSQL dan PHP-FPM tidak mengekspos port ke host.

## Migrasi database

Siapkan database PostgreSQL dan isi konfigurasi `DB_*` pada `.env`.
PHP CLI harus memiliki ekstensi `pdo_pgsql`.

```powershell
php scripts/migrate.php --status
php scripts/migrate.php
```

Perintah migrate membuat semua tabel dari `database/schema.sql` jika database
kosong, lalu menjalankan seluruh file `database/migrations/*.sql` sesuai urutan
nama. Database harus sudah dibuat; seed data tidak dijalankan otomatis.

Pada database lama yang sudah memiliki seluruh tabel awal, schema awal dicatat
sebagai baseline tanpa membuat ulang tabel. Deteksi ini memeriksa keberadaan
tabel, bukan kesamaan seluruh kolom; periksa schema database lama terlebih dulu.
Jika hanya sebagian tabel awal tersedia, proses berhenti untuk diperiksa.
Backup database yang sudah berisi data sebelum migrasi.

Tabel `schema_migrations` mencatat migrasi yang berhasil agar eksekusi berikutnya
melewatinya. SQL dan catatan masing-masing migrasi disimpan dalam satu transaksi;
jika gagal, migrasi tersebut dibatalkan dan proses berhenti. Migrasi sebelumnya
tetap tersimpan. File yang sudah diterapkan tidak boleh diedit; buat file migrasi
baru dengan awalan nomor berikutnya dan satu wrapper `BEGIN;` / `COMMIT;`.
`--status` hanya membaca status; `CHANGED` menunjukkan checksum file berubah.
