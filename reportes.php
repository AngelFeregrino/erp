<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

require 'db.php';

// Fecha seleccionada (hoy por defecto)
$fecha = $_GET['fecha'] ?? date('Y-m-d');

// Consulta de reportes de producción
$stmt = $pdo->prepare("
    SELECT pr.nombre AS prensa, pi.nombre AS pieza,
           SUM(ch.cantidad) AS total_cantidad
    FROM capturas_hora ch
    JOIN prensas pr ON pr.id = ch.prensa_id
    JOIN piezas pi ON pi.id = ch.pieza_id
    WHERE ch.fecha = ?
    GROUP BY pr.nombre, pi.nombre
    ORDER BY pr.nombre, pi.nombre
");
$stmt->execute([$fecha]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta de rendimientos (ajustada a tu esquema)
$stmt2 = $pdo->prepare("
    SELECT r.id, r.pieza_id, r.fecha, r.esperado, r.producido, r.rendimiento, r.fecha_registro,
           pi.nombre AS pieza
    FROM rendimientos r
    JOIN piezas pi ON pi.id = r.pieza_id
    WHERE r.fecha = ?
    ORDER BY pi.nombre
");
$stmt2->execute([$fecha]);
$rendimientos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes de Producción</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'sidebar.php'; ?>

<div class="content p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">📊 Reportes de Producción</h1>
        <div>
            <a href="generar_reporte.php?fecha=<?= urlencode($fecha) ?>" class="btn btn-danger" target="_blank">
                📄 Descargar Reporte
            </a>
            <a href="panel_admin.php" class="btn btn-secondary ms-2">⬅ Volver</a>
        </div>
    </div>

    <!-- Filtro de fecha -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">Seleccionar fecha</div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success">Consultar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Resultados de producción -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            Resultados de Producción para <?= htmlspecialchars($fecha) ?>
        </div>
        <div class="card-body">
            <?php if (empty($resultados)): ?>
                <div class="alert alert-info">No hay datos de producción para esta fecha.</div>
            <?php else: ?>
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Prensa</th>
                            <th>Pieza</th>
                            <th class="text-end">Total Producido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['prensa']) ?></td>
                            <td><?= htmlspecialchars($r['pieza']) ?></td>
                            <td class="text-end"><?= number_format((int)$r['total_cantidad']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabla de Rendimientos -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white">
            Rendimientos para <?= htmlspecialchars($fecha) ?>
        </div>
        <div class="card-body">
            <?php if (empty($rendimientos)): ?>
                <div class="alert alert-warning">No hay rendimientos registrados para esta fecha.</div>
            <?php else: ?>
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-secondary">
                        <tr>
                            <th>Pieza</th>
                            <th class="text-end">Esperado</th>
                            <th class="text-end">Producido</th>
                            <th class="text-end">Rendimiento (%)</th>
                            <th>Fecha y hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rendimientos as $ren): ?>
                        <tr>
                            <td><?= htmlspecialchars($ren['pieza']) ?></td>
                            <td class="text-end"><?= number_format((int)$ren['esperado']) ?></td>
                            <td class="text-end"><?= number_format((int)$ren['producido']) ?></td>
                            <td class="text-end"><?= number_format((float)$ren['rendimiento'], 2) ?></td>
                            <td><?= htmlspecialchars($ren['fecha_registro']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
