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

// Mensaje flash
$mensaje = $_SESSION['mensaje'] ?? null;
unset($_SESSION['mensaje']);

// --- PROCESAR ELIMINACIÓN (AJAX o FORM POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_orden_id'])) {
    $orden_id = intval($_POST['eliminar_orden_id']);

    // Seguridad: comprobar que la orden existe
    $chk = $pdo->prepare("SELECT id FROM ordenes_produccion WHERE id = ?");
    $chk->execute([$orden_id]);
    if (!$chk->fetch()) {
        $_SESSION['mensaje'] = "⚠ Orden no encontrada (id $orden_id).";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $deletedCounts = [];

    // 1) Capturas asociadas
    $st = $pdo->prepare("SELECT id FROM capturas_hora WHERE orden_id = ?");
    $st->execute([$orden_id]);
    $capturasIds = $st->fetchAll(PDO::FETCH_COLUMN);

    $runInDelete = function(array $ids, $table, $col) use ($pdo, &$deletedCounts) {
        if (empty($ids)) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `$table` WHERE `$col` IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $deletedCounts["$table ($col)"] = $stmt->rowCount();
    };

    if (!empty($capturasIds)) {
        // buscar tablas con columna 'captura_id'
        $q = $pdo->prepare("
            SELECT table_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND column_name = 'captura_id'
        ");
        $q->execute();
        $tables = $q->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tbl) {
            $runInDelete($capturasIds, $tbl, 'captura_id');
        }

        // borrar valores_hora y capturas_hora explícitamente (por si acaso)
        $runInDelete($capturasIds, 'valores_hora', 'captura_id');

        // borrar capturas_hora por id
        // capturas_hora.id IN (...)
        $placeholders = implode(',', array_fill(0, count($capturasIds), '?'));
        $del = $pdo->prepare("DELETE FROM `capturas_hora` WHERE `id` IN ($placeholders)");
        $del->execute($capturasIds);
        $deletedCounts['capturas_hora (id)'] = $del->rowCount();
    } else {
        // eliminar por orden_id en capturas_hora por si no hay ids (defensivo)
        $del = $pdo->prepare("DELETE FROM capturas_hora WHERE orden_id = ?");
        $del->execute([$orden_id]);
        $deletedCounts['capturas_hora (orden_id)'] = $del->rowCount();
    }

    // 2) Borrar tablas que referencien orden_id (dinámico)
    $q2 = $pdo->prepare("
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND column_name = 'orden_id'
    ");
    $q2->execute();
    $ordenCols = $q2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ordenCols as $row) {
        $tbl = $row['table_name'];
        $col = $row['column_name'];
        if ($tbl === 'ordenes_produccion') continue;
        $del = $pdo->prepare("DELETE FROM `$tbl` WHERE `$col` = ?");
        $del->execute([$orden_id]);
        $deletedCounts["$tbl ($col)"] = $del->rowCount();
    }

    // 3) prensas_habilitadas explícito por si no se detectó
    $delPh = $pdo->prepare("DELETE FROM prensas_habilitadas WHERE orden_id = ?");
    $delPh->execute([$orden_id]);
    $deletedCounts['prensas_habilitadas (orden_id)'] = $delPh->rowCount();

    // 4) borrar la orden en ordenes_produccion (la última)
    $delOrd = $pdo->prepare("DELETE FROM ordenes_produccion WHERE id = ?");
    $delOrd->execute([$orden_id]);
    $deletedCounts['ordenes_produccion (id)'] = $delOrd->rowCount();

    $pdo->commit();

    // Verificación final: buscar tablas que contienen filas todavía con la orden_id
    $remaining = [];
    $q3 = $pdo->prepare("
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND column_name LIKE '%orden%';
    ");
    $q3->execute();
    $colsLikeOrden = $q3->fetchAll(PDO::FETCH_ASSOC);

    foreach ($colsLikeOrden as $colRow) {
        $tbl = $colRow['table_name'];
        $col  = $colRow['column_name'];
        // construir consulta segura (no parámetros en nombre de tabla/col)
        $sql = "SELECT COUNT(*) FROM `$tbl` WHERE `$col` = ?";
        $chk = $pdo->prepare($sql);
        $chk->execute([$orden_id]);
        $cnt = intval($chk->fetchColumn());
        if ($cnt > 0) $remaining["$tbl.$col"] = $cnt;
    }

    // también comprobar ordenes_produccion
    $chkOrd = $pdo->prepare("SELECT COUNT(*) FROM ordenes_produccion WHERE id = ?");
    $chkOrd->execute([$orden_id]);
    $cntOrd = intval($chkOrd->fetchColumn());
    if ($cntOrd > 0) $remaining['ordenes_produccion.id'] = $cntOrd;

    // Generar mensaje legible
    $msg = "✅ Eliminación completada. Detalle filas eliminadas: ";
    foreach ($deletedCounts as $k => $v) {
        $msg .= "<br>• $k : $v";
    }
    if (empty($remaining)) {
        $msg .= "<br><b>No se encontraron referencias restantes a la orden $orden_id.</b>";
    } else {
        $msg .= "<br><b>¡ATENCIÓN! Quedan referencias a la orden $orden_id:</b>";
        foreach ($remaining as $k => $v) {
            $msg .= "<br>• $k => $v filas";
        }
        $msg .= "<br>Revisa estas tablas y el código que lista las órdenes (p.e. 'Cerrar orden').";
    }

    $_SESSION['mensaje'] = $msg;
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['mensaje'] = "❌ Error al eliminar orden $orden_id: " . htmlspecialchars($e->getMessage());
}


    // redirigir para limpiar POST y mostrar mensaje
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// 1. Catálogos
$prensas = $pdo->query("SELECT id, nombre FROM prensas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$piezas  = $pdo->query("SELECT id, nombre FROM piezas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// 2. Procesar habilitación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['habilitar'])) {
    $numero_orden    = trim($_POST['numero_orden']);
    $fecha           = $_POST['fecha'];
    $prensa_id       = intval($_POST['prensa_id']);
    $pieza_id        = intval($_POST['pieza_id']);
    $lote_manual     = trim($_POST['lote_manual']);
    $admin_id        = $_SESSION['id'];

    // NUEVO: cantidad_inicio (aseguramos entero >= 0)
    $cantidad_inicio = isset($_POST['cantidad_inicio']) ? intval($_POST['cantidad_inicio']) : 0;
    if ($cantidad_inicio < 0) $cantidad_inicio = 0;

    // Insertar orden incluyendo cantidad_inicio
    $stmt = $pdo->prepare("INSERT INTO ordenes_produccion 
        (numero_orden, pieza_id, prensa_id, fecha_inicio, admin_id, estado, cantidad_inicio) 
        VALUES (?, ?, ?, ?, ?, 'abierta', ?)");
    $stmt->execute([$numero_orden, $pieza_id, $prensa_id, $fecha, $admin_id, $cantidad_inicio]);
    $orden_id = $pdo->lastInsertId();

    // 🔹 Generar numero_lote con formato: fecha + "-" + texto manual
    $numero_lote = $fecha . "-" . $lote_manual;

    // Actualizar orden con numero_lote (y por si acaso asegurar cantidad_inicio también)
    $stmtUpd = $pdo->prepare("UPDATE ordenes_produccion SET numero_lote = ?, cantidad_inicio = ? WHERE id = ?");
    $stmtUpd->execute([$numero_lote, $cantidad_inicio, $orden_id]);

    // Habilitar prensa
    $stmt2 = $pdo->prepare("INSERT INTO prensas_habilitadas 
        (orden_id, fecha, prensa_id, pieza_id, habilitado) 
        VALUES (?, ?, ?, ?, 1)");
    $stmt2->execute([$orden_id, $fecha, $prensa_id, $pieza_id]);
    // ==============================
    // 🔧 Crear franjas horarias dinámicas (soporta turnos 08-16, 16-24 y 00-08)
    // ==============================
    date_default_timezone_set('America/Mexico_City');
    $hora_actual   = intval(date('H'));
    $minuto_actual = intval(date('i'));

    // Definimos los turnos (start inclusive, end exclusive; end puede ser 24 o 8 o 16)
    $turnos = [
        ['start' => 8,  'end' => 16], // 08:00 - 16:00
        ['start' => 16, 'end' => 24], // 16:00 - 00:00 (representado como 24)
        ['start' => 0,  'end' => 8],  // 00:00 - 08:00 (madrugada)
    ];

    // Encontrar el turno actual (el que contiene la hora actual)
    $turno_actual = null;
    foreach ($turnos as $t) {
        // tratamiento especial para el turno 0..8 (madrugada)
        if ($t['start'] <= $t['end']) {
            if ($hora_actual >= $t['start'] && $hora_actual < $t['end']) {
                $turno_actual = $t;
                break;
            }
        } else {
            // no se usa en este arreglo, pero se deja por si se agrega un turno que cruza medianoche
            if ($hora_actual >= $t['start'] || $hora_actual < $t['end']) {
                $turno_actual = $t;
                break;
            }
        }
    }

    // Si la hora actual no cae dentro de ningún turno (por ejemplo habilitamos antes del inicio del primer turno),
    // elegimos el primer turno del día (08-16) por defecto para generar franjas desde su inicio.
    if ($turno_actual === null) {
        $turno_actual = $turnos[0];
    }

    $shift_start = $turno_actual['start'];
    $shift_end   = $turno_actual['end']; // puede ser 24 o 8 o 16

    // Calcular hora_inicio basada en la hora actual y el umbral de minutos (>=50 => siguiente hora)
    if ($hora_actual < $shift_start) {
        // si habilitaron antes del inicio del turno, empezamos desde el inicio del turno
        $hora_inicio = $shift_start;
    } elseif ($hora_actual >= $shift_end && $shift_end > $shift_start) {
        // si ya pasó el turno (solo aplica para turnos no cruzados), no crear franjas
        $hora_inicio = null;
    } else {
        $hora_inicio = ($minuto_actual >= 50) ? ($hora_actual + 1) : $hora_actual;
        // aseguramos que no empecemos antes del inicio
        if ($shift_start <= $shift_end) {
            if ($hora_inicio < $shift_start) $hora_inicio = $shift_start;
        } else {
            // en caso hipotético de un turno que cruce medianoche (no usado aquí),
            // dejamos la hora tal cual porque manejaremos fechas con DateTime abajo.
        }
    }

    if ($hora_inicio !== null) {
        // Construimos DateTime de inicio (puede pertenecer al día $fecha o al día siguiente si corresponde)
        // Normalizamos la hora de inicio dentro del rango del turno
        // Si hora_inicio >= 24 reducimos 24 y movemos al día siguiente (por seguridad)
        if ($hora_inicio >= 24) {
            $hora_inicio -= 24;
            $start_date = (new DateTime($fecha))->modify('+1 day');
        } else {
            $start_date = new DateTime($fecha);
        }

        // Ajustar la hora de $start_date al $hora_inicio calculado
        $start_dt = DateTime::createFromFormat('Y-m-d H:i', $start_date->format('Y-m-d') . ' ' . str_pad($hora_inicio, 2, '0', STR_PAD_LEFT) . ':00');

        // Construir DateTime de fin de turno ($shift_end). Si shift_end <= shift_start, significa que cruza medianoche.
        if ($shift_end > $shift_start) {
            // fin el mismo día (pero si shift_end == 24, lo tratamos como 00:00 del día siguiente)
            if ($shift_end === 24) {
                $end_dt = (new DateTime($fecha))->modify('+1 day')->setTime(0, 0);
            } else {
                $end_dt = DateTime::createFromFormat('Y-m-d H:i', $start_date->format('Y-m-d') . ' ' . str_pad($shift_end, 2, '0', STR_PAD_LEFT) . ':00');
            }
        } else {
            // turno que cruza medianoche (ej. start 16 end 8) -> fin es día siguiente
            $end_dt = DateTime::createFromFormat('Y-m-d H:i', $start_date->format('Y-m-d') . ' ' . str_pad($shift_end, 2, '0', STR_PAD_LEFT) . ':00')->modify('+1 day');
        }

        // Si por alguna razón start >= end (p. ej. habilitaron después del fin), no generar nada
        if ($start_dt < $end_dt) {
            $slot_dt = clone $start_dt;
            while ($slot_dt < $end_dt) {
                $slot_fin_dt = (clone $slot_dt)->modify('+1 hour');

                $inicio = $slot_dt->format('H:i');
                $fin    = $slot_fin_dt->format('H:i');
                $slot_fecha = $slot_dt->format('Y-m-d'); // la fecha correcta (si cruza medianoche, será día siguiente)

                $stmt3 = $pdo->prepare("INSERT INTO capturas_hora 
                    (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pendiente')");
                $stmt3->execute([$orden_id, $slot_fecha, $prensa_id, $pieza_id, $inicio, $fin]);

                // avanzar una hora
                $slot_dt->modify('+1 hour');
            }
        }
    }


    $mensaje = "✅ Prensa habilitada y orden creada con lote <b>$numero_lote</b>. Cantidad inicio registrada: <b>" . number_format($cantidad_inicio) . "</b>.";
}

// 3. Prensas habilitadas hoy (traemos orden_id también)
$hoy = date('Y-m-d');
$stmt = $pdo->prepare("SELECT ph.id AS ph_id, ph.orden_id, ph.fecha, pr.nombre AS prensa, pi.nombre AS pieza
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
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .btn-danger-sm { padding: .35rem .6rem; font-size: .9rem; border-radius: .35rem; }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="col-md-10 content bg-light">
        <h1 class="h3 mb-4">🧑‍💼 Panel Administrador</h1>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success"><?= $mensaje ?></div>
        <?php elseif (!empty($_SESSION['mensaje'])): ?>
            <div class="alert alert-info"><?= $_SESSION['mensaje']; unset($_SESSION['mensaje']); ?></div>
        <?php endif; ?>

        <!-- Habilitar prensa -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                Habilitar prensa y pieza
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Número de Orden</label>
                            <input type="text" name="numero_orden" class="form-control" placeholder="Ej: ORD-1234" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha</label>
                            <input type="date" name="fecha" value="<?= $hoy ?>" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Código manual de lote</label>
                            <input type="text" name="lote_manual" class="form-control" placeholder="Ej: A1, 001, TEST" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cantidad inicio</label>
                            <input type="number" name="cantidad_inicio" class="form-control" min="0" step="1" value="0" required>
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
                                <th>N° Orden (id)</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($habilitadas as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['fecha']) ?></td>
                                    <td><?= htmlspecialchars($h['prensa']) ?></td>
                                    <td><?= htmlspecialchars($h['pieza']) ?></td>
                                    <td><?= htmlspecialchars($h['orden_id']) ?></td>
                                    <td style="white-space:nowrap;">
                                        <!-- Botón para eliminar todo lo creado por esa orden -->
                                        <form method="post" style="display:inline" onsubmit="return confirmEliminar(event, <?= (int)$h['orden_id'] ?>);">
                                            <input type="hidden" name="eliminar_orden_id" value="<?= (int)$h['orden_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-danger-sm">Eliminar</button>
                                        </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="text-muted mt-2 small">
                        Al eliminar una orden se borran sus franjas horarias (capturas), los valores técnicos asociados y la habilitación.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        function confirmEliminar(e, ordenId) {
            // Si el submit viene por JS (onclick), confirmamos
            const ok = confirm("¿Eliminar la orden " + ordenId + " y todo lo creado por ella?\nEsta acción no se puede deshacer.");
            if (!ok) {
                e.preventDefault();
                return false;
            }
            return true;
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
