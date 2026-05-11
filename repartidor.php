<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/token_helper.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Autenticación por Bearer token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = null;
if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    $token = $matches[1];
}
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$accion = $_GET['accion'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = getConnection();

    // Obtener id_usuario desde el token (buscamos en sesión o validamos directamente)
    // El token es el id_usuario codificado en base64 por simplicidad; ajustar según tu lógica de auth
    $idUsuario = validarToken($token);
    if (!$idUsuario) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token inválido']);
        exit;
    }

    if ($method === 'GET' && $accion === 'perfil') {
        handlePerfil($conn, $idUsuario);
    } elseif ($method === 'GET' && $accion === 'pedidos') {
        handlePedidos($conn, $idUsuario);
    } elseif ($method === 'POST' && $accion === 'ubicacion') {
        handleUbicacion($conn, $idUsuario);
    } elseif ($method === 'POST' && $accion === 'tomar_pedido') {
        handleTomarPedido($conn, $idUsuario);
    } elseif ($method === 'POST' && $accion === 'estado_entrega') {
        handleEstadoEntrega($conn, $idUsuario);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }

    oci_close($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ─── Perfil del repartidor ───────────────────────────────────────────────────
function handlePerfil($conn, int $idUsuario): void {
    $sql = "SELECT r.ID_REPARTIDOR, r.ID_PERSONA, r.ID_USUARIO,
                   p.NOMBRES, p.APELLIDOS, p.CORREO, p.TELEFONO,
                   r.TIPO_VEHICULO, r.PLACA_VEHICULO, r.LICENCIA_CONDUCCION,
                   r.CATEGORIA_LICENCIA, r.ZONA_ASIGNADA, r.ESTADO,
                   r.CALIFICACION, r.LATITUD, r.LONGITUD
            FROM REPARTIDOR r
            JOIN PERSONA p ON p.ID_PERSONA = r.ID_PERSONA
            WHERE r.ID_USUARIO = :id_usuario";

    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':id_usuario', $idUsuario);
    oci_execute($stmt);
    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Repartidor no encontrado']);
        return;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id_repartidor'      => (int) $row['ID_REPARTIDOR'],
            'id_persona'         => (int) $row['ID_PERSONA'],
            'id_usuario'         => (int) $row['ID_USUARIO'],
            'nombres'            => $row['NOMBRES'],
            'apellidos'          => $row['APELLIDOS'],
            'correo'             => $row['CORREO'],
            'telefono'           => $row['TELEFONO'],
            'tipo_vehiculo'      => $row['TIPO_VEHICULO'],
            'placa_vehiculo'     => $row['PLACA_VEHICULO'],
            'licencia_conduccion'=> $row['LICENCIA_CONDUCCION'],
            'categoria_licencia' => $row['CATEGORIA_LICENCIA'],
            'zona_asignada'      => $row['ZONA_ASIGNADA'],
            'estado'             => $row['ESTADO'],
            'calificacion'       => $row['CALIFICACION'] !== null ? (float) $row['CALIFICACION'] : null,
            'latitud'            => $row['LATITUD'] !== null ? (float) $row['LATITUD'] : null,
            'longitud'           => $row['LONGITUD'] !== null ? (float) $row['LONGITUD'] : null,
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// ─── Pedidos asignados ───────────────────────────────────────────────────────
function handlePedidos($conn, int $idUsuario): void {
    $sql = "SELECT pe.ID_PEDIDO, pe.ID_VENTA, pe.ID_ESTADO, pe.FECHA_ESTIMADA_ENTREGA,
                   ep.NOMBRE AS ESTADO_NOMBRE,
                   en.ID_ENTREGA, en.ESTADO_ENTREGA, en.NOTAS,
                   en.LATITUD AS LATITUD_ENTREGA, en.LONGITUD AS LONGITUD_ENTREGA,
                   dp.NOMBRE_RECEPTOR, dp.APELLIDO_RECEPTOR, dp.DIRECCION_ENVIO,
                   dp.CIUDAD, dp.BARRIO, dp.TELEFONO_RECEPTOR, dp.INFORMACION_ADICIONAL,
                   v.TOTAL
            FROM PEDIDO pe
            JOIN REPARTIDOR r ON r.ID_REPARTIDOR = pe.ID_REPARTIDOR
            LEFT JOIN ESTADO_PEDIDO ep ON ep.ID_ESTADO = pe.ID_ESTADO
            LEFT JOIN ENTREGA_PEDIDO en ON en.ID_PEDIDO = pe.ID_PEDIDO AND en.ID_REPARTIDOR = r.ID_REPARTIDOR
            LEFT JOIN DIRECCION_PEDIDO dp ON dp.ID_PEDIDO = pe.ID_PEDIDO
            LEFT JOIN VENTA v ON v.ID_VENTA = pe.ID_VENTA
            WHERE r.ID_USUARIO = :id_usuario
            ORDER BY pe.ID_PEDIDO DESC";

    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':id_usuario', $idUsuario);
    oci_execute($stmt);

    $pedidos = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $pedidos[] = [
            'id_pedido'              => (int) $row['ID_PEDIDO'],
            'id_venta'               => (int) $row['ID_VENTA'],
            'id_estado'              => $row['ID_ESTADO'] !== null ? (int) $row['ID_ESTADO'] : null,
            'estado_nombre'          => $row['ESTADO_NOMBRE'],
            'fecha_estimada_entrega' => $row['FECHA_ESTIMADA_ENTREGA'],
            'id_entrega'             => $row['ID_ENTREGA'] !== null ? (int) $row['ID_ENTREGA'] : null,
            'estado_entrega'         => $row['ESTADO_ENTREGA'],
            'notas'                  => $row['NOTAS'],
            'latitud_entrega'        => $row['LATITUD_ENTREGA'] !== null ? (float) $row['LATITUD_ENTREGA'] : null,
            'longitud_entrega'       => $row['LONGITUD_ENTREGA'] !== null ? (float) $row['LONGITUD_ENTREGA'] : null,
            'nombre_receptor'        => $row['NOMBRE_RECEPTOR'],
            'apellido_receptor'      => $row['APELLIDO_RECEPTOR'],
            'direccion_envio'        => $row['DIRECCION_ENVIO'],
            'ciudad'                 => $row['CIUDAD'],
            'barrio'                 => $row['BARRIO'],
            'telefono_receptor'      => $row['TELEFONO_RECEPTOR'],
            'informacion_adicional'  => $row['INFORMACION_ADICIONAL'],
            'total'                  => $row['TOTAL'] !== null ? (float) $row['TOTAL'] : null,
        ];
    }
    oci_free_statement($stmt);

    echo json_encode(['success' => true, 'data' => $pedidos], JSON_UNESCAPED_UNICODE);
}

