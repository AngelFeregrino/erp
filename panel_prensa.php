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

// Obtener nombre de prensa y validar existencia
$stmt = $pdo->prepare("SELECT nombre FROM prensas WHERE id = ?");
$stmt->execute([$prensa_id]);
$prensa = $stmt->fetchColumn();
if (!$prensa) {
    // prensa no encontrada — redirigir para evitar consultas con id inválido
    header('Location: panel_operador.php');
    exit();
}

// --- imagen de la prensa ---
$imagen_prensa = null;
$nombre_saneado = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prensa);
$ruta_imagen = "img/prensas/" . $nombre_saneado . ".jpg";
if (file_exists($ruta_imagen)) {
    $imagen_prensa = $ruta_imagen;
}

// Obtener capturas: incluir franjas con fecha = hoy
// y además franjas iniciadas AYER que pertenezcan a un turno nocturno (turno = 3).
$ayer = (new DateTime($hoy))->modify('-1 day')->format('Y-m-d');

$stmt = $pdo->prepare("
    SELECT ch.id AS captura_id,
           ch.fecha,
           ch.hora_inicio, ch.hora_fin, ch.estado,
           pr.nombre AS prensa, pi.nombre AS pieza, pi.codigo,
           ch.orden_id, ch.pieza_id,
           op.numero_orden AS numero_orden, op.numero_lote,
           COALESCE(op.cantidad_inicio,0) AS cantidad_inicio,
           ph.turno AS prensa_turno
    FROM capturas_hora ch
    JOIN prensas pr ON pr.id = ch.prensa_id
    JOIN piezas pi ON pi.id = ch.pieza_id
    JOIN ordenes_produccion op ON op.id = ch.orden_id
    LEFT JOIN prensas_habilitadas ph
        ON ph.orden_id = ch.orden_id
       AND ph.prensa_id = ch.prensa_id
       AND ph.fecha = ch.fecha
    WHERE ( (ch.fecha = ?) OR (ch.fecha = ? AND ph.turno = 3) )
      AND ch.prensa_id = ?
    ORDER BY ch.fecha, ch.hora_inicio
");
$stmt->execute([$hoy, $ayer, $prensa_id]);
$capturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($prensa, ENT_QUOTES) ?> — Panel Operador</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-success: linear-gradient(90deg, #20c997, #63e6be);
            --card-radius: 14px;
            --panel-width: 320px;
        }
        body { font-size:1.05rem; background:#f6f9ff; }
        .container { max-width:1100px; }
        .card { border-radius:var(--card-radius); overflow:hidden; position:relative; transition:transform .12s ease, box-shadow .12s ease; cursor:pointer; }
        .card.selected { transform:translateY(-6px); box-shadow:0 18px 44px rgba(14,30,60,0.18); }
        .card-header { padding:1.1rem 1.25rem; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:1rem; font-size:1.25rem; }
        .card-header.bg-primary { background:linear-gradient(90deg,#6c757d,#adb5bd); color:#fff; }
        .card-header.bg-success { background:var(--accent-success); color:#012; }
        .card-header small { font-size:3.05rem; opacity:0.95; margin-left:0.4rem; display:block; line-height:1.05; }
        .estado-box { display:inline-block; width:22px; height:22px; border-radius:50%; margin-left:8px; box-shadow:0 4px 10px rgba(0,0,0,.15); vertical-align:middle; }
        .estado-pendiente { background:radial-gradient(circle at 30% 30%, #ffb347,#ff7a00); border:2px solid rgba(255,140,0,.9); }
        .estado-cerrada { background:radial-gradient(circle at 30% 30%, #04fd74ff,#00ff4cff); border:2px solid rgba(0,255,55,1); }
        .img-prensa { max-width:320px; border-radius:12px; object-fit:cover; }
        .form-label { font-size:1.15rem; font-weight:700; }
        .form-control { padding:.9rem .75rem; font-size:1.05rem; }
        .btn-success { padding:.85rem 1.25rem; border-radius:10px; font-size:1.15rem; box-shadow:0 6px 18px rgba(37,150,190,0.12); }
        .valido { border:10px solid #16a34a !important; box-shadow:0 6px 18px rgba(22,163,74,0.08); background:rgba(16,185,129,0.04); }
        .invalido { border:10px solid #dc2626 !important; box-shadow:0 6px 18px rgba(220,38,38,0.08); background:rgba(220,38,38,0.03); }
        .atributo-col { margin-bottom:.8rem; }
        .badge.bg-secondary { font-size:.95rem; padding:.6rem .75rem; border-radius:8px; }
        .display-1 { font-size:2.25rem; font-weight:800; margin:0; }
        h2.display-4 { font-size:1.6rem; font-weight:700; margin-top:.6rem; margin-bottom:1rem; }
        .sticky-panel { position:fixed; right:18px; top:110px; width:var(--panel-width); background:white; border-radius:12px; box-shadow:0 18px 44px rgba(14,30,60,0.16); padding:18px; z-index:1200; display:none; }
        .sticky-panel.visible { display:block; }
        .sticky-row { display:flex; justify-content:space-between; margin:8px 0; font-size:.98rem; }
        @media (max-width:1200px) { .sticky-panel { display:none !important; } }
        @media (max-width:768px) { .img-prensa { max-width:180px; } .card-header { font-size:1.05rem; } .display-1 { font-size:1.6rem; } }
    </style>
</head>

<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-3">
                <h1 class="display-1">👷 Prensa: <?= htmlspecialchars($prensa, ENT_QUOTES) ?></h1>
                <?php if ($imagen_prensa): ?>
                    <img src="<?= htmlspecialchars($imagen_prensa, ENT_QUOTES) ?>" alt="Foto <?= htmlspecialchars($prensa, ENT_QUOTES) ?>" class="img-fluid img-prensa shadow">
                <?php endif; ?>
            </div>
            <a href="panel_operador.php" class="btn btn-secondary btn-lg">⬅ Volver</a>
        </div>

        <h2 class="display-1 mb-1">Lotes activos — <?= $hoy ?></h2>

        <?php if (empty($capturas)): ?>
            <div class="alert alert-warning fs-4">No hay capturas activas hoy para esta prensa.</div>
        <?php else: ?>
            <?php foreach ($capturas as $c): ?>
                <?php
                $imagen_pieza = null;
                $codigo_pieza = trim($c['codigo'] ?? '');
                $patron_busqueda = "img/piezas/" . $codigo_pieza . "-*";
                $coincidencias = glob($patron_busqueda . ".{jpg,jpeg,png}", GLOB_BRACE);
                if (!empty($coincidencias)) $imagen_pieza = $coincidencias[0];
                $cantidad_inicio = number_format((float)($c['cantidad_inicio'] ?? 0), 3, '.', '');
                // atributos escapados
                $attr_captura_id = htmlspecialchars($c['captura_id'] ?? '', ENT_QUOTES);
                $attr_orden_id   = htmlspecialchars($c['orden_id'] ?? '', ENT_QUOTES);
                $attr_numero_orden = htmlspecialchars($c['numero_orden'] ?? '', ENT_QUOTES);
                $attr_numero_lote  = htmlspecialchars($c['numero_lote'] ?? '', ENT_QUOTES);
                $attr_cantidad_inicio = htmlspecialchars($cantidad_inicio ?? '', ENT_QUOTES);
                $attr_fecha = htmlspecialchars($c['fecha'] ?? $hoy, ENT_QUOTES);
                ?>
                <div class="card mb-4 shadow-lg"
                    data-captura-id='<?= $attr_captura_id ?>'
                    data-orden-id='<?= $attr_orden_id ?>'
                    data-numero-orden='<?= $attr_numero_orden ?>'
                    data-numero-lote='<?= $attr_numero_lote ?>'
                    data-cantidad-inicio='<?= $attr_cantidad_inicio ?>'
                    data-fecha='<?= $attr_fecha ?>'
                    tabindex="0" role="button">

                    <?php $headerClass = ($c['estado'] ?? '') === 'cerrada' ? 'bg-success' : 'bg-primary'; ?>
                    <div class="card-header <?= $headerClass ?> text-white d-flex justify-content-between align-items-center">
                        <div class="lote-info">
                            <div>
                                <div style="font-size:3.05rem; font-weight:800;">
                                    Lote <?= htmlspecialchars($c['numero_lote'] ?? '-', ENT_QUOTES) ?> — <?= htmlspecialchars($c['pieza'] ?? '-', ENT_QUOTES) ?>
                                </div>
                                <div style="font-size:0.95rem; opacity:0.95; margin-top:4px;">
                                    <small>(<?= substr($c['hora_inicio'] ?? '00:00', 0, 5) ?> - <?= substr($c['hora_fin'] ?? '00:00', 0, 5) ?>)</small>
                                </div>
                            </div>

                            <span class="estado-box <?= ($c['estado'] ?? '') === 'cerrada' ? 'estado-cerrada' : 'estado-pendiente' ?>"></span>
                        </div>

                        <?php if ($imagen_pieza): ?>
                            <img src="<?= htmlspecialchars($imagen_pieza, ENT_QUOTES) ?>"
                                alt="Imagen de <?= htmlspecialchars($c['pieza'] ?? '', ENT_QUOTES) ?>"
                                class="img-thumbnail shadow-sm"
                                style="max-height:200px; border-radius:10px;">
                        <?php else: ?>
                            <span class="badge bg-secondary fs-6">Sin imagen</span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <?php if (($c['estado'] ?? '') === 'cerrada'): ?>
                            <div class="alert alert-success fs-5">✅ Esta franja ya fue capturada.</div>
                        <?php else: ?>
                            <form class="captura-form">
                                <input type="hidden" name="captura_id" value="<?= htmlspecialchars($c['captura_id'] ?? '', ENT_QUOTES) ?>">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Observaciones</label>
                                        <input type="text" name="observaciones" class="form-control" placeholder="Comentarios, incidencias...">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Firma operador</label>
                                        <input type="text" name="firma" class="form-control" required>
                                    </div>
                                </div>

                                <h5 class="mt-3 display-5">Datos técnicos</h5>
                                <div class="row">
                                    <?php
                                    $stmt3 = $pdo->prepare("SELECT id, nombre_atributo, unidad, valor_predeterminado, tolerancia
                                                            FROM atributos_pieza WHERE pieza_id = ?");
                                    $stmt3->execute([$c['pieza_id']]);
                                    $atributos = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($atributos as $a):
                                        $pred_val = number_format((float)$a['valor_predeterminado'], 3, '.', '');
                                        $tol = (float)$a['tolerancia'];
                                        $nombre_limpio = strtolower($a['nombre_atributo']);
                                        $es_densidad = (strpos($nombre_limpio, 'densidad') !== false);
                                        $es_peso = (strpos($nombre_limpio, 'peso') !== false);
                                    ?>
                                        <div class="col-md-4 mb-2 atributo-col">
                                            <label class="form-label"><?= htmlspecialchars($a['nombre_atributo'], ENT_QUOTES) ?> (<?= htmlspecialchars($a['unidad'], ENT_QUOTES) ?>)</label>
                                            <?php if ($es_densidad): ?>
                                                <input type="number" step="0.001" inputmode="decimal" name="atributo[<?= (int)$a['id'] ?>]"
                                                    class="form-control atributo-input densidad-input" value="" readonly
                                                    data-nombre="<?= htmlspecialchars($nombre_limpio, ENT_QUOTES) ?>"
                                                    data-pred="<?= $pred_val ?>" data-tol="<?= $tol ?>">
                                            <?php elseif ($es_peso): ?>
                                                <input type="number" step="0.001" inputmode="decimal" name="atributo[<?= (int)$a['id'] ?>]"
                                                    class="form-control atributo-input peso-input" value="<?= $pred_val ?>"
                                                    data-nombre="<?= htmlspecialchars($nombre_limpio, ENT_QUOTES) ?>"
                                                    data-pred="<?= $pred_val ?>" data-tol="<?= $tol ?>">
                                            <?php else: ?>
                                                <input type="number" step="0.001" inputmode="decimal" name="atributo[<?= (int)$a['id'] ?>]"
                                                    class="form-control atributo-input" value="<?= $pred_val ?>"
                                                    data-nombre="<?= htmlspecialchars($nombre_limpio, ENT_QUOTES) ?>"
                                                    data-pred="<?= $pred_val ?>" data-tol="<?= $tol ?>">
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="text-end mt-4">
                                    <button type="submit" class="btn btn-success btn-lg">Guardar captura</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Sticky panel -->
    <div id="stickyPanel" class="sticky-panel" aria-hidden="true">
        <h4>Resumen de franja</h4>
        <div class="sticky-row"><span>Orden</span><strong id="spOrden">-</strong></div>
        <div class="sticky-row"><span>Lote</span><strong id="spLote">-</strong></div>
        <div class="sticky-row"><span>Cantidad inicio</span><strong id="spInicio">-</strong></div>
        <div class="sticky-row"><span>Horario</span><strong id="spHorario">-</strong></div>
        <div style="margin-top:12px;">
            <button id="spMarkCaptured" class="btn btn-success">Marcar franja como capturada</button>
        </div>
        <div style="margin-top:10px;">
            <button id="spClose" class="btn btn-outline-secondary">Cerrar panel</button>
        </div>
    </div>

    <script>
        const sticky = document.getElementById('stickyPanel');
        const spOrden = document.getElementById('spOrden');
        const spLote = document.getElementById('spLote');
        const spInicio = document.getElementById('spInicio');
        const spHorario = document.getElementById('spHorario');
        const spMark = document.getElementById('spMarkCaptured');
        const spClose = document.getElementById('spClose');
        let selectedCard = null;

        function sanitizeNumericValue(str) {
            if (typeof str !== 'string') return '';
            let v = str.replace(/[^0-9.\-]/g, '');
            v = v.replace(/(?!^)-/g, '');
            const parts = v.split('.');
            if (parts.length > 1) {
                v = parts.shift() + '.' + parts.join('');
            }
            return v;
        }

        function showSticky(card) {
            if (!card) return;
            const numeroOrden = card.dataset.numeroOrden || card.dataset.ordenId || '-';
            const lote = card.dataset.numeroLote || '-';
            const inicio = card.dataset.cantidadInicio || '-';
            const hora = (card.querySelector('.card-header small') || {}).textContent || '-';

            spOrden.textContent = numeroOrden;
            spLote.textContent = lote;
            spInicio.textContent = (inicio !== null && inicio !== '') ? Number(inicio).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 }) : '-';
            spHorario.textContent = hora.trim();

            sticky.classList.add('visible');
            sticky.setAttribute('aria-hidden', 'false');

            if (selectedCard) selectedCard.classList.remove('selected');
            selectedCard = card;
            card.classList.add('selected');

            spMark.dataset.capturaId = card.dataset.capturaId;
            spMark.dataset.ordenId = card.dataset.ordenId;
            spMark.dataset.numeroOrden = numeroOrden;
        }

        spClose.addEventListener('click', () => {
            sticky.classList.remove('visible');
            sticky.setAttribute('aria-hidden', 'true');
            if (selectedCard) selectedCard.classList.remove('selected');
            selectedCard = null;
        });

        document.querySelectorAll('.card[data-captura-id]').forEach(card => {
            card.addEventListener('click', (e) => {
                const forbidden = e.target.closest('input, button, a, select, textarea, label');
                if (forbidden) return;
                showSticky(card);
                setTimeout(() => card.scrollIntoView({ behavior:'smooth', block:'center' }), 120);
            });

            card.addEventListener('keydown', (e) => {
                try {
                    const active = document.activeElement;
                    if (active) {
                        const tag = (active.tagName || '').toUpperCase();
                        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || active.isContentEditable) return;
                    }
                } catch (err) { console.error(err); }

                const forbidden = e.target.closest('input, button, a, select, textarea, label');
                if (forbidden) return;

                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    showSticky(card);
                }
            });
        });

        spMark.addEventListener('click', async () => {
            const capturaId = spMark.dataset.capturaId;
            if (!capturaId) return alert('No se pudo identificar la franja seleccionada.');
            const card = document.querySelector('.card[data-captura-id="' + capturaId + '"]');
            if (!card) return alert('Franja no encontrada en la página.');
            const form = card.querySelector('.captura-form');
            if (!form) return alert('No hay formulario disponible para esta franja.');
            form.scrollIntoView({ behavior:'smooth', block:'center' });
            submitCapturaForm(form);
        });

        function submitCapturaForm(form) {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            const formData = new FormData(form);
            fetch('guardar_captura_ajax.php', { method:'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const card = form.closest('.card');
                    const successBox = document.createElement('div');
                    successBox.className = 'alert alert-success fs-5';
                    successBox.textContent = '✅ Captura guardada correctamente.';
                    card.querySelector('.card-body').innerHTML = '';
                    card.querySelector('.card-body').appendChild(successBox);
                    const header = card.querySelector('.card-header');
                    if (header) { header.classList.remove('bg-primary'); header.classList.add('bg-success'); }
                    const estadoBox = card.querySelector('.estado-box');
                    if (estadoBox) { estadoBox.classList.remove('estado-pendiente'); estadoBox.classList.add('estado-cerrada'); }
                    if (selectedCard && selectedCard.isSameNode(card)) {
                        spInicio.textContent = card.dataset.cantidadInicio ? Number(card.dataset.cantidadInicio).toLocaleString(undefined,{ minimumFractionDigits:3, maximumFractionDigits:3 }) : '-';
                    }
                } else {
                    alert('Error: ' + (data.error || 'Error desconocido'));
                }
            })
            .catch(err => { console.error(err); alert('Error de conexión o del servidor.'); })
            .finally(() => { if (submitButton) submitButton.disabled = false; });
        }

        document.querySelectorAll('.captura-form').forEach(form => {
            const inputs = form.querySelectorAll('.atributo-input');
            const pesoInput = form.querySelector('.peso-input');
            const densidadInput = form.querySelector('.densidad-input');

            function calcularDensidad() {
                if (!densidadInput) return;
                const divisor = parseFloat(densidadInput.dataset.pred);
                let pesoVal = null;
                if (pesoInput) {
                    const v = parseFloat(pesoInput.value);
                    if (!isNaN(v)) pesoVal = v;
                } else {
                    const anyPeso = form.querySelectorAll('.atributo-input');
                    anyPeso.forEach(inp => {
                        const nombre = (inp.dataset.nombre || '').toLowerCase();
                        if (pesoVal === null && nombre.includes('peso')) {
                            const vv = parseFloat(inp.value);
                            if (!isNaN(vv)) pesoVal = vv;
                        }
                    });
                }

                if (pesoVal !== null && !isNaN(divisor) && divisor > 0) {
                    const dens = pesoVal / divisor;
                    densidadInput.value = dens.toFixed(3);
                    const tol = parseFloat(densidadInput.dataset.tol || 0);
                    const pred = parseFloat(densidadInput.dataset.pred || 0);
                    if (!isNaN(pred) && !isNaN(tol)) {
                        if (dens >= (pred - tol) && dens <= (pred + tol)) {
                            densidadInput.classList.add('valido'); densidadInput.classList.remove('invalido');
                        } else { densidadInput.classList.add('invalido'); densidadInput.classList.remove('valido'); }
                    }
                } else { densidadInput.value = ''; densidadInput.classList.remove('valido','invalido'); }
            }

            function attachNumericSanitizers(inp) {
                inp.addEventListener('input', () => {
                    if (inp.classList.contains('densidad-input') && inp.readOnly) return;
                    const orig = inp.value;
                    const clean = sanitizeNumericValue(orig);
                    if (clean !== orig) {
                        const pos = inp.selectionStart;
                        inp.value = clean;
                        try { inp.setSelectionRange(Math.min(pos, clean.length), Math.min(pos, clean.length)); } catch {}
                    }
                });

                inp.addEventListener('paste', (e) => {
                    const paste = (e.clipboardData || window.clipboardData).getData('text');
                    const clean = sanitizeNumericValue(paste);
                    if (clean === '') { e.preventDefault(); return; }
                    e.preventDefault();
                    const start = inp.selectionStart; const end = inp.selectionEnd;
                    const before = inp.value.slice(0, start); const after = inp.value.slice(end);
                    inp.value = before + clean + after;
                    const newPos = before.length + clean.length;
                    try { inp.setSelectionRange(newPos, newPos); } catch {}
                    inp.dispatchEvent(new Event('input', { bubbles:true }));
                });

                inp.addEventListener('blur', () => {
                    const v = parseFloat(inp.value);
                    if (!isNaN(v)) inp.value = v.toFixed(3);
                });
            }

            inputs.forEach(inp => attachNumericSanitizers(inp));

            if (pesoInput) {
                pesoInput.addEventListener('input', () => {
                    const pred = parseFloat(pesoInput.dataset.pred || 0);
                    const tol = parseFloat(pesoInput.dataset.tol || 0);
                    const val = parseFloat(pesoInput.value);
                    if (!isNaN(val) && !isNaN(pred)) {
                        if (val >= (pred - tol) && val <= (pred + tol)) { pesoInput.classList.add('valido'); pesoInput.classList.remove('invalido'); }
                        else { pesoInput.classList.add('invalido'); pesoInput.classList.remove('valido'); }
                    } else { pesoInput.classList.remove('valido','invalido'); }
                    calcularDensidad();
                });
                pesoInput.addEventListener('blur', () => { const v = parseFloat(pesoInput.value); if (!isNaN(v)) pesoInput.value = v.toFixed(3); calcularDensidad(); });
            }

            inputs.forEach(inp => {
                inp.addEventListener('input', () => {
                    const pred = parseFloat(inp.dataset.pred);
                    const tol = parseFloat(inp.dataset.tol);
                    const val = parseFloat(inp.value);
                    if (!isNaN(val) && !isNaN(pred)) {
                        if (val >= (pred - tol) && val <= (pred + tol)) { inp.classList.add('valido'); inp.classList.remove('invalido'); }
                        else { inp.classList.add('invalido'); inp.classList.remove('valido'); }
                    } else { inp.classList.remove('valido','invalido'); }
                    calcularDensidad();
                });
            });

            form.addEventListener('submit', (e) => { e.preventDefault(); submitCapturaForm(form); });

            calcularDensidad();
        });

        function verificarFranjas() {
            const ahora = new Date();
            const ahoraT = ahora.getTime();
            const oneDayMs = 24 * 60 * 60 * 1000;
            const toleranciaMs = 10 * 60 * 1000; // 10 min
            const avisoMs = 10 * 60 * 1000; // 10 min

            function mismaFechaLocal(d1, d2) {
                return d1.getFullYear() === d2.getFullYear() && d1.getMonth() === d2.getMonth() && d1.getDate() === d2.getDate();
            }

            document.querySelectorAll('.captura-form').forEach(form => {
                const card = form.closest('.card');
                const headerEl = card.querySelector('.card-header small');
                if (!headerEl) return;

                const fechaStr = card.dataset.fecha || (() => {
                    const d = new Date(); return d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,'0') + "-" + String(d.getDate()).padStart(2,'0');
                })();

                const txtRaw = (headerEl.textContent || '').trim();
                const txt = txtRaw.replace(/[()]/g, '').trim();
                const match = txt.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
                if (!match) return;

                const hInicio = parseInt(match[1], 10);
                const mInicio = parseInt(match[2], 10);
                const hFin = parseInt(match[3], 10);
                const mFin = parseInt(match[4], 10);

                const parts = fechaStr.split('-').map(p => parseInt(p,10));
                if (parts.length !== 3 || parts.some(isNaN)) return;
                const year = parts[0], month = parts[1]-1, day = parts[2];

                const startBase = new Date(year, month, day, hInicio, mInicio, 0, 0);
                let endBase = new Date(year, month, day, hFin, mFin, 0, 0);
                if (endBase.getTime() <= startBase.getTime()) endBase = new Date(endBase.getTime() + oneDayMs);

                const ocurrencias = [];
                for (let offset=-1; offset<=1; offset++) {
                    const s = new Date(startBase.getTime() + offset*oneDayMs);
                    const e = new Date(endBase.getTime() + offset*oneDayMs);
                    ocurrencias.push({ start: s, end: e });
                }

                // limpiar alerts previas del script
                const prevAlerts = card.querySelectorAll('.alert-tiempo');
                prevAlerts.forEach(a => a.remove());
                form.querySelectorAll('input, button, textarea, select').forEach(el => el.disabled = false);

                function putClosed() {
                    form.querySelectorAll('input, button, textarea, select').forEach(el => el.disabled = true);
                    const obs = form.querySelector('input[name="observaciones"]');
                    if (obs && !obs.value) obs.value = 'Fuera de tiempo';
                    if (!card.querySelector('.alert-danger.alert-tiempo')) {
                        card.querySelector('.card-body').insertAdjacentHTML('afterbegin',
                            `<div class="alert alert-danger mt-2 fs-5 alert-tiempo">⏰ Franja cerrada (fuera de tiempo)</div>`
                        );
                    }
                }

                function putWarning(minutos) {
                    if (card.querySelector('.alert-danger.alert-tiempo')) return;
                    const existing = card.querySelector('.alert-warning.alert-tiempo');
                    const mensaje = `⏳ Quedan ${minutos} minuto${minutos !== 1 ? 's' : ''} para cerrar la franja`;
                    if (!existing) {
                        card.querySelector('.card-body').insertAdjacentHTML('afterbegin',
                            `<div class="alert alert-warning mt-2 fs-5 alert-tiempo">${mensaje}</div>`
                        );
                    } else {
                        existing.textContent = mensaje;
                    }
                    form.querySelectorAll('input, button, textarea, select').forEach(el => el.disabled = false);
                }

                function clearAlerts() {
                    const a = card.querySelectorAll('.alert-tiempo');
                    a.forEach(x => x.remove());
                    form.querySelectorAll('input, button, textarea, select').forEach(el => el.disabled = false);
                }

                if (card.querySelector('.alert-success')) { clearAlerts(); return; }

                // 1) ahora dentro de alguna ocurrencia?
                for (const occ of ocurrencias) {
                    const sT = occ.start.getTime();
                    const eT = occ.end.getTime();
                    if (ahoraT >= sT && ahoraT < eT) {
                        if (ahoraT >= (eT - avisoMs) && ahoraT <= (eT + toleranciaMs)) {
                            const minutosRestantes = Math.max(0, Math.ceil((eT - ahoraT) / 60000));
                            putWarning(minutosRestantes);
                        } else {
                            clearAlerts();
                        }
                        return;
                    }
                }

                // 2) futuras PERO solo en la misma fecha de la franja
                const hayFuturasEnFechaFranja = ocurrencias.some(occ => occ.start.getTime() > ahoraT && mismaFechaLocal(occ.start, new Date(year, month, day)));
                if (hayFuturasEnFechaFranja) { clearAlerts(); return; }

                // 3) buscar última pasada
                let ultimaPasada = null;
                for (const occ of ocurrencias) {
                    const eT = occ.end.getTime();
                    if (eT <= ahoraT) {
                        if (!ultimaPasada || eT > ultimaPasada.endT) ultimaPasada = { endT: eT, occ };
                    }
                }

                if (ultimaPasada) {
                    const endT = ultimaPasada.endT;
                    if (ahoraT > endT + toleranciaMs) { putClosed(); return; }
                    if (ahoraT >= (endT - avisoMs) && ahoraT <= (endT + toleranciaMs)) {
                        const minutosRestantes = Math.max(0, Math.ceil((endT - ahoraT) / 60000));
                        putWarning(minutosRestantes);
                        return;
                    }
                    clearAlerts();
                    return;
                }

                clearAlerts();
            });
        }

        verificarFranjas();
        setInterval(verificarFranjas, 30000);
    </script>

</body>
</html>
