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

try {
    $conn = getConnection();

    $currentRow = fetchOne($conn, "SELECT NVL(SUM(TOTAL), 0) AS TOTAL
FROM VENTA
WHERE FECHA >= TRUNC(SYSDATE, 'MM')
AND FECHA < ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)");
    $previousRow = fetchOne($conn, "SELECT NVL(SUM(TOTAL), 0) AS TOTAL
FROM VENTA
WHERE FECHA >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -1)
AND FECHA < TRUNC(SYSDATE, 'MM')");

    $ventasTotales = (float)($currentRow['TOTAL'] ?? 0);
    $ventasAnteriores = (float)($previousRow['TOTAL'] ?? 0);

    $ventasPorDiaRows = fetchAll($conn, "SELECT TO_CHAR(TRUNC(FECHA), 'DY') AS LABEL,
NVL(SUM(TOTAL), 0) AS TOTAL,
TRUNC(FECHA) AS ORDEN
FROM VENTA
WHERE FECHA >= TRUNC(SYSDATE) - 6
AND FECHA < TRUNC(SYSDATE) + 1
GROUP BY TRUNC(FECHA), TO_CHAR(TRUNC(FECHA), 'DY')
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
    INNER JOIN PRODUCTO P ON P.ID_PRODUCTO = DV.ID_PRODUCTO
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
    FROM VENTA
    GROUP BY TRUNC(FECHA)
    ORDER BY SUM(TOTAL) DESC
)
WHERE ROWNUM = 1");

    oci_close($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Reportes cargados',
        'data' => [
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
