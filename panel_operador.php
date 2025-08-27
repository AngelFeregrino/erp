<?php
session_start();
if ($_SESSION['role'] !== 'operador') {
    header("Location: login_simple.php");
    exit();
}
$nombre = $_SESSION['nombre'] ?? $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel Operador</title>
</head>
<body>
  <h1>Hola, <?php echo htmlspecialchars($nombre); ?> 🛠️</h1>
  <p>Acceso limitado como operador.</p>
  <a href="logout.php">Cerrar sesión</a>
</body>
</html>