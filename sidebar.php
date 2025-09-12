<div class="sidebar bg-dark text-white">
    <h4 class="px-3 pt-3">⚙️ Admin</h4>
    <hr class="bg-light">

    
    <a href="panel_admin.php" class="nav-link"> 🏠 Inicio</a>
    <a href="cerrar_orden.php" class="nav-link"> 🗂 Cerrar Lote Producción</a>
    <a href="reportes.php" class="nav-link">📊 Consultar Reportes</a>
    <a href="crear_usuario.php" class="nav-link">👤 Crear Usuarios</a>
    <a href="ingreso_piezas.php" class="nav-link">⚙️ Ingreso de Piezas</a>
    <a href="ingreso_prensas.php" class="nav-link">🛠️ Ingreso de Prensas</a>
    <a href="respaldo.php" class="nav-link">💾 Respaldo</a>
    <hr class="bg-light">
    <a href="logout.php" class="nav-link text-danger">🚪 Cerrar Sesión</a>
</div>

<style>
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100%;   /* toda la altura */
    width: 220px;   /* ancho fijo */
    padding-top: 10px;
}
.sidebar a {
    display: block;
    padding: 10px 15px;
    color: white;
    text-decoration: none;
}
.sidebar a:hover {
    background: #495057;
    color: #fff;
}
.content {
    margin-left: 220px; /* espacio para el sidebar */
    padding: 20px;
}
</style>
