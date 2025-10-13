<?php
// ingreso_prensas.php
// Requiere: db.php debe definir $pdo (PDO)
session_start();
require 'db.php';

// Verificar que $pdo exista y sea PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("<div class='content'><div class='alert alert-danger mt-3'>❌ No se encontró una conexión PDO en db.php. Asegúrate de definir <code>\$pdo = new PDO(...)</code>.</div></div>");
}

// Helper: flash message (guardar en session para mostrar después de redirect)
function set_flash($html) {
    $_SESSION['flash'] = $html;
}
function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ----- Procesar ELIMINAR por GET (seguro via confirm JS) -----
if (isset($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];
    if ($idEliminar > 0) {
        $stmt = $pdo->prepare("DELETE FROM prensas WHERE id = ?");
        $ok = $stmt->execute([$idEliminar]);
        if ($ok) {
            set_flash("<div class='alert alert-success'>🗑️ Prensa eliminada correctamente.</div>");
        } else {
            set_flash("<div class='alert alert-danger'>❌ Error al eliminar la prensa.</div>");
        }
    }
    header('Location: ingreso_prensas.php');
    exit();
}

// ----- Procesar INSERT / UPDATE por POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($nombre === '') {
        set_flash("<div class='alert alert-warning'>⚠️ El nombre de la prensa es obligatorio.</div>");
        header('Location: ingreso_prensas.php' . ($id? "?editar={$id}": ""));
        exit();
    }

    if ($id > 0) {
        // UPDATE
        $stmt = $pdo->prepare("UPDATE prensas SET nombre = ?, descripcion = ? WHERE id = ?");
        $ok = $stmt->execute([$nombre, $descripcion, $id]);
        if ($ok) {
            set_flash("<div class='alert alert-info'>✏️ Prensa actualizada correctamente.</div>");
        } else {
            set_flash("<div class='alert alert-danger'>❌ Error al actualizar la prensa.</div>");
        }
        header('Location: ingreso_prensas.php');
        exit();
    } else {
        // INSERT
        $stmt = $pdo->prepare("INSERT INTO prensas (nombre, descripcion, creado_at) VALUES (?, ?, NOW())");
        $ok = $stmt->execute([$nombre, $descripcion]);
        if ($ok) {
            set_flash("<div class='alert alert-success'>✅ Prensa registrada correctamente.</div>");
        } else {
            set_flash("<div class='alert alert-danger'>❌ Error al registrar la prensa.</div>");
        }
        header('Location: ingreso_prensas.php');
        exit();
    }
}

// ----- Si viene ?editar=ID traer registro para pre-llenar el formulario -----
$editar = null;
if (isset($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    if ($idEditar > 0) {
        $stmt = $pdo->prepare("SELECT id, nombre, descripcion FROM prensas WHERE id = ?");
        $stmt->execute([$idEditar]);
        $editar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        // si no se encuentra, $editar queda null y se mostrará formulario en blanco
    }
}

// ----- Listado de prensas (devuelve [] si no hay filas) -----
$stmt = $pdo->query("SELECT id, nombre, descripcion, creado_at FROM prensas ORDER BY id DESC");
$prensas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener flash si existe
$flashHtml = get_flash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ingreso de Prensas</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .content { margin-left: 220px; padding: 20px; } /* coincide con tu sidebar */
        .table-actions .btn { margin-right: 6px; }
    </style>
    <script>
        function confirmarEliminar(nombre, id) {
            if (confirm('¿Eliminar la prensa \"' + nombre + '\" ?')) {
                // redirige a la misma página con ?eliminar=id (será procesado por PHP)
                window.location.href = 'ingreso_prensas.php?eliminar=' + id;
            }
        }
    </script>
</head>
<body class="bg-light">
<?php include 'sidebar.php'; ?>

<div class="content">
    <?php if ($flashHtml) echo $flashHtml; ?>

    <h2 class="mb-4"><?= $editar ? "✏️ Editar Prensa" : "➕ Registrar Nueva Prensa" ?></h2>

    <form method="POST" class="card p-4 shadow-sm mb-5" novalidate>
        <?php if ($editar): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($editar['id']) ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label class="form-label">Nombre de la prensa</label>
            <input type="text" name="nombre" class="form-control" required value="<?= htmlspecialchars($editar['nombre'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($editar['descripcion'] ?? '') ?></textarea>
        </div>

        <div>
            <button type="submit" class="btn btn-primary"><?= $editar ? "💾 Actualizar Prensa" : "💾 Guardar Prensa" ?></button>
            <?php if ($editar): ?>
                <a href="ingreso_prensas.php" class="btn btn-secondary ms-2">↩️ Cancelar</a>
            <?php endif; ?>
        </div>
    </form>

    <h3 class="mb-3">📋 Prensas Registradas</h3>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:60px">ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th style="width:180px">Fecha de Creación</th>
                            <th style="width:140px" class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($prensas)): ?>
                            <?php foreach ($prensas as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['id']) ?></td>
                                    <td><?= htmlspecialchars($r['nombre']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($r['descripcion'])) ?></td>
                                    <td><?= htmlspecialchars($r['creado_at']) ?></td>
                                    <td class="text-center table-actions">
                                        <a href="ingreso_prensas.php?editar=<?= $r['id'] ?>" class="btn btn-sm btn-warning">✏️ Editar</a>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmarEliminar('<?= addslashes(htmlspecialchars($r['nombre'])) ?>', <?= $r['id'] ?>)">🗑️ Eliminar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No hay prensas registradas aún.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
