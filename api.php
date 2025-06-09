<?php
// api.php (Router Principal)

// Configuración de errores para depuración (comentar/modificar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar buffer de salida para capturar cualquier salida inesperada (como errores PHP si display_errors está On)
// Esto es crucial para asegurar que solo se envíe JSON.
ob_start();

// Cabeceras CORS y de tipo de contenido
header("Access-Control-Allow-Origin: *"); // Cambiar a tu dominio específico en producción
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-HTTP-Method-Override");

// Manejar solicitud OPTIONS (pre-flight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean(); // Limpiar buffer antes de salir
    http_response_code(200);
    exit();
}

try {
    // Definir la ruta a los manejadores
    define('API_HANDLERS_PATH', __DIR__ . '/api_handlers/'); 
    
    // Incluir configuración de la base de datos
    // $mysqli estará disponible globalmente para los manejadores
    require_once 'db_config.php'; 

    // Funciones de Ayuda
    function getTodayDate() {
        return date("Y-m-d");
    }

    function jsonResponse($data, $statusCode = 200) {
        // Limpiar cualquier buffer de salida que pudiera haberse generado antes (errores PHP, etc.)
        // ob_get_level() > 0 && ob_clean(); // Alternativa a ob_end_clean() si se quiere seguir con el script
        while (ob_get_level() > 0) {
            ob_end_clean(); // Limpiar todos los niveles de buffer
        }
        // Iniciar un nuevo buffer si es necesario, aunque no debería serlo si ya se limpió.
        // ob_start(); 

        if (!headers_sent()) { 
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=UTF-8');
        }
        
        $json_output = json_encode($data);
        if ($json_output === false) {
            $json_error_msg = json_last_error_msg();
            error_log("Error en json_encode: " . $json_error_msg . " - Datos: " . print_r($data, true));
            // Asegurar que no se envíe nada más
            if (ob_get_length() > 0) { ob_clean(); } 
            echo json_encode([
                "error" => "Error interno del servidor al codificar la respuesta JSON.",
                "json_encode_error_details" => $json_error_msg
            ]);
        } else {
            echo $json_output;
        }
        
        // Si se inició un buffer aquí, enviarlo y limpiarlo.
        // if (ob_get_length() > 0) { ob_end_flush(); } 
        exit();
    }

    // --- Enrutamiento Basado en Parámetro GET ---
    $endpoint_param = $_GET['endpoint'] ?? ''; 

    // Determinar el método HTTP real y los datos del cuerpo
    $actual_http_method = $_SERVER['REQUEST_METHOD']; 
    $data_for_handler = []; // Payload JSON decodificado para POST, PUT, DELETE

    // Leer el cuerpo solo para métodos que típicamente lo llevan
    if (in_array($actual_http_method, ['POST', 'PUT', 'PATCH', 'DELETE'])) { 
        $input_data = file_get_contents("php://input");
        if ($input_data) {
            $data_for_handler = json_decode($input_data, true);
            if (json_last_error() !== JSON_ERROR_NONE && $data_for_handler === null && trim($input_data) !== '') {
                // El cuerpo no era JSON válido o estaba vacío pero no era un string vacío trimado.
                jsonResponse(["error" => "Cuerpo JSON inválido en la solicitud."], 400);
            }
        }
    }
    
    // Variable que usará el handler para determinar la acción (GET, POST, PUT, DELETE)
    $handler_http_method = $actual_http_method; 
    // Manejar _method solo si la solicitud original es POST y _method está en el payload JSON
    if ($actual_http_method === 'POST' && isset($data_for_handler['_method'])) {
        $handler_http_method = strtoupper($data_for_handler['_method']); 
    }

    // Mapa de endpoints de URL a nombres de archivo base de manejador
    $endpoint_map = [
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
            try {
                // $mysqli (global de db_config.php), $handler_http_method, $data_for_handler,
                // y las funciones jsonResponse(), getTodayDate() están disponibles en el scope del archivo incluido.
                require_once $handler_file;
            } catch (Throwable $e) { 
                error_log("Error DENTRO del manejador $handler_file: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                jsonResponse(["error" => "Error al procesar el endpoint específico: " . $e->getMessage(), "file" => basename($handler_file)], 500);
            }
        } else {
            jsonResponse(["error" => "Archivo manejador ('" . htmlspecialchars($handler_filename_base) . ".php') no encontrado para endpoint: '" . htmlspecialchars($endpoint_param) . "'. Ruta buscada: " . $handler_file], 404);
        }
    } else {
        jsonResponse(["error" => "Endpoint no mapeado o no especificado: '" . htmlspecialchars($endpoint_param) . "'"], 404);
    }

    // Cerrar la conexión si sigue abierta (aunque jsonResponse() hace exit())
    if (isset($mysqli) && $mysqli instanceof mysqli && $mysqli->thread_id) {
        $mysqli->close();
    }

} catch (Throwable $e) { // Captura errores fatales en el router principal o antes de jsonResponse
    error_log("Error FATAL no capturado en API principal: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\nInput data: " . file_get_contents("php://input"));
    // Intentar enviar una respuesta JSON, pero podría fallar si las cabeceras ya se enviaron.
    if (!headers_sent()) {
        // Limpiar cualquier buffer antes de enviar nuestra respuesta de error JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        jsonResponse(["error" => "Ocurrió un error general crítico en el servidor.", "debug_exception_message_global" => $e->getMessage()], 500);
    } else {
        // Si las cabeceras ya se enviaron, es difícil recuperarse, pero loguear es importante.
        // Esto podría imprimir JSON después de HTML si un error fatal ocurrió muy tarde.
        echo '{"error":"Error crítico del servidor después de enviar cabeceras. Revise los logs del servidor."}';
    }
}

// Asegurar que cualquier buffer restante se envíe o se limpie si jsonResponse no hizo exit()
while (ob_get_level() > 0) {
    ob_end_flush(); 
}
?>