<?php
require_once __DIR__.'/functions.php';
require_login();
$cfg = require __DIR__.'/config.php';
$pageTitle = $pageTitle ?? 'Dashboard';
$flashData = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($pageTitle)?> - <?=e($cfg['app_name'])?></title><link rel="stylesheet" href="style.css"></head><body>
<aside class="sidebar"><div class="brand">BPR Marunting<span>●</span><small>PINJAMAN JATUH TEMPO</small></div>
<nav><a href="index.php">▦ Dashboard</a><a href="borrowers.php">♙ Data Peminjam</a><a href="loans.php">▤ Data Pinjaman</a><a href="payments.php">↗ Pembayaran</a><a href="reports.php">▥ Laporan</a></nav>
<div class="userbox"><b><?=e($_SESSION['user']['name'])?></b><small><?=e($_SESSION['user']['role'])?></small><a href="logout.php">Keluar ↗</a></div></aside>
<main class="main"><header class="topbar"><div><span class="eyebrow">MANAJEMEN KOPERASI / USAHA</span><h1><?=e($pageTitle)?></h1></div><div class="today"><?=date('d/m/Y')?></div></header>
<?php if($flashData): ?><div class="alert <?=e($flashData['type'])?>"><?=e($flashData['message'])?></div><?php endif; ?>
