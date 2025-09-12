<?php
include "db.php"; // tu conexión a MySQL

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Datos de la pieza
    $codigo = $_POST['codigo'];
    $nombre = $_POST['nombre'];
    $tipo = $_POST['tipo'];
    $descripcion = $_POST['descripcion'];

    // Insertar la pieza
    $stmt = $conn->prepare("INSERT INTO piezas (codigo, nombre, tipo, descripcion, creado_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssss", $codigo, $nombre, $tipo, $descripcion);
    $stmt->execute();
    $pieza_id = $stmt->insert_id;
    $stmt->close();

    // Atributos dinámicos
    if (!empty($_POST['atributo_nombre'])) {
        foreach ($_POST['atributo_nombre'] as $i => $nombre_atributo) {
            $unidad = $_POST['atributo_unidad'][$i];
            $valor_predeterminado = $_POST['atributo_valor'][$i];
            $tolerancia = $_POST['atributo_tolerancia'][$i];

            $stmt = $conn->prepare("INSERT INTO atributos_pieza (pieza_id, nombre_atributo, unidad, valor_predeterminado, tolerancia) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssd", $pieza_id, $nombre_atributo, $unidad, $valor_predeterminado, $tolerancia);
            $stmt->execute();
            $stmt->close();
        }
    }

    echo "<div class='content'><div class='alert alert-success'>✅ Pieza y atributos guardados correctamente.</div></div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes de Producción</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'sidebar.php'; ?>
<div class="content">
    <h2>➕ Registrar Nueva Pieza</h2>
    <form method="POST" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label class="form-label">Código</label>
            <input type="text" name="codigo" class="form-control" required>
        </div>
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
</div>

<script>
// Permitir agregar atributos dinámicamente
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

// Quitar atributo
document.addEventListener("click", function(e) {
    if (e.target.classList.contains("remove-attr")) {
        e.target.closest(".atributo-item").remove();
    }
});
</script>
