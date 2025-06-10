<?php
// api.php (VERSIÓN DE DEPURACIÓN AVANZADA)

// 1. Forzar la visualización de errores (puede que el hosting lo bloquee, pero lo intentamos)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Definimos una función de respuesta JSON simple que usaremos en caso de error
function send_json_error_and_exit($error_details, $status_code = 500) {
    if (!headers_sent()) {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    // Usamos @ para suprimir cualquier error que pudiera dar json_encode
    echo @json_encode($error_details);
    exit();
}

// 3. Capturamos cualquier error fatal que ocurra en el script
// Usamos Throwable para capturar tanto Errores como Excepciones en PHP 7+
try {
    session_start();

    // Cargar librerías de Google (si existen)
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    // El resto de la lógica del enrutador
    define('API_HANDLERS_PATH', __DIR__ . '/api_handlers/'); 
    require_once 'db_config.php'; 

    function getTodayDate() { return date("Y-m-d"); }
    function jsonResponse($data, $statusCode = 200) {
        send_json_error_and_exit($data, $statusCode);
    }

    $endpoint_param = $_GET['endpoint'] ?? ''; 
    $actual_http_method = $_SERVER['REQUEST_METHOD']; 
    $data_for_handler = []; 

    if (in_array($actual_http_method, ['POST', 'PUT', 'DELETE'])) { 
        $input_data = file_get_contents("php://input");
        if ($input_data) {
            $data_for_handler = json_decode($input_data, true);
            if (json_last_error() !== JSON_ERROR_NONE && $data_for_handler === null) {
                jsonResponse(["error" => "Cuerpo JSON inválido en la solicitud."], 400);
            }
        }
    }
    
    $handler_http_method = $actual_http_method; 
    if ($actual_http_method === 'POST' && isset($data_for_handler['_method'])) {
        $handler_http_method = strtoupper($data_for_handler['_method']); 
    }

    $endpoint_map = [
        'google-signin' => 'google_signin', 'register' => 'register', 'login' => 'login',
        'logout' => 'logout', 'session-status' => 'session_status', 'modos' => 'modos',                 
        'objetivos' => 'objetivos', 'sub_objetivos' => 'sub_objetivos',         
        'sub-objetivos-estado' => 'sub_objetivos_estado', 'tareas-dia-a-dia' => 'tareas_diarias',        
        'calendario-dia-a-dia' => 'calendario', 'tareas-por-fecha' => 'tareas_por_fecha',      
        'anotaciones' => 'anotaciones'            
    ];

    $handler_key = strtolower($endpoint_param);
    $handler_filename_base = $endpoint_map[$handler_key] ?? null;

    if ($handler_filename_base) {
        $handler_file = API_HANDLERS_PATH . $handler_filename_base . '.php';
        if (file_exists($handler_file)) {
            require_once $handler_file;
        } else {
            jsonResponse(["error" => "Archivo manejador no encontrado."], 404);
        }
    } else {
        jsonResponse(["error" => "Endpoint no especificado o inválido."], 404);
    }

} catch (Throwable $e) {
    // Si ocurre CUALQUIER error fatal en CUALQUIER parte, lo capturamos aquí
    $error_details = [
        "error" => "Ha ocurrido un error fatal en el servidor.",
        "exception_type" => get_class($e),
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ];
    // Escribimos el error en el log del servidor para tener un registro
    error_log(print_r($error_details, true));
    // E intentamos enviar los detalles al navegador como respuesta JSON
    send_json_error_and_exit($error_details);
}
?>