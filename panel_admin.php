<?php
session_start();
if ($_SESSION['role'] !== 'admin') {
    header("Location: login_simple.php");
    exit();
}
$nombre = $_SESSION['nombre'] ?? $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel Administrador</title>
</head>
<body>
  <h1>Bienvenido, <?php echo htmlspecialchars($nombre); ?> 👑</h1>
  <p>Acceso completo como administrador.</p>
  <a href="reportes.php">📊 Reportes</a><br>
  <a href="usuarios.php">👥 Gestión de usuarios</a><br>
  <a href="logout.php">Cerrar sesión</a>
</body>
</html>