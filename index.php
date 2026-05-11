<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'exito' => true,
    'mensaje' => 'API PHP funcionando',
    'endpoints' => [
        'GET /test-db',
        'GET /test-db.php',
        'POST /login',
        'POST /login.php',
        'GET /reportes',
        'GET /reportes.php',
        'GET /ventas-grafico',
        'GET /ventas-grafico.php',
        'GET /reportes-dashboard',
        'GET /reportes-dashboard.php',
        'GET /registro?tipo=proveedores',
        'GET /registro?tipo=productos',
        'GET /registro?tipo=categorias',
        'POST /registro?tipo=proveedores&accion=crear',
        'POST /registro?tipo=proveedores&accion=actualizar',
        'POST /registro?tipo=productos&accion=crear',
        'POST /registro?tipo=productos&accion=actualizar',
        'POST /registro?tipo=inventario&accion=ajustar',
    ],
], JSON_UNESCAPED_UNICODE);
