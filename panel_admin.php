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

        $runInDelete = function (array $ids, $table, $col) use ($pdo, &$deletedCounts) {
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

    // obtener turno seleccionado
    $turno_sel = isset($_POST['turno']) ? intval($_POST['turno']) : 1;

    // generar numero_lote
    $numero_lote = $fecha . "-" . $lote_manual;

    // ==============================
    // Lógica principal: realizar todo en una transacción para atomicidad
    // ==============================
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        // Insertar orden incluyendo cantidad_inicio y turno
        $stmt = $pdo->prepare("INSERT INTO ordenes_produccion 
            (numero_orden, pieza_id, prensa_id, fecha_inicio, admin_id, estado, cantidad_inicio, turno) 
            VALUES (?, ?, ?, ?, ?, 'abierta', ?, ?)");
        $stmt->execute([$numero_orden, $pieza_id, $prensa_id, $fecha, $admin_id, $cantidad_inicio, $turno_sel]);
        $orden_id = $pdo->lastInsertId();

        // Actualizar orden con numero_lote (y por si acaso asegurar cantidad_inicio también)
        $stmtUpd = $pdo->prepare("UPDATE ordenes_produccion SET numero_lote = ?, cantidad_inicio = ? WHERE id = ?");
        $stmtUpd->execute([$numero_lote, $cantidad_inicio, $orden_id]);

        // ---------- MAPA DE TURNOS ----------
        // detectar si la fecha de inicio es sábado
        $dia_sem = (new DateTime($fecha))->format('N'); // 1..7 (1=Mon,7=Sun)
        $esSabado = ($dia_sem == 6);

        if (!$esSabado) {
            $mapTurnos = [
                1 => ['start' => 6,  'end' => 14],
                2 => ['start' => 14, 'end' => 22],
                3 => ['start' => 22, 'end' => 6],  // cruza medianoche
                4 => ['start' => 8,  'end' => 16],
            ];
        } else {
            $mapTurnos = [
                1 => ['start' => 6,  'end' => 12],
                2 => ['start' => 12, 'end' => 18],
                3 => ['start' => 18, 'end' => 0],  // 18:00 - 00:00
                4 => ['start' => 8,  'end' => 13], // mixto sábado 08:00-13:00
            ];
        }
        if (!isset($mapTurnos[$turno_sel])) $turno_sel = 1;
        $shift = $mapTurnos[$turno_sel];
        $shift_start = $shift['start'];
        $shift_end   = $shift['end'];
        if ($shift_end === 0) $shift_end = 24;

        // construir datetimes absolutos para la ocurrencia del turno (fecha = $fecha)
        $start_date = new DateTime($fecha);
        $shift_start_dt = (clone $start_date)->setTime($shift_start % 24, 0, 0);
        if ($shift_end <= $shift_start) {
            // cruza medianoche -> fin es día siguiente
            $shift_end_dt = (clone $start_date)->modify('+1 day')->setTime($shift_end % 24, 0, 0);
        } else {
            $shift_end_dt = (clone $start_date)->setTime($shift_end % 24, 0, 0);
        }

        // ---------- VERIFICACIÓN DE HABILITACIÓN EXISTENTE ----------
        // Para turno 3 buscar fecha actual o fecha previa
        $fecha_dt = new DateTime($fecha);
        $fecha_prev = (clone $fecha_dt)->modify('-1 day')->format('Y-m-d');

        if ($turno_sel == 3) {
            $chkPh = $pdo->prepare("
                SELECT COUNT(*) FROM prensas_habilitadas
                WHERE prensa_id = ? AND turno = ? AND (fecha = ? OR fecha = ?)
            ");
            $chkPh->execute([$prensa_id, $turno_sel, $fecha, $fecha_prev]);
        } else {
            $chkPh = $pdo->prepare("
                SELECT COUNT(*) FROM prensas_habilitadas
                WHERE prensa_id = ? AND turno = ? AND fecha = ?
            ");
            $chkPh->execute([$prensa_id, $turno_sel, $fecha]);
        }

        $existsPh = intval($chkPh->fetchColumn());

        if ($existsPh === 0) {
            $stmt2 = $pdo->prepare("INSERT INTO prensas_habilitadas 
                (orden_id, fecha, prensa_id, pieza_id, habilitado, turno) 
                VALUES (?, ?, ?, ?, 1, ?)");
            $stmt2->execute([$orden_id, $fecha, $prensa_id, $pieza_id, $turno_sel]);
        } else {
            // ya existía habilitación para esta prensa/turno/fecha
            $mensaje = "⚠ Ya existe una habilitación para Prensa {$prensa_id} en el turno {$turno_sel} (fecha {$fecha} o fecha previa). Se registró la orden pero no se duplicó la habilitación.";
            // NOTA: seguimos, pero no crearemos franjas duplicadas más abajo si ya existen.
        }

        // ----------------------------
        // VALIDACIONES DEFENSIVAS
        // ----------------------------
        // Asegurarnos que prensa_id y pieza_id son enteros simples (no arrays ni nulos)
        $prensa_id = intval($prensa_id);
        $pieza_id  = intval($pieza_id);

        // 1) Comprobar que la prensa existe
        $chk = $pdo->prepare("SELECT COUNT(*) FROM prensas WHERE id = ?");
        $chk->execute([$prensa_id]);
        if (intval($chk->fetchColumn()) === 0) {
            // Deshacer y abortar
            $pdo->prepare("DELETE FROM prensas_habilitadas WHERE orden_id = ? AND prensa_id = ?")->execute([$orden_id, $prensa_id]);
            $pdo->rollBack();
            $_SESSION['mensaje'] = "❌ Error: la prensa seleccionada (id $prensa_id) no existe. Habilitación cancelada.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // 2) Comprobar que la pieza existe
        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM piezas WHERE id = ?");
        $chk2->execute([$pieza_id]);
        if (intval($chk2->fetchColumn()) === 0) {
            $pdo->prepare("DELETE FROM prensas_habilitadas WHERE orden_id = ? AND prensa_id = ?")->execute([$orden_id, $prensa_id]);
            $pdo->rollBack();
            $_SESSION['mensaje'] = "❌ Error: la pieza seleccionada (id $pieza_id) no existe. Habilitación cancelada.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // ----------------------------
        // Prevención de duplicados / creación masiva accidental (por rango horario)
        // ----------------------------
        // construir ranges por fecha/hora para detectar solapamiento (considera si cruza medianoche)
        $prensa_id_int = $prensa_id;
        $dates_to_check = [];
        $start_day = $shift_start_dt->format('Y-m-d');
        $end_day = $shift_end_dt->format('Y-m-d');

        if ($start_day === $end_day) {
            $dates_to_check[] = [
                'fecha' => $start_day,
                'hora_inicio_from' => $shift_start_dt->format('H:i'),
                'hora_inicio_to' => $shift_end_dt->format('H:i')
            ];
        } else {
            // turno cruza medianoche -> dos rangos
            $dates_to_check[] = [
                'fecha' => $start_day,
                'hora_inicio_from' => $shift_start_dt->format('H:i'),
                'hora_inicio_to' => '23:59'
            ];
            $dates_to_check[] = [
                'fecha' => $end_day,
                'hora_inicio_from' => '00:00',
                'hora_inicio_to' => $shift_end_dt->format('H:i')
            ];
        }

        // construir consulta dinámica
        $whereParts = [];
        $params = [];
        foreach ($dates_to_check as $d) {
            $whereParts[] = "(prensa_id = ? AND fecha = ? AND hora_inicio >= ? AND hora_inicio < ?)";
            $params[] = $prensa_id_int;
            $params[] = $d['fecha'];
            $params[] = $d['hora_inicio_from'];
            $params[] = $d['hora_inicio_to'];
        }
        $existentes = 0;
        if (!empty($whereParts)) {
            $sql = "SELECT COUNT(*) FROM capturas_hora WHERE " . implode(' OR ', $whereParts);
            $chk3 = $pdo->prepare($sql);
            $chk3->execute($params);
            $existentes = intval($chk3->fetchColumn());
        }

        if ($existentes > 0) {
            // Ya hay franjas en ese rango; no crear nuevas franjas duplicadas.
            $mensaje = "⚠ Ya existen <b>$existentes</b> franjas para la prensa en el rango horario del turno seleccionado. No se crearán nuevas franjas para evitar solapamientos.";
            // commit parcial: dejamos la orden e (posible) habilitación creada y salimos
            $pdo->commit();
        } else {
            // ==============================
            // 🔧 Generar franjas por TURNO seleccionado respetando la hora de habilitación
            // ==============================
            date_default_timezone_set('America/Mexico_City');

            // umbral en minutos: si se habilita con >= este umbral se inicia en la siguiente hora.
            $MINUTO_UMBRAL = 50;

            // ahora (momento en que se habilita) en zona local
            $now = new DateTime('now', new DateTimeZone('America/Mexico_City'));

            // ¿quiere forzar la creación completa del turno aunque haya pasado?
            $force_full = isset($_POST['force_full']) && ($_POST['force_full'] == '1' || $_POST['force_full'] === 1);

            // Si la fecha seleccionada NO es hoy (p. ej. mañana), asumimos que se quiere crear el turno completo
            $fecha_dt2 = new DateTime($fecha);
            $hoy_dt = new DateTime(date('Y-m-d'));
            $fecha_es_igual_hoy = ($fecha_dt2->format('Y-m-d') === $hoy_dt->format('Y-m-d'));

            // decidir punto de inicio real ($start_dt)
            $start_dt = null;
            if ($force_full || !$fecha_es_igual_hoy || $now < $shift_start_dt) {
                $start_dt = clone $shift_start_dt;
            } else {
                if ($now >= $shift_end_dt) {
                    $start_dt = null;
                } else {
                    $minNow = intval($now->format('i'));
                    $hourNow = intval($now->format('H'));
                    if ($minNow >= $MINUTO_UMBRAL) {
                        $hourStart = $hourNow + 1;
                    } else {
                        $hourStart = $hourNow;
                    }
                    $start_dt = (clone $now)->setTime($hourStart % 24, 0, 0);
                }
            }

            if ($start_dt !== null) {
                // asegurarse que start_dt no sea anterior al shift_start_dt
                if ($start_dt < $shift_start_dt) $start_dt = clone $shift_start_dt;

                $slot_dt = clone $start_dt;
                $insertCount = 0;
                $insStmt = $pdo->prepare("INSERT INTO capturas_hora 
                    (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pendiente')");

                while ($slot_dt < $shift_end_dt) {
                    $slot_fin_dt = (clone $slot_dt)->modify('+1 hour');
                    $inicio = $slot_dt->format('H:i');
                    $fin    = $slot_fin_dt->format('H:i');
                    // asignar fecha real del slot (importante cuando el turno cruza medianoche)
                    $slot_fecha = $slot_dt->format('Y-m-d');

                    $insStmt->execute([$orden_id, $slot_fecha, $prensa_id, $pieza_id, $inicio, $fin]);
                    $insertCount++;

                    $slot_dt->modify('+1 hour');
                }

                $pdo->commit();
                $mensaje = "✅ Prensa habilitada y orden creada con lote <b>$numero_lote</b>. Cantidad inicio registrada: <b>" . number_format($cantidad_inicio) . "</b>.<br>Se generaron <b>$insertCount</b> franjas horarias.";
            } else {
                // turno pasado y no forzado -> no generar franjas
                $pdo->commit();
                $mensaje = "✅ Prensa habilitada y orden creada con lote <b>$numero_lote</b>. Cantidad inicio registrada: <b>" . number_format($cantidad_inicio) . "</b>. (No se generaron franjas: turno pasado o condición no cumplida.)";
            }
        }
    } catch (Exception $e) {
        // Intentar limpiar habilitación si fue creada y hubo fallo.
        // Hacemos esto en su propio try/catch para no sobreescribir la excepción original.
        try {
            if (!empty($orden_id) && !empty($prensa_id)) {
                $delStmt = $pdo->prepare("DELETE FROM prensas_habilitadas WHERE orden_id = ? AND prensa_id = ?");
                $delStmt->execute([$orden_id, $prensa_id]);
            }
        } catch (Exception $cleanupEx) {
            // Registrar el fallo de limpieza para diagnóstico, pero no romper la ruta de manejo de errores.
            error_log("Cleanup prensas_habilitadas failed: " . $cleanupEx->getMessage());
        }

        // Solo hacer rollback si hay una transacción abierta
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Exception $rbEx) {
            // Registrar pero no detener: ya vamos a informar del error original
            error_log("Rollback failed: " . $rbEx->getMessage());
        }

        $_SESSION['mensaje'] = "❌ Error al generar habilitación/franjas: " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
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
        .btn-danger-sm {
            padding: .35rem .6rem;
            font-size: .9rem;
            border-radius: .35rem;
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="col-md-10 content bg-light">
        <h1 class="h3 mb-4">🧑‍💼 Panel Administrador</h1>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-success"><?= $mensaje ?></div>
        <?php elseif (!empty($_SESSION['mensaje'])): ?>
            <div class="alert alert-info"><?= $_SESSION['mensaje'];
                                            unset($_SESSION['mensaje']); ?></div>
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
                            <input type="date" id="fecha_input" name="fecha" value="<?= $hoy ?>" class="form-control" required>
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
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="force_full" name="force_full">
                                    <label class="form-check-label" for="force_full">
                                        Forzar creación completa del turno (crear todas las franjas aunque el turno ya haya pasado)
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Si marcas esto, se crearán todas las franjas del turno seleccionado para la fecha indicada.
                                </small>
                            </div>
                        </div>
                        <div class="col-md-3 mt-3">
                            <label class="form-label">Turno</label>
                            <select id="turno_select" name="turno" class="form-select" required>
                                <option value="1">1 - Primer turno (06:00 - 14:00)</option>
                                <option value="2">2 - Segundo turno (14:00 - 22:00)</option>
                                <option value="3">3 - Tercer turno (22:00 - 06:00)</option>
                                <option value="4">4 - Turno mixto (08:00 - 16:00)</option>
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
        (function() {
            // mapeos de texto para mostrar en el select
            const textosNormal = {
                1: '1 - Primer turno (06:00 - 14:00)',
                2: '2 - Segundo turno (14:00 - 22:00)',
                3: '3 - Tercer turno (22:00 - 06:00) — cruza medianoche',
                4: '4 - Turno mixto (08:00 - 16:00)'
            };
            const textosSabado = {
                1: '1 - Primer turno (06:00 - 12:00)',
                2: '2 - Segundo turno (12:00 - 18:00)',
                3: '3 - Tercer turno (18:00 - 00:00)',
                4: '4 - Turno mixto (08:00 - 13:00)' // <--- actualizado
            };

            const fechaInput = document.getElementById('fecha_input');
            const turnoSelect = document.getElementById('turno_select');

            if (!fechaInput || !turnoSelect) return;

            function esSabadoFecha(dStr) {
                if (!dStr) return false;
                const parts = dStr.split('-');
                if (parts.length !== 3) return false;
                const dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                return dt.getDay() === 6;
            }

            function actualizarLabels() {
                const fechaVal = fechaInput.value;
                const esSab = esSabadoFecha(fechaVal);
                const textos = esSab ? textosSabado : textosNormal;
                Array.from(turnoSelect.options).forEach(opt => {
                    const v = opt.value;
                    if (textos[v]) opt.text = textos[v];
                });
                if (esSab) {
                    turnoSelect.title = "Fecha seleccionada: sábado — horarios especiales aplicarán al enviar";
                } else {
                    turnoSelect.title = "Fecha seleccionada: día normal";
                }
            }

            document.addEventListener('DOMContentLoaded', actualizarLabels);
            fechaInput.addEventListener('change', actualizarLabels);
        })();

        function confirmEliminar(e, ordenId) {
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