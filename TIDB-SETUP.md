# PULSE CHO: TiDB dan Render

Frontend statis tetap di `https://pulse-cho.onrender.com`; API PHP TiDB dijalankan sebagai layanan Render terpisah. Password akun di-hash dengan PHP, sesi disimpan di TiDB, dan endpoint dataset mewajibkan sesi valid. Hanya Admin yang dapat mengunggah atau menghapus dataset.

## 1. Siapkan skema TiDB

Jalankan [`tidb-setup.sql`](tidb-setup.sql) melalui SQL Editor TiDB Cloud. Ini membuat tabel dataset/chunk, akun, dan sesi. Skrip aman dijalankan ulang untuk instalasi yang sudah memiliki tabel dataset.

## 2. Deploy layanan API di Render

Gunakan Blueprint dari repository ini dan pilih `render.yaml`. Blueprint hanya membuat layanan `pulse-cho-api`, sehingga tidak mengganti layanan frontend yang sudah ada. Jika nama layanan API Render berbeda, ubah `PULSE_API_BASE_URL` di bagian konfigurasi awal `index.html` ke URL layanan tersebut.

Isi environment variables yang ditandai rahasia di Render:

- `PULSE_TIDB_HOST`, `PULSE_TIDB_USERNAME`, `PULSE_TIDB_PASSWORD`: kredensial MySQL TiDB Cloud yang baru.
- `PULSE_ADMIN_USERNAME`, `PULSE_ADMIN_PASSWORD`, `PULSE_ADMIN_NAME`, `PULSE_ADMIN_SITE`: akun Admin awal. Password minimal 12 karakter; buat nilai unik dan rahasia.
- Tambahkan CA TiDB sebagai **Secret File** bernama `tidb-ca.pem`. Blueprint menunjuknya melalui `/etc/secrets/tidb-ca.pem`.
- `PULSE_ALLOWED_ORIGIN` boleh berisi beberapa origin dipisah koma, mis. `https://pulse-cho.onrender.com,http://localhost,http://127.0.0.1`. API hanya mengizinkan origin yang terdaftar di sini untuk login/upload.

Admin awal dibuat otomatis ketika tabel user masih kosong. Akun dan password lama yang tersimpan di browser tidak dimigrasikan; setelah login, Admin dapat membuat akun baru di menu pengelolaan user. Password yang diubah tidak pernah ditampilkan kembali.

Jangan deploy `api/tidb-config.php`; file lokal ini diabaikan Git dan tidak ikut di-copy ke image Docker. Rotasi kredensial TiDB yang pernah dibagikan sebelum mengisi environment Render.

## 3. Batas upload dan verifikasi

API mengizinkan request hingga 250 MB, membagi record ke chunk TiDB sekitar 1 MB, dan mengonfigurasi PHP dengan batas memori 1.5 GB. Dataset sebesar itu memerlukan instance Render dengan memori yang cukup (disarankan minimal 2 GB); paket lebih kecil dapat kehabisan memori walaupun batas request mengizinkan upload. Render paket berbayar menimbulkan biaya, jadi pilih kapasitas sesuai ukuran dataset sebelum deploy.

Setelah deploy, `https://pulse-cho-api.onrender.com/health.php` harus mengembalikan `{"ok":true}`. Tanpa sesi login, `dataset.php` seharusnya mengembalikan HTTP 401. Login dari frontend, lalu uji baca dan upload dataset memakai akun Admin. User biasa hanya boleh membaca.

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

2. **Environment API** (`pulse-cho-api` → Environment). Wajib ada:

   | Key | Nilai |
   |---|---|
   | `PULSE_TIDB_HOST` / `PULSE_TIDB_USERNAME` / `PULSE_TIDB_PASSWORD` | kredensial TiDB |
   | `PULSE_TIDB_CA_PATH` | `/etc/secrets/tidb-ca.pem` (Secret File `tidb-ca.pem`) |
   | `PULSE_ALLOWED_ORIGIN` | `https://pulse-cho.onrender.com,http://localhost,http://127.0.0.1` |

3. **Migrasi skema** (sekali saja) lewat Shell layanan API:

   ```sh
   php migrate-schema.php
   ```

   Keluarannya harus `Selesai. Jumlah akun terdaftar: N`. Jika `N = 0`, buat Admin:

   ```sh
   php bootstrap-admin.php --username=19233208 --password="RahasiaPanjang123" \
     --name="Eva Aroma Fitriawan" --site="Site 1 Semarang"
   ```

4. **Verifikasi**:

   - `https://pulse-cho-api.onrender.com/health.php` → `{"ok":true}`
   - `https://pulse-cho-api.onrender.com/dataset.php` tanpa login → HTTP 401
   - Login di `https://pulse-cho.onrender.com` → dashboard terisi
   - Uji cepat otomatis dari terminal:

     ```powershell
     node qa-login.mjs https://pulse-cho.onrender.com
     ```

5. **Kalau frontend tidak memakai repo ini** (layanan `pulse-cho` di-deploy dari sumber lain), salin `index.html` terbaru ke repo frontend tersebut, lalu push. Memeriksa versi yang sedang tayang: halaman production harus memuat blok `TIDB API CONFIG` dengan `provider: 'TiDB'`.

6. **Render free tier** mematikan layanan setelah idle dan cold start bisa 30–60 detik. Muat dataset pertama (± 73 ribu baris) juga butuh puluhan detik karena TiDB melayani chunk berurutan di sisi database. Badge `☁ Memuat data...` dan `Memuat dataset dari TiDB... N / M baris` menunjukkan progresnya.
