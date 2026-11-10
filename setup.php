<?php
require_once __DIR__.'/functions.php';
$count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if($count>0){exit('Akun sudah tersedia. Demi keamanan, hapus file setup.php dari server. <a href="login.php">Login</a>');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $name=trim($_POST['name']??'');$username=trim($_POST['username']??'');$password=$_POST['password']??'';
 if($name===''||$username===''||strlen($password)<8){$error='Lengkapi nama, username, dan password minimal 8 karakter.';}
 else{$q=$pdo->prepare("INSERT INTO users(name,username,password_hash,role) VALUES(?,?,?,'admin')");
 $q->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT)]);
 echo 'Admin berhasil dibuat. Hapus setup.php dari server, lalu <a href="login.php">login</a>.';exit;}
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><link rel="stylesheet" href="style.css"><title>Setup Admin</title></head><body class="loginbody"><form class="loginbox" method="post"><h1>Buat Admin Pertama</h1><p>Form ini hanya bisa digunakan sebelum ada akun.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="field"><label>Nama</label><input name="name" required></div><div class="field"><label>Username</label><input name="username" required></div><div class="field"><label>Password (minimal 8 karakter)</label><input type="password" name="password" minlength="8" required></div><button class="btn">Buat akun admin</button></form></body></html>
