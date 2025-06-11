<?php
// --- CREDENCIALES CORRECTAS ---
// Datos de conexión reales proporcionados por el usuario.
define('DB_SERVER', 'sql309.infinityfree.com');
define('DB_USERNAME', 'if0_39074189');
define('DB_PASSWORD', 'vJJFiXNaYGj5');
define('DB_NAME', 'if0_39074189_proyectosdevida');
define('DB_PORT', '3306'); // Puerto estándar de MySQL

// Establecer conexión a la base de datos
try {
    // Se incluye el puerto como quinto parámetro en el constructor de mysqli
    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);

    // Verificar conexión
    if ($mysqli->connect_error) {
        throw new Exception("Error de conexión a la base de datos: " . $mysqli->connect_error);
    }

    // Establecer el conjunto de caracteres a utf8mb4 para soportar emojis y caracteres especiales
    if (!$mysqli->set_charset("utf8mb4")) {
        // Log del error si falla, pero no necesariamente detener el script
        error_log("Error al cargar el conjunto de caracteres utf8mb4: " . $mysqli->error);
    }

} catch (Exception $e) {
    // En caso de un error de conexión, no se puede usar json_response si no está definido aún.
    // Enviamos una respuesta de error HTTP directamente.
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'No se pudo conectar a la base de datos.',
        'details' => $e->getMessage()
    ]);
    exit;
}
?>