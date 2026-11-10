
<?php
require_once __DIR__ . '/functions.php';
require_login();

$loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);

/* Ambil data pinjaman */
$q = $pdo->prepare(
    'SELECT l.*, b.name borrower
     FROM loans l
     JOIN borrowers b ON b.id = l.borrower_id
     WHERE l.id = ?'
);
$q->execute([$loanId]);
$loan = $q->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    exit('Pinjaman tidak ditemukan.');
}

/* Hitung total pembayaran dan sisa pinjaman */
$paid = loan_paid($pdo, $loanId);
$loanLeft = max(0, (float)$loan['total_due'] - $paid);

/* Ambil jadwal cicilan */
$installments = get_loan_installments($pdo, $loanId);

/* Cari cicilan pertama yang belum lunas */
$firstInstallment = null;
$firstInstallmentPaid = 0;
$firstInstallmentLeft = 0;

foreach ($installments as $item) {
    $itemPaid = installment_paid($pdo, (int)$item['id']);
    $itemLeft = max(
        0,
        (float)$item['installment_amount'] - $itemPaid
    );

    if ($itemLeft > 0) {
        $firstInstallment = $item;
        $firstInstallmentPaid = $itemPaid;
        $firstInstallmentLeft = $itemLeft;
        break;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $amountInput = trim($_POST['amount'] ?? '');
    $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);

    $date = $_POST['payment_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if (
        $amount === false ||
        !is_finite((float)$amount) ||
        $amount <= 0
    ) {
        $error = 'Masukkan nominal pembayaran yang benar.';
    } elseif (!$date) {
        $error = 'Tanggal pembayaran harus diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Kunci data pinjaman agar pembayaran bersamaan
             * tidak menggunakan saldo yang sama.
             */
            $q = $pdo->prepare(
                'SELECT id, total_due
                 FROM loans
                 WHERE id = ?
                 FOR UPDATE'
            );
            $q->execute([$loanId]);
            $lockedLoan = $q->fetch(PDO::FETCH_ASSOC);

            if (!$lockedLoan) {
                throw new Exception('Pinjaman tidak ditemukan.');
            }

            /* Hitung ulang sisa pinjaman di dalam transaksi */
            $q = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                 FROM payments
                 WHERE loan_id = ?'
            );
            $q->execute([$loanId]);

            $currentPaid = (float)$q->fetchColumn();

            $currentLoanLeft = max(
                0,
                (float)$lockedLoan['total_due'] - $currentPaid
            );

            $amount = round((float)$amount, 2);

            if ($amount > $currentLoanLeft + 0.001) {
                throw new Exception(
                    'Pembayaran melebihi sisa pinjaman. Maksimal pembayaran adalah '
                    . rupiah($currentLoanLeft) . '.'
                );
            }

            if ($currentLoanLeft <= 0) {
                throw new Exception('Pinjaman sudah lunas.');
            }

            /*
             * Ambil semua cicilan secara berurutan.
             * Pembayaran akan dialokasikan mulai cicilan pertama.
             */
            $q = $pdo->prepare(
                'SELECT *
                 FROM loan_installments
                 WHERE loan_id = ?
                 ORDER BY installment_no ASC
                 FOR UPDATE'
            );
            $q->execute([$loanId]);
            $allInstallments = $q->fetchAll(PDO::FETCH_ASSOC);

            $remainingPayment = $amount;

            if ($allInstallments) {

                foreach ($allInstallments as $item) {

                    if ($remainingPayment <= 0.001) {
                        break;
                    }

                    /* Hitung pembayaran cicilan ini */
                    $q = $pdo->prepare(
                        'SELECT COALESCE(SUM(amount), 0)
                         FROM payments
                         WHERE installment_id = ?'
                    );
                    $q->execute([(int)$item['id']]);

                    $alreadyPaid = (float)$q->fetchColumn();

                    $installmentLeft = max(
                        0,
                        (float)$item['installment_amount'] - $alreadyPaid
                    );

                    if ($installmentLeft <= 0.001) {
                        continue;
                    }

                    /* Tentukan nominal yang masuk ke cicilan ini */
                    $allocated = round(
                        min($remainingPayment, $installmentLeft),
                        2
                    );

                    if ($allocated <= 0) {
                        continue;
                    }

                    /* Simpan alokasi pembayaran */
                    $q = $pdo->prepare(
                        'INSERT INTO payments
                        (
                            loan_id,
                            installment_id,
                            payment_code,
                            amount,
                            payment_date,
                            notes,
                            created_by
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );

                    $q->execute([
                        $loanId,
                        (int)$item['id'],
                        make_code('BYR'),
                        $allocated,
                        $date,
                        $notes,
                        current_user_id()
                    ]);

                    $remainingPayment = round(
                        $remainingPayment - $allocated,
                        2
                    );
                }

                /*
                 * Jika masih ada nominal yang belum dapat dialokasikan,
                 * batalkan transaksi agar data tidak tidak sesuai.
                 */
                if ($remainingPayment > 0.01) {
                    throw new Exception(
                        'Nominal pembayaran melebihi total sisa jadwal cicilan.'
                    );
                }

            } else {

                /*
                 * Jika pinjaman tidak memiliki jadwal cicilan,
                 * simpan sebagai pembayaran umum.
                 */
                $q = $pdo->prepare(
                    'INSERT INTO payments
                    (
                        loan_id,
                        installment_id,
                        payment_code,
                        amount,
                        payment_date,
                        notes,
                        created_by
                    )
                    VALUES (?, NULL, ?, ?, ?, ?, ?)'
                );

                $q->execute([
                    $loanId,
                    make_code('BYR'),
                    $amount,
                    $date,
                    $notes,
                    current_user_id()
                ]);
            }

            $pdo->commit();

            flash(
                'Pembayaran berhasil dicatat dan dialokasikan otomatis ke cicilan.'
            );

            redirect('loan_detail.php?id=' . $loanId);

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());

            $error = $e->getMessage();
        }
    }
}

