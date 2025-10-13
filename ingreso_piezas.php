<?php
include "db.php"; // conexión PDO en variable $pdo

// --- Registrar pieza y atributos ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == "crear") {
    try {
        $pdo->beginTransaction();

        $nombre = trim($_POST['nombre']);
        $tipo = trim($_POST['tipo']);
        $descripcion = trim($_POST['descripcion']);

        // Insertar pieza sin código
        $stmt = $pdo->prepare("INSERT INTO piezas (codigo, nombre, tipo, descripcion, creado_at) VALUES ('', ?, ?, ?, NOW())");
        $stmt->execute([$nombre, $tipo, $descripcion]);
        $pieza_id = $pdo->lastInsertId();

        // Generar código
        $codigo = strtoupper($pieza_id . substr($nombre, 0, 3) . substr($tipo, 0, 3));
        $stmt = $pdo->prepare("UPDATE piezas SET codigo = ? WHERE id = ?");
        $stmt->execute([$codigo, $pieza_id]);

        // Insertar atributos
        if (!empty($_POST['atributo_nombre'])) {
            $stmt = $pdo->prepare("
                INSERT INTO atributos_pieza (pieza_id, nombre_atributo, unidad, valor_predeterminado, tolerancia)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($_POST['atributo_nombre'] as $i => $nombre_atributo) {
                $unidad = $_POST['atributo_unidad'][$i];
                $valor = is_numeric($_POST['atributo_valor'][$i]) ? $_POST['atributo_valor'][$i] : 0;
                $tolerancia = is_numeric($_POST['atributo_tolerancia'][$i]) ? $_POST['atributo_tolerancia'][$i] : 0;
                $stmt->execute([$pieza_id, $nombre_atributo, $unidad, $valor, $tolerancia]);
            }
        }

        $pdo->commit();
        $mensaje = "<div class='alert alert-success'>✅ Pieza registrada con código <b>$codigo</b>.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='alert alert-danger'>❌ Error al registrar la pieza: " . $e->getMessage() . "</div>";
    }
}

// --- Eliminar pieza y sus atributos ---
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    $pdo->prepare("DELETE FROM atributos_pieza WHERE pieza_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM piezas WHERE id = ?")->execute([$id]);
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// --- Obtener piezas y sus atributos ---
$stmt = $pdo->query("SELECT * FROM piezas ORDER BY id DESC");
$piezas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Piezas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'sidebar.php'; ?>

<div class="content p-4">
    <h2>➕ Registrar Nueva Pieza</h2>
    <?= $mensaje ?? '' ?>

    <form method="POST" class="card p-4 shadow-sm mb-5">
        <input type="hidden" name="accion" value="crear">
        <div class="mb-3">
            <label class="form-label">Nombre</label>
            <input type="text" name="nombre" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Tipo</label>
            <input type="text" name="tipo" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Descripción</label>
            <input type="text" name="descripcion" class="form-control">
        </div>

        <hr>
        <h4>⚙️ Atributos de la Pieza</h4>
        <div id="atributos">
            <div class="row g-3 mb-3 atributo-item">
                <div class="col-md-3">
                    <input type="text" name="atributo_nombre[]" class="form-control" placeholder="Nombre atributo" required>
                </div>
                <div class="col-md-2">
                    <input type="text" name="atributo_unidad[]" class="form-control" placeholder="Unidad (ej: mm)" required>
                </div>
                <div class="col-md-3">
                    <input type="number" step="any" name="atributo_valor[]" class="form-control" placeholder="Valor predeterminado" required>
                </div>
                <div class="col-md-2">
                    <input type="number" step="any" name="atributo_tolerancia[]" class="form-control" placeholder="Tolerancia" required>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger remove-attr">❌</button>
                </div>
            </div>
        </div>
        <button type="button" id="addAttr" class="btn btn-secondary mb-3">➕ Agregar Atributo</button>
        <div>
            <button type="submit" class="btn btn-primary">💾 Guardar Pieza</button>
        </div>
    </form>

    <h3>📋 Piezas Registradas</h3>
    <?php if (empty($piezas)): ?>
        <div class="alert alert-info">No hay piezas registradas.</div>
    <?php else: ?>
        <table class="table table-striped table-bordered">
            <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Código</th>
                <th>Nombre</th>
                <th>Tipo</th>
                <th>Descripción</th>
                <th>Fecha</th>
                <th>Atributos</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($piezas as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= htmlspecialchars($p['codigo']) ?></td>
                    <td><?= htmlspecialchars($p['nombre']) ?></td>
                    <td><?= htmlspecialchars($p['tipo']) ?></td>
                    <td><?= htmlspecialchars($p['descripcion']) ?></td>
                    <td><?= $p['creado_at'] ?></td>
                    <td>
                        <?php
                        $stmt_attr = $pdo->prepare("SELECT * FROM atributos_pieza WHERE pieza_id = ?");
                        $stmt_attr->execute([$p['id']]);
                        $atributos = $stmt_attr->fetchAll(PDO::FETCH_ASSOC);
                        if ($atributos):
                            echo "<ul>";
                            foreach ($atributos as $a) {
                                echo "<li>{$a['nombre_atributo']} ({$a['unidad']}): {$a['valor_predeterminado']} ±{$a['tolerancia']}</li>";
                            }
                            echo "</ul>";
                        else:
                            echo "<small>No tiene atributos</small>";
                        endif;
                        ?>
                    </td>
                    <td>
                        <a href="editar_pieza.php?id=<?= $p['id'] ?>" class="btn btn-warning btn-sm">✏️ Editar</a>
                        <a href="?eliminar=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar esta pieza y sus atributos?')" class="btn btn-danger btn-sm">🗑️ Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
// Agregar atributo dinámico
document.getElementById("addAttr").addEventListener("click", function() {
    let div = document.createElement("div");
    div.classList.add("row", "g-3", "mb-3", "atributo-item");
    div.innerHTML = `
        <div class="col-md-3">
            <input type="text" name="atributo_nombre[]" class="form-control" placeholder="Nombre atributo" required>
        </div>
        <div class="col-md-2">
            <input type="text" name="atributo_unidad[]" class="form-control" placeholder="Unidad (ej: mm)" required>
        </div>
        <div class="col-md-3">
            <input type="number" step="any" name="atributo_valor[]" class="form-control" placeholder="Valor predeterminado" required>
        </div>
        <div class="col-md-2">
            <input type="number" step="any" name="atributo_tolerancia[]" class="form-control" placeholder="Tolerancia" required>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-danger remove-attr">❌</button>
        </div>`;
    document.getElementById("atributos").appendChild(div);
});

// Eliminar atributo dinámico
document.addEventListener("click", function(e) {
    if (e.target.classList.contains("remove-attr")) {
        e.target.closest(".atributo-item").remove();
    }
});
</script>
</body>
</html>
