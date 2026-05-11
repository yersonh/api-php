<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($uri === '/login' && $method === 'POST') {
    require __DIR__ . '/login.php';
    exit;
}

if ($uri === '/test-db') {
    require __DIR__ . '/test-db.php';
    exit;
}

if ($uri === '/reportes' && $method === 'GET') {
    require __DIR__ . '/reportes.php';
    exit;
}

if ($uri === '/ventas-grafico' && $method === 'GET') {
    require __DIR__ . '/ventas-grafico.php';
    exit;
}

if ($uri === '/reportes-dashboard' && $method === 'GET') {
    require __DIR__ . '/reportes-dashboard.php';
    exit;
}

if ($uri === '/registro' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/registro.php';
    exit;
}

if ($uri === '/repartidor' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/repartidor.php';
    exit;
}

if ($uri === '/usuarios' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/usuarios.php';
    exit;
}

if ($uri === '/') {
    echo json_encode([
        "exito" => true,
        "mensaje" => "API PHP funcionando"
    ]);
    exit;
}

http_response_code(404);
echo json_encode([
    "success" => false,
    "message" => "Ruta no encontrada"
]);
exit;
