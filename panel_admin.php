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

    // --- Reemplaza tu bloque dentro del try (donde haces beginTransaction y deletes) por este ---
try {
    $pdo->beginTransaction();

    // 1) Obtener capturas asociadas para borrar valores_hora (como ya hacías)
    $st = $pdo->prepare("SELECT id FROM capturas_hora WHERE orden_id = ?");
    $st->execute([$orden_id]);
    $capturasIds = $st->fetchAll(PDO::FETCH_COLUMN);

    // Helper: ejecutar DELETE dinámico con placeholders
    $runInDelete = function(array $ids, $table, $col) use ($pdo) {
        if (empty($ids)) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `$table` WHERE `$col` IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
    };

    // 1.a) Si hay capturas, borrar cualquier tabla que tenga columna captura_id
    if (!empty($capturasIds)) {
        // buscar tablas en el esquema actual que tengan columna 'captura_id'
        $q = $pdo->prepare("
            SELECT table_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND column_name = 'captura_id'
        ");
        $q->execute();
        $tables = $q->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $tbl) {
            // evitar borrar de valores_hora/capturas_hora con lógica duplicada: lo haremos pero está OK
            $runInDelete($capturasIds, $tbl, 'captura_id');
        }

        // borrar valores_hora (si existe)
        $runInDelete($capturasIds, 'valores_hora', 'captura_id');

        // borrar capturas_hora por id
        $runInDelete($capturasIds, 'capturas_hora', 'id');
    } else {
        // Si no hay capturas encontradas, todavía intentaremos borrar por orden_id más abajo
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
        // Evitar borrar la propia ordenes_produccion hasta el final
        if ($tbl === 'ordenes_produccion') continue;
        // Ejecutar DELETE FROM $tbl WHERE orden_id = ?
        $del = $pdo->prepare("DELETE FROM `$tbl` WHERE orden_id = ?");
        $del->execute([$orden_id]);
    }

    // 3) borrar prensas_habilitadas asociadas a la orden (si no fue borrada por la query anterior)
    $delPh = $pdo->prepare("DELETE FROM prensas_habilitadas WHERE orden_id = ?");
    $delPh->execute([$orden_id]);

    // 4) borrar la orden en ordenes_produccion (la última)
    $delOrd = $pdo->prepare("DELETE FROM ordenes_produccion WHERE id = ?");
    $delOrd->execute([$orden_id]);

    $pdo->commit();
    $_SESSION['mensaje'] = "✅ Orden $orden_id y sus datos fueron eliminados correctamente.";
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['mensaje'] = "❌ Error al eliminar orden $orden_id: " . $e->getMessage();
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
        $hora_inicio = ($minuto_actual >= 50) ? ($hora_actual + 1) : $hora_actual;
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
