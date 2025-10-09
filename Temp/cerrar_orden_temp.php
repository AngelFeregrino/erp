<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orden_id = $_POST['orden_id'];
    $equipo   = $_POST['equipo_asignado'];
    $firma    = $_POST['firma_responsable'];
    $fechaLib = date('Y-m-d');

    // Calcular cantidad total del lote
    $stmt = $pdo->prepare("SELECT SUM(cantidad) as total FROM capturas_hora WHERE orden_id=?");
    $stmt->execute([$orden_id]);
    $total = $stmt->fetchColumn();

    // Actualizar orden
    $stmt2 = $pdo->prepare("UPDATE ordenes_produccion
        SET cantidad_total_lote=?, equipo_asignado=?, fecha_liberacion=?, firma_responsable=?, estado='cerrada'
        WHERE id=?");
    $stmt2->execute([$total, $equipo, $fechaLib, $firma, $orden_id]);

    header("Location: panel_admin.php?msg=Orden cerrada");
    exit();
}
