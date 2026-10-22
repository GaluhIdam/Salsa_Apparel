# Salsa Apparel

## Docker Compose

Butuh Docker Engine / Docker Desktop dengan Compose v2. Dari folder hasil clone,
cukup jalankan:

```sh
docker compose up -d
```

Buka http://localhost:9123. Image otomatis dibangun dari source (menggunakan
build cache), PostgreSQL disiapkan, migrasi dan seeder dijalankan, lalu PHP-FPM
dan Nginx dinyalakan. Tidak perlu membuat file env atau memakai `--build`.
Startup aplikasi berhenti jika migrasi atau seeder gagal.

### Seeder otomatis

`database/seed.sql` mengisi:

- 3 role: `super_admin`, `admin`, `order_manager`.
- 10 permission dan pembagian akses untuk ketiga role.
- 7 kategori: Accessories, Men, Women, Hoodies, T-Shirts, Shirts, Shoes.
- 2 tarif kirim: Reguler Rp15.000 dan Express Rp30.000.
- 5 produk demo: Essential T-Shirt, Relaxed Fit Shirt, Everyday Hoodie,
  Classic Sneakers, Minimalist Tote.
- 17 varian produk: 12 varian pakaian dengan stok 20 per varian,
  4 varian sepatu dengan stok 15 per varian, dan 1 tote dengan stok 25.
- 4 pengaturan teks situs: announcement, headline, subheadline, dan tombol hero.

Setelah seed, SQL `002_placeholder_images.sql` diterapkan kembali untuk mengisi
placeholder kategori, produk, hero, dan editorial yang masih kosong.
Seeder tidak membuat akun admin/pelanggan. Buat admin sendiri setelah startup:

```sh
docker compose exec --user www-data app php scripts/create-admin.php --email=admin@example.com --name="Admin Salsa"
```

Seeder dicatat pada `schema_seeders` dan dilewati pada startup berikutnya agar
tarif kirim tidak berlipat dan stok yang sudah berubah tidak direset. SQL tarif
kirim juga memeriksa kombinasi kurir dan layanan agar data seed manual yang
sudah ada tidak ditambahkan lagi. Backup database lama sebelum menjalankan seed.

### Konfigurasi opsional

Default memakai database internal Docker dengan password development, email
berupa log, dan kunci Midtrans kosong. Checkout pembayaran memerlukan kunci
Midtrans yang valid. Data PostgreSQL dan upload lokal tidak otomatis dipindahkan.

Untuk mengganti default, salin `docker/.env.example` menjadi `.env` di root hasil
clone, lalu sesuaikan variabel berawalan `SALSA_`. Jika `.env` lokal sudah ada,
tambahkan variabel `SALSA_` yang dibutuhkan ke file tersebut tanpa menimpanya.
Variabel `DB_*` / SMTP lokal tidak memengaruhi konfigurasi Docker. File env tidak
dimasukkan ke image.

Alternatif file terpisah: salin contoh ke `docker/.env`, lalu gunakan
`docker compose --env-file docker/.env up -d`. Semua perintah Compose berikutnya
harus memakai flag env yang sama jika menggunakan alternatif ini.

Untuk VPS, isi `SALSA_DB_PASSWORD` dengan password kuat, set
`SALSA_APP_ENV=production`, `SALSA_APP_DEBUG=false`, dan `SALSA_APP_URL` ke URL
publik. Isi konfigurasi SMTP/Midtrans yang diperlukan. Default web terikat ke
`0.0.0.0:9123`, sehingga bisa diakses melalui `http://IP-SERVER:9123`.
Set `SALSA_APP_URL=http://IP-SERVER:9123` atau domain publik agar tautan aplikasi
memakai alamat yang benar. `0.0.0.0` adalah alamat bind; URL publik harus memakai
IP/domain server. HTTPS perlu dikonfigurasi terpisah. PostgreSQL dan PHP-FPM
tidak mengekspos port ke host.

### Perintah operasional

```sh
docker compose logs --tail=100 migrate app web
docker compose run --rm migrate php scripts/migrate.php --status
docker compose run --rm migrate
docker compose run --rm migrate php scripts/seed.php
docker compose down
```

Database, upload, log, dan session tersimpan dalam named volume. `down` menyimpan
volume; `down -v` menghapusnya. Setelah kode berubah, jalankan kembali
`docker compose up -d` untuk membangun perubahan. Mengubah password env setelah
volume database dibuat tidak mengubah password PostgreSQL; ubah juga kredensial
di PostgreSQL jika diperlukan.

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
