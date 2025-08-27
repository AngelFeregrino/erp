<?php
include 'db.php';

$username = 'admin';
$password = 'admin123';
$role = 'admin';

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO usuarios (username, password, role) VALUES (?, ?, ?)");
$stmt->execute([$username, $hash, $role]);

echo "Administrador creado correctamente.";
?>