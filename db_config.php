<?php
// db_config.php

// Define tus constantes de conexión a la base de datos
define('DB_SERVER', 'sql309.infinityfree.com');
define('DB_USERNAME', 'if0_39074189');
define('DB_PASSWORD', 'vJJFiXNaYGj5'); // Contraseña que proporcionaste
define('DB_NAME', 'if0_39074189_proyectosdevida');
define('DB_PORT', '3306');

// Habilitar el reporte de errores de MySQLi como excepciones para un manejo más robusto
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Crear la conexión usando las constantes definidas
    // Esta línea crea la variable $mysqli que usarán los otros scripts
    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
    
    // Establecer el charset a utf8mb4 para un correcto manejo de emojis y caracteres especiales
    if (!$mysqli->set_charset("utf8mb4")) {
        error_log("Error al establecer el charset utf8mb4: " . $mysqli->error);
        // No es necesariamente un error fatal aquí, pero se loguea.
    }

} catch (mysqli_sql_exception $e) {
    // Si la conexión falla, $mysqli->connect_error o $e->getMessage() contendrán el error.
    error_log("Error de conexión a la base de datos: " . $e->getMessage() . " (Código: " . $e->getCode() . ")");
    
    // Intentar enviar una respuesta JSON genérica al cliente
    // Esto es importante si db_config.php es incluido por un script de API
    if (!headers_sent()) { // Solo enviar cabeceras si no se han enviado ya
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500); // Error interno del servidor
    }
    // Usamos directamente json_encode aquí porque la función jsonResponse() está en api.php,
    // y podría no estar disponible si db_config.php falla muy temprano.
    echo json_encode(["error" => "Error interno del servidor: No se pudo conectar con la base de datos."]);
    exit(); // Detener la ejecución del script si la conexión falla
}

// Si todo ha ido bien, la variable $mysqli ahora está disponible globalmente 
// para ser usada por otros scripts que incluyan este archivo (como tu api.php principal).
?>