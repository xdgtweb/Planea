<?php
// Autocargador de Composer para dependencias como la biblioteca de Google
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

// Configuración de la base de datos
require_once 'db_config.php';

// ZONA HORARIA
date_default_timezone_set('Europe/Madrid');


// Manejo de errores centralizado
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log'); // Asegúrate de que este archivo tenga permisos de escritura
// No mostrar errores en producción
$is_production = false; // Cambiar a true en producción
if ($is_production) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Iniciar la sesión en el punto de entrada de la API
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


// Función para enviar respuestas JSON
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Enrutador simple
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = '/'; 
$request_path = parse_url($request_uri, PHP_URL_PATH);

// Eliminar el base_path de la ruta de la solicitud
if (strpos($request_path, 'api.php') !== false) {
    // Si 'api.php' está en la URL, tomar el segmento después de él
    $path_parts = explode('api.php', $request_path);
    $endpoint = isset($path_parts[1]) ? $path_parts[1] : '/';
} else {
    // Fallback por si la reescritura de URL está configurada de otra manera
    $endpoint = $request_path;
}


// Mapa de endpoints a sus manejadores
$endpoint_map = [
    '/register' => 'api_handlers/register.php',
    '/login' => 'api_handlers/login.php',
    '/logout' => 'api_handlers/logout.php',
    '/session-status' => 'api_handlers/session_status.php',
    '/google-signin' => 'api_handlers/google_signin.php',

    // Rutas de la aplicación
    '/objetivos' => 'api_handlers/objetivos.php',
    '/sub-objetivos' => 'api_handlers/sub_objetivos.php',
    '/sub-objetivos-estado' => 'api_handlers/sub-objetivos-estado.php', 
    '/modos' => 'api_handlers/modos.php',
    '/anotaciones' => 'api_handlers/anotaciones.php',

    // CORRECCIÓN: Se añaden las rutas que faltaban para el calendario y las tareas.
    '/calendario-dia-a-dia' => 'api_handlers/calendario.php',
    '/tareas-dia-a-dia' => 'api_handlers/tareas_diarias.php',
    '/tareas-por-fecha' => 'api_handlers/tareas_por_fecha.php',
];

try {
    if (isset($endpoint_map[$endpoint])) {
        require $endpoint_map[$endpoint];
    } else {
        json_response(['error' => 'Endpoint no encontrado'], 404);
    }
} catch (Throwable $e) {
    // Captura cualquier error o excepción fatal
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Enviar una respuesta genérica al cliente
    if ($is_production) {
        json_response(['error' => 'Ha ocurrido un error inesperado en el servidor.'], 500);
    } else {
        // En desarrollo, enviar más detalles (cuidado con la información sensible)
        json_response([
            'error' => 'Ha ocurrido un error fatal en el servidor.',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
}

?>