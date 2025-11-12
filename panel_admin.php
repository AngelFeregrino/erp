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

// 1. Catálogos
$prensas = $pdo->query("SELECT id, nombre FROM prensas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$piezas  = $pdo->query("SELECT id, nombre FROM piezas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// 2. Procesar habilitación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['habilitar'])) {
    $numero_orden = $_POST['numero_orden'];
    $fecha        = $_POST['fecha'];
    $prensa_id    = $_POST['prensa_id'];
    $pieza_id     = $_POST['pieza_id'];
    $lote_manual  = trim($_POST['lote_manual']);
    $admin_id     = $_SESSION['id'];

    // Insertar orden sin número de lote todavía
    $stmt = $pdo->prepare("INSERT INTO ordenes_produccion 
        (numero_orden, pieza_id, prensa_id, fecha_inicio, admin_id, estado) 
        VALUES (?, ?, ?, ?, ?, 'abierta')");
    $stmt->execute([$numero_orden, $pieza_id, $prensa_id, $fecha, $admin_id]);
    $orden_id = $pdo->lastInsertId();

    // 🔹 Generar numero_lote con formato: fecha + "-" + texto manual
    $numero_lote = $fecha . "-" . $lote_manual;

    // Actualizar orden con numero_lote
    $stmtUpd = $pdo->prepare("UPDATE ordenes_produccion SET numero_lote = ? WHERE id = ?");
    $stmtUpd->execute([$numero_lote, $orden_id]);

    // Habilitar prensa
    $stmt2 = $pdo->prepare("INSERT INTO prensas_habilitadas 
        (orden_id, fecha, prensa_id, pieza_id, habilitado) 
        VALUES (?, ?, ?, ?, 1)");
    $stmt2->execute([$orden_id, $fecha, $prensa_id, $pieza_id]);

    // ==============================
    // 🔧 Crear franjas horarias dinámicas
    // ==============================
    date_default_timezone_set('America/Mexico_City');
    $hora_actual = intval(date('H'));
    $minuto_actual = intval(date('i'));
    $hora_inicio_turno = 8;
    $hora_fin_turno = 16;

    if ($hora_actual < $hora_inicio_turno) {
        $hora_inicio = $hora_inicio_turno;
    } elseif ($hora_actual >= $hora_fin_turno) {
        $hora_inicio = null;
    } else {
        $hora_inicio = ($minuto_actual >= 30) ? ($hora_actual + 1) : $hora_actual;
        if ($hora_inicio < $hora_inicio_turno) $hora_inicio = $hora_inicio_turno;
    }

    if ($hora_inicio !== null && $hora_inicio < $hora_fin_turno) {
        for ($h = $hora_inicio; $h < $hora_fin_turno; $h++) {
            $inicio = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $fin = str_pad($h + 1, 2, '0', STR_PAD_LEFT) . ':00';

            $stmt3 = $pdo->prepare("INSERT INTO capturas_hora 
                (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, estado) 
                VALUES (?, ?, ?, ?, ?, ?, 'pendiente')");
            $stmt3->execute([$orden_id, $fecha, $prensa_id, $pieza_id, $inicio, $fin]);
        }
    }

    $mensaje = "✅ Prensa habilitada y orden creada con lote <b>$numero_lote</b>.";
}

// 3. Prensas habilitadas hoy
$hoy = date('Y-m-d');
$stmt = $pdo->prepare("SELECT ph.fecha, pr.nombre AS prensa, pi.nombre AS pieza
                       FROM prensas_habilitadas ph
                       JOIN prensas pr ON pr.id = ph.prensa_id
                       JOIN piezas pi ON pi.id = ph.pieza_id
                       WHERE ph.fecha = ? AND ph.habilitado = 1");
$stmt->execute([$hoy]);
$habilitadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Panel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="col-md-10 content bg-light">
        <h1 class="h3 mb-4">🧑‍💼 Panel Administrador</h1>

        <?php if (isset($mensaje)): ?>
            <div class="alert alert-success"><?= $mensaje ?></div>
        <?php endif; ?>

        <!-- Habilitar prensa -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                Habilitar prensa y pieza
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Número de Orden</label>
                            <input type="text" name="numero_orden" class="form-control" placeholder="Ej: ORD-1234" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fecha</label>
                            <input type="date" name="fecha" value="<?= $hoy ?>" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Código manual de lote</label>
                            <input type="text" name="lote_manual" class="form-control" placeholder="Ej: A1, 001, TEST" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Prensa</label>
                            <select name="prensa_id" class="form-select" required>
                                <?php foreach ($prensas as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= $p['nombre'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pieza</label>
                            <select name="pieza_id" class="form-select" required>
                                <?php foreach ($piezas as $pi): ?>
                                    <option value="<?= $pi['id'] ?>"><?= $pi['nombre'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="habilitar" class="btn btn-success">Habilitar</button>
                </form>
            </div>
        </div>

        <!-- Prensas habilitadas -->
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                Prensas habilitadas hoy (<?= $hoy ?>)
            </div>
            <div class="card-body">
                <?php if (empty($habilitadas)): ?>
                    <div class="alert alert-info">No hay prensas habilitadas hoy.</div>
                <?php else: ?>
                    <table class="table table-bordered table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>Fecha</th>
                                <th>Prensa</th>
                                <th>Pieza</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($habilitadas as $h): ?>
                                <tr>
                                    <td><?= $h['fecha'] ?></td>
                                    <td><?= $h['prensa'] ?></td>
                                    <td><?= $h['pieza'] ?></td>
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
