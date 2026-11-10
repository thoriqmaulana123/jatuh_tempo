# PINJAMAN JATUH TEMPO

Aplikasi web desktop PHP 8.1+ dan MySQL untuk koperasi/usaha, mendukung banyak komputer melalui satu database bersama.

## Fitur
- Login admin/petugas dan logout
- Dashboard ringkasan pinjaman, pembayaran, sisa tagihan, dan jatuh tempo
- Data peminjam
- Pencatatan pinjaman dengan bunga flat satu kali
- Pencatatan pembayaran sebagian atau pelunasan
- Status otomatis: Belum jatuh tempo, Jatuh tempo hari ini, Terlambat, Lunas
- Riwayat pembayaran per pinjaman
- Laporan pinjaman dan filter status

## Cara instalasi lokal (uji coba)
1. Install XAMPP (Apache + MySQL) atau PHP dan MySQL.
2. Salin folder `pinjaman_jatuh_tempo` ke `htdocs` jika memakai XAMPP.
3. Import `database.sql` melalui phpMyAdmin.
4. Salin `config.example.php` menjadi `config.php`, lalu isi koneksi database.
5. Buka `http://localhost/pinjaman_jatuh_tempo/setup.php` untuk membuat akun admin pertama.
6. Setelah akun berhasil dibuat, hapus `setup.php` dari server.
7. Login melalui `http://localhost/pinjaman_jatuh_tempo/`.

Akun admin dibuat sendiri saat setup; tidak ada password bawaan.

## Menggunakan beberapa komputer
Semua komputer harus mengakses aplikasi pada satu server yang sama, bukan memasang database terpisah.
- **LAN kantor:** pasang aplikasi + MySQL di satu komputer server yang selalu menyala. Komputer lain membuka `http://IP-SERVER/pinjaman_jatuh_tempo/`.
- **Internet:** unggah aplikasi ke hosting yang mendukung PHP 8.1+ dan MySQL, aktifkan HTTPS, lalu semua komputer membuka domain yang sama.
- Jangan membuka port MySQL ke internet. Biarkan aplikasi PHP menjadi satu-satunya akses database.
- Atur firewall server agar port web (80/443) dapat diakses komputer yang diizinkan.
- Lakukan backup database rutin.

## Rumus bunga
Versi awal memakai bunga flat satu kali per pinjaman:
`bunga = pokok × persentase_bunga / 100`
`total_tagihan = pokok + bunga`
Pembayaran mengurangi total tagihan. Pembayaran lebih besar dari sisa tagihan ditolak.

## Catatan
Ini adalah versi awal operasional. Sebelum digunakan untuk transaksi riil, uji perhitungan dan alur kerja, buat backup, serta sesuaikan kebijakan bunga/denda, kuitansi, dan hak akses dengan aturan koperasi/usaha.
