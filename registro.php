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

function jsonInput() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function respond($success, $message, $data = null, $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function fetchAll($conn, $sql) {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        $error = $stmt ? oci_error($stmt) : oci_error($conn);
        throw new Exception($error['message'] ?? 'Error consultando Oracle');
    }

    $rows = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $rows[] = $row;
    }
    oci_free_statement($stmt);
    return $rows;
}

function nextId($conn, $table, $column) {
    $row = fetchAll($conn, "SELECT NVL(MAX($column), 0) + 1 AS NEXT_ID FROM $table");
    return (int)($row[0]['NEXT_ID'] ?? 1);
}

function bind($stmt, $name, &$value) {
    if (!oci_bind_by_name($stmt, $name, $value)) {
        $error = oci_error($stmt);
        throw new Exception($error['message'] ?? 'Error enlazando parametro');
    }
}

function executeStmt($stmt) {
    if (!$stmt || !oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $error = $stmt ? oci_error($stmt) : oci_error();
        throw new Exception($error['message'] ?? 'Error ejecutando consulta');
    }
}

function textValue($value) {
    if (is_object($value) && method_exists($value, 'load')) {
        return $value->load();
    }
    return $value;
}

try {
    $conn = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $tipo = strtolower(trim($_GET['tipo'] ?? ''));
    $accion = strtolower(trim($_GET['accion'] ?? 'listar'));

    if ($method === 'GET' && $tipo === 'categorias') {
        $rows = fetchAll($conn, "SELECT ID_CATEGORIA, NOMBRE FROM CATEGORIA_PRODUCTO ORDER BY NOMBRE");
        oci_close($conn);
        respond(true, 'Categorias cargadas', array_map(function ($row) {
            return [
                'id_categoria' => (int)$row['ID_CATEGORIA'],
                'nombre' => $row['NOMBRE'],
            ];
        }, $rows));
    }

    if ($method === 'GET' && $tipo === 'proveedores') {
        $rows = fetchAll($conn, "SELECT ID_PROVEEDOR, NOMBRE, RUT_P, DIRECCION, TELEFONO, CORREO, ESTADO
FROM PROVEEDOR
ORDER BY NOMBRE");
        oci_close($conn);
        respond(true, 'Proveedores cargados', array_map(function ($row) {
            return [
                'id_proveedor' => (int)$row['ID_PROVEEDOR'],
                'nombre' => $row['NOMBRE'],
                'rut_p' => $row['RUT_P'],
                'direccion' => $row['DIRECCION'],
                'telefono' => $row['TELEFONO'],
                'correo' => $row['CORREO'],
                'estado' => $row['ESTADO'],
            ];
        }, $rows));
    }

    if ($method === 'GET' && $tipo === 'productos') {
        $rows = fetchAll($conn, "SELECT P.ID_PRODUCTO, P.NOMBRE, P.CODIGO, P.DESCRIPCION, P.PRECIO,
P.ESTADO, P.ID_CATEGORIA, C.NOMBRE AS CATEGORIA,
NVL(COMPRAS.TOTAL_COMPRADO, 0) - NVL(VENTAS.TOTAL_VENDIDO, 0) AS STOCK
FROM PRODUCTO P
LEFT JOIN CATEGORIA_PRODUCTO C ON C.ID_CATEGORIA = P.ID_CATEGORIA
LEFT JOIN (
    SELECT ID_PRODUCTO, SUM(CANTIDAD) AS TOTAL_COMPRADO
    FROM DETALLE_COMPRA_PROVEEDOR
    GROUP BY ID_PRODUCTO
) COMPRAS ON COMPRAS.ID_PRODUCTO = P.ID_PRODUCTO
LEFT JOIN (
    SELECT ID_PRODUCTO, SUM(CANTIDAD) AS TOTAL_VENDIDO
    FROM DETALLE_VENTA
    GROUP BY ID_PRODUCTO
) VENTAS ON VENTAS.ID_PRODUCTO = P.ID_PRODUCTO
ORDER BY P.NOMBRE");
        oci_close($conn);
        respond(true, 'Productos cargados', array_map(function ($row) {
            return [
                'id_producto' => (int)$row['ID_PRODUCTO'],
                'nombre' => $row['NOMBRE'],
                'codigo' => $row['CODIGO'],
                'descripcion' => textValue($row['DESCRIPCION']),
                'precio' => (float)$row['PRECIO'],
                'estado' => $row['ESTADO'],
                'id_categoria' => (int)$row['ID_CATEGORIA'],
                'categoria' => $row['CATEGORIA'],
                'stock' => (int)$row['STOCK'],
            ];
        }, $rows));
    }

    if ($method !== 'POST') {
        respond(false, 'Metodo no permitido', null, 405);
    }

    $data = jsonInput();

    if ($tipo === 'proveedores') {
        $nombre = trim($data['nombre'] ?? '');
        $rut = trim($data['rut_p'] ?? '');
        $direccion = trim($data['direccion'] ?? '');
        $telefono = trim($data['telefono'] ?? '');
        $correo = trim($data['correo'] ?? '');
        $estado = trim($data['estado'] ?? 'ACTIVO');

        if ($nombre === '') {
            respond(false, 'El nombre del proveedor es requerido', null, 422);
        }

        if ($accion === 'crear') {
            $id = nextId($conn, 'PROVEEDOR', 'ID_PROVEEDOR');
            $stmt = oci_parse($conn, "INSERT INTO PROVEEDOR
(ID_PROVEEDOR, NOMBRE, RUT_P, DIRECCION, TELEFONO, CORREO, ESTADO, CREATED_AT, UPDATED_AT)
VALUES (:id, :nombre, :rut, :direccion, :telefono, :correo, :estado, SYSTIMESTAMP, SYSTIMESTAMP)");
            bind($stmt, ':id', $id);
        } else {
            $id = (int)($data['id_proveedor'] ?? 0);
            $stmt = oci_parse($conn, "UPDATE PROVEEDOR
SET NOMBRE = :nombre, RUT_P = :rut, DIRECCION = :direccion, TELEFONO = :telefono,
CORREO = :correo, ESTADO = :estado, UPDATED_AT = SYSTIMESTAMP
WHERE ID_PROVEEDOR = :id");
            bind($stmt, ':id', $id);
        }

        bind($stmt, ':nombre', $nombre);
        bind($stmt, ':rut', $rut);
        bind($stmt, ':direccion', $direccion);
        bind($stmt, ':telefono', $telefono);
        bind($stmt, ':correo', $correo);
        bind($stmt, ':estado', $estado);
        executeStmt($stmt);
        oci_commit($conn);
        oci_close($conn);
        respond(true, 'Proveedor guardado');
    }

    if ($tipo === 'productos') {
        $nombre = trim($data['nombre'] ?? '');
        $codigo = trim($data['codigo'] ?? '');
        $descripcion = trim($data['descripcion'] ?? '');
        $precio = (float)($data['precio'] ?? 0);
        $estado = trim($data['estado'] ?? 'ACTIVO');
        $idCategoria = (int)($data['id_categoria'] ?? 0);

        if ($nombre === '' || $idCategoria <= 0) {
            respond(false, 'Nombre y categoria son requeridos', null, 422);
        }

        if ($accion === 'crear') {
            $id = nextId($conn, 'PRODUCTO', 'ID_PRODUCTO');
            $stmt = oci_parse($conn, "INSERT INTO PRODUCTO
(ID_PRODUCTO, NOMBRE, CODIGO, DESCRIPCION, PRECIO, ESTADO, ID_CATEGORIA, CREATED_AT, UPDATED_AT)
VALUES (:id, :nombre, :codigo, :descripcion, :precio, :estado, :id_categoria, SYSTIMESTAMP, SYSTIMESTAMP)");
            bind($stmt, ':id', $id);
        } else {
            $id = (int)($data['id_producto'] ?? 0);
            $stmt = oci_parse($conn, "UPDATE PRODUCTO
SET NOMBRE = :nombre, CODIGO = :codigo, DESCRIPCION = :descripcion,
PRECIO = :precio, ESTADO = :estado, ID_CATEGORIA = :id_categoria, UPDATED_AT = SYSTIMESTAMP
WHERE ID_PRODUCTO = :id");
            bind($stmt, ':id', $id);
        }

        bind($stmt, ':nombre', $nombre);
        bind($stmt, ':codigo', $codigo);
        bind($stmt, ':descripcion', $descripcion);
        bind($stmt, ':precio', $precio);
        bind($stmt, ':estado', $estado);
        bind($stmt, ':id_categoria', $idCategoria);
        executeStmt($stmt);
        oci_commit($conn);
        oci_close($conn);
        respond(true, 'Producto guardado');
    }

    respond(false, 'Tipo no soportado', null, 404);
} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        @oci_rollback($conn);
        @oci_close($conn);
    }
    respond(false, 'Error en registro', ['error' => $e->getMessage()], 500);
}
