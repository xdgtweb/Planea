<?php
// Manejar la petición POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email) || !isset($data->password)) {
        json_response(['error' => 'Faltan el correo electrónico o la contraseña'], 400);
        return;
    }

    $email = $data->email;
    $password = $data->password;

    // Buscar usuario por email, incluyendo los nuevos campos 'email_verified_at' e 'is_admin'
    $stmt = $mysqli->prepare("SELECT id, username, password_hash, email_verified_at, is_admin FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Verificar la contraseña
        if (password_verify($password, $user['password_hash'])) {
            // Contraseña correcta, iniciar sesión
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email_verified'] = !empty($user['email_verified_at']); // Nuevo: estado de verificación del email
            $_SESSION['is_admin'] = (bool)$user['is_admin']; // Nuevo: rol de administrador

            json_response([
                'success' => true,
                'message' => 'Inicio de sesión exitoso',
                'username' => $user['username'],
                'email_verified' => !empty($user['email_verified_at']), // Enviar también al frontend
                'is_admin' => (bool)$user['is_admin'] // Enviar también al frontend
            ]);
        } else {
            // Contraseña incorrecta
            json_response(['success' => false, 'message' => 'La contraseña es incorrecta.'], 401);
        }
    } else {
        // Usuario no encontrado
        json_response(['success' => false, 'message' => 'No se encontró un usuario con ese correo electrónico.'], 404);
    }

    $stmt->close();
}
?>