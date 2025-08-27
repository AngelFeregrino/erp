<?php
session_start();
require 'db.php'; // conexión a la BD

// 1. Verificar sesión y rol
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 2. Obtener órdenes abiertas
$ordenes = $db->query("SELECT op.id, op.numero_orden, p.nombre AS pieza 
                       FROM ordenes_produccion op
                       JOIN piezas p ON p.id = op.pieza_id
                       WHERE op.estado = 'abierta'
                       ORDER BY op.fecha_inicio DESC");

// 3. Obtener catálogo de prensas y piezas
$prensas = $db->query("SELECT id, nombre FROM prensas ORDER BY nombre");
$piezas = $db->query("SELECT id, nombre FROM piezas ORDER BY nombre");

// 4. Procesar formulario de habilitación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['habilitar'])) {
    $orden_id = $_POST['orden_id'];
    $fecha = $_POST['fecha'];
    $prensa_id = $_POST['prensa_id'];
    $pieza_id = $_POST['pieza_id'];

    // Insertar en prensas_habilitadas
    $stmt = $db->prepare("INSERT INTO prensas_habilitadas 
        (orden_id, fecha, prensa_id, pieza_id, habilitado) 
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE pieza_id = VALUES(pieza_id), habilitado = 1");
    $stmt->execute([$orden_id, $fecha, $prensa_id, $pieza_id]);

    // Crear franjas horarias del turno
    $horas = [
        ['08:00', '09:00'],
        ['09:00', '10:00'],
        ['10:00', '11:00'],
        ['11:00', '12:00'],
        ['12:00', '13:00'],
        ['13:00', '14:00'],
        ['14:00', '15:00'],
        ['15:00', '16:00']
    ];

    foreach ($horas as $h) {
        $stmt2 = $db->prepare("INSERT INTO capturas_hora 
            (orden_id, fecha, prensa_id, pieza_id, hora_inicio, hora_fin, estado) 
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente')");
        $stmt2->execute([$orden_id, $fecha, $prensa_id, $pieza_id, $h[0], $h[1]]);
    }

    $mensaje = "Prensa habilitada y horas creadas para $fecha.";
}

// 5. Consultar prensas habilitadas hoy
$hoy = date('Y-m-d');
$habilitadas = $db->prepare("SELECT ph.fecha, ph.prensa_id, pr.nombre AS prensa, 
                                    ph.pieza_id, pi.nombre AS pieza
                             FROM prensas_habilitadas ph
                             JOIN prensas pr ON pr.id = ph.prensa_id
                             JOIN piezas pi ON pi.id = ph.pieza_id
                             WHERE ph.fecha = ? AND ph.habilitado = 1");
$habilitadas->execute([$hoy]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Panel Admin</title>
</head>
<body>
<h1>Panel Administrador</h1>

<?php if (isset($mensaje)) echo "<p style='color:green;'>$mensaje</p>"; ?>

<form method="post">
    <label>Orden:</label>
    <select name="orden_id">
        <?php foreach ($ordenes as $o): ?>
            <option value="<?= $o['id'] ?>"><?= $o['numero_orden'] ?> - <?= $o['pieza'] ?></option>
        <?php endforeach; ?>
    </select><br>

    <label>Fecha:</label>
    <input type="date" name="fecha" value="<?= date('Y-m-d') ?>"><br>

    <label>Prensa:</label>
    <select name="prensa_id">
        <?php foreach ($prensas as $p): ?>
            <option value="<?= $p['id'] ?>"><?= $p['nombre'] ?></option>
        <?php endforeach; ?>
    </select><br>

    <label>Pieza:</label>
    <select name="pieza_id">
        <?php foreach ($piezas as $pi): ?>
            <option value="<?= $pi['id'] ?>"><?= $pi['nombre'] ?></option>
        <?php endforeach; ?>
    </select><br>

    <button type="submit" name="habilitar">Habilitar prensa y pieza</button>
</form>

<h2>Prensas habilitadas hoy (<?= $hoy ?>)</h2>
<table border="1">
    <tr>
        <th>Fecha</th>
        <th>Prensa</th>
        <th>Pieza</th>
    </tr>
    <?php foreach ($habilitadas as $h): ?>
    <tr>
        <td><?= $h['fecha'] ?></td>
        <td><?= $h['prensa'] ?></td>
        <td><?= $h['pieza'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>