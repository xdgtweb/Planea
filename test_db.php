<?php
// --- COPIA AQUÍ TUS 4 CREDENCIALES EXACTAS ---
define('DB_SERVER', 'sql123.infinityfree.com');
define('DB_USERNAME', 'if0_34567890');
define('DB_PASSWORD', 'vJJFiXNaYGj5'); // La contraseña que SÍ funciona en phpMyAdmin
define('DB_NAME', 'if0_34567890_planea');
// -----------------------------------------

// --- CÓDIGO DE PRUEBA DE CONEXIÓN ---
echo "<h1>Prueba de Conexión a la Base de Datos</h1>";

// Intentamos crear una nueva conexión
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Verificamos el resultado de la conexión
if ($mysqli->connect_error) {
    // Si hay un error, lo mostramos en rojo.
    echo "<p style='color: red; font-weight: bold;'>FALLO EN LA CONEXIÓN.</p>";
    echo "<p>Error: " . $mysqli->connect_error . "</p>";
} else {
    // Si la conexión es exitosa, lo mostramos en verde.
    echo "<p style='color: green; font-weight: bold;'>¡CONEXIÓN EXITOSA!</p>";
    echo "<p>La conexión a la base de datos '" . DB_NAME . "' se ha realizado correctamente.</p>";
    // Cerramos la conexión si fue exitosa
    $mysqli->close();
}

echo "<hr>";

// --- INFORMACIÓN DEL ENTORNO PHP ---
// Esto nos mostrará todos los detalles de la configuración de PHP en tu servidor.
phpinfo();

?>