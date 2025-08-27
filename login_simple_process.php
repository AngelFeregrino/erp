<?php
session_start();
$_SESSION['usuario_id'] = 0; // Si tienes id real, ponlo aquí
$_SESSION['rol'] = "operador";
$_SESSION['user'] = "Operador";
header("Location: panel_operador.php");
exit();
?>