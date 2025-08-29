<?php
session_start();
session_unset();
session_destroy();
session_start();

// Sesión de operador "automática"

$_SESSION['from_login'] = true;
$_SESSION['id']         = 2; // o id real si existe en BD
$_SESSION['usuario']    = 'operador';
$_SESSION['rol']        = 'operador';
$_SESSION['nombre']     = '';
$_SESSION['login_time'] = time();

header("Location: panel_operador.php");
exit();
