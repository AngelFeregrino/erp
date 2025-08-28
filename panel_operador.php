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

// 1. Obtener prensas habilitadas hoy
$stmt = $pdo->prepare("SELECT ph.orden_id, ph.prensa_id, pr.nombre AS prensa,
                               ph.pieza_id, pi.nombre AS pieza
                        FROM prensas_habilitadas ph
                        JOIN prensas pr ON pr.id = ph.prensa_id
                        JOIN piezas pi ON pi.id = ph.pieza_id
                        WHERE ph.fecha = ? AND ph.habilitado = 1");
$stmt->execute([$hoy]);
$habilitadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Procesar captura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['capturar'])) {
    $orden_id  = $_POST['orden_id'];
    $prensa_id = $_POST['prensa_id'];
    $pieza_id  = $_POST['pieza_id'];
    $hora_ini  = $_POST['hora_inicio'];
    $hora_fin  = $_POST['hora_fin'];
    $cantidad  = $_POST['cantidad'];
    $obs       = $_POST['observaciones'];
    $firma     = $_POST['firma'];

    // Tolerancia de +10 min
    $hora_limite = date('H:i:s', strtotime($hora_fin . ' +10 minutes'));

    if ($hora_actual >= $hora_ini && $hora_actual <= $hora_limite) {
        // Guardar captura
        $stmt = $pdo->prepare("INSERT INTO capturas_hora
            (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, cantidad, observaciones_op, firma_operador, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cerrada')");
        $stmt->execute([$orden_id, $hoy, $prensa_id, $pieza_id, $hora_ini, $hora_fin, $cantidad, $obs, $firma]);
        $captura_id = $pdo->lastInsertId();

        // Guardar atributos técnicos
        if (!empty($_POST['atributo'])) {
            foreach ($_POST['atributo'] as $atributo_id => $valor) {
                $stmt2 = $pdo->prepare("INSERT INTO valores_hora (captura_id, atributo_pieza_id, valor)
                                        VALUES (?, ?, ?)");
                $stmt2->execute([$captura_id, $atributo_id, $valor]);
            }
        }

        $mensaje = "✅ Captura registrada correctamente.";
    } else {
        $mensaje = "⚠️ Fuera de la ventana horaria permitida.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Operador</title>
</head>
<body>
<h1>Hola, <?= htmlspecialchars($nombre) ?> 🛠️</h1>
<p><a href="logout.php" style="color:red; font-weight:bold;">Cerrar sesión</a></p>

<?php if (!empty($mensaje)) echo "<p>$mensaje</p>"; ?>

<h2>Prensas habilitadas hoy (<?= $hoy ?>)</h2>
<?php if (empty($habilitadas)): ?>
    <p>No hay prensas habilitadas para hoy.</p>
<?php else: ?>
    <?php foreach ($habilitadas as $h): ?>
        <h3><?= htmlspecialchars($h['prensa']) ?> — <?= htmlspecialchars($h['pieza']) ?></h3>
        <form method="post">
            <input type="hidden" name="orden_id" value="<?= $h['orden_id'] ?>">
            <input type="hidden" name="prensa_id" value="<?= $h['prensa_id'] ?>">
            <input type="hidden" name="pieza_id" value="<?= $h['pieza_id'] ?>">

            <label>Hora inicio:</label>
            <input type="time" name="hora_inicio" required>
            <label>Hora fin:</label>
            <input type="time" name="hora_fin" required><br><br>

            <label>Cantidad:</label>
            <input type="number" name="cantidad" required><br><br>

            <label>Observaciones:</label>
            <input type="text" name="observaciones"><br><br>

            <label>Firma operador:</label>
            <input type="text" name="firma" required><br><br>

            <h4>Datos técnicos:</h4>
            <?php
            $stmt3 = $pdo->prepare("SELECT id, nombre_atributo, unidad
                                    FROM atributos_pieza
                                    WHERE pieza_id = ?");
            $stmt3->execute([$h['pieza_id']]);
            $atributos = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            foreach ($atributos as $a):
            ?>
                <label><?= htmlspecialchars($a['nombre_atributo']) ?> (<?= htmlspecialchars($a['unidad']) ?>):</label>
                <input type="text" name="atributo[<?= $a['id'] ?>]" required><br>
            <?php endforeach; ?>

            <br>
            <button type="submit" name="capturar">Guardar captura</button>
        </form>
        <hr>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>