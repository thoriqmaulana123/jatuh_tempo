
<?php
require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = 'Tambah Pinjaman';

$borrowers = $pdo->query(
    'SELECT id, code, name FROM borrowers ORDER BY name'
)->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $bid = (int)($_POST['borrower_id'] ?? 0);
    $principal = (float)($_POST['principal'] ?? 0);
    $rate = (float)($_POST['interest_percent'] ?? 0);
    $tenor = (int)($_POST['tenor_months'] ?? 0);

    $loanDate = $_POST['loan_date'] ?? '';
    $firstDate = $_POST['first_installment_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if (
        !$bid ||
        $principal <= 0 ||
        $rate < 0 ||
        $rate > 100 ||
        $tenor < 1 ||
        $tenor > 600 ||
        !$loanDate ||
        !$firstDate ||
        $firstDate < $loanDate
    ) {
        $error = 'Periksa kembali peminjam, nominal, bunga, tenor, dan tanggal.';
    } else {
        try {
            // Bunga flat per bulan.
            $interest = round(
                $principal * $rate / 100 * $tenor,
                2
            );

            $total = round($principal + $interest, 2);

            // Angsuran bulanan dasar.
            $installmentAmount = round(
                $total / $tenor,
                2
            );

            // Jatuh tempo pinjaman mengikuti angsuran terakhir.
            $lastDueDate = installment_due_date(
                $firstDate,
                $tenor - 1
            );

            $pdo->beginTransaction();

            $q = $pdo->prepare(
                'INSERT INTO loans
                (
                    borrower_id,
                    loan_code,
                    principal,
                    interest_percent,
                    interest_amount,
                    total_due,
                    loan_date,
                    due_date,
                    notes,
                    created_by,
                    tenor_months,
                    first_installment_date,
                    installment_amount,
                    interest_period
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $q->execute([
                $bid,
                make_code('PJM'),
                $principal,
                $rate,
                $interest,
                $total,
                $loanDate,
                $lastDueDate,
                $notes,
                current_user_id(),
                $tenor,
                $firstDate,
                $installmentAmount,
                'monthly'
            ]);

            $id = (int)$pdo->lastInsertId();

            // Membuat jadwal angsuran otomatis.
            create_installment_schedule(
                $pdo,
                $id,
                $principal,
                $interest,
                $tenor,
                $firstDate
            );

            $pdo->commit();

            flash('Pinjaman dan jadwal angsuran berhasil dibuat.');
            redirect('loan_detail.php?id=' . $id);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());

            $error = 'Pinjaman gagal disimpan. Periksa konfigurasi database atau hubungi administrator.';
        }
    }
}

require 'header.php';
?>

<section class="panel">

    <div class="panelhead">
        <h2>Informasi Pinjaman</h2>
    </div>

    <?php if (!$borrowers): ?>

        <div class="alert error">
            Tambahkan data peminjam terlebih dahulu.
        </div>

        <a class="btn" href="borrowers.php">Tambah Peminjam</a>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="formgrid">

                <div class="field full">
                    <label>Peminjam *</label>

                    <select name="borrower_id" required>
                        <option value="">Pilih peminjam</option>

                        <?php foreach ($borrowers as $b): ?>
                            <option
                                value="<?= $b['id'] ?>"
                                <?= (($_POST['borrower_id'] ?? '') == $b['id']) ? 'selected' : '' ?>
                            >
                                <?= e($b['code'] . ' — ' . $b['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label>Jumlah Pokok (Rp) *</label>

                    <input
                        type="number"
                        id="principal"
                        name="principal"
                        min="0.01"
                        step="any"
                        placeholder="Contoh: 12000000"
                        value="<?= e($_POST['principal'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Bunga Flat per Bulan (%) *</label>

                    <input
                        type="number"
                        id="interest_percent"
                        name="interest_percent"
                        min="0"
                        max="100"
                        step="0.01"
                        value="<?= e($_POST['interest_percent'] ?? '2') ?>"
                        required
                    >

                    <small>
                        Masukkan persentase bunga setiap bulan.
                    </small>
                </div>

                <div class="field">
                    <label>Tenor (Bulan) *</label>

                    <input
                        type="number"
                        id="tenor_months"
                        name="tenor_months"
                        min="1"
                        max="600"
                        step="1"
                        placeholder="Contoh: 12"
                        value="<?= e($_POST['tenor_months'] ?? '12') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Tanggal Pinjaman *</label>

                    <input
                        type="date"
                        name="loan_date"
                        value="<?= e($_POST['loan_date'] ?? date('Y-m-d')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label>Tanggal Angsuran Pertama *</label>

                    <input
                        type="date"
                        name="first_installment_date"
                        value="<?= e($_POST['first_installment_date'] ?? '') ?>"
                        required
                    >

                    <small>
                        Angsuran berikutnya otomatis setiap bulan.
                    </small>
                </div>

                <div class="field full">
                    <label>Catatan</label>

                    <textarea
                        name="notes"
                        placeholder="Catatan tambahan (opsional)"
                    ><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="panel" style="margin-top:20px;">
                <h3>Perkiraan Perhitungan Pinjaman</h3>

                <p>Total Bunga:
                    <strong id="total_interest">Rp 0</strong>
                </p>

                <p>Total Pinjaman:
                    <strong id="total_loan">Rp 0</strong>
                </p>

                <p>Angsuran per Bulan:
                    <strong id="monthly_installment">Rp 0</strong>
                </p>

                <small>
                    Angsuran terakhir dapat menyesuaikan selisih pembulatan.
                </small>
            </div>

            <div class="actions">
                <button class="btn" type="submit">
                    Simpan Pinjaman
                </button>

                <a class="btn secondary" href="loans.php">
                    Batal
                </a>
            </div>

        </form>

    <?php endif; ?>

</section>

<script>
function formatRupiah(value) {
    return 'Rp ' + Math.round(value).toLocaleString('id-ID');
}

function calculateLoan() {
    const principal = parseFloat(
        document.getElementById('principal')?.value
    ) || 0;

    const rate = parseFloat(
        document.getElementById('interest_percent')?.value
    ) || 0;

    const tenor = parseInt(
        document.getElementById('tenor_months')?.value
    ) || 0;

    const interest = principal * rate / 100 * tenor;
    const total = principal + interest;
    const monthly = tenor > 0 ? total / tenor : 0;

    document.getElementById('total_interest').textContent =
        formatRupiah(interest);

    document.getElementById('total_loan').textContent =
        formatRupiah(total);

    document.getElementById('monthly_installment').textContent =
        formatRupiah(monthly);
}

['principal', 'interest_percent', 'tenor_months'].forEach(id => {
    document.getElementById(id)?.addEventListener(
        'input',
        calculateLoan
    );
});

calculateLoan();
</script>

<?php require 'footer.php'; ?>
