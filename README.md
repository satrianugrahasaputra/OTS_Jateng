# Neots Project

Repository ini menggabungkan frontend dan backend untuk aplikasi **Neots**.

## Struktur Folder

- `Neots/` - Folder frontend (Aplikasi Android berbasis Kotlin/Gradle).
- `api/` - Folder backend (API Server berbasis PHP dengan Composer).

---

## 🚀 Cara Menjalankan Aplikasi

### 1. Backend (API)
Backend berada di dalam folder `api/`.
1. Masuk ke folder `api/`.
2. Salin file `.env.example` menjadi `.env` dan sesuaikan konfigurasinya (misalnya, database credentials).
3. Jalankan `composer install` jika dependencies belum terinstall.
4. Pastikan server web Anda (seperti XAMPP Apache/MySQL) berjalan dan mengarah ke folder backend ini.

### 2. Frontend (Neots Android App)
Frontend berada di dalam folder `Neots/`.
1. Buka folder `Neots/` menggunakan Android Studio.
2. Pastikan file `local.properties` sudah terkonfigurasi dengan benar (jalur SDK Android).
3. Sesuaikan URL API/Backend pada file konfigurasi/sumber kode Android agar mengarah ke alamat backend Anda (IP lokal komputer Anda atau domain hosting).
4. Lakukan sinkronisasi Gradle (Sync Project with Gradle Files).
5. Jalankan aplikasi pada Emulator atau Perangkat Android fisik.
