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
            // Incluir el campo is_admin para recuperación
            $stmt_check = $mysqli->prepare("SELECT id, username, is_admin FROM usuarios WHERE email = ?");
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            
            if ($result->num_rows > 0) {
                // El usuario ya existe, iniciar sesión
                $user = $result->fetch_assoc();
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email_verified'] = true; // Google ya verificó el email
                $_SESSION['is_admin'] = (bool)$user['is_admin']; // Nuevo: rol de administrador

                json_response([
                    'success' => true,
                    'message' => 'Inicio de sesión exitoso.',
                    'username' => $user['username'],
                    'email_verified' => true,
                    'is_admin' => (bool)$user['is_admin']
                ]);
            } else {
                // El usuario no existe, crearlo
                // Generar una contraseña aleatoria y segura, ya que el usuario usará Google para iniciar sesión
                $random_password = bin2hex(random_bytes(16)); 
                $password_hash = password_hash($random_password, PASSWORD_DEFAULT);
                
                // Usar el nombre de Google como nombre de usuario, o una versión saneada
                // INICIO DE LA MODIFICACIÓN
                // Paso 1: Eliminar cualquier carácter que NO sea alfanumérico o un espacio.
                // Esto mantiene los caracteres válidos del nombre (letras, números, espacios).
                $sanitized_name = preg_replace('/[^a-zA-Z0-9\s]/u', '', $nombre_usuario); 

                // Paso 2: Reemplazar secuencias de espacios múltiples (o cualquier espacio en blanco) con un solo espacio.
                $collapsed_spaces_name = preg_replace('/\s+/', ' ', $sanitized_name);

                // Paso 3: Recortar los espacios al principio y al final.
                $username_base = trim($collapsed_spaces_name);
                // FIN DE LA MODIFICACIÓN

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
                    // Para evitar nombres de usuario excesivamente largos, limitar el contador o la longitud total
                    if (strlen($final_username) > 250) { // Un límite razonable
                        $final_username = substr($username_base, 0, 240) . '_' . $counter;
                    }
                }

                // Insertar nuevo usuario, marcando el email como verificado inmediatamente
                // e incluyendo is_admin (por defecto false)
                $stmt_insert = $mysqli->prepare("INSERT INTO usuarios (username, email, password_hash, google_id, email_verified_at, is_admin) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP(), ?)");
                // Asumiendo que is_admin por defecto será false para nuevos registros de Google
                $default_is_admin = false;
                $stmt_insert->bind_param("ssssi", $final_username, $email, $password_hash, $google_id, $default_is_admin);
                
                if ($stmt_insert->execute()) {
                    // Iniciar sesión para el nuevo usuario
                    $_SESSION['usuario_id'] = $mysqli->insert_id;
                    $_SESSION['username'] = $final_username;
                    $_SESSION['email_verified'] = true; // Google ya verificó el email
                    $_SESSION['is_admin'] = $default_is_admin;

                    json_response([
                        'success' => true,
                        'message' => 'Registro e inicio de sesión exitosos.',
                        'username' => $final_username,
                        'email_verified' => true,
                        'is_admin' => $default_is_admin
                    ]);
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