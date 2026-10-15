# Kasirku - Sistem POS (Modul Barang dan Inventaris) untuk Secure Programming

Stack: PHP 8.1+ (PDO), MySQL 8.0.16+ / MariaDB 10.2+, HTML + CSS (tanpa JavaScript).

## Struktur

```
pos-secure/
├── bin/create_admin.php     # buat akun lewat CLI (tidak ada halaman register)
├── config/config.php        # kredensial DB & pengaturan (di luar webroot)
├── database/schema.sql      # tabel, constraint, indeks, contoh GRANT minimum
├── src/                     # bootstrap (header, sesi, PDO, helper), auth, layout
└── public/                  # DOCUMENT ROOT: login, index, products, product_form, stock, assets/
```

## Cara menjalankan

1. Import skema dan buat user database terbatas (lihat komentar di akhir `schema.sql`):
   `mysql -u root -p < database/schema.sql`
2. Atur kredensial lewat environment variable `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   (atau edit `config/config.php`, lalu `chmod 600`).
3. Buat akun admin: `php bin/create_admin.php admin "Nama Anda"` (password min. 12 karakter, huruf + angka).
4. Jalankan lokal: `php -S 127.0.0.1:8000 -t public` lalu buka http://127.0.0.1:8000/login.php
   (untuk produksi gunakan Apache/Nginx dengan HTTPS dan document root = `public/`).

## Pemetaan fitur keamanan (OWASP Top 10)

| Risiko | Kontrol di kode | Lokasi |
|---|---|---|
| A01 Broken Access Control | RBAC admin/staf dicek di server di tiap halaman dan aksi; harga modal disembunyikan dari staf; user divalidasi ulang ke DB tiap request; redirect hanya ke path tetap | `src/auth.php` (`require_role`, `require_login`), `products.php`, `stock.php` |
| A02 Cryptographic Failures | `password_hash` Argon2id/bcrypt + salt otomatis, `password_needs_rehash`; cookie `Secure` + HSTS saat HTTPS | `src/auth.php`, `src/bootstrap.php` |
| A03 Injection | PDO prepared statement asli (`EMULATE_PREPARES=false`), tidak ada string concat dari input; wildcard LIKE di-escape; output di-encode `e()`; CSP `script-src 'none'` | semua halaman, `bootstrap.php` |
| A04 Insecure Design | Stok hanya berubah lewat transaksi + `SELECT ... FOR UPDATE` (cegah race condition); riwayat stok tak bisa dihapus; soft delete; CHECK `stock >= 0` | `stock.php`, `schema.sql` |
| A05 Security Misconfiguration | `display_errors=0`, error di-log, pesan generik; header keamanan; `.htaccess`; config di luar webroot; user DB tanpa hak DROP/ALTER | `bootstrap.php`, `.htaccess`, `schema.sql` |
| A07 Authentication Failures | Batas percobaan login (per user+IP, per IP, per user); pesan error seragam; anti timing user-enumeration; `session_regenerate_id` saat login; idle dan absolute timeout; logout via POST + CSRF | `src/auth.php`, `login.php`, `logout.php` |
| A08 Data Integrity | Token CSRF per sesi (`hash_equals`), cookie `SameSite=Strict`, semua perubahan data via POST | `bootstrap.php`, semua form |
| A09 Logging Failures | Tabel `audit_logs` (login, logout, CRUD barang, mutasi stok, akses ditolak) + IP | `audit()` |

## Skenario uji untuk laporan

- **SQL injection**: cari `' OR 1=1 --` di kolom pencarian; username `admin' --` di login. Hasil: diperlakukan sebagai teks biasa.
- **XSS**: simpan nama barang `<script>alert(1)</script>`. Hasil: tampil sebagai teks (di-encode), dan CSP memblokir skrip.
- **CSRF**: buat form di situs lain yang POST ke `products.php` atau `stock.php`. Hasil: HTTP 419.
- **Brute force**: 5 kali password salah -> akun+IP terkunci 15 menit.
- **Broken access control**: login sebagai staf, buka `product_form.php` atau POST jenis `adjust`. Hasil: 403.
- **Manipulasi parameter**: ubah `quantity` jadi `-5`, `1e9`, array (`quantity[]=1`), atau `type=hack`. Hasil: ditolak validasi.
- **Race condition**: dua kasir mencatat stok keluar bersamaan; baris dikunci sehingga stok tidak pernah negatif.
- **Session fixation**: ID sesi sebelum dan sesudah login berbeda.

## Batasan (bisa dijadikan bahan diskusi atau pengembangan)

- Modul transaksi penjualan (keranjang, struk, pengurangan stok otomatis) belum ada; pola transaksi di `stock.php` bisa dipakai ulang.
- Belum ada manajemen user lewat UI, 2FA, atau reset password.
- Jalankan di belakang HTTPS; rate limiting tingkat jaringan (WAF/fail2ban) tetap disarankan.
