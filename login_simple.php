<?php
session_start();

// Evitar caché del navegador
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Redirección automática SOLO si vienes de un login real
if (!empty($_SESSION['from_login']) && isset($_SESSION['rol'])) {
    unset($_SESSION['from_login']); // se usa una sola vez

    if ($_SESSION['rol'] === 'admin') {
        header("Location: panel_admin.php");
    } elseif ($_SESSION['rol'] === 'operador') {
        header("Location: panel_operador.php");
    }
    exit();
}
?>


<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Sistema ERP</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: url('img/banner.png') no-repeat center center fixed;
      background-size: cover;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 0;
    }

    .container {
      text-align: center;
      background: rgba(255, 255, 255, 0.15);
      padding: 50px;
      border-radius: 20px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
    }

    h1 {
      color: white;
      margin-bottom: 40px;
      text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.7);
    }

    .main-button {
      background: #3498db;
      color: white;
      border: none;
      padding: 25px 80px;
      font-size: 26px;
      border-radius: 15px;
      cursor: pointer;
      transition: 0.3s;
      box-shadow: 0px 6px 15px rgba(0, 0, 0, 0.3);
    }

    .main-button:hover {
      background: #2980b9;
      transform: scale(1.07);
    }

    .admin-button {
      position: absolute;
      top: 25px;
      right: 25px;
      background: #e74c3c;
      color: white;
      border: none;
      padding: 15px 35px;
      font-size: 18px;
      border-radius: 12px;
      cursor: pointer;
      transition: 0.3s;
      box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.2);
    }

    .admin-button:hover {
      background: #c0392b;
      transform: scale(1.05);
    }
  </style>
</head>

<body>
  <button class="admin-button" onclick="location.href='admin_login.php'">Admin</button>
  <div class="container">
    <h1>Bienvenido</h1>
    <button class="main-button" onclick="location.href='login_operador.php'">Entrar al Sistema</button>
  </div>
</body>

</html>