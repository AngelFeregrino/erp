<?php
session_start();
include 'db.php'; 
if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

$mensaje = ''; 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['usuario']) && isset($_POST['password'])) {
        
       
        $username = trim($_POST['usuario']);
        $password_plano = $_POST['password'];
        $role = 'admin'; 
        
        $hash = password_hash($password_plano, PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, password, rol) VALUES (?, ?, ?)");
            
           
            if ($stmt->execute([$username, $hash, $role])) {
                $mensaje = '<div class="alert alert-success" role="alert">✅ Administrador **' . htmlspecialchars($username) . '** creado correctamente.</div>';
            } else {
                $mensaje = '<div class="alert alert-danger" role="alert">❌ Error al crear el usuario.</div>';
            }
        } catch (PDOException $e) {
           
            if ($e->getCode() === '23000') { 
                $mensaje = '<div class="alert alert-warning" role="alert">⚠️ El nombre de usuario **' . htmlspecialchars($username) . '** ya existe.</div>';
            } else {
                
                $mensaje = '<div class="alert alert-danger" role="alert">❌ Error de base de datos: ' . $e->getMessage() . '</div>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Administrador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content { margin-left: 250px; padding: 20px; } /* Ajuste simple para el contenido */
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <h2>👑 Crear Nuevo Administrador</h2>
        
        <?php echo $mensaje; ?>

        <form method="post">
            <div class="mb-3">
                <label class="form-label">Nombre de usuario</label>
                <input type="text" name="usuario" class="form-control" required autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" required autocomplete="new-password"> 
            </div>
            
            <div class="mb-3">
                <label class="form-label">Rol</label>
                <input type="text" name="rol" class="form-control" value="admin" readonly>
            </div>
            
            <button type="submit" class="btn btn-success">Crear Administrador</button>
        </form>
    </div>
</body>
</html>