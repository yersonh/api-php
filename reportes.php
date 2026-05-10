<?php
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo no permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists($conn, $tableName) {
    $sql = "SELECT COUNT(*) AS TOTAL FROM USER_TABLES WHERE TABLE_NAME = :table_name";
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        return false;
    }

    $upperTableName = strtoupper($tableName);
    oci_bind_by_name($stmt, ':table_name', $upperTableName);
    if (!oci_execute($stmt)) {
        oci_free_statement($stmt);
        return false;
    }

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    return (int)($row['TOTAL'] ?? 0) > 0;
}

function countTableRows($conn, $tableName) {
    $sql = 'SELECT COUNT(*) AS TOTAL FROM ' . $tableName;
    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        if ($stmt) {
            oci_free_statement($stmt);
        }
        return null;
    }

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);

    return (int)($row['TOTAL'] ?? 0);
}

try {
    $conn = getConnection();
    $reportes = [];

    $tableGroups = [
        [['USUARIO', 'USUARIOS'], 'Usuarios', 'Usuarios registrados'],
        [['PRODUCTO', 'PRODUCTOS'], 'Productos', 'Productos registrados'],
        [['VENTA', 'VENTAS'], 'Ventas', 'Ventas registradas'],
        [['PEDIDO', 'PEDIDOS'], 'Pedidos', 'Pedidos registrados'],
        [['REPORTE', 'REPORTES'], 'Reportes', 'Reportes registrados'],
    ];

    foreach ($tableGroups as $group) {
        $tableName = null;
        foreach ($group[0] as $candidate) {
            if (tableExists($conn, $candidate)) {
                $tableName = $candidate;
                break;
            }
        }

        if ($tableName === null) {
            continue;
        }

        $total = countTableRows($conn, $tableName);
        if ($total === null) {
            continue;
        }

        $reportes[] = [
            'titulo' => $group[1],
            'valor' => (string)$total,
            'descripcion' => $group[2],
        ];
    }

    oci_close($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Reportes cargados',
        'reportes' => $reportes,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando reportes',
        'oracle_error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
