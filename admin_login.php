<?php
session_start();
if (isset($_SESSION['rol'])) {
  if ($_SESSION['rol'] === 'admin') {
    header("Location: panel_admin.php");
    exit();
  } else {
    // Si no es admin, lo mandamos al panel que le corresponde
    header("Location: admin_login.php");
    exit();
  }
}

include 'db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $user = $_POST['usuario'];
  $pass = $_POST['password'];

  $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario=?");
  $stmt->execute([$user]);
  $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$usuario) {
    $error = "Usuario no encontrado";
  } elseif (!password_verify($pass, $usuario['password'])) {
    $error = "Contraseña incorrecta";
  } elseif ($usuario['rol'] !== 'admin') {
    $error = "No tienes permisos de administrador";
  } else {
    session_regenerate_id(true);
    $_SESSION['rol'] = "admin";
    $_SESSION['user'] = $usuario['usuario'];
    $_SESSION['nombre'] = $usuario['nombre'] ?? $usuario['usuario'];
    $_SESSION['login_time'] = time();
    header("Location: panel_admin.php");
    exit();
  }
}
?>
<!DOCTYPE html>

<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Login Admin</title>
  <style>
    body {
      margin: 0;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      background: url("img/banner.png") no-repeat center center fixed;
      background-size: cover;
      font-family: Arial, sans-serif;
    }

    .login-container {
      background: rgba(255, 255, 255, 0.15);
      padding: 40px;
      border-radius: 20px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
      text-align: center;
      width: 320px;
    }

    h2 {
      color: white;
      margin-bottom: 25px;
      text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.7);
    }

    input {
      width: 90%;
      padding: 12px;
      margin: 10px 0;
      border: none;
      border-radius: 10px;
      font-size: 16px;
    }

    button {
      width: 95%;
      padding: 14px;
      background: #e74c3c;
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 18px;
      cursor: pointer;
      box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.2);
      transition: 0.3s;
    }

    button:hover {
      background: #c0392b;
      transform: scale(1.05);
    }

    .error {
      color: #ffdddd;
      font-size: 14px;
      margin-top: 10px;
      text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.6);
    }

    .back-wrapper {
      position: fixed;
      top: 20px;
      left: 20px;
      z-index: 1000;
    }

    .back-button {
      background: #3498db;
      color: white;
      border: none;
      padding: 15px 35px;
      font-size: 18px;
      border-radius: 12px;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
      transition: 0.3s ease;
      display: inline-block;
      width: auto;
    }

    .back-button:hover {
      background: #2980b9;
      transform: scale(1.05);
    }
  </style>
</head>

<body>
  <div class="back-wrapper">
    <button class="back-button" onclick="location.href='login_simple.php'">← Regresar</button>
  </div>
  <div class="login-container">
    <h2>Acceso</h2>
    <form method="post">
      <input type="text" name="usuario" placeholder="Usuario" required><br>
      <input type="password" name="password" placeholder="Contraseña" required><br>
      <button type="submit">Ingresar</button>
    </form>
    <?php if (!empty($error))
      echo "<p class='error'>$error</p>"; ?>
  </div>
</body>

</html>