<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    header('Location: login_simple.php');
    exit();
}

require 'db.php';

$hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$nombre = $_SESSION['nombre'] ?: $_SESSION['usuario'];

// 1. Obtener prensas habilitadas hoy
$stmt = $pdo->prepare("SELECT ph.orden_id, ph.prensa_id, pr.nombre AS prensa,
                               ph.pieza_id, pi.nombre AS pieza
                        FROM prensas_habilitadas ph
                        JOIN prensas pr ON pr.id = ph.prensa_id
                        JOIN piezas pi ON pi.id = ph.pieza_id
                        WHERE ph.fecha = ? AND ph.habilitado = 1");
$stmt->execute([$hoy]);
$habilitadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Procesar captura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['capturar'])) {
    $orden_id  = $_POST['orden_id'];
    $prensa_id = $_POST['prensa_id'];
    $pieza_id  = $_POST['pieza_id'];
    $hora_ini  = $_POST['hora_inicio'];
    $hora_fin  = $_POST['hora_fin'];
    $cantidad  = $_POST['cantidad'];
    $obs       = $_POST['observaciones'];
    $firma     = $_POST['firma'];

    // Tolerancia de +10 min
    $hora_limite = date('H:i:s', strtotime($hora_fin . ' +10 minutes'));

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM capturas_hora 
                                WHERE orden_id=? AND hora_inicio=? AND hora_fin=?");
    $stmtCheck->execute([$orden_id, $hora_ini, $hora_fin]);

    if ($stmtCheck->fetchColumn() > 0) {
        $mensaje = "⚠️ Ya existe una captura para esa franja.";
    } elseif ($hora_actual >= $hora_ini && $hora_actual <= $hora_limite) {
        // Guardar captura
        $stmt = $pdo->prepare("INSERT INTO capturas_hora
            (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, cantidad, observaciones_op, firma_operador, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cerrada')");
        $stmt->execute([$orden_id, $hoy, $prensa_id, $pieza_id, $hora_ini, $hora_fin, $cantidad, $obs, $firma]);
        $captura_id = $pdo->lastInsertId();

        // Guardar atributos técnicos
        if (!empty($_POST['atributo'])) {
            foreach ($_POST['atributo'] as $atributo_id => $valor) {
                $stmt2 = $pdo->prepare("INSERT INTO valores_hora (captura_id, atributo_pieza_id, valor)
                                        VALUES (?, ?, ?)");
                $stmt2->execute([$captura_id, $atributo_id, $valor]);
            }
        }

        $mensaje = "✅ Captura registrada correctamente.";
    } else {
        $mensaje = "⚠️ Fuera de la ventana horaria permitida.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Operador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">👷 Panel Operador — Hola, <?= htmlspecialchars($nombre) ?></h1>
        <a href="logout.php" class="btn btn-danger">Cerrar sesión</a>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <h4 class="mb-3">Prensas habilitadas hoy (<?= $hoy ?>)</h4>

    <?php if (empty($habilitadas)): ?>
        <div class="alert alert-warning">No hay prensas habilitadas para hoy.</div>
    <?php else: ?>
        <?php foreach ($habilitadas as $h): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                <?= htmlspecialchars($h['prensa']) ?> — <?= htmlspecialchars($h['pieza']) ?>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="orden_id" value="<?= $h['orden_id'] ?>">
                    <input type="hidden" name="prensa_id" value="<?= $h['prensa_id'] ?>">
                    <input type="hidden" name="pieza_id" value="<?= $h['pieza_id'] ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Hora inicio</label>
                            <input type="time" name="hora_inicio" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hora fin</label>
                            <input type="time" name="hora_fin" class="form-control" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Cantidad</label>
                            <input type="number" name="cantidad" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Observaciones</label>
                            <input type="text" name="observaciones" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Firma operador</label>
                            <input type="text" name="firma" class="form-control" required>
                        </div>
                    </div>

                    <h5 class="mt-3">Datos técnicos</h5>
                    <div class="row">
                        <?php
                        $stmt3 = $pdo->prepare("SELECT id, nombre_atributo, unidad
                                                FROM atributos_pieza
                                                WHERE pieza_id = ?");
                        $stmt3->execute([$h['pieza_id']]);
                        $atributos = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($atributos as $a): ?>
                            <div class="col-md-4 mb-2">
                                <label class="form-label">
                                    <?= htmlspecialchars($a['nombre_atributo']) ?> (<?= htmlspecialchars($a['unidad']) ?>)
                                </label>
                                <input type="text" name="atributo[<?= $a['id'] ?>]" class="form-control" required>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="text-end mt-3">
                        <button type="submit" name="capturar" class="btn btn-success">Guardar captura</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
