<?php
$db_host = 'localhost';
$db_name = 'erp';
$db_user = 'root';
$db_pass = 'admin1234';
$fecha = date("Ymd");

$salida_sql = $db_name . '_' . $fecha . '.sql';

system('C:\xampp\mysql\bin\mysqldump'." -h$db_host -u$db_user -p$db_pass $db_name > "."$salida_sql", $sal);

$zip = new ZipArchive();
$salida_zip = $db_name . '_' . $fecha . '.zip';

if ($zip->open($salida_zip, ZIPARCHIVE::CREATE) === true) {
    
    $zip->addFile($salida_sql);
    $zip->close();
    
    // --- LÓGICA DE DESCARGA ---
    
    // Verificar que el archivo ZIP existe y se puede leer
    if (file_exists($salida_zip)) {
        
        // 2. Establecer las cabeceras HTTP para forzar la descarga
        header('Content-Type: application/zip');
        // 'Content-Disposition' fuerza la descarga y establece el nombre del archivo
        header('Content-Disposition: attachment; filename="' . basename($salida_zip) . '"');
        header('Content-Length: ' . filesize($salida_zip));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        // 3. Limpiar cualquier contenido de buffer pendiente para evitar corrupción
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 4. Transmitir el archivo al navegador
        readfile($salida_zip);
        
        // 5. Borrar los archivos después de que han sido enviados al navegador
        // PHP borra los archivos solo después de que 'readfile' ha terminado.
        unlink($salida_sql);
        unlink($salida_zip);
        
        exit; // Terminar el script para asegurar que no se envíe más contenido
        
    } else {
        echo 'Error: El archivo ZIP no se pudo crear o no existe.';
    }

} else {
    echo 'Error: No se pudo abrir el archivo ZIP para creación.';
}
?>