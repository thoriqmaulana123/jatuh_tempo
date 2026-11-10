
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';

        if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
            http_response_code(419);
            exit('Permintaan tidak valid. Muat ulang halaman.');
        }
    }
}

function require_login(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function current_user_id(): ?int {
    return isset($_SESSION['user']['id'])
        ? (int)$_SESSION['user']['id']
        : null;
}

/* Total pembayaran seluruh pinjaman */
function loan_paid(PDO $pdo, int $loanId): float {
    $q = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE loan_id = ?'
    );

    $q->execute([$loanId]);

    return (float)$q->fetchColumn();
}

/* Status keseluruhan pinjaman */
function loan_status(array $loan, float $paid): string {
    if ($paid >= (float)$loan['total_due']) {
        return 'Lunas';
    }

    $today = date('Y-m-d');

    if ($loan['due_date'] < $today) {
        return 'Terlambat';
    }

    if ($loan['due_date'] === $today) {
        return 'Jatuh tempo hari ini';
    }

    return 'Belum jatuh tempo';
}

/* Total pembayaran untuk satu angsuran */
function installment_paid(PDO $pdo, int $installmentId): float {
    $q = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE installment_id = ?'
    );

    $q->execute([$installmentId]);

    return (float)$q->fetchColumn();
}

/* Status satu angsuran */
function installment_status(
    array $installment,
    float $paid
): string {
    $total = (float)$installment['installment_amount'];

    if ($paid >= $total) {
        return 'Lunas';
    }

    if ($paid > 0) {
        return 'Dibayar Sebagian';
    }

    if ($installment['due_date'] < date('Y-m-d')) {
        return 'Terlambat';
    }

    if ($installment['due_date'] === date('Y-m-d')) {
        return 'Jatuh Tempo Hari Ini';
    }

    return 'Belum Bayar';
}

/* Menghitung tanggal angsuran bulanan tanpa loncatan bulan */
function installment_due_date(
    string $firstDate,
    int $monthOffset
): string {
    $date = new DateTime($firstDate);

    $originalDay = (int)$date->format('d');

    $date->modify('first day of this month');
    $date->modify('+' . $monthOffset . ' months');

    $lastDay = (int)$date->format('t');

    $day = min($originalDay, $lastDay);

    $date->setDate(
        (int)$date->format('Y'),
        (int)$date->format('m'),
        $day
    );

    return $date->format('Y-m-d');
}

/*
 * Membuat jadwal angsuran pinjaman.
 * Panggil fungsi ini setelah data pinjaman berhasil disimpan.
 */
function create_installment_schedule(
    PDO $pdo,
    int $loanId,
    float $principal,
    float $interestAmount,
    int $tenorMonths,
    string $firstInstallmentDate
): void {
    if ($tenorMonths < 1) {
        throw new InvalidArgumentException(
            'Tenor minimal 1 bulan.'
        );
    }

    if ($principal <= 0 || $interestAmount < 0) {
        throw new InvalidArgumentException(
            'Nominal pinjaman atau bunga tidak valid.'
        );
    }

    $totalDue = round($principal + $interestAmount, 2);

    $principalPerMonth = round(
        $principal / $tenorMonths,
        2
    );

    $interestPerMonth = round(
        $interestAmount / $tenorMonths,
        2
    );

    $insert = $pdo->prepare(
        'INSERT INTO loan_installments
        (
            loan_id,
            installment_no,
            due_date,
            principal_amount,
            interest_amount,
            installment_amount
        )
        VALUES (?, ?, ?, ?, ?, ?)'
    );

    $remainingPrincipal = round($principal, 2);
    $remainingInterest = round($interestAmount, 2);

    for ($i = 1; $i <= $tenorMonths; $i++) {
        if ($i === $tenorMonths) {
            // Angsuran terakhir menyesuaikan sisa pembulatan.
            $principalPart = $remainingPrincipal;
            $interestPart = $remainingInterest;
        } else {
            $principalPart = min(
                $principalPerMonth,
                $remainingPrincipal
            );

            $interestPart = min(
                $interestPerMonth,
                $remainingInterest
            );
        }

        $principalPart = round($principalPart, 2);
        $interestPart = round($interestPart, 2);

        $installmentAmount = round(
            $principalPart + $interestPart,
            2
        );

        $dueDate = installment_due_date(
            $firstInstallmentDate,
            $i - 1
        );

        $insert->execute([
            $loanId,
            $i,
            $dueDate,
            $principalPart,
            $interestPart,
            $installmentAmount
        ]);

        $remainingPrincipal = round(
            $remainingPrincipal - $principalPart,
            2
        );

        $remainingInterest = round(
            $remainingInterest - $interestPart,
            2
        );
    }
}

/* Mengambil jadwal angsuran beserta total pembayaran */
function get_loan_installments(
    PDO $pdo,
    int $loanId
): array {
    $q = $pdo->prepare(
        'SELECT
            li.*,
            COALESCE(
                (
                    SELECT SUM(p.amount)
                    FROM payments p
                    WHERE p.installment_id = li.id
                ),
                0
            ) AS paid_amount
         FROM loan_installments li
         WHERE li.loan_id = ?
         ORDER BY li.installment_no ASC'
    );

    $q->execute([$loanId]);

    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function make_code(string $prefix): string {
    return $prefix
        . date('ymd')
        . strtoupper(bin2hex(random_bytes(3)));
}

function flash(
    string $message,
    string $type = 'success'
): void {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
