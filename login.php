<?php
require_once __DIR__ . '/database.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo no permitido'
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

error_log('LOGIN username recibido: ' . $username);

if (empty($username) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Usuario y contraseÃ±a requeridos'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getConnection();

    $sql = "SELECT *
FROM USUARIO
WHERE LOWER(USERNAME) = LOWER(:username)";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $e = oci_error($conn);
        throw new Exception($e['message'] ?? 'Error preparando consulta Oracle');
    }

    if (!oci_bind_by_name($stmt, ':username', $username)) {
        $e = oci_error($stmt);
        throw new Exception($e['message'] ?? 'Error enlazando username');
    }
    if (!oci_execute($stmt)) {
        $e = oci_error($stmt);
        throw new Exception($e['message'] ?? 'Error ejecutando consulta Oracle');
    }

    $row = oci_fetch_assoc($stmt);

    error_log('LOGIN usuario encontrado: ' . ($row ? 'SI' : 'NO'));

    if ($row) {
        error_log('PASSWORD_COLUMN_EXISTS=' .
            (isset($row['PASSWORD']) ? 'YES' : 'NO'));
        error_log('USERNAME_COLUMN_EXISTS=' .
            (isset($row['USERNAME']) ? 'YES' : 'NO'));

        $storedPassword = $row['PASSWORD'];
        $passwordIngresada = $password;

        if (str_starts_with($storedPassword, '$2')) {
            $validPassword = password_verify(
                $passwordIngresada,
                $storedPassword
            );
        } else {
            $validPassword =
                $storedPassword === $passwordIngresada;
        }

        error_log('HASH_PREFIX=' . substr($storedPassword, 0, 3));
        error_log(
            'PASSWORD_VALID=' .
            ($validPassword ? 'TRUE' : 'FALSE')
        );
        if ($validPassword) {
            error_log('LOGIN exitoso para username: ' . $username);
            echo json_encode([
                'success' => true,
                'message' => 'Login correcto',
                'user' => [
                    'id_usuario' => $row['ID_USUARIO'],
                    'username' => $row['USERNAME'],
                    'estado' => $row['ESTADO']
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Usuario o contraseÃ±a incorrectos'
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Usuario o contraseÃ±a incorrectos'
        ], JSON_UNESCAPED_UNICODE);
    }

    oci_free_statement($stmt);
    oci_close($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexiÃ³n Oracle',
        'oracle_error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}



