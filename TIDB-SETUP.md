# PULSE CHO: TiDB dan Render

Halaman aplikasi dan API PHP berjalan pada **satu** layanan Render (Docker) bernama `pulse-cho-v2` di `https://pulse-cho-v2.onrender.com`: `/` melayani UI dan `/api/*.php` melayani API, sehingga tidak ada CORS. Password akun di-hash dengan PHP, sesi disimpan di TiDB, dan endpoint dataset mewajibkan sesi valid. Hanya Admin yang dapat mengunggah atau menghapus dataset.

## 1. Siapkan skema TiDB

Jalankan [`tidb-setup.sql`](tidb-setup.sql) melalui SQL Editor TiDB Cloud. Ini membuat tabel dataset/chunk, akun, dan sesi. Skrip aman dijalankan ulang untuk instalasi yang sudah memiliki tabel dataset.

## 2. Deploy layanan di Render

Buat **Web Service** baru dari repository ini (New + > Web Service), lalu isi: Runtime **Docker**, Dockerfile Path `./Dockerfile`, Health Check Path `/api/health.php`, dan nama layanan `pulse-cho-v2` (nama ini yang dipakai `render.yaml`). Blueprint juga opsional: pilih `render.yaml`, dan Render hanya membuat layanan `pulse-cho-v2` tanpa mengubah layanan lama.

Isi environment variables berikut di tab **Environment**:

- `PULSE_TIDB_HOST`, `PULSE_TIDB_USERNAME`, `PULSE_TIDB_PASSWORD`: kredensial MySQL TiDB Cloud.
- `PULSE_TIDB_PORT` = `4000` dan `PULSE_TIDB_DATABASE` = `pulse_cho`.
- `PULSE_TIDB_CA_PATH` = `/etc/secrets/tidb-ca.pem` bila CA TiDB ditambahkan sebagai **Secret File** bernama `tidb-ca.pem`. Kosongkan bila ingin memakai CA bawaan sistem.
- `PULSE_ADMIN_USERNAME`, `PULSE_ADMIN_PASSWORD`, `PULSE_ADMIN_NAME`, `PULSE_ADMIN_SITE`: akun Admin awal. Password minimal 12 karakter; buat nilai unik dan rahasia.
- `PULSE_ALLOWED_ORIGIN` boleh dikosongkan karena UI dan API satu domain. Isi hanya bila API dipakai dari domain lain, mis. `https://contoh.com,http://localhost`.

Admin awal dibuat otomatis ketika tabel user masih kosong. Akun dan password lama yang tersimpan di browser tidak dimigrasikan; setelah login, Admin dapat membuat akun baru di menu pengelolaan user. Password yang diubah tidak pernah ditampilkan kembali.

Jangan deploy `api/tidb-config.php`; file lokal ini diabaikan Git dan tidak ikut di-copy ke image Docker. Rotasi kredensial TiDB yang pernah dibagikan sebelum mengisi environment Render.

## 3. Batas upload dan verifikasi

API mengizinkan request hingga 250 MB, membagi record ke chunk TiDB sekitar 1 MB, dan mengonfigurasi PHP dengan batas memori 1.5 GB. Dataset sebesar itu memerlukan instance Render dengan memori yang cukup (disarankan minimal 2 GB); paket lebih kecil dapat kehabisan memori walaupun batas request mengizinkan upload. Render paket berbayar menimbulkan biaya, jadi pilih kapasitas sesuai ukuran dataset sebelum deploy.

Setelah deploy, `https://pulse-cho-v2.onrender.com/api/health.php` harus mengembalikan `{"ok":true}`. Tanpa sesi login, `https://pulse-cho-v2.onrender.com/api/dataset.php` seharusnya mengembalikan HTTP 401. Login dari halaman aplikasi, lalu uji baca dan upload dataset memakai akun Admin. User biasa hanya boleh membaca.

Untuk pengembangan lokal, PHP harus memiliki ekstensi `pdo_mysql`. Isi konfigurasi lokal di `api/tidb-config.php` atau gunakan environment variables `PULSE_TIDB_*`; jangan pernah menaruh kredensial di `index.html`, dokumentasi, atau repository.

## 4. Akun Admin awal

