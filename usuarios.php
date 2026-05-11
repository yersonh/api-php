<?php
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function jsonInputUsuarios() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function respondUsuarios($success, $message, $data = null, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function parseEstadoUsuario($value) {
    if (is_bool($value)) {
        return $value ? 'ACTIVO' : 'INACTIVO';
    }

    if (is_numeric($value)) {
        return ((int)$value) === 1 ? 'ACTIVO' : 'INACTIVO';
    }

    $text = strtoupper(trim((string)$value));
    if (in_array($text, ['1', 'TRUE', 'ACTIVO', 'SI', 'YES'], true)) {
        return 'ACTIVO';
    }
    return 'INACTIVO';
}

function listarUsuariosAdministrables($conn) {
    $sql = "SELECT U.ID_USUARIO, U.ID_PERSONA, U.ID_TIPO, U.USERNAME, U.ESTADO,
T.NOMBRE AS TIPO_NOMBRE,
P.NOMBRES, P.APELLIDOS, P.CORREO, P.TELEFONO
FROM USUARIO U
LEFT JOIN TIPO_USUARIO T ON T.ID_TIPO = U.ID_TIPO
LEFT JOIN PERSONA P ON P.ID_PERSONA = U.ID_PERSONA
WHERE U.ID_TIPO IN (1, 5)
ORDER BY T.ID_TIPO, U.USERNAME";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        $error = $stmt ? oci_error($stmt) : oci_error($conn);
        throw new Exception($error['message'] ?? 'Error consultando usuarios');
    }

    $usuarios = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $usuarios[] = [
            'id_usuario' => (int)$row['ID_USUARIO'],
            'id_persona' => $row['ID_PERSONA'] !== null ? (int)$row['ID_PERSONA'] : null,
            'id_tipo' => $row['ID_TIPO'] !== null ? (int)$row['ID_TIPO'] : null,
            'tipo_nombre' => $row['TIPO_NOMBRE'] ?? null,
            'username' => $row['USERNAME'],
            'estado' => $row['ESTADO'],
            'nombre' => $row['NOMBRES'] ?? null,
            'primer_apellido' => $row['APELLIDOS'] ?? null,
            'email' => $row['CORREO'] ?? null,
            'persona' => [
                'id' => $row['ID_PERSONA'] !== null ? (int)$row['ID_PERSONA'] : null,
                'nombre' => $row['NOMBRES'] ?? null,
                'primer_apellido' => $row['APELLIDOS'] ?? null,
                'correo' => $row['CORREO'] ?? null,
                'telefono' => $row['TELEFONO'] !== null ? (int)$row['TELEFONO'] : null,
            ],
        ];
    }

    oci_free_statement($stmt);
    respondUsuarios(true, 'Usuarios cargados', $usuarios);
}

function actualizarEstadoUsuario($conn, $data) {
    $idUsuario = (int)($data['id_usuario'] ?? $data['ID_USUARIO'] ?? 0);
    if ($idUsuario <= 0) {
        respondUsuarios(false, 'ID de usuario requerido', null, 400);
    }

    $estadoSource = $data['estado'] ?? $data['ESTADO'] ?? $data['activo'] ?? null;
    if ($estadoSource === null) {
        respondUsuarios(false, 'Estado requerido', null, 400);
    }
    $estado = parseEstadoUsuario($estadoSource);

    $sql = "UPDATE USUARIO
SET ESTADO = :estado, UPDATED_AT = SYSTIMESTAMP
WHERE ID_USUARIO = :id_usuario
AND ID_TIPO IN (1, 5)";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $error = oci_error($conn);
        throw new Exception($error['message'] ?? 'Error preparando actualizacion');
    }

    oci_bind_by_name($stmt, ':estado', $estado);
    oci_bind_by_name($stmt, ':id_usuario', $idUsuario);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = oci_error($stmt);
        throw new Exception($error['message'] ?? 'Error actualizando usuario');
    }

    $rows = oci_num_rows($stmt);
    if ($rows < 1) {
        oci_rollback($conn);
        respondUsuarios(false, 'Usuario no encontrado o no administrable', null, 404);
    }

    oci_commit($conn);
    oci_free_statement($stmt);
    respondUsuarios(true, 'Estado actualizado');
}

try {
    $conn = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $data = jsonInputUsuarios();
    $accion = strtolower(trim($_GET['accion'] ?? $data['accion'] ?? 'listar'));

    if ($method === 'GET') {
        listarUsuariosAdministrables($conn);
    }

    if ($method === 'POST' && in_array($accion, ['listar', 'administrables'], true)) {
        listarUsuariosAdministrables($conn);
    }

    if ($method === 'POST' && in_array($accion, ['estado', 'actualizar_estado'], true)) {
        actualizarEstadoUsuario($conn, $data);
    }

    respondUsuarios(false, 'Metodo no permitido', null, 405);
} catch (Exception $e) {
    if (isset($conn) && $conn) {
        @oci_rollback($conn);
    }
    respondUsuarios(false, 'Error en usuarios', ['error' => $e->getMessage()], 500);
} finally {
    if (isset($conn) && $conn) {
        @oci_close($conn);
    }
}
