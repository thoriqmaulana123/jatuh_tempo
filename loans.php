
<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = 'Data Pinjaman';
$filter = $_GET['status'] ?? '';

// Proses hapus pinjaman yang sudah lunas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_loan_id'])) {
    verify_csrf();

    $id = (int) $_POST['delete_loan_id'];

    // Ambil data pinjaman dan total pembayaran
    $check = $pdo->prepare("
        SELECT l.*, COALESCE(p.paid, 0) AS paid
        FROM loans l
        LEFT JOIN (
            SELECT loan_id, SUM(amount) AS paid
            FROM payments
            GROUP BY loan_id
        ) p ON p.loan_id = l.id
        WHERE l.id = ?
    ");

    $check->execute([$id]);
    $loan = $check->fetch();

    if (!$loan) {
        flash('Data pinjaman tidak ditemukan.', 'error');
        redirect('loans.php');
    }

    $status = loan_status($loan, (float) $loan['paid']);

    if ($status !== 'Lunas') {
        flash('Hanya pinjaman yang sudah lunas yang boleh dihapus.', 'error');
        redirect('loans.php');
    }

    try {
        $pdo->beginTransaction();

        // Hapus riwayat pembayaran terlebih dahulu
        $deletePayments = $pdo->prepare(
            'DELETE FROM payments WHERE loan_id = ?'
        );
        $deletePayments->execute([$id]);

        // Hapus data pinjaman
        $deleteLoan = $pdo->prepare(
            'DELETE FROM loans WHERE id = ?'
        );
        $deleteLoan->execute([$id]);

        $pdo->commit();

        flash('Data pinjaman lunas berhasil dihapus.');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('Gagal menghapus data pinjaman.', 'error');
    }

    redirect('loans.php');
}

// Ambil seluruh data pinjaman
$rows = $pdo->query("
    SELECT
        l.*,
        b.name AS borrower,
        COALESCE(p.paid, 0) AS paid
    FROM loans l
    JOIN borrowers b ON b.id = l.borrower_id
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM payments
        GROUP BY loan_id
    ) p ON p.loan_id = l.id
    ORDER BY l.due_date DESC
")->fetchAll();

require 'header.php';
?>

<section class="panel">

    <div class="panelhead">
        <h2>Semua Pinjaman</h2>
        <a class="btn" href="loan_form.php">+ Tambah Pinjaman</a>
    </div>

    <form class="toolbar" method="get">
        <select name="status">
            <option value="">Semua status</option>

            <?php foreach ([
                'Belum jatuh tempo',
                'Jatuh tempo hari ini',
                'Terlambat',
                'Lunas'
            ] as $s): ?>

                <option value="<?= e($s) ?>" <?= $filter === $s ? 'selected' : '' ?>>
                    <?= e($s) ?>
                </option>

            <?php endforeach; ?>
        </select>

        <button class="btn secondary">Terapkan</button>
    </form>

    <div class="tablewrap" style="margin-top:15px">

        <table>
            <tr>
                <th>Kode</th>
                <th>Peminjam</th>
                <th>Tanggal pinjam</th>
                <th>Jatuh tempo</th>
                <th>Pokok</th>
                <th>Bunga</th>
                <th>Sisa tagihan</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>

            <?php
            $shown = 0;

            foreach ($rows as $r):

                $st = loan_status($r, (float) $r['paid']);

                if ($filter !== '' && $filter !== $st) {
                    continue;
                }

                $shown++;
            ?>

                <tr>
                    <td><?= e($r['loan_code']) ?></td>

                    <td><?= e($r['borrower']) ?></td>

                    <td><?= date('d/m/Y', strtotime($r['loan_date'])) ?></td>

                    <td><?= date('d/m/Y', strtotime($r['due_date'])) ?></td>

                    <td><?= rupiah($r['principal']) ?></td>

                    <td>
                        <?= rupiah($r['interest_amount']) ?>
                        (<?= e($r['interest_percent']) ?>%)
                    </td>

                    <td>
                        <?= rupiah(max(
                            0,
                            (float) $r['total_due'] - (float) $r['paid']
                        )) ?>
                    </td>

                    <td>
                        <span class="badge <?= str_replace(' ', '', e($st)) ?>">
                            <?= e($st) ?>
                        </span>
                    </td>

                    <td>
                        <a href="loan_detail.php?id=<?= (int) $r['id'] ?>">
                            Detail
                        </a>

                        <?php if ($st === 'Lunas'): ?>

                            <form method="post"
                                  style="display:inline"
                                  onsubmit="return confirm('Yakin ingin menghapus pinjaman lunas ini beserta seluruh riwayat pembayarannya? Data tidak dapat dikembalikan.');">

                                <input type="hidden"
                                       name="csrf"
                                       value="<?= e(csrf_token()) ?>">

                                <input type="hidden"
                                       name="delete_loan_id"
                                       value="<?= (int) $r['id'] ?>">

                                <button type="submit"
                                        class="btn secondary"
                                        style="padding:6px 10px; margin-left:5px;">
                                    Hapus
                                </button>

                            </form>

                        <?php endif; ?>
                    </td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$shown): ?>
                <tr>
                    <td colspan="9" class="empty">
                        Tidak ada pinjaman untuk filter ini.
                    </td>
                </tr>
            <?php endif; ?>

        </table>

    </div>
</section>

<?php require 'footer.php'; ?>
