<?php
include "db.php"; // conexión PDO en variable $pdo

if (!isset($_GET['id'])) {
    header("Location: ingreso_piezas.php");
    exit();
}

$pieza_id = $_GET['id'];

// --- Actualizar pieza y atributos ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == "editar") {
    try {
        $pdo->beginTransaction();

        $nombre = trim($_POST['nombre']);
        $tipo = trim($_POST['tipo']);
        $descripcion = trim($_POST['descripcion']);

        // Actualizar datos de la pieza
        $stmt = $pdo->prepare("UPDATE piezas SET nombre = ?, tipo = ?, descripcion = ? WHERE id = ?");
        $stmt->execute([$nombre, $tipo, $descripcion, $pieza_id]);

        // Actualizar atributos existentes
        if (!empty($_POST['atributo_id'])) {
            foreach ($_POST['atributo_id'] as $i => $attr_id) {
                $nombre_attr = $_POST['atributo_nombre'][$i];
                $unidad = $_POST['atributo_unidad'][$i];
                $valor = is_numeric($_POST['atributo_valor'][$i]) ? $_POST['atributo_valor'][$i] : 0;
                $tolerancia = is_numeric($_POST['atributo_tolerancia'][$i]) ? $_POST['atributo_tolerancia'][$i] : 0;

                $stmt = $pdo->prepare("UPDATE atributos_pieza 
                                       SET nombre_atributo = ?, unidad = ?, valor_predeterminado = ?, tolerancia = ?
                                       WHERE id = ?");
                $stmt->execute([$nombre_attr, $unidad, $valor, $tolerancia, $attr_id]);
            }
        }

        // Agregar nuevos atributos
        if (!empty($_POST['atributo_nombre_new'])) {
            $stmt = $pdo->prepare("
                INSERT INTO atributos_pieza (pieza_id, nombre_atributo, unidad, valor_predeterminado, tolerancia)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($_POST['atributo_nombre_new'] as $i => $nombre_atributo) {
                $unidad = $_POST['atributo_unidad_new'][$i];
                $valor = is_numeric($_POST['atributo_valor_new'][$i]) ? $_POST['atributo_valor_new'][$i] : 0;
                $tolerancia = is_numeric($_POST['atributo_tolerancia_new'][$i]) ? $_POST['atributo_tolerancia_new'][$i] : 0;
                $stmt->execute([$pieza_id, $nombre_atributo, $unidad, $valor, $tolerancia]);
            }
        }

        // Eliminar atributos marcados
        // Eliminar atributos marcados
        if (!empty($_POST['atributo_eliminar'])) {
            $in = str_repeat('?,', count($_POST['atributo_eliminar']) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM atributos_pieza WHERE id IN ($in)");
            $stmt->execute($_POST['atributo_eliminar']);
        }


        $pdo->commit();
        $mensaje = "<div class='alert alert-success'>✅ Pieza y atributos actualizados correctamente.</div>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='alert alert-danger'>❌ Error al actualizar: " . $e->getMessage() . "</div>";
    }
}

// --- Obtener datos de la pieza ---
$stmt = $pdo->prepare("SELECT * FROM piezas WHERE id = ?");
$stmt->execute([$pieza_id]);
$pieza = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pieza) {
    header("Location: ingreso_piezas.php");
    exit();
}

// --- Obtener atributos ---
$stmt = $pdo->prepare("SELECT * FROM atributos_pieza WHERE pieza_id = ?");
$stmt->execute([$pieza_id]);
$atributos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Editar Pieza</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <?php include 'sidebar.php'; ?>

    <div class="content p-4">
        <h2>✏️ Editar Pieza</h2>
        <?= $mensaje ?? '' ?>

        <form method="POST" class="card p-4 shadow-sm">
            <input type="hidden" name="accion" value="editar">

            <div class="mb-3">
                <label class="form-label">Nombre</label>
                <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($pieza['nombre']) ?>"
                    required>
            </div>
            <div class="mb-3">
                <label class="form-label">Tipo</label>
                <input type="text" name="tipo" class="form-control" value="<?= htmlspecialchars($pieza['tipo']) ?>"
                    required>
            </div>
            <div class="mb-3">
                <label class="form-label">Descripción</label>
                <input type="text" name="descripcion" class="form-control"
                    value="<?= htmlspecialchars($pieza['descripcion']) ?>">
            </div>

            <hr>
            <h4>⚙️ Atributos Existentes</h4>
            <div id="atributos_existentes">
                <?php foreach ($atributos as $a): ?>
                    <div class="row g-3 mb-3 atributo-item" data-id="<?= $a['id'] ?>">
                        <input type="hidden" name="atributo_id[]" value="<?= $a['id'] ?>">
                        <div class="col-md-3">
                            <input type="text" name="atributo_nombre[]" class="form-control"
                                value="<?= htmlspecialchars($a['nombre_atributo']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="atributo_unidad[]" class="form-control"
                                value="<?= htmlspecialchars($a['unidad']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="any" name="atributo_valor[]" class="form-control"
                                value="<?= $a['valor_predeterminado'] ?>" required>
                        </div>
                        <div class="col-md-2">
                            <input type="number" step="any" name="atributo_tolerancia[]" class="form-control"
                                value="<?= $a['tolerancia'] ?>" required>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-danger remove-attr">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr>
            <h4>➕ Agregar Nuevos Atributos</h4>
            <div id="atributos_nuevos"></div>
            <button type="button" id="addAttrNew" class="btn btn-secondary mb-3">➕ Agregar Atributo</button>

            <div>
                <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
                <a href="ingreso_piezas.php" class="btn btn-secondary">↩️ Volver</a>
            </div>
        </form>
    </div>

    <script>
        // --- Agregar nuevo atributo ---
        document.getElementById("addAttrNew").addEventListener("click", function () {
            let div = document.createElement("div");
            div.classList.add("row", "g-3", "mb-3", "atributo-item");
            div.innerHTML = `
        <div class="col-md-3">
            <input type="text" name="atributo_nombre_new[]" class="form-control" placeholder="Nombre atributo" required>
        </div>
        <div class="col-md-2">
            <input type="text" name="atributo_unidad_new[]" class="form-control" placeholder="Unidad (ej: mm)" required>
        </div>
        <div class="col-md-3">
            <input type="number" step="any" name="atributo_valor_new[]" class="form-control" placeholder="Valor predeterminado" required>
        </div>
        <div class="col-md-2">
            <input type="number" step="any" name="atributo_tolerancia_new[]" class="form-control" placeholder="Tolerancia" required>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-danger remove-attr">🗑️</button>
        </div>`;
            document.getElementById("atributos_nuevos").appendChild(div);
        });

        // --- Eliminar atributo de la interfaz ---
        // --- Eliminar atributo de la interfaz y marcar para borrado ---
        document.addEventListener("click", function (e) {
            if (e.target.classList.contains("remove-attr")) {
                let row = e.target.closest(".atributo-item");
                let idInput = row.querySelector("input[name='atributo_id[]']");
                if (idInput) {
                    // Crear input oculto para enviar al POST
                    let input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "atributo_eliminar[]";
                    input.value = idInput.value;
                    document.querySelector("form").appendChild(input);
                }
                row.remove(); // Quitar de la UI
            }
        });

    </script>
</body>

</html>