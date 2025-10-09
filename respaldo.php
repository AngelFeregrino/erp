<?php
session_start();
require 'db.php';
$fecha = date("Ymd-His");

$salida_sql = $db_name.'_'.$.'.sql';
$dump "mysqldump -h$db_host -u$db_user -p$db_pass --opt
$db_name > $salida_sql";

system($dump, $output);
?>