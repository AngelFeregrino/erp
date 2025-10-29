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

// --- NUEVO BLOQUE: verificar imagen de la prensa ---
$imagen_prensa = null;
$ruta_imagen = "img/" . $prensa . ".jpg"; // Ejemplo: img/P01.jpg
if (file_exists($ruta_imagen)) {
    $imagen_prensa = $ruta_imagen;
}

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
        /* Estilos originales */
        .estado-box {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            margin-left: 8px;
        }

        .estado-pendiente {
            background: orange;
        }

        .estado-cerrada {
            background: green;
        }

        .valido {
            border: 2px solid green;
        }

        .invalido {
            border: 2px solid red;
        }

        /* >>> ESTILOS AÑADIDOS PARA HACER TODO MÁS GRANDE <<< */
        /* Aumenta el ancho máximo de la imagen para que se vea más grande */
        .img-prensa {
            max-width: 300px;
            border-radius: 10px;
        }

        /* Aumenta el tamaño de fuente general en el cuerpo */
        body {
            font-size: 1.1rem;
        }

        /* Aumenta el tamaño de fuente en las etiquetas (labels) */
        .form-label {
            font-size: 1.3rem;
            font-weight: bold;
        }

        /* Aumenta el tamaño de fuente y padding en los campos de entrada */
        .form-control {
            padding: 1rem 0.75rem;
            /* Aumenta el relleno */
            font-size: 1.25rem;
            /* Aumenta el tamaño de la fuente */
        }
    </style>
</head>

