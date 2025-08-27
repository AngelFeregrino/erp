<?php
session_start();
$_SESSION['role'] = "operador";
$_SESSION['user'] = "Operador";
header("Location: panel_operador.php");
exit();
?>