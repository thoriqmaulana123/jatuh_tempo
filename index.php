
<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = 'Dashboard';

$totalLoans = (float) $pdo->query(
    'SELECT COALESCE(SUM(total_due),0) FROM loans'
)->fetchColumn();

$totalPaid = (float) $pdo->query(
    'SELECT COALESCE(SUM(amount),0) FROM payments'
)->fetchColumn();

$remaining = max(0, $totalLoans - $totalPaid);

$borrowers = (int) $pdo->query(
    'SELECT COUNT(*) FROM borrowers'
)->fetchColumn();

$allLoans = $pdo->query("
    SELECT l.*, COALESCE(p.paid,0) AS paid
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM payments
        GROUP BY loan_id
    ) p ON p.loan_id = l.id
")->fetchAll();

$rows = $pdo->query("
    SELECT
        l.*,
        b.name AS borrower,
        COALESCE(p.paid,0) AS paid
    FROM loans l
    JOIN borrowers b ON b.id = l.borrower_id
    LEFT JOIN (
        SELECT loan_id, SUM(amount) AS paid
        FROM payments
        GROUP BY loan_id
    ) p ON p.loan_id = l.id
    ORDER BY l.due_date ASC
    LIMIT 12
")->fetchAll();

require 'header.php';
?>

<div class="cards">
    <div class="stat">
        <small>Total nilai tagihan</small>
        <strong><?= rupiah($totalLoans) ?></strong>
        <span class="hint">Akumulasi pokok + bunga</span>
    </div>

    <div class="stat">
        <small>Total sudah dibayar</small>
        <strong><?= rupiah($totalPaid) ?></strong>
        <span class="hint">Seluruh transaksi pembayaran</span>
    </div>

    <div class="stat">
        <small>Sisa tagihan</small>
        <strong><?= rupiah($remaining) ?></strong>
        <span class="hint">Belum termasuk denda</span>
    </div>

    <div class="stat">
        <small>Jumlah peminjam</small>
        <strong><?= $borrowers ?></strong>
        <span class="hint">Data peminjam terdaftar</span>
    </div>
</div>

<div class="cards">
    <?php
    $counts = [
        'Terlambat' => 0,
        'Jatuh tempo hari ini' => 0,
        'Belum jatuh tempo' => 0,
        'Lunas' => 0
    ];

    foreach ($allLoans as $r) {
        $status = loan_status($r, (float) $r['paid']);
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    foreach ($counts as $label => $num):
    ?>
        <div class="stat">
            <small><?= e($label) ?></small>
            <strong><?= $num ?></strong>
            <span class="hint">Dari seluruh pinjaman</span>
        </div>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panelhead">
        <h2>Daftar jatuh tempo terdekat</h2>
        <a class="btn" href="loan_form.php">+ Tambah pinjaman</a>
    </div>

    <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Peminjam</th>
                    <th>Jatuh tempo</th>
                    <th>Total tagihan</th>
                    <th>Sisa</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="empty">
                            Belum ada data pinjaman.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r):
                    $left = max(
                        0,
                        (float) $r['total_due'] - (float) $r['paid']
                    );

                    $status = loan_status($r, (float) $r['paid']);
                ?>
                    <tr>
                        <td><?= e($r['loan_code']) ?></td>
                        <td><?= e($r['borrower']) ?></td>
                        <td><?= date('d/m/Y', strtotime($r['due_date'])) ?></td>
                        <td><?= rupiah($r['total_due']) ?></td>
                        <td><?= rupiah($left) ?></td>
                        <td>
                            <span class="badge <?= str_replace(' ', '', e($status)) ?>">
                                <?= e($status) ?>
                            </span>
                        </td>
                        <td>
                            <a href="loan_detail.php?id=<?= $r['id'] ?>">
                                Detail
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'footer.php'; ?>
