<?php
session_start();
require 'db.php';

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    header('Location: login_simple.php');
    exit();
}

$hoy = date('Y-m-d');
$nombre = $_SESSION['nombre'] ?? $_SESSION['usuario'];
$prensa_id = $_GET['id'] ?? null;

if (!$prensa_id) {
    header('Location: panel_operador.php');
    exit();
}

// Obtener nombre de prensa
$stmt = $pdo->prepare("SELECT nombre FROM prensas WHERE id=?");
$stmt->execute([$prensa_id]);
$prensa = $stmt->fetchColumn();

// Obtener capturas del día de esa prensa
$stmt = $pdo->prepare("
    SELECT ch.id AS captura_id, ch.hora_inicio, ch.hora_fin, ch.estado,
           pr.nombre AS prensa, pi.nombre AS pieza, ch.orden_id, ch.pieza_id,
           op.numero_lote
    FROM capturas_hora ch
    JOIN prensas pr ON pr.id = ch.prensa_id
    JOIN piezas pi ON pi.id = ch.pieza_id
    JOIN ordenes_produccion op ON op.id = ch.orden_id
    WHERE ch.fecha = ? AND ch.prensa_id = ?
    ORDER BY ch.hora_inicio
");
$stmt->execute([$hoy, $prensa_id]);
$capturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($prensa) ?> — Panel Operador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .estado-box { display:inline-block; width:15px; height:15px; border-radius:50%; margin-left:8px; }
        .estado-pendiente { background: orange; }
        .estado-cerrada { background: green; }
        .valido { border:2px solid green; }
        .invalido { border:2px solid red; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4">👷 Prensa: <?= htmlspecialchars($prensa) ?></h1>
        <a href="panel_operador.php" class="btn btn-secondary">⬅ Volver</a>
    </div>

    <h5 class="mb-3">Lotes activos — <?= $hoy ?></h5>

    <?php if (empty($capturas)): ?>
        <div class="alert alert-warning">No hay capturas activas hoy para esta prensa.</div>
    <?php else: ?>
        <?php foreach ($capturas as $c): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary text-white">
                    Lote <?= htmlspecialchars($c['numero_lote']) ?> — <?= htmlspecialchars($c['pieza']) ?>
                    <span class="estado-box estado-<?= $c['estado'] ?>"></span>
                    <small>(<?= substr($c['hora_inicio'],0,5) ?> - <?= substr($c['hora_fin'],0,5) ?>)</small>
                </div>
                <div class="card-body">
                    <?php if ($c['estado'] === 'cerrada'): ?>
                        <div class="alert alert-success">✅ Esta franja ya fue capturada.</div>
                    <?php else: ?>
                        <form class="captura-form">
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
                                <button type="submit" class="btn btn-success">Guardar captura</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.captura-form').forEach(form => {
    const inputs = form.querySelectorAll('.atributo-input');

    // Validación visual
    inputs.forEach(inp => {
        inp.addEventListener('input', () => {
            const pred = parseFloat(inp.dataset.pred);
            const tol = parseFloat(inp.dataset.tol);
            const val = parseFloat(inp.value);
            if (!isNaN(val)) {
                if (val >= (pred - tol) && val <= (pred + tol)) {
                    inp.classList.add('valido'); inp.classList.remove('invalido');
                } else {
                    inp.classList.add('invalido'); inp.classList.remove('valido');
                }
            } else {
                inp.classList.remove('valido', 'invalido');
            }
        });
    });

    // Enviar AJAX
    form.addEventListener('submit', e => {
        e.preventDefault();
        const formData = new FormData(form);
        fetch('guardar_captura_ajax.php', { method:'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    alert(data.mensaje);
                    const card = form.closest('.card');
                    card.querySelector('.estado-box').classList.replace('estado-pendiente','estado-cerrada');
                    card.querySelector('.card-body').innerHTML = `<div class="alert alert-success">✅ Esta franja ya fue capturada.</div>`;
                } else {
                    alert('Error: '+data.error);
                }
            })
            .catch(err => console.error(err));
    });
});
</script>
</body>
</html>
