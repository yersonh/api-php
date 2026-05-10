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
    ],
], JSON_UNESCAPED_UNICODE);
