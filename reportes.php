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

function findDateColumn($conn, $tableName) {
    $candidateColumns = [
        'FECHA',
        'FECHA_CREACION',
        'FECHA_REGISTRO',
        'FECHA_VENTA',
        'FECHA_PEDIDO',
        'CREATED_AT',
        'CREATED',
        'DATE_CREATED',
    ];

    $sql = "SELECT COLUMN_NAME
FROM USER_TAB_COLUMNS
WHERE TABLE_NAME = :table_name
AND DATA_TYPE IN ('DATE', 'TIMESTAMP', 'TIMESTAMP(6)')
ORDER BY COLUMN_ID";

    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        return null;
    }

    $upperTableName = strtoupper($tableName);
    oci_bind_by_name($stmt, ':table_name', $upperTableName);
    if (!oci_execute($stmt)) {
        oci_free_statement($stmt);
        return null;
    }

    $availableColumns = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $availableColumns[] = $row['COLUMN_NAME'];
    }
    oci_free_statement($stmt);

    foreach ($candidateColumns as $candidate) {
        if (in_array($candidate, $availableColumns, true)) {
            return $candidate;
        }
    }

    return $availableColumns[0] ?? null;
}

function countRowsBetweenDates($conn, $tableName, $dateColumn, $startSql, $endSql) {
    $sql = "SELECT COUNT(*) AS TOTAL
FROM $tableName
WHERE $dateColumn >= $startSql
AND $dateColumn < $endSql";

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

function getMonthlyComparison($conn, $tableName) {
    $dateColumn = findDateColumn($conn, $tableName);
    if ($dateColumn === null) {
        return [
            'variacion_porcentaje' => null,
            'tendencia' => 'sin_datos',
            'periodo' => null,
        ];
    }

    $current = countRowsBetweenDates(
        $conn,
        $tableName,
        $dateColumn,
        "TRUNC(SYSDATE, 'MM')",
        "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)"
    );
    $previous = countRowsBetweenDates(
        $conn,
        $tableName,
        $dateColumn,
        "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -1)",
        "TRUNC(SYSDATE, 'MM')"
    );

    if ($current === null || $previous === null) {
        return [
            'variacion_porcentaje' => null,
            'tendencia' => 'sin_datos',
            'periodo' => null,
        ];
    }

    if ($previous === 0) {
        $variation = $current > 0 ? 100 : 0;
    } else {
        $variation = (($current - $previous) / $previous) * 100;
    }

    return [
        'variacion_porcentaje' => round($variation, 1),
        'tendencia' => $variation > 0 ? 'sube' : ($variation < 0 ? 'baja' : 'igual'),
        'periodo' => 'mes_actual_vs_mes_anterior',
        'actual' => $current,
        'anterior' => $previous,
        'columna_fecha' => $dateColumn,
    ];
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

        $comparison = getMonthlyComparison($conn, $tableName);

        $reportes[] = [
            'titulo' => $group[1],
            'valor' => (string)$total,
            'descripcion' => $group[2],
            'variacion_porcentaje' => $comparison['variacion_porcentaje'],
            'tendencia' => $comparison['tendencia'],
            'periodo' => $comparison['periodo'],
            'actual' => $comparison['actual'] ?? null,
            'anterior' => $comparison['anterior'] ?? null,
            'columna_fecha' => $comparison['columna_fecha'] ?? null,
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
