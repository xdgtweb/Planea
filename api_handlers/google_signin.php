<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Manejar la petición POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->token)) {
        json_response(['error' => 'No se proporcionó el token de Google'], 400);
        return;
    }

    $id_token = $data->token;

    // CAMBIAR: Reemplaza este valor con tu propio ID de cliente de Google
    $CLIENT_ID = '356486997376-1ctts9tjobjh4bl2b70lcms4dq98se3l.apps.googleusercontent.com';

    $client = new Google_Client(['client_id' => $CLIENT_ID]);
    
    try {
        $payload = $client->verifyIdToken($id_token);

        if ($payload) {
            $email = $payload['email'];
            $nombre_usuario = $payload['name'];
            $google_id = $payload['sub'];

            // Verificar si el usuario ya existe en la base de datos
            $stmt_check = $mysqli->prepare("SELECT id, username FROM usuarios WHERE email = ?");
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            
            if ($result->num_rows > 0) {
                // El usuario ya existe, iniciar sesión
                $user = $result->fetch_assoc();
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                json_response(['success' => true, 'message' => 'Inicio de sesión exitoso.']);
            } else {
                // El usuario no existe, crearlo
                // Generar una contraseña aleatoria y segura, ya que el usuario usará Google para iniciar sesión
                $random_password = bin2hex(random_bytes(16)); 
                $password_hash = password_hash($random_password, PASSWORD_DEFAULT);
                
                // Usar el nombre de Google como nombre de usuario, o una versión saneada
                $username_base = preg_replace('/[^a-zA-Z0-9]/', '', $nombre_usuario);
                $final_username = $username_base;
                $counter = 1;
                // Asegurarse de que el nombre de usuario sea único
                while (true) {
                    $stmt_user_check = $mysqli->prepare("SELECT id FROM usuarios WHERE username = ?");
                    $stmt_user_check->bind_param("s", $final_username);
                    $stmt_user_check->execute();
                    if ($stmt_user_check->get_result()->num_rows == 0) {
                        break;
                    }
                    $final_username = $username_base . $counter++;
                }


                $stmt_insert = $mysqli->prepare("INSERT INTO usuarios (username, email, password_hash, google_id) VALUES (?, ?, ?, ?)");
                $stmt_insert->bind_param("ssss", $final_username, $email, $password_hash, $google_id);
                
                if ($stmt_insert->execute()) {
                    // Iniciar sesión para el nuevo usuario
                    $_SESSION['usuario_id'] = $mysqli->insert_id;
                    $_SESSION['username'] = $final_username;
                    json_response(['success' => true, 'message' => 'Registro e inicio de sesión exitosos.']);
                } else {
                    json_response(['error' => 'Error al registrar el nuevo usuario.'], 500);
                }
            }
        } else {
            // Token inválido
            json_response(['error' => 'Token de Google inválido.'], 401);
        }
    } catch (Exception $e) {
        // Capturar errores de la verificación del token
        json_response(['error' => 'Error al verificar el token: ' . $e->getMessage()], 500);
    }
}
?>