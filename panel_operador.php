<?php
session_start();
require 'db.php';

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    header('Location: login_simple.php');
    exit();
}

$nombre = $_SESSION['nombre'] ?? $_SESSION['usuario'];

// Obtener prensas registradas
$stmt = $pdo->query("SELECT id, nombre FROM prensas ORDER BY nombre");
$prensas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Operador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">👷 Panel Operador — Hola, operador <?= htmlspecialchars($nombre) ?></h1>
        <a href="logout.php" class="btn btn-danger">Cerrar sesión</a>
    </div>

    <h4 class="mb-3">Selecciona una prensa</h4>

    <div class="row">
        <?php foreach ($prensas as $pr): ?>
            <div class="col-md-4 mb-3">
                <a href="panel_prensa.php?id=<?= $pr['id'] ?>" class="btn btn-primary w-100 p-3">
                    <?= htmlspecialchars($pr['nombre']) ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
