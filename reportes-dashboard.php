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

function fetchOne($conn, $sql) {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        if ($stmt) {
            oci_free_statement($stmt);
        }
        return null;
    }

    $row = oci_fetch_assoc($stmt);
    oci_free_statement($stmt);
    return $row ?: null;
}

function fetchAll($conn, $sql) {
    $stmt = oci_parse($conn, $sql);
    if (!$stmt || !oci_execute($stmt)) {
        if ($stmt) {
            oci_free_statement($stmt);
        }
        return [];
    }

    $rows = [];
    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $rows[] = $row;
    }
    oci_free_statement($stmt);
    return $rows;
}

function variation($current, $previous) {
    if ((float)$previous === 0.0) {
        return (float)$current > 0 ? 100 : 0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

function periodConfig($periodo) {
    if ($periodo === 'todo') {
        return [
            'label' => 'Todo',
            'current_start' => null,
            'current_end' => null,
            'previous_start' => null,
            'previous_end' => null,
        ];
    }

    if ($periodo === 'hoy') {
        return [
            'label' => 'Hoy',
            'current_start' => 'TRUNC(SYSDATE)',
            'current_end' => 'TRUNC(SYSDATE) + 1',
            'previous_start' => 'TRUNC(SYSDATE) - 1',
            'previous_end' => 'TRUNC(SYSDATE)',
        ];
    }

    if ($periodo === 'semana') {
        return [
            'label' => 'Esta semana',
            'current_start' => "TRUNC(SYSDATE, 'IW')",
            'current_end' => "TRUNC(SYSDATE, 'IW') + 7",
            'previous_start' => "TRUNC(SYSDATE, 'IW') - 7",
            'previous_end' => "TRUNC(SYSDATE, 'IW')",
        ];
    }

    if ($periodo === 'anio') {
        return [
            'label' => 'Este ano',
            'current_start' => "TRUNC(SYSDATE, 'YYYY')",
            'current_end' => "ADD_MONTHS(TRUNC(SYSDATE, 'YYYY'), 12)",
            'previous_start' => "ADD_MONTHS(TRUNC(SYSDATE, 'YYYY'), -12)",
            'previous_end' => "TRUNC(SYSDATE, 'YYYY')",
        ];
    }

    return [
        'label' => 'Este mes',
        'current_start' => "TRUNC(SYSDATE, 'MM')",
        'current_end' => "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)",
        'previous_start' => "ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -1)",
        'previous_end' => "TRUNC(SYSDATE, 'MM')",
    ];
}

function dateWhere($alias, $config) {
    if ($config['current_start'] === null || $config['current_end'] === null) {
        return '1 = 1';
    }
    return "$alias.FECHA >= {$config['current_start']} AND $alias.FECHA < {$config['current_end']}";
}

try {
    $periodo = strtolower(trim($_GET['periodo'] ?? 'mes'));
    if (!in_array($periodo, ['todo', 'hoy', 'semana', 'mes', 'anio'], true)) {
        $periodo = 'mes';
    }
    $config = periodConfig($periodo);
    $ventaWhere = dateWhere('V', $config);
    $compraWhere = $config['current_start'] === null
        ? '1 = 1'
        : "CP.FECHA >= {$config['current_start']} AND CP.FECHA < {$config['current_end']}";

    $conn = getConnection();

    $currentRow = fetchOne($conn, "SELECT NVL(SUM(V.TOTAL), 0) AS TOTAL
FROM VENTA V
WHERE $ventaWhere");
    $previousRow = $config['previous_start'] === null
        ? ['TOTAL' => 0]
        : fetchOne($conn, "SELECT NVL(SUM(V.TOTAL), 0) AS TOTAL
FROM VENTA V
WHERE V.FECHA >= {$config['previous_start']}
AND V.FECHA < {$config['previous_end']}");

    $ventasTotales = (float)($currentRow['TOTAL'] ?? 0);
    $ventasAnteriores = (float)($previousRow['TOTAL'] ?? 0);

    $ventasPorDiaRows = fetchAll($conn, "SELECT TO_CHAR(TRUNC(V.FECHA), 'DD/MM') AS LABEL,
NVL(SUM(V.TOTAL), 0) AS TOTAL,
TRUNC(V.FECHA) AS ORDEN
FROM VENTA V
WHERE $ventaWhere
GROUP BY TRUNC(V.FECHA), TO_CHAR(TRUNC(V.FECHA), 'DD/MM')
ORDER BY ORDEN");

    $ventasPorDia = array_map(function ($row) {
        return [
            'label' => ucfirst(strtolower(trim($row['LABEL']))),
            'total' => (float)($row['TOTAL'] ?? 0),
        ];
    }, $ventasPorDiaRows);

    $topProductosRows = fetchAll($conn, "SELECT *
FROM (
    SELECT P.NOMBRE AS NOMBRE, SUM(DV.CANTIDAD) AS CANTIDAD
    FROM DETALLE_VENTA DV
    INNER JOIN VENTA V ON V.ID_VENTA = DV.ID_VENTA
    INNER JOIN PRODUCTO P ON P.ID_PRODUCTO = DV.ID_PRODUCTO
    WHERE $ventaWhere
    GROUP BY P.NOMBRE
    ORDER BY SUM(DV.CANTIDAD) DESC
)
WHERE ROWNUM <= 3");

    $topProductos = array_map(function ($row) {
        return [
            'nombre' => $row['NOMBRE'] ?? 'Producto',
            'detalle' => (int)($row['CANTIDAD'] ?? 0) . ' vendidos',
        ];
    }, $topProductosRows);

    $topClientesRows = fetchAll($conn, "SELECT *
FROM (
    SELECT TRIM(PER.NOMBRES || ' ' || PER.APELLIDOS) AS NOMBRE,
    COUNT(V.ID_VENTA) AS COMPRAS
    FROM VENTA V
    INNER JOIN CLIENTE C ON C.ID_CLIENTE = V.ID_CLIENTE
    INNER JOIN PERSONA PER ON PER.ID_PERSONA = C.ID_PERSONA
    WHERE $ventaWhere
    GROUP BY TRIM(PER.NOMBRES || ' ' || PER.APELLIDOS)
    ORDER BY COUNT(V.ID_VENTA) DESC
)
WHERE ROWNUM <= 3");

    $topClientes = array_map(function ($row) {
        return [
            'nombre' => $row['NOMBRE'] ?? 'Cliente',
            'detalle' => (int)($row['COMPRAS'] ?? 0) . ' compras',
        ];
    }, $topClientesRows);

    $topProveedoresRows = fetchAll($conn, "SELECT *
FROM (
    SELECT P.NOMBRE AS NOMBRE, COUNT(CP.ID_COMPRA) AS COMPRAS
    FROM COMPRA_PROVEEDOR CP
    INNER JOIN PROVEEDOR P ON P.ID_PROVEEDOR = CP.ID_PROVEEDOR
    WHERE $compraWhere
    GROUP BY P.NOMBRE
    ORDER BY COUNT(CP.ID_COMPRA) DESC
)
WHERE ROWNUM <= 3");

    $topProveedores = array_map(function ($row) {
        return [
            'nombre' => $row['NOMBRE'] ?? 'Proveedor',
            'detalle' => (int)($row['COMPRAS'] ?? 0) . ' compras',
        ];
    }, $topProveedoresRows);

    $diaMasVendidoRow = fetchOne($conn, "SELECT *
FROM (
    SELECT TO_CHAR(TRUNC(FECHA), 'DD Mon YYYY') AS FECHA,
    NVL(SUM(TOTAL), 0) AS TOTAL,
    COUNT(ID_VENTA) AS VENTAS
    FROM VENTA V
    WHERE $ventaWhere
    GROUP BY TRUNC(FECHA)
    ORDER BY SUM(TOTAL) DESC
)
WHERE ROWNUM = 1");

    oci_close($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Reportes cargados',
        'data' => [
            'periodo' => $periodo,
            'periodo_label' => $config['label'],
            'ventas_totales' => $ventasTotales,
            'variacion_ventas' => variation($ventasTotales, $ventasAnteriores),
            'ventas_por_dia' => $ventasPorDia,
            'top_productos' => $topProductos,
            'top_clientes' => $topClientes,
            'top_proveedores' => $topProveedores,
            'dia_mas_vendido' => $diaMasVendidoRow ? [
                'fecha' => ucfirst(strtolower($diaMasVendidoRow['FECHA'] ?? '')),
                'total' => (float)($diaMasVendidoRow['TOTAL'] ?? 0),
                'ventas' => (int)($diaMasVendidoRow['VENTAS'] ?? 0),
            ] : null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando reportes',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
