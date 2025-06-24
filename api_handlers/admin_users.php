<?php
// api_handlers/admin_users.php
// Este archivo maneja las funcionalidades de administración de usuarios.

// Verificar si la sesión está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Asegurarse de que el usuario esté logueado y sea administrador para acceder a este endpoint
if (!isset($_SESSION['usuario_id']) || !($_SESSION['is_admin'] ?? false)) {
    json_response(['error' => 'Acceso no autorizado. Se requiere ser administrador.'], 403);
    exit;
}

// Incluir db_config.php para la conexión a la base de datos
// (Asumiendo que api.php ya lo incluyó, pero es buena práctica para acceso directo si fuera el caso)
require_once __DIR__ . '/../db_config.php';

// La función json_response ya debe estar definida en api.php
// No la definimos de nuevo aquí para evitar errores si ya existe.

// Determinar el método HTTP real o el método simulado por _method
$method = $_SERVER['REQUEST_METHOD'];
$request_payload = json_decode(file_get_contents("php://input"), true);
$_method_override = $request_payload['_method'] ?? $method; // Si se envía _method en el payload

// Determinar la acción a realizar
$action = $_GET['action'] ?? ($request_payload['action'] ?? null);

switch ($action) {
    case 'list': // Obtener la lista de usuarios
        if ($method === 'GET' || $_method_override === 'GET') {
            $stmt = $mysqli->prepare("SELECT id, username, email FROM usuarios ORDER BY username ASC");
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta de usuarios: ' . $mysqli->error], 500);
                return;
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            
            json_response($users);
            $stmt->close();
        } else {
            json_response(['error' => 'Método no permitido para esta acción'], 405);
        }
        break;

    case 'login-as': // Iniciar sesión como otro usuario
        if ($method === 'POST' || $_method_override === 'POST') {
            $target_user_id = $request_payload['target_user_id'] ?? 0;

            if ($target_user_id <= 0) {
                json_response(['error' => 'ID de usuario objetivo inválido'], 400);
                return;
            }

            // Para mayor seguridad, el administrador NO PUEDE iniciar sesión como otro administrador
            // para evitar elevación de privilegios o ciclos.
            $stmt = $mysqli->prepare("SELECT id, username, is_admin FROM usuarios WHERE id = ? AND is_admin = FALSE");
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta de login-as: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user) {
                // Guardar el estado original del administrador antes de cambiar la sesión
                $_SESSION['is_admin_original'] = $_SESSION['is_admin'];
                $_SESSION['original_user_id'] = $_SESSION['usuario_id'];
                $_SESSION['original_username'] = $_SESSION['username'];
                // Podrías guardar también el email_verified original si es relevante

                // Cambiar la sesión a la del usuario objetivo
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email_verified'] = true; // Asumimos verificado al loguearse como
                $_SESSION['is_admin'] = (bool)$user['is_admin']; // Se convierte en el rol del usuario objetivo

                json_response(['success' => true, 'message' => 'Sesión cambiada exitosamente.', 'username' => $user['username']]);
            } else {
                json_response(['error' => 'Usuario no encontrado o es un administrador. No se puede acceder como administrador.'], 404);
            }
        } else {
            json_response(['error' => 'Método no permitido para esta acción'], 405);
        }
        break;

    case 'revert-login': // Revertir a la sesión original del administrador
        if ($method === 'POST' || $_method_override === 'POST') {
            if (isset($_SESSION['is_admin_original']) && $_SESSION['is_admin_original'] === true && isset($_SESSION['original_user_id'])) {
                $_SESSION['usuario_id'] = $_SESSION['original_user_id'];
                $_SESSION['username'] = $_SESSION['original_username'];
                $_SESSION['email_verified'] = true; // Asumimos que el admin tiene el correo verificado
                $_SESSION['is_admin'] = $_SESSION['is_admin_original'];

                // Limpiar variables temporales de la sesión
                unset($_SESSION['is_admin_original']);
                unset($_SESSION['original_user_id']);
                unset($_SESSION['original_username']);

                json_response(['success' => true, 'message' => 'Sesión revertida exitosamente a la del administrador.', 'username' => $_SESSION['username']]);
            } else {
                json_response(['error' => 'No hay una sesión de administrador para revertir.'], 400);
            }
        } else {
            json_response(['error' => 'Método no permitido para esta acción'], 405);
        }
        break;

    default:
        json_response(['error' => 'Acción no especificada o no válida'], 400);
        break;
}
?>