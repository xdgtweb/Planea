<?php
// api.php (Router Principal)

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-HTTP-Method-Override");
header("Access-control-allow-credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

try {
    define('API_HANDLERS_PATH', __DIR__ . '/api_handlers/'); 
    require_once 'db_config.php'; 

    function getTodayDate() {
        return date("Y-m-d");
    }

    function jsonResponse($data, $statusCode = 200) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { 
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        $json_output = json_encode($data);
        if ($json_output === false) {
            error_log("Error en json_encode: " . json_last_error_msg());
            if (ob_get_length() > 0) { ob_clean(); } 
            echo json_encode(["error" => "Error interno del servidor al codificar JSON."]);
        } else {
            echo $json_output;
        }
        exit();
    }

    $endpoint_param = $_GET['endpoint'] ?? ''; 
    $actual_http_method = $_SERVER['REQUEST_METHOD']; 
    $data_for_handler = []; 

    if (in_array($actual_http_method, ['POST', 'PUT', 'PATCH', 'DELETE'])) { 
        $input_data = file_get_contents("php://input");
        if ($input_data) {
            $data_for_handler = json_decode($input_data, true);
            if (json_last_error() !== JSON_ERROR_NONE && $data_for_handler === null && trim($input_data) !== '') {
                jsonResponse(["error" => "Cuerpo JSON inválido en la solicitud."], 400);
            }
        }
    }
    
    $handler_http_method = $actual_http_method; 
    if ($actual_http_method === 'POST' && isset($data_for_handler['_method'])) {
        $handler_http_method = strtoupper($data_for_handler['_method']); 
    }

    $endpoint_map = [
        'register'             => 'register',
        'login'                => 'login',
        'logout'               => 'logout',
        'session-status'       => 'session_status',
        'modos'                => 'modos',                 
        'objetivos'            => 'objetivos',             
        'sub_objetivos'        => 'sub_objetivos',         
        'sub-objetivos-estado' => 'sub_objetivos_estado',  
        'tareas-dia-a-dia'     => 'tareas_diarias',        
        'calendario-dia-a-dia' => 'calendario',            
        'tareas-por-fecha'     => 'tareas_por_fecha',      
        'anotaciones'          => 'anotaciones'            
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
    error_log("Error FATAL no capturado en API: " . $e->getMessage());
    if (!headers_sent()) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        jsonResponse(["error" => "Ocurrió un error general crítico en el servidor."], 500);
    }
}

while (ob_get_level() > 0) { ob_end_flush(); }
?>