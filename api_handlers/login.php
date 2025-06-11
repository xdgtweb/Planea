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

    // Buscar usuario por email
    $stmt = $mysqli->prepare("SELECT id, username, password_hash FROM usuarios WHERE email = ?");
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

            json_response(['success' => true, 'message' => 'Inicio de sesión exitoso', 'username' => $user['username']]);
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