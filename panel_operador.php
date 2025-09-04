<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    header('Location: login_simple.php');
    exit();
}

require 'db.php';

$hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$nombre = $_SESSION['nombre'] ?: $_SESSION['usuario'];

// 1. Obtener capturas pendientes hoy
$stmt = $pdo->prepare("SELECT ch.id AS captura_id, ch.hora_inicio, ch.hora_fin, ch.estado,
                              pr.nombre AS prensa, pi.nombre AS pieza, ch.orden_id, ch.prensa_id, ch.pieza_id
                       FROM capturas_hora ch
                       JOIN prensas pr ON pr.id = ch.prensa_id
                       JOIN piezas pi ON pi.id = ch.pieza_id
                       WHERE ch.fecha = ?");
$stmt->execute([$hoy]);
$capturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Procesar captura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['capturar'])) {
    $captura_id = $_POST['captura_id'];
    $cantidad   = $_POST['cantidad'];
    $obs        = $_POST['observaciones'];
    $firma      = $_POST['firma'];

    // Actualizar captura
    $stmt = $pdo->prepare("UPDATE capturas_hora 
                           SET cantidad=?, observaciones_op=?, firma_operador=?, estado='cerrada' 
                           WHERE id=?");
    $stmt->execute([$cantidad, $obs, $firma, $captura_id]);

    // Guardar valores técnicos
    if (!empty($_POST['atributo'])) {
        foreach ($_POST['atributo'] as $atributo_id => $valor) {
            $stmt2 = $pdo->prepare("INSERT INTO valores_hora (captura_id, atributo_pieza_id, valor)
                                    VALUES (?, ?, ?)");
            $stmt2->execute([$captura_id, $atributo_id, $valor]);
        }
    }

    $mensaje = "✅ Captura registrada correctamente.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Operador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .estado-box {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            margin-left: 8px;
        }
        .estado-pendiente { background: orange; }
        .estado-cerrada { background: green; }
        .valido { border: 2px solid green; }
        .invalido { border: 2px solid red; }
    </style>
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">👷 Panel Operador — Hola, <?= htmlspecialchars($nombre) ?></h1>
        <a href="logout.php" class="btn btn-danger">Cerrar sesión</a>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <h4 class="mb-3">Capturas programadas (<?= $hoy ?>)</h4>

    <?php if (empty($capturas)): ?>
        <div class="alert alert-warning">No hay capturas programadas hoy.</div>
    <?php else: ?>
        <?php foreach ($capturas as $c): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                <?= htmlspecialchars($c['prensa']) ?> — <?= htmlspecialchars($c['pieza']) ?>
                <span class="estado-box estado-<?= $c['estado'] ?>"></span>
                <small>(<?= substr($c['hora_inicio'],0,5) ?> - <?= substr($c['hora_fin'],0,5) ?>)</small>
            </div>
            <div class="card-body">
                <?php if ($c['estado'] === 'cerrada'): ?>
                    <div class="alert alert-success">✅ Esta franja ya fue capturada.</div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="captura_id" value="<?= $c['captura_id'] ?>">

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Cantidad</label>
                                <input type="number" name="cantidad" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Observaciones</label>
                                <input type="text" name="observaciones" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Firma operador</label>
                                <input type="text" name="firma" class="form-control" required>
                            </div>
                        </div>

                        <h5 class="mt-3">Datos técnicos</h5>
                        <div class="row">
                            <?php
                            $stmt3 = $pdo->prepare("SELECT id, nombre_atributo, unidad, valor_predeterminado, tolerancia
                                                    FROM atributos_pieza
                                                    WHERE pieza_id = ?");
                            $stmt3->execute([$c['pieza_id']]);
                            $atributos = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($atributos as $a): 
                                $pred = htmlspecialchars($a['valor_predeterminado']);
                                $tol  = (float)$a['tolerancia'];
                            ?>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">
                                        <?= htmlspecialchars($a['nombre_atributo']) ?> (<?= htmlspecialchars($a['unidad']) ?>)
                                    </label>
                                    <input type="number" step="0.01"
                                           name="atributo[<?= $a['id'] ?>]"
                                           class="form-control atributo-input"
                                           value="<?= $pred ?>"
                                           data-pred="<?= $pred ?>" data-tol="<?= $tol ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" name="capturar" class="btn btn-success">Guardar captura</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.atributo-input').forEach(inp => {
    inp.addEventListener('input', () => {
        const pred = parseFloat(inp.dataset.pred);
        const tol  = parseFloat(inp.dataset.tol);
        const val  = parseFloat(inp.value);

        if (!isNaN(val)) {
            if (val >= (pred - tol) && val <= (pred + tol)) {
                inp.classList.add('valido');
                inp.classList.remove('invalido');
            } else {
                inp.classList.add('invalido');
                inp.classList.remove('valido');
            }
        } else {
            inp.classList.remove('valido', 'invalido');
        }
    });
});
</script>
</body>
</html>