// ─── Tomar pedido escaneando QR ─────────────────────────────────────────────
function handleTomarPedido($conn, int $idUsuario): void {
    $data     = json_decode(file_get_contents('php://input'), true);
    $idPedido = (int) ($data['id_pedido'] ?? 0);

    if (!$idPedido) {
        echo json_encode(['success' => false, 'message' => 'ID de pedido inválido']);
        return;
    }

    // Obtener id_repartidor
    $stmtR = oci_parse($conn, 'SELECT ID_REPARTIDOR FROM REPARTIDOR WHERE ID_USUARIO = :id_usuario');
    oci_bind_by_name($stmtR, ':id_usuario', $idUsuario);
    oci_execute($stmtR);
    $rowR = oci_fetch_assoc($stmtR);
    oci_free_statement($stmtR);

    if (!$rowR) {
        echo json_encode(['success' => false, 'message' => 'Repartidor no encontrado']);
        return;
    }
    $idRepartidor = (int) $rowR['ID_REPARTIDOR'];

    // Verificar que el pedido existe y no tiene repartidor asignado
    $stmtP = oci_parse($conn, 'SELECT ID_PEDIDO, ID_REPARTIDOR FROM PEDIDO WHERE ID_PEDIDO = :id_pedido');
    oci_bind_by_name($stmtP, ':id_pedido', $idPedido);
    oci_execute($stmtP);
    $rowP = oci_fetch_assoc($stmtP);
    oci_free_statement($stmtP);

    if (!$rowP) {
        echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
        return;
    }
    if ($rowP['ID_REPARTIDOR'] !== null) {
        echo json_encode(['success' => false, 'message' => 'Este pedido ya tiene un repartidor asignado']);
        return;
    }

    // Asignar repartidor al pedido
    $stmtU = oci_parse($conn, 'UPDATE PEDIDO SET ID_REPARTIDOR = :id_repartidor WHERE ID_PEDIDO = :id_pedido');
    oci_bind_by_name($stmtU, ':id_repartidor', $idRepartidor);
    oci_bind_by_name($stmtU, ':id_pedido',     $idPedido);
    oci_execute($stmtU);
    oci_free_statement($stmtU);

    // Crear registro en ENTREGA_PEDIDO
    $stmtE = oci_parse($conn,
        'INSERT INTO ENTREGA_PEDIDO (ID_ENTREGA, ID_PEDIDO, ID_REPARTIDOR, ESTADO_ENTREGA, FECHA_ESTADO)
         VALUES (SEQ_ENTREGA_PEDIDO.NEXTVAL, :id_pedido, :id_repartidor, :estado, SYSTIMESTAMP)'
    );
    $estadoInicial = 'PENDIENTE';
    oci_bind_by_name($stmtE, ':id_pedido',     $idPedido);
    oci_bind_by_name($stmtE, ':id_repartidor', $idRepartidor);
    oci_bind_by_name($stmtE, ':estado',        $estadoInicial);
    oci_execute($stmtE);
    oci_free_statement($stmtE);

    echo json_encode(['success' => true, 'message' => 'Pedido asignado correctamente']);
}

