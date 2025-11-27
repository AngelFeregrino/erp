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

$query = "SELECT op.*, p.nombre AS pieza, pr.nombre AS prensa,
                 COALESCE(op.cantidad_inicio, 0) AS cantidad_inicio,
                 op.cantidad_final,
                 COALESCE(op.total_producida, NULL) AS total_producida
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
    $query .= " AND p.codigo LIKE ?";
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

// Calcular total de cantidades: sumamos total_producida cuando exista, si no usar cantidad_total_lote
$total_cantidad = 0;
if (!empty($ordenes)) {
    foreach ($ordenes as $o) {
        if ($o['total_producida'] !== null && $o['total_producida'] !== '') {
            $total_cantidad += (int)$o['total_producida'];
        } else {
            $total_cantidad += (int)$o['cantidad_total_lote'];
        }
    }
}
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

        <div class="text-end mb-2 me-2">
            <span class="badge bg-primary fs-6 px-3 py-2 shadow-sm">
                <strong>Cantidad total:</strong> <?= number_format($total_cantidad) ?>
            </span>
        </div>

        <!-- Filtros -->
        <form class="row g-3 mb-4" method="get">
            <div class="col-md-2">
                <label class="form-label">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">-- Todos --</option>
                    <option value="abierta" <?= $filtro_estado === 'abierta' ? 'selected' : '' ?>>Abierta</option>
                    <option value="cerrada" <?= $filtro_estado === 'cerrada' ? 'selected' : '' ?>>Cerrada</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Número de Orden</label>
                <input type="text" name="orden" value="<?= htmlspecialchars($filtro_orden) ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Número de Parte</label>
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
                    <th>Inicio</th>
                    <th>Final</th>
                    <th>Total</th>
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
                    <tr>
                        <td colspan="15" class="text-center">⚠️ No hay órdenes encontradas.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ordenes as $o): 
                        // determinar valores a mostrar
                        $cant_inicio = (int)$o['cantidad_inicio'];
                        $cant_final = ($o['cantidad_final'] !== null && $o['cantidad_final'] !== '') ? (int)$o['cantidad_final'] : null;
                        // si total_producida está presente úsalo; si no, intenta calcular si hay final; si no, fallback a cantidad_total_lote
                        if ($o['total_producida'] !== null && $o['total_producida'] !== '') {
                            $cant_total = (int)$o['total_producida'];
                        } elseif ($cant_final !== null) {
                            $cant_total = $cant_final - $cant_inicio;
                            if ($cant_total < 0) $cant_total = 0;
                        } else {
                            $cant_total = (int)$o['cantidad_total_lote'];
                        }
                    ?>
                        <tr data-id="<?= $o['id'] ?>">
                            <td><?= $o['id'] ?></td>
                            <td><?= htmlspecialchars($o['numero_orden']) ?></td>
                            <td><?= htmlspecialchars($o['numero_lote']) ?></td>
                            <td><?= htmlspecialchars($o['pieza']) ?></td>
                            <td><?= htmlspecialchars($o['prensa']) ?></td>

                            <td class="text-end cant-inicio"><?= number_format($cant_inicio) ?></td>
                            <td class="text-end cant-final"><?= $cant_final !== null ? number_format($cant_final) : '-' ?></td>
                            <td class="text-end cant-total"><?= number_format($cant_total) ?></td>

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
                                        <input type="number" name="cantidad_final" placeholder="Cantidad final (ej: 400000)" class="form-control" min="0" required>
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
                            // actualizar estado y campos visibles
                            row.querySelector(".estado").textContent = "Cerrada";
                            row.querySelector(".fecha_cierre").textContent = data.fecha_cierre;
                            row.querySelector(".equipo").textContent = data.equipo_asignado;
                            row.querySelector(".firma").textContent = data.firma_responsable;

                            // actualizar inicio, final y total (si existen)
                            const tdInicio = row.querySelector(".cant-inicio");
                            const tdFinal = row.querySelector(".cant-final");
                            const tdTotal = row.querySelector(".cant-total");

                            if (tdInicio && data.cantidad_inicio !== undefined) {
                                tdInicio.textContent = Number(data.cantidad_inicio).toLocaleString();
                            }
                            if (tdFinal && data.cantidad_final !== undefined) {
                                tdFinal.textContent = Number(data.cantidad_final).toLocaleString();
                            }
                            if (tdTotal && data.total_producida !== undefined) {
                                tdTotal.textContent = Number(data.total_producida).toLocaleString();
                            } else if (tdTotal && data.cantidad_final !== undefined && data.cantidad_inicio !== undefined) {
                                const total = Number(data.cantidad_final) - Number(data.cantidad_inicio);
                                tdTotal.textContent = (total >= 0 ? total : 0).toLocaleString();
                            }

                            // reemplazar formulario por badge cerrada
                            row.querySelector("td:last-child").innerHTML = '<span class="badge bg-success">✔ Cerrada</span>';

                            // actualizar contador superior (Cantidad total)
                            // recalcular sumatorio simple en el DOM
                            let suma = 0;
                            document.querySelectorAll(".cant-total").forEach(td => {
                                const txt = td.textContent.replace(/,/g,'').trim();
                                const val = parseInt(txt) || 0;
                                suma += val;
                            });
                            document.querySelector('.badge.bg-primary strong').parentNode.innerHTML =
                                '<strong>Cantidad total:</strong> ' + suma.toLocaleString();
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
