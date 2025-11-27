<?php
session_start();
if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}
require 'db.php';

// --- Insertar o actualizar valor esperado manualmente ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pieza_id']) && isset($_POST['esperado'])) {
    $pieza_id = $_POST['pieza_id'];
    $esperado = $_POST['esperado'];
    $fecha = date('Y-m-d');

    // Ver si ya existe registro de hoy
    $check = $pdo->prepare("SELECT * FROM rendimientos WHERE pieza_id = ? AND fecha = ?");
    $check->execute([$pieza_id, $fecha]);
    $row = $check->fetch();

    if ($row) {
        $rendimiento = $row['producido'] > 0 ? ($row['producido'] / $esperado) * 100 : 0;
        $stmt = $pdo->prepare("UPDATE rendimientos SET esperado = ?, rendimiento = ? WHERE id = ?");
        $stmt->execute([$esperado, $rendimiento, $row['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO rendimientos (pieza_id, fecha, esperado) VALUES (?, ?, ?)");
        $stmt->execute([$pieza_id, $fecha, $esperado]);
    }
    header("Location: rendimiento.php");
    exit();
}

// --- Actualización vía AJAX (botón editar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar') {
    $id = $_POST['id'];
    $esperado = $_POST['esperado'];

    $stmt = $pdo->prepare("SELECT producido FROM rendimientos WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    if ($r) {
        $rendimiento = $esperado > 0 ? ($r['producido'] / $esperado) * 100 : 0;
        $upd = $pdo->prepare("UPDATE rendimientos SET esperado = ?, rendimiento = ? WHERE id = ?");
        $upd->execute([$esperado, $rendimiento, $id]);
        echo json_encode(['success' => true, 'rendimiento' => round($rendimiento, 2)]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// --- Datos ---
$piezas = $pdo->query("SELECT * FROM piezas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
// --- Filtro de fecha ---
$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');

// --- Consultar rendimientos según fecha seleccionada ---
$stmt = $pdo->prepare("SELECT r.*, p.nombre AS pieza 
                       FROM rendimientos r 
                       JOIN piezas p ON p.id = r.pieza_id 
                       WHERE r.fecha = ?
                       ORDER BY r.fecha DESC");
$stmt->execute([$fechaFiltro]);
$rendimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Rendimientos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .editando {
            background-color: #fff8e1 !important;
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="col-md-10 content bg-light p-4">
        <h2 class="mb-4">📈 Rendimientos por Pieza</h2>

        
        <!-- Filtro por fecha -->
        <form method="get" class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">📅 Filtrar por fecha</label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($fechaFiltro) ?>" class="form-control" onchange="this.form.submit()">
            </div>
        </form>


        <!-- Tabla de rendimientos -->
        <table class="table table-bordered table-striped text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Fecha</th>
                    <th>Pieza</th>
                    <th>Esperado</th>
                    <th>Producido</th>
                    <th>Rendimiento (%)</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rendimientos)): ?>
                    <tr>
                        <td colspan="6">Sin registros de rendimientos.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rendimientos as $r): ?>
                        <tr id="row<?= $r['id'] ?>">
                            <td><?= htmlspecialchars($r['fecha']) ?></td>
                            <td><?= htmlspecialchars($r['pieza']) ?></td>
                            <td class="esperado"><?= htmlspecialchars($r['esperado']) ?></td>
                            <td><?= htmlspecialchars($r['producido']) ?></td>
                            <td class="rendimiento"><?= htmlspecialchars(round($r['rendimiento'], 2)) ?>%</td>
                            <td>
                                <button class="btn btn-warning btn-sm editar-btn" data-id="<?= $r['id'] ?>">✏️ Editar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll(".editar-btn").forEach(btn => {
                btn.addEventListener("click", () => {
                    const id = btn.dataset.id;
                    const row = document.getElementById("row" + id);
                    const celdaEsperado = row.querySelector(".esperado");

                    // Si ya estamos editando esta fila, no hacer nada
                    if (row.classList.contains("editando")) return;

                    // Valor actual directo de la celda (incluso si es 0)
                    const valorActual = parseFloat(celdaEsperado.textContent.replace(/\s|,/g, '').trim()) || 0;

                    // Cambiar a modo edición
                    row.classList.add("editando");
                    celdaEsperado.innerHTML = `<input type="number" class="form-control form-control-sm nuevoEsperado" value="${valorActual}" step="any" min="0.01">`;
                    btn.textContent = "💾 Guardar";
                    btn.classList.remove("btn-warning");
                    btn.classList.add("btn-success");

                    // Listener de guardar (solo una vez)
                    const guardarListener = async () => {
                        let nuevoEsperado = row.querySelector(".nuevoEsperado").value.trim();

                        // Reemplazar comas por punto y quitar puntos de miles
                        nuevoEsperado = nuevoEsperado.replace(/\./g, '').replace(/,/g, '.');
                        nuevoEsperado = parseFloat(nuevoEsperado);

                        if (isNaN(nuevoEsperado) || nuevoEsperado <= 0) {
                            alert("⚠ Ingresa un número válido mayor que 0");
                            return;
                        }

                        // Enviar AJAX
                        const formData = new FormData();
                        formData.append("action", "editar");
                        formData.append("id", id);
                        formData.append("esperado", nuevoEsperado);

                        try {
                            const response = await fetch("rendimiento.php", {
                                method: "POST",
                                body: formData
                            });
                            const data = await response.json();

                            if (data.success) {
                                celdaEsperado.textContent = nuevoEsperado;
                                row.querySelector(".rendimiento").textContent = data.rendimiento.toFixed(2) + "%";
                                row.classList.remove("editando");
                                btn.textContent = "✏️ Editar";
                                btn.classList.remove("btn-success");
                                btn.classList.add("btn-warning");
                            } else {
                                alert("Error al actualizar rendimiento");
                            }
                        } catch (err) {
                            console.error(err);
                            alert("Error de conexión con el servidor");
                        }
                    };

                    btn.addEventListener("click", guardarListener, {
                        once: true
                    });
                });
            });
        });
    </script>

</body>

</html>