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

// 1. Traer todas las capturas con info básica
$stmt = $pdo->prepare("
    SELECT ch.id AS captura_id, ch.fecha, ch.hora_inicio, ch.hora_fin,
           ch.cantidad, ch.observaciones_op, ch.firma_operador, ch.estado,
           pr.nombre AS prensa, pi.nombre AS pieza, op.numero_orden, op.numero_lote
    FROM capturas_hora ch
    JOIN prensas pr ON pr.id = ch.prensa_id
    JOIN piezas pi ON pi.id = ch.pieza_id
    JOIN ordenes_produccion op ON op.id = ch.orden_id
    WHERE ch.fecha = ?
    ORDER BY ch.fecha, ch.hora_inicio, pr.nombre
");
$stmt->execute([$fecha]);
$capturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Traer valores técnicos (EAV)
$valoresPorCaptura = [];
if ($capturas) {
    $ids = array_column($capturas, 'captura_id');
    $in  = str_repeat('?,', count($ids) - 1) . '?';
    $stmtVals = $pdo->prepare("
        SELECT vh.captura_id, ap.nombre_atributo, ap.unidad, vh.valor
        FROM valores_hora vh
        JOIN atributos_pieza ap ON ap.id = vh.atributo_pieza_id
        WHERE vh.captura_id IN ($in)
        ORDER BY vh.captura_id, ap.nombre_atributo
    ");
    $stmtVals->execute($ids);
    foreach ($stmtVals->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valoresPorCaptura[$v['captura_id']][] = $v;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hojas de Trabajo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-slider {
            overflow-x: auto;
            white-space: nowrap;
        }
        .table-slider::-webkit-scrollbar {
            height: 8px;
        }
        .table-slider::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .table-slider::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="content py-4"><!-- antes era container-fluid -->
    <h1 class="h3 mb-4">📄 Hojas de Trabajo</h1>

    <!-- Selector de fecha -->
    <form method="get" class="row g-3 mb-4">
        <div class="col-md-3">
            <input type="date" name="fecha" value="<?= $fecha ?>" class="form-control">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>

    <?php if (empty($capturas)): ?>
        <div class="alert alert-info">No hay capturas registradas para <?= htmlspecialchars($fecha) ?>.</div>
    <?php else: ?>
        <div class="table-slider"><!-- slider horizontal -->
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Prensa</th>
                        <th>Pieza</th>
                        <th>N° Orden</th>
                        <th>N° Lote</th>
                        <th>Cantidad</th>
                        <th>Valores técnicos</th>
                        <th>Observaciones</th>
                        <th>Firma Operador</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($capturas as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['fecha']) ?></td>
                            <td><?= substr($c['hora_inicio'],0,5) ?> - <?= substr($c['hora_fin'],0,5) ?></td>
                            <td><?= htmlspecialchars($c['prensa']) ?></td>
                            <td><?= htmlspecialchars($c['pieza']) ?></td>
                            <td><?= htmlspecialchars($c['numero_orden']) ?></td>
                            <td><?= htmlspecialchars($c['numero_lote']) ?></td>
                            <td class="text-end"><?= $c['cantidad'] !== null ? number_format($c['cantidad']) : '-' ?></td>
                            <td>
                                <?php if (!empty($valoresPorCaptura[$c['captura_id']])): ?>
                                    <ul class="mb-0">
                                        <?php foreach ($valoresPorCaptura[$c['captura_id']] as $v): ?>
                                            <li><?= htmlspecialchars($v['nombre_atributo']) ?>: <?= htmlspecialchars($v['valor']) ?> <?= htmlspecialchars($v['unidad']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <em>Sin valores</em>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['observaciones_op'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($c['firma_operador'] ?? '-') ?></td>
                            <td>
                                <?php if ($c['estado'] === 'pendiente'): ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Cerrada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
