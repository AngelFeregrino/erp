<?php
session_start();
if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}
require 'db.php';

// Filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_orden  = $_GET['orden'] ?? '';
$filtro_lote   = $_GET['lote'] ?? '';
$fecha_inicio  = $_GET['fecha_inicio'] ?? '';
$fecha_fin     = $_GET['fecha_fin'] ?? '';

$query = "SELECT op.*, p.nombre AS pieza, pr.nombre AS prensa
          FROM ordenes_produccion op
          JOIN piezas p ON p.id = op.pieza_id
          JOIN prensas pr ON pr.id = op.prensa_id
          WHERE 1=1";

$params = [];

// Filtros existentes
if ($filtro_estado !== '') {
    $query .= " AND op.estado = ?";
    $params[] = $filtro_estado;
}
if ($filtro_orden !== '') {
    $query .= " AND op.numero_orden LIKE ?";
    $params[] = "%$filtro_orden%";
}
if ($filtro_lote !== '') {
    $query .= " AND op.numero_lote LIKE ?";
    $params[] = "%$filtro_lote%";
}

// ✅ NUEVO FILTRO POR RANGO DE FECHAS
if ($fecha_inicio !== '' && $fecha_fin !== '') {
    $query .= " AND DATE(op.fecha_inicio) BETWEEN ? AND ?";
    $params[] = $fecha_inicio;
    $params[] = $fecha_fin;
}

$query .= " ORDER BY op.fecha_inicio DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cerrar Orden</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="col-md-10 content bg-light">
    <h1 class="h3 mb-4">🗂 Cerrar Órdenes de Producción</h1>

    <!-- Filtros -->
    <form class="row g-3 mb-4" method="get">
        <div class="col-md-2">
            <label class="form-label">Estado</label>
            <select name="estado" class="form-select">
                <option value="">-- Todos --</option>
                <option value="abierta" <?= $filtro_estado==='abierta'?'selected':'' ?>>Abierta</option>
                <option value="cerrada" <?= $filtro_estado==='cerrada'?'selected':'' ?>>Cerrada</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Número de Orden</label>
            <input type="text" name="orden" value="<?= htmlspecialchars($filtro_orden) ?>" class="form-control">
        </div>
        <div class="col-md-2">
            <label class="form-label">Número de Lote</label>
            <input type="text" name="lote" value="<?= htmlspecialchars($filtro_lote) ?>" class="form-control">
        </div>

        <!-- ✅ NUEVOS CAMPOS DE FECHA -->
        <div class="col-md-2">
            <label class="form-label">Desde</label>
            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" class="form-control">
        </div>
        <div class="col-md-2">
            <label class="form-label">Hasta</label>
            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" class="form-control">
        </div>

        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">🔍 Filtrar</button>
        </div>
    </form>

    <!-- Tabla -->
    <table class="table table-bordered table-striped align-middle">
        <thead class="table-dark text-center">
            <tr>
                <th>ID</th>
                <th>Número Orden</th>
                <th>Lote</th>
                <th>Pieza</th>
                <th>Prensa</th>
                <th>Cantidad</th>
                <th>Estado</th>
                <th>Operador Asignado</th>
                <th>Equipo Asignado</th>
                <th>Firma Responsable</th>
                <th>Fecha Inicio</th>
                <th>Fecha Cierre</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($ordenes)): ?>
            <tr><td colspan="13" class="text-center">⚠️ No hay órdenes encontradas.</td></tr>
        <?php else: ?>
            <?php foreach ($ordenes as $o): ?>
                <tr data-id="<?= $o['id'] ?>">
                    <td><?= $o['id'] ?></td>
                    <td><?= htmlspecialchars($o['numero_orden']) ?></td>
                    <td><?= htmlspecialchars($o['numero_lote']) ?></td>
                    <td><?= htmlspecialchars($o['pieza']) ?></td>
                    <td><?= htmlspecialchars($o['prensa']) ?></td>
                    <td><?= htmlspecialchars($o['cantidad_total_lote']) ?></td>
                    <td class="estado"><?= ucfirst($o['estado']) ?></td>
                    <td><?= htmlspecialchars($o['operador_asignado']) ?></td>
                    <td class="equipo"><?= htmlspecialchars($o['equipo_asignado'] ?? '-') ?></td>
                    <td class="firma"><?= htmlspecialchars($o['firma_responsable'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($o['fecha_inicio']) ?></td>
                    <td class="fecha_cierre"><?= $o['fecha_cierre'] ?? '-' ?></td>
                    <td class="text-center">
                        <?php if ($o['estado'] === 'abierta'): ?>
                            <form class="cerrar-form d-flex flex-column gap-1">
                                <input type="text" name="equipo_asignado" placeholder="Equipo asignado" class="form-control" required>
                                <input type="text" name="firma_responsable" placeholder="Firma responsable" class="form-control" required>
                                <button type="submit" class="btn btn-danger btn-sm mt-1">Cerrar</button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-success">✔ Cerrada</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".cerrar-form").forEach(form => {
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const row = form.closest("tr");
            const ordenId = row.dataset.id;
            const formData = new FormData(form);
            formData.append("orden_id", ordenId);

            try {
                const response = await fetch("ajax_cerrar_orden.php", {
                    method: "POST",
                    body: formData
                });

                const data = await response.json();
                if (data.success) {
                    row.querySelector(".estado").textContent = "Cerrada";
                    row.querySelector(".fecha_cierre").textContent = data.fecha_cierre;
                    row.querySelector(".equipo").textContent = data.equipo_asignado;
                    row.querySelector(".firma").textContent = data.firma_responsable;
                    row.querySelector("td:last-child").innerHTML = '<span class="badge bg-success">✔ Cerrada</span>';
                } else {
                    alert("⚠ Error: " + (data.error || "No se pudo cerrar la orden"));
                }
            } catch (err) {
                console.error(err);
                alert("Error en la conexión con el servidor");
            }
        });
    });
});
</script>

</body>
</html>
