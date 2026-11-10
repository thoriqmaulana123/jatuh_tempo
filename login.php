<?php
require_once __DIR__.'/functions.php';
if(!empty($_SESSION['user'])) redirect('index.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$q=$pdo->prepare('SELECT * FROM users WHERE username=?');$q->execute([trim($_POST['username']??'')]);$u=$q->fetch();
 if($u && password_verify($_POST['password']??'',$u['password_hash'])){session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role']];redirect('index.php');}
 $error='Username atau password salah.';}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="style.css"><title>Login BPR Marunting</title></head><body class="loginbody"><form class="loginbox" method="post"><div class="brand" style="color:#14243b;padding:0 0 12px">BPR Marunting<span>●</span><small style="color:#758197">PINJAMAN JATUH TEMPO</small></div><h1>Selamat datang</h1><p>Masuk untuk mengelola pinjaman koperasi.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="field"><label>Username</label><input name="username" required autofocus></div><div class="field"><label>Password</label><input type="password" name="password" required></div><button class="btn">Masuk ke aplikasi</button></form></body></html>
