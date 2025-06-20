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
$is_production = true; // CAMBIAR ESTO A TRUE PARA DESHABILITAR display_errors en producción
if ($is_production) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1); // ESTO DEBERÍA ESTAR EN 0 EN PRODUCCIÓN O EN DESARROLLO CUANDO SE ESPERA JSON
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

// *** INICIO DE LAS MODIFICACIONES PARA NUEVA ESTRATEGIA DE ENRUTAMIENTO ***

// Leer el cuerpo RAW de la petición (donde ahora viene todo)
$raw_input = file_get_contents("php://input");
$request_payload = json_decode($raw_input, true); // Decodificar el JSON completo

if ($request_payload === null && json_last_error() !== JSON_ERROR_NONE) {
    json_response(['error' => 'Invalid JSON body in POST request: ' . json_last_error_msg()], 400);
}
if (!is_array($request_payload)) {
    json_response(['error' => 'Invalid data format, expected JSON object/array in POST body.'], 400);
}

// Extraer el endpoint y los parámetros de la API del payload
$endpoint_from_payload = $request_payload['_api_endpoint'] ?? null;
$params_from_payload = $request_payload['_api_params'] ?? [];
$original_method_from_payload = $request_payload['_api_original_method'] ?? 'POST'; // Asumir POST si no se especifica

// Eliminar estas claves internas del payload principal para que no interfieran con los manejadores
unset($request_payload['_api_endpoint']);
unset($request_payload['_api_params']);
unset($request_payload['_api_original_method']);

// Asignar el endpoint derivado del payload
$endpoint = '/' . trim($endpoint_from_payload, '/');

// *** SIMULAR LAS VARIABLES GLOBALES DE PHP para compatibilidad con los manejadores existentes ***
// Simular $_SERVER['REQUEST_METHOD']
$_SERVER['REQUEST_METHOD'] = $original_method_from_payload;

// Simular $_GET con los parámetros extraídos del payload
$_GET = $params_from_payload;

// Simular $_POST con el resto del payload (útil para manejadores que leen $_POST directamente)
$_POST = $request_payload; 

// Simular $_REQUEST
$_REQUEST = array_merge($_GET, $_POST); // Merge GET and POST params

// *** FIN DE LAS MODIFICACIONES PARA NUEVA ESTRATEGIA DE ENRUTAMIENTO ***


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

    // Rutas para calendario y tareas
    '/calendario-dia-a-dia' => 'api_handlers/calendario.php',
    '/tareas-dia-a-dia' => 'api_handlers/tareas_diarias.php',
    '/tareas-por-fecha' => 'api_handlers/tareas_por_fecha.php',
];

try {
    if (isset($endpoint_map[$endpoint])) {
        // Para los manejadores que leen el cuerpo raw (como tareas_diarias), necesitan el JSON original.
        // Como ya lo leímos y decodificamos en $request_payload (que está en $_POST),
        // aseguramos que file_get_contents("php://input") pueda ser leído de nuevo o simulado.
        // Es una práctica mejor que los manejadores accedan a $_POST directamente en este setup.
        // Sin embargo, si leen file_get_contents("php://input") de nuevo, podría estar vacío.
        // La mejor solución es que los manejadores usen $_POST en este esquema.
        
        // Manejadores como tareas_diarias.php leen: $data = json_decode(file_get_contents("php://input"), true);
        // Esto ahora debe cambiarse para que lean directamente de $_POST o de $request_payload.
        // Haremos que $data en los manejadores sea $request_payload para la compatibilidad.
        
        // Paso temporal para evitar un error en manejadores que intenten leer file_get_contents("php://input")
        // No es la forma más limpia, pero fuerza la compatibilidad sin modificar cada manejador.
        // Es preferible modificar cada manejador para que lea directamente de $_POST si el método es POST.
        
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