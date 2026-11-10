
<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = 'Data Peminjam';

// PROSES HAPUS DATA PEMINJAM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();

    $borrowerId = (int)($_POST['borrower_id'] ?? 0);

    if ($borrowerId <= 0) {
        flash('Data peminjam tidak valid.', 'error');
        redirect('borrowers.php');
    }

    // Periksa apakah peminjam memiliki riwayat pinjaman
    $check = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE borrower_id = ?');
    $check->execute([$borrowerId]);
    $loanCount = (int)$check->fetchColumn();

    if ($loanCount > 0) {
        flash(
            'Data peminjam tidak bisa dihapus karena masih memiliki riwayat pinjaman. Hapus data transaksi hanya jika memang diperlukan dan sesuai kebijakan.',
            'error'
        );
        redirect('borrowers.php');
    }

    // Hapus peminjam jika belum memiliki pinjaman
    $delete = $pdo->prepare('DELETE FROM borrowers WHERE id = ?');
    $delete->execute([$borrowerId]);

    if ($delete->rowCount() > 0) {
        flash('Data peminjam berhasil dihapus.');
    } else {
        flash('Data peminjam tidak ditemukan.', 'error');
    }

    redirect('borrowers.php');
}

// PROSES TAMBAH PEMINJAM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($code === '' || $name === '') {
        flash('Kode dan nama peminjam wajib diisi.', 'error');
        redirect('borrowers.php');
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM borrowers WHERE code = ?');
    $check->execute([$code]);

    if ($check->fetchColumn() > 0) {
        flash('Kode peminjam sudah digunakan. Silakan gunakan kode lain.', 'error');
        redirect('borrowers.php');
    }

    $q = $pdo->prepare(
        'INSERT INTO borrowers(code, name, phone, address) VALUES(?,?,?,?)'
    );

    $q->execute([$code, $name, $phone, $address]);

    flash('Data peminjam berhasil ditambahkan.');
    redirect('borrowers.php');
}

// PENCARIAN DATA
$search = trim($_GET['q'] ?? '');

$q = $pdo->prepare(
    'SELECT * FROM borrowers
     WHERE name LIKE ? OR code LIKE ?
     ORDER BY id DESC'
);

$q->execute(["%$search%", "%$search%"]);
$rows = $q->fetchAll();

require 'header.php';
?>

<section class="panel">
    <div class="panelhead">
        <h2>Tambah Peminjam</h2>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="formgrid">
            <div class="field">
                <label>NO Rekening *</label>
                <input
                    name="code"
                    placeholder="Contoh: 01.001"
                    required
                >
            </div>

            <div class="field">
                <label>Nama Lengkap *</label>
                <input
                    name="name"
                    placeholder="Masukkan nama peminjam"
                    required
                >
            </div>

            <div class="field">
                <label>Nomor HP</label>
                <input
                    name="phone"
                    placeholder="Masukkan nomor HP"
                >
            </div>

            <div class="field full">
                <label>Alamat</label>
                <textarea
                    name="address"
                    placeholder="Masukkan alamat peminjam"
                ></textarea>
            </div>
        </div>

        <div class="actions">
            <button class="btn">Simpan Peminjam</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panelhead">
        <h2>Daftar Peminjam</h2>

        <form class="toolbar" method="get">
            <input
                name="q"
                value="<?= e($search) ?>"
                placeholder="Cari nama/kode"
            >
            <button class="btn secondary">Cari</button>
        </form>
    </div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>NO Rekening</th>
                <th>Nama</th>
                <th>Nomor HP</th>
                <th>Alamat</th>
                <th>Terdaftar</th>
                <th>Aksi</th>
            </tr>

            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['code']) ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['phone']) ?></td>
                    <td><?= e($r['address']) ?></td>
                    <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                    <td>
                        <form
                            method="post"
                            onsubmit="return confirm('Yakin ingin menghapus peminjam <?= e($r['name']) ?>?')"
                        >
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= e(csrf_token()) ?>"
                            >
                            <input type="hidden" name="action" value="delete">
                            <input
                                type="hidden"
                                name="borrower_id"
                                value="<?= (int)$r['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn"
                                style="background:#dc2626;color:white;"
                            >
                                Hapus
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6" class="empty">
                        Data tidak ditemukan.
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php require 'footer.php'; ?>