<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-3">
                <h1 class="display-1">👷 Prensa: <?= htmlspecialchars($prensa) ?></h1>
                <?php if ($imagen_prensa): ?>
                    <img src="<?= htmlspecialchars($imagen_prensa) ?>" alt="Foto <?= htmlspecialchars($prensa) ?>" class="img-fluid img-prensa shadow">
                <?php endif; ?>
            </div>
            <a href="panel_operador.php" class="btn btn-secondary btn-lg">⬅ Volver</a>
        </div>

        <h2 class="display-4 mb-3">Lotes activos — <?= $hoy ?></h2>

        <?php if (empty($capturas)): ?>
            <div class="alert alert-warning fs-4">No hay capturas activas hoy para esta prensa.</div>
        <?php else: ?>
            <?php foreach ($capturas as $c): ?>
                <div class="card mb-4 shadow-lg">
                    <div class="card-header bg-primary text-white fs-4">
                        Lote <?= htmlspecialchars($c['numero_lote']) ?> — <?= htmlspecialchars($c['pieza']) ?>
                        <span class="estado-box estado-<?= $c['estado'] ?>"></span>
                        <small class="fs-5">(<?= substr($c['hora_inicio'], 0, 5) ?> - <?= substr($c['hora_fin'], 0, 5) ?>)</small>
                    </div>
                    <div class="card-body">
                        <?php if ($c['estado'] === 'cerrada'): ?>
                            <div class="alert alert-success fs-2">✅ Esta franja ya fue capturada.</div>
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

                                <h5 class="mt-3 display-5">Datos técnicos</h5>
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
                                <div class="text-end mt-4">
                                    <button type="submit" class="btn btn-success btn-lg fs-3">Guardar captura</button>
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

            // --- VALIDACIÓN DE TOLERANCIA EN TIEMPO REAL ---
            inputs.forEach(inp => {
                inp.addEventListener('input', () => {
                    const pred = parseFloat(inp.dataset.pred);
                    const tol = parseFloat(inp.dataset.tol);
                    const val = parseFloat(inp.value);
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

            // --- GUARDAR CAPTURA POR AJAX ---
            form.addEventListener('submit', e => {
                e.preventDefault();
                const submitButton = form.querySelector('button[type="submit"]');
                submitButton.disabled = true; // Deshabilita el botón al inicio
                const formData = new FormData(form);
                fetch('guardar_captura_ajax.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.mensaje);
                            const card = form.closest('.card');

                            // Reemplazar la tarjeta y actualizar el estado
                            card.querySelector('.card-header').classList.replace('bg-primary', 'bg-success'); // Opcional: cambiar el color
                            card.querySelector('.estado-box').classList.replace('estado-pendiente', 'estado-cerrada');
                            card.querySelector('.card-body').innerHTML = `<div class="alert alert-success fs-2">✅ Esta franja ya fue capturada.</div>`;

                            // Para que la función verificarFranjas lo ignore, actualizamos el estado en el header
                            // Esta línea es opcional, pero ayuda a que el JS que corre cada 60s lo vea actualizado.
                            const smallElement = card.querySelector('.card-header small');
                            if (smallElement) {
                                smallElement.textContent = smallElement.textContent.replace('(pendiente)', '(cerrada)');
                            }
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error de conexión o del servidor.'); // Mensaje de error más genérico para el usuario
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                    });
            });
        });


        // --- BLOQUE NUEVO: CONTROL AUTOMÁTICO DE FRANJAS POR HORA ---
        function verificarFranjas() {
            const ahora = new Date();
            const minutosActuales = ahora.getHours() * 60 + ahora.getMinutes();

            document.querySelectorAll('.captura-form').forEach(form => {
                const card = form.closest('.card');
                const header = card.querySelector('.card-header small');
                if (!header) return;

                // Extrae el rango horario (ejemplo: "12:00 - 13:00")
                const match = header.textContent.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
                if (!match) return;

                const [_, hInicio, mInicio, hFin, mFin] = match.map(Number);
                const inicioMin = hInicio * 60 + mInicio;
                const finMin = hFin * 60 + mFin;

                // Configuración
                const tolerancia = 5; // minutos extra permitidos
                const avisoMinutos = 10; // cuando faltan menos de 10 minutos
                const finConTolerancia = finMin + tolerancia;

                // Buscar alertas previas
                let alertaExistente = card.querySelector('.alert-tiempo');
                let alertaFuera = card.querySelector('.alert-danger');

                // Si ya está cerrada, no hacer nada
                if (card.querySelector('.alert-success')) return;

                // Si ya pasó la hora límite + tolerancia → bloquear
                if (minutosActuales > finConTolerancia) {
                    form.querySelectorAll('input, button').forEach(el => el.disabled = true);

                    const obs = form.querySelector('input[name="observaciones"]');
                    if (obs && !obs.value) obs.value = 'Fuera de tiempo';

                    if (!alertaFuera) {
                        card.querySelector('.card-body').insertAdjacentHTML('afterbegin',
                            `<div class="alert alert-danger mt-2 fs-4 alert-tiempo">⏰ Franja cerrada (fuera de tiempo)</div>`
                        );
                    }
                    if (alertaExistente && alertaExistente !== alertaFuera) alertaExistente.remove();
                } else if (minutosActuales >= finMin - avisoMinutos && minutosActuales <= finConTolerancia) {
                    // Cuando faltan menos de 10 minutos para cerrar
                    const minutosRestantes = finMin - minutosActuales;
                    if (minutosRestantes >= 0) {
                        const mensaje = `⏳ Quedan ${minutosRestantes} minuto${minutosRestantes !== 1 ? 's' : ''} para cerrar la franja`;
                        if (alertaExistente) {
                            alertaExistente.textContent = mensaje;
                        } else {
                            card.querySelector('.card-body').insertAdjacentHTML('afterbegin',
                                `<div class="alert alert-warning mt-2 fs-4 alert-tiempo">${mensaje}</div>`
                            );
                        }
                    }
                } else {
                    // Eliminar alertas si está fuera del rango de aviso
                    if (alertaExistente) alertaExistente.remove();
                }
            });
        }

        // Ejecuta al cargar y cada minuto
        verificarFranjas();
        setInterval(verificarFranjas, 60000);
    </script>


</body>

</html>