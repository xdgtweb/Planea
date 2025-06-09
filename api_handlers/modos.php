<?php
// api_handlers/modos.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    // Esta comprobación es una salvaguarda, el router principal ya debería haberla manejado.
    if (function_exists('jsonResponse')) {
        jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en modos.php."], 500);
    } else {
        http_response_code(500);
        echo '{"error":"Error crítico: Conexión a base de datos no disponible en modos.php."}';
    }
    exit;
}
if (!isset($handler_http_method)) {
    if (function_exists('jsonResponse')) {
        jsonResponse(["error" => "Error crítico: Método HTTP no determinado en modos.php."], 500);
    } else {
        http_response_code(500);
        echo '{"error":"Error crítico: Método HTTP no determinado en modos.php."}';
    }
    exit;
}

// El endpoint /modos solo soporta el método GET
if ($handler_http_method === 'GET') {
    try {
        $modos = [];
        $sql = "SELECT id, nombre FROM modos ORDER BY orden ASC"; // Asegurar un orden consistente
        $result = $mysqli->query($sql);
        
        if (!$result) {
            // Si hay un error en la consulta, $mysqli->query() devuelve false
            throw new mysqli_sql_exception("Error en la consulta SQL para modos: " . $mysqli->error . " (SQL: " . $sql . ")");
        }
        
        while ($row = $result->fetch_assoc()) {
            $modos[] = $row;
        }
        $result->free(); // Liberar el conjunto de resultados
        
        // jsonResponse() se define en el api.php principal y hace exit()
        jsonResponse($modos);

    } catch (mysqli_sql_exception $e) {
        error_log("Error en /modos GET (SQL): " . $e->getMessage());
        jsonResponse(["error" => "Error al consultar los modos.", "details_debug" => $e->getMessage()], 500);
    } catch (Exception $e) { // Capturar otras excepciones generales
        error_log("Error inesperado en /modos GET: " . $e->getMessage());
        jsonResponse(["error" => "Ocurrió un error inesperado al procesar su solicitud de modos."], 500);
    }
} else {
    // Si se intenta acceder con un método diferente a GET
    jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no permitido para el endpoint /modos. Solo se acepta GET."], 405);
}

?>