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
- Pastikan `PULSE_ALLOWED_ORIGIN` persis `https://pulse-cho.onrender.com`.

Admin awal dibuat otomatis ketika tabel user masih kosong. Akun dan password lama yang tersimpan di browser tidak dimigrasikan; setelah login, Admin dapat membuat akun baru di menu pengelolaan user. Password yang diubah tidak pernah ditampilkan kembali.

Jangan deploy `api/tidb-config.php`; file lokal ini diabaikan Git dan tidak ikut di-copy ke image Docker. Rotasi kredensial TiDB yang pernah dibagikan sebelum mengisi environment Render.

## 3. Batas upload dan verifikasi

API mengizinkan request hingga 250 MB, membagi record ke chunk TiDB sekitar 1 MB, dan mengonfigurasi PHP dengan batas memori 1.5 GB. Dataset sebesar itu memerlukan instance Render dengan memori yang cukup (disarankan minimal 2 GB); paket lebih kecil dapat kehabisan memori walaupun batas request mengizinkan upload. Render paket berbayar menimbulkan biaya, jadi pilih kapasitas sesuai ukuran dataset sebelum deploy.

Setelah deploy, `https://pulse-cho-api.onrender.com/health.php` harus mengembalikan `{"ok":true}`. Tanpa sesi login, `dataset.php` seharusnya mengembalikan HTTP 401. Login dari frontend, lalu uji baca dan upload dataset memakai akun Admin. User biasa hanya boleh membaca.

Untuk pengembangan lokal, PHP harus memiliki ekstensi `pdo_mysql`. Isi konfigurasi lokal di `api/tidb-config.php` atau gunakan environment variables `PULSE_TIDB_*`; jangan pernah menaruh kredensial di `index.html`, dokumentasi, atau repository.