Tabel `pulse_users` harus berisi minimal satu Admin. Jika database masih kosong dan environment `PULSE_ADMIN_*` tidak diisi, login akan selalu gagal (tidak ada akun yang bisa dicek).

Untuk membuat / mereset Admin langsung dari terminal:

```powershell
C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe api\bootstrap-admin.php `
  --username=19233208 --password="PasswordPanjang123" `
  --name="Nama Lengkap" --site="Site 1 Semarang" --reset
```

- `--reset` menghapus semua akun & sesi lama, lalu membuat Admin baru.
- `--force` (tanpa `--reset`) mengganti password/role akun dengan username yang sama.
- Password minimal 12 karakter, sama seperti aturan API.

Setelah itu login di aplikasi memakai username dan password tersebut.

## 5. Skema chunk dataset (wajib untuk dataset besar)

Jalankan `tidb-migration-chunks.sql` sekali di TiDB Cloud SQL Editor. Tabel `pulse_dataset_chunk_index` menyimpan daftar chunk sehingga server tidak perlu memindai seluruh dataset saat membuka halaman: browser mengunduh chunk paralel lewat `dataset.php?version=<id>&chunk=<n>`.

Uji cepat setelah migrasi: `dataset.php?metaOnly=1` harus menjawab dalam < 2 detik, dan membuka aplikasi setelah login menampilkan progres "Memuat dataset dari TiDB... N / M baris".

## 6. Deploy ke Render (langkah per langkah)

Semua perubahan harus sampai ke repository Git yang dipakai Render — deploy tidak bisa dilakukan dari folder lokal saja.

1. **Commit & push** folder ini (tanpa `api/tidb-config.php`, sudah di-`.gitignore`):

   ```powershell
   & "C:\Program Files\Git\cmd\git.exe" add .
   & "C:\Program Files\Git\cmd\git.exe" commit -m "PULSE CHO: cloud TiDB, login API, dataset chunk paralel"
   & "C:\Program Files\Git\cmd\git.exe" push
   ```

2. **Environment** layanan `pulse-cho-v2` (tab Environment). Wajib ada:

   | Key | Nilai |
   |---|---|
   | `PULSE_TIDB_HOST` / `PULSE_TIDB_USERNAME` / `PULSE_TIDB_PASSWORD` | kredensial TiDB |
   | `PULSE_TIDB_PORT` / `PULSE_TIDB_DATABASE` | `4000` / `pulse_cho` |
   | `PULSE_TIDB_CA_PATH` | `/etc/secrets/tidb-ca.pem` (Secret File `tidb-ca.pem`) atau kosongkan |
   | `PULSE_ADMIN_USERNAME` / `PULSE_ADMIN_PASSWORD` / `PULSE_ADMIN_NAME` / `PULSE_ADMIN_SITE` | akun Admin awal (password ≥ 12 karakter) |

   Menyimpan variabel akan memicu redeploy otomatis. Admin awal dibuat saat tabel `pulse_users` masih kosong, jadi cukup diisi lewat environment ini.

3. **Migrasi skema** (sekali saja) lewat Shell layanan:

   ```sh
   php api/migrate-schema.php
   ```

   Keluarannya harus `Selesai. Jumlah akun terdaftar: N`. Jika `N = 0` dan environment `PULSE_ADMIN_*` tidak diisi, buat Admin manual:

   ```sh
   php api/bootstrap-admin.php --username=19233208 --password="RahasiaPanjang123" \
     --name="Eva Aroma Fitriawan" --site="Site 1 Semarang"
   ```

4. **Verifikasi**:

   - `https://pulse-cho-v2.onrender.com/api/health.php` → `{"ok":true}`
   - `https://pulse-cho-v2.onrender.com/api/dataset.php` tanpa login → HTTP 401
   - Login di `https://pulse-cho-v2.onrender.com` → dashboard terisi

5. **Render free tier** mematikan layanan setelah idle dan cold start bisa 30–60 detik. Muat dataset pertama (± 73 ribu baris) juga butuh puluhan detik karena TiDB melayani chunk berurutan di sisi database. Badge `☁ Memuat data...` dan `Memuat dataset dari TiDB... N / M baris` menunjukkan progresnya.
