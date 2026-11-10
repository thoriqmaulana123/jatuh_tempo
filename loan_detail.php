
<?php
require_once __DIR__ . '/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare(
    'SELECT l.*, b.name borrower, b.phone, b.address
     FROM loans l
     JOIN borrowers b ON b.id = l.borrower_id
     WHERE l.id = ?'
);
$q->execute([$id]);

$loan = $q->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    http_response_code(404);
    exit('Pinjaman tidak ditemukan.');
}

/* Ringkasan pinjaman */
$paid = loan_paid($pdo, $id);
$left = max(0, (float)$loan['total_due'] - $paid);
$status = loan_status($loan, $paid);

/* Jadwal cicilan */
$installments = get_loan_installments($pdo, $id);

/* Riwayat pembayaran */
$p = $pdo->prepare(
    'SELECT p.*, li.installment_no
     FROM payments p
     LEFT JOIN loan_installments li
        ON li.id = p.installment_id
     WHERE p.loan_id = ?
     ORDER BY p.payment_date DESC, p.id DESC'
);
$p->execute([$id]);
$payments = $p->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Detail Pinjaman';
require 'header.php';
?>

<section class="panel">

    <div class="panelhead">
        <h2>
            <?= e($loan['loan_code']) ?>
            — <?= e($loan['borrower']) ?>
        </h2>

        <span class="badge">
            <?= e($status) ?>
        </span>
    </div>

    <div class="cards">

        <div class="stat">
            <small>Pokok Pinjaman</small>
            <strong><?= rupiah($loan['principal']) ?></strong>
        </div>

        <div class="stat">
            <small>
                Bunga Flat
                (<?= e($loan['interest_percent']) ?>% / bulan)
            </small>
            <strong><?= rupiah($loan['interest_amount']) ?></strong>
        </div>

        <div class="stat">
            <small>Total Tagihan</small>
            <strong><?= rupiah($loan['total_due']) ?></strong>
        </div>

        <div class="stat">
            <small>Total Dibayar</small>
            <strong><?= rupiah($paid) ?></strong>
        </div>

        <div class="stat">
            <small>Sisa Tagihan</small>
            <strong><?= rupiah($left) ?></strong>
        </div>

    </div>

    <div class="formgrid">

        <div>
            <b>Tanggal Pinjaman:</b>
            <?= date('d/m/Y', strtotime($loan['loan_date'])) ?>
        </div>

        <div>
            <b>Jatuh Tempo Terakhir:</b>
            <?= date('d/m/Y', strtotime($loan['due_date'])) ?>
        </div>

        <div>
            <b>Tenor:</b>
            <?= (int)$loan['tenor_months'] ?> Bulan
        </div>

        <div>
            <b>Angsuran per Bulan:</b>
            <?= rupiah($loan['installment_amount']) ?>
        </div>

        <div>
            <b>Nomor HP:</b>
            <?= e($loan['phone']) ?>
        </div>

        <div>
            <b>Alamat:</b>
            <?= e($loan['address']) ?>
        </div>

        <div class="field full">
            <b>Catatan:</b>
            <?= e($loan['notes']) ?>
        </div>

    </div>

</section>


<!-- JADWAL CICILAN -->

<section class="panel">

    <div class="panelhead">
        <h2>Jadwal Pembayaran Cicilan</h2>
    </div>

    <?php if ($installments): ?>

        <div class="tablewrap">

            <table>

                <thead>
                    <tr>
                        <th>Cicilan</th>
                        <th>Jatuh Tempo</th>
                        <th>Pokok</th>
                        <th>Bunga</th>
                        <th>Tagihan</th>
                        <th>Dibayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($installments as $ins): ?>

                        <?php
                        $installmentPaid = (float)$ins['paid_amount'];

                        $installmentTotal = (float)$ins['installment_amount'];

                        $installmentLeft = max(
                            0,
                            $installmentTotal - $installmentPaid
                        );

                        $installmentStatus = installment_status(
                            $ins,
                            $installmentPaid
                        );
                        ?>

                        <tr>

                            <td>
                                <b>
                                    Cicilan
                                    <?= (int)$ins['installment_no'] ?>
                                </b>
                            </td>

                            <td>
                                <?= date(
                                    'd/m/Y',
                                    strtotime($ins['due_date'])
                                ) ?>
                            </td>

                            <td>
                                <?= rupiah($ins['principal_amount']) ?>
                            </td>

                            <td>
                                <?= rupiah($ins['interest_amount']) ?>
                            </td>

                            <td>
                                <b>
                                    <?= rupiah($installmentTotal) ?>
                                </b>
                            </td>

                            <td>
                                <?= rupiah($installmentPaid) ?>
                            </td>

                            <td>
                                <?= rupiah($installmentLeft) ?>
                            </td>

                            <td>
                                <span class="badge">
                                    <?= e($installmentStatus) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($installmentLeft > 0): ?>

                                    <a
                                        class="btn"
                                        href="payment_form.php?loan_id=<?= $id ?>&installment_id=<?= (int)$ins['id'] ?>"
                                    >
                                        Bayar Cicilan
                                    </a>

                                <?php else: ?>

                                    <span>Lunas</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="alert error">
            Jadwal cicilan belum tersedia untuk pinjaman ini.
            Pinjaman lama mungkin belum memiliki jadwal angsuran.
        </div>

    <?php endif; ?>

</section>


<!-- RIWAYAT PEMBAYARAN -->

<section class="panel">

    <div class="panelhead">
        <h2>Riwayat Pembayaran</h2>

        <?php if ($left > 0 && !$installments): ?>

            <a class="btn" href="payment_form.php?loan_id=<?= $id ?>">
                + Catat Pembayaran
            </a>

        <?php endif; ?>
    </div>

    <div class="tablewrap">

        <table>

            <thead>
                <tr>
                    <th>Kode Pembayaran</th>
                    <th>Cicilan</th>
                    <th>Tanggal</th>
                    <th>Jumlah</th>
                    <th>Catatan</th>
                    <th>Bukti</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($payments as $pay): ?>

                    <tr>

                        <td>
                            <?= e($pay['payment_code']) ?>
                        </td>

                        <td>
                            <?php if ($pay['installment_no']): ?>
                                Cicilan <?= (int)$pay['installment_no'] ?>
                            <?php else: ?>
                                Pembayaran umum
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= date(
                                'd/m/Y',
                                strtotime($pay['payment_date'])
                            ) ?>
                        </td>

                        <td>
                            <?= rupiah($pay['amount']) ?>
                        </td>

                        <td>
                            <?= e($pay['notes']) ?>
                        </td>

                        <td>
                            <a
                                href="receipt.php?id=<?= (int)$pay['id'] ?>"
                                target="_blank"
                            >
                                Cetak
                            </a>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (!$payments): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            Belum ada pembayaran.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

    <div class="actions">
        <a class="btn secondary" href="loans.php">
            Kembali
        </a>
    </div>

</section>

<?php require 'footer.php'; ?>