// ─── Actualizar ubicación del repartidor ─────────────────────────────────────
function handleUbicacion($conn, int $idUsuario): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $latitud  = (float) ($data['latitud']  ?? 0);
    $longitud = (float) ($data['longitud'] ?? 0);

    $sql = "UPDATE REPARTIDOR
            SET LATITUD = :latitud, LONGITUD = :longitud, ULTIMA_ACTUALIZACION = SYSTIMESTAMP
            WHERE ID_USUARIO = :id_usuario";

    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':latitud',    $latitud);
    oci_bind_by_name($stmt, ':longitud',   $longitud);
    oci_bind_by_name($stmt, ':id_usuario', $idUsuario);
    oci_execute($stmt);
    oci_free_statement($stmt);

    echo json_encode(['success' => true, 'message' => 'Ubicación actualizada']);
}

// ─── Actualizar estado de entrega ────────────────────────────────────────────
function handleEstadoEntrega($conn, int $idUsuario): void {
    $data          = json_decode(file_get_contents('php://input'), true);
    $idEntrega     = (int) ($data['id_entrega']     ?? 0);
    $estadoEntrega = trim($data['estado_entrega']   ?? '');
    $notas         = trim($data['notas']            ?? '');

    if (!$idEntrega || !$estadoEntrega) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }

    $sql = "UPDATE ENTREGA_PEDIDO
            SET ESTADO_ENTREGA = :estado, NOTAS = :notas, FECHA_ESTADO = SYSTIMESTAMP
            WHERE ID_ENTREGA = :id_entrega
              AND ID_REPARTIDOR = (SELECT ID_REPARTIDOR FROM REPARTIDOR WHERE ID_USUARIO = :id_usuario)";

    $stmt = oci_parse($conn, $sql);
    oci_bind_by_name($stmt, ':estado',     $estadoEntrega);
    oci_bind_by_name($stmt, ':notas',      $notas);
    oci_bind_by_name($stmt, ':id_entrega', $idEntrega);
    oci_bind_by_name($stmt, ':id_usuario', $idUsuario);
    oci_execute($stmt);
    $afectadas = oci_num_rows($stmt);
    oci_free_statement($stmt);

    if ($afectadas > 0) {
        echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo actualizar']);
    }
}
