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

function findTable($conn, $tables) {
    foreach ($tables as $tableName) {
        if (tableExists($conn, $tableName)) {
            return $tableName;
        }
    }
    return null;
}

function getColumns($conn, $tableName) {
    $sql = "SELECT COLUMN_NAME, DATA_TYPE
FROM USER_TAB_COLUMNS
WHERE TABLE_NAME = :table_name
ORDER BY COLUMN_ID";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        return [];
    }

    $upperTableName = strtoupper($tableName);
    oci_bind_by_name($stmt, ':table_name', $upperTableName);
    if (!oci_execute($stmt)) {
        oci_free_statement($stmt);
        return [];
    }

    $columns = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $columns[] = $row;
    }
    oci_free_statement($stmt);

    return $columns;
}

function findDateColumn($columns) {
    $candidates = [
        'FECHA',
        'FECHA_VENTA',
        'FECHA_REGISTRO',
        'FECHA_CREACION',
        'CREATED_AT',
        'CREATED',
    ];

    foreach ($candidates as $candidate) {
        foreach ($columns as $column) {
            if ($column['COLUMN_NAME'] === $candidate) {
                return $candidate;
            }
        }
    }

    foreach ($columns as $column) {
        if (strpos($column['DATA_TYPE'], 'DATE') !== false ||
            strpos($column['DATA_TYPE'], 'TIMESTAMP') !== false) {
            return $column['COLUMN_NAME'];
        }
    }

    return null;
}

function findAmountColumn($columns) {
    $candidates = [
        'TOTAL',
        'MONTO_TOTAL',
        'TOTAL_VENTA',
        'MONTO',
        'VALOR_TOTAL',
        'PRECIO_TOTAL',
        'SUBTOTAL',
    ];

    foreach ($candidates as $candidate) {
        foreach ($columns as $column) {
            if ($column['COLUMN_NAME'] === $candidate) {
                return $candidate;
            }
        }
    }

    return null;
}

function periodConfig($periodo) {
    if ($periodo === 'mes') {
        return [
            'start' => "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -5)",
            'end' => "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)",
            'group' => "TRUNC(%s, 'MM')",
            'label' => "TO_CHAR(TRUNC(%s, 'MM'), 'MON')",
            'order' => "TRUNC(%s, 'MM')",
        ];
    }

    if ($periodo === 'quincenal') {
        return [
            'start' => "TRUNC(SYSDATE) - 14",
            'end' => "TRUNC(SYSDATE) + 1",
            'group' => "TRUNC(%s)",
            'label' => "TO_CHAR(TRUNC(%s), 'DD/MM')",
            'order' => "TRUNC(%s)",
        ];
    }

    return [
        'start' => "TRUNC(SYSDATE) - 6",
        'end' => "TRUNC(SYSDATE) + 1",
        'group' => "TRUNC(%s)",
        'label' => "TO_CHAR(TRUNC(%s), 'DY')",
        'order' => "TRUNC(%s)",
    ];
}

try {
    $periodo = strtolower(trim($_GET['periodo'] ?? 'diaria'));
    if (!in_array($periodo, ['mes', 'quincenal', 'diaria'], true)) {
        $periodo = 'diaria';
    }

    $conn = getConnection();
    $tableName = findTable($conn, ['VENTA', 'VENTAS']);

    if ($tableName === null) {
        throw new Exception('No existe la tabla VENTA o VENTAS.');
    }

    $columns = getColumns($conn, $tableName);
    $dateColumn = findDateColumn($columns);
    $amountColumn = findAmountColumn($columns);

    if ($dateColumn === null) {
        throw new Exception('No se encontro columna de fecha para ventas.');
    }

    if ($amountColumn === null) {
        throw new Exception('No se encontro columna numerica para total de ventas.');
    }

    $config = periodConfig($periodo);
    $groupSql = sprintf($config['group'], $dateColumn);
    $labelSql = sprintf($config['label'], $dateColumn);
    $orderSql = sprintf($config['order'], $dateColumn);

    $sql = "SELECT $labelSql AS LABEL,
SUM(NVL($amountColumn, 0)) AS TOTAL,
$orderSql AS ORDEN
FROM $tableName
WHERE $dateColumn >= {$config['start']}
AND $dateColumn < {$config['end']}
GROUP BY $groupSql, $labelSql, $orderSql
ORDER BY ORDEN";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        $error = $stmt ? oci_error($stmt) : oci_error($conn);
        throw new Exception($error['message'] ?? 'Error consultando ventas.');
    }

    $ventas = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $ventas[] = [
            'label' => ucfirst(strtolower(trim($row['LABEL']))),
            'total' => (float)($row['TOTAL'] ?? 0),
        ];
    }

    oci_free_statement($stmt);
    oci_close($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Ventas cargadas',
        'periodo' => $periodo,
        'ventas' => $ventas,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando grafico de ventas',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