/* Perbarui informasi tampilan */
$paid = loan_paid($pdo, $loanId);
$loanLeft = max(0, (float)$loan['total_due'] - $paid);

$installments = get_loan_installments($pdo, $loanId);

$firstInstallment = null;
$firstInstallmentPaid = 0;
$firstInstallmentLeft = 0;

foreach ($installments as $item) {
    $itemPaid = installment_paid($pdo, (int)$item['id']);

    $itemLeft = max(
        0,
        (float)$item['installment_amount'] - $itemPaid
    );

    if ($itemLeft > 0) {
        $firstInstallment = $item;
        $firstInstallmentPaid = $itemPaid;
        $firstInstallmentLeft = $itemLeft;
        break;
    }
}

$pageTitle = 'Catat Pembayaran';
require 'header.php';
?>

<section class="panel">

    <div class="panelhead">
        <h2>
            <?= e($loan['loan_code']) ?> —
            <?= e($loan['borrower']) ?>
        </h2>
    </div>

    <div class="cards">

        <div class="stat">
            <small>Total Pinjaman</small>
            <strong><?= rupiah($loan['total_due']) ?></strong>
        </div>

        <div class="stat">
            <small>Total Dibayar</small>
            <strong><?= rupiah($paid) ?></strong>
        </div>

        <div class="stat">
            <small>Sisa Pinjaman</small>
            <strong><?= rupiah($loanLeft) ?></strong>
        </div>

    </div>

    <?php if ($firstInstallment): ?>

        <div class="alert">

            <h3>
                Alokasi dimulai dari Cicilan
                <?= (int)$firstInstallment['installment_no'] ?>
            </h3>

            <p>
                Tanggal jatuh tempo:
                <b>
                    <?= date(
                        'd/m/Y',
                        strtotime($firstInstallment['due_date'])
                    ) ?>
                </b>
            </p>

            <p>
                Total cicilan:
                <b>
                    <?= rupiah($firstInstallment['installment_amount']) ?>
                </b>
            </p>

            <p>
                Sudah dibayar:
                <b><?= rupiah($firstInstallmentPaid) ?></b>
            </p>

            <p>
                Sisa cicilan:
                <b><?= rupiah($firstInstallmentLeft) ?></b>
            </p>

            <small>
                Jika pembayaran melebihi sisa cicilan ini,
                kelebihannya otomatis masuk ke cicilan berikutnya.
            </small>

        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($loanLeft <= 0): ?>

        <div class="alert">
            Seluruh pinjaman sudah lunas.
        </div>

        <a class="btn secondary"
           href="loan_detail.php?id=<?= $loanId ?>">
            Kembali
        </a>

    <?php else: ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="loan_id"
                value="<?= $loanId ?>"
            >

            <div class="formgrid">

                <div class="field full">

                    <label>Jumlah Pembayaran (Rp) *</label>

                    <input
                        type="number"
                        name="amount"
                        min="1"
                        max="<?= e($loanLeft) ?>"
                        step="1"
                        placeholder="Contoh: 500000"
                        value="<?= e($_POST['amount'] ?? '') ?>"
                        required
                    >

                    <small>
                        Masukkan nominal sesuai uang yang diterima.
                        Tidak harus sama dengan jumlah cicilan.
                    </small>

                    <small>
                        Maksimal pembayaran:
                        <b><?= rupiah($loanLeft) ?></b>
                    </small>

                </div>

                <div class="field">

                    <label>Tanggal Pembayaran *</label>

                    <input
                        type="date"
                        name="payment_date"
                        value="<?= e(
                            $_POST['payment_date'] ?? date('Y-m-d')
                        ) ?>"
                        required
                    >

                </div>

                <div class="field full">

                    <label>Catatan Pembayaran</label>

                    <textarea
                        name="notes"
                        rows="3"
                        placeholder="Contoh: Pembayaran cicilan"
                    ><?= e($_POST['notes'] ?? '') ?></textarea>

                </div>

            </div>

            <div class="actions">

                <button class="btn" type="submit">
                    Simpan Pembayaran
                </button>

                <a
                    class="btn secondary"
                    href="loan_detail.php?id=<?= $loanId ?>"
                >
                    Batal
                </a>

            </div>

        </form>

    <?php endif; ?>

</section>

<?php require 'footer.php'; ?>
