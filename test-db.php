<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

ob_start();
set_time_limit(35);

$responseSent = false;
$startTime = microtime(true);
$diagnostics = [
    'oracle_tns' => null,
    'tns_admin_exists' => false,
    'tns_admin' => null,
    'wallet_path' => null,
    'wallet_exists' => false,
    'wallet_files' => [],
    'missing_wallet_files' => [],
    'final_paths' => [],
    'sqlnet_final_used' => null,
    'sqlnet_write_success' => null,
    'sqlnet_file_content_after_write' => null,
    'tnsnames_content' => null,
    'oci8_loaded' => false,
    'oracle_connected' => false,
    'pcntl_alarm_available' => function_exists('pcntl_alarm'),
    'possible_timeout' => false,
    'connection_time_seconds' => null,
    'query_time_seconds' => null,
    'total_time_seconds' => null,
    'select_from_dual_successful' => false,
    'logs' => [],
];

function addDiagnosticLog(&$diagnostics, $step) {
    global $startTime;

    $diagnostics['logs'][] = [
        'step' => $step,
        'time' => date('c'),
        'elapsed_seconds' => round(microtime(true) - $startTime, 4)
    ];
}

function sendDiagnosticResponse($success, $message, &$diagnostics, $oracleError = null, $statusCode = 200) {
    global $responseSent, $startTime;

    $diagnostics['total_time_seconds'] = round(microtime(true) - $startTime, 4);
    if (($diagnostics['connection_time_seconds'] ?? 0) > 10) {
        $diagnostics['possible_timeout'] = true;
    }

    if (ob_get_length() !== false) {
        ob_clean();
    }

    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'oracle_error' => $oracleError,
        'diagnostics' => $diagnostics
    ], JSON_UNESCAPED_UNICODE);

    $responseSent = true;
    exit;
}

register_shutdown_function(function () {
    global $responseSent, $diagnostics, $startTime;

    if ($responseSent) {
        return;
    }

    $error = error_get_last();
    if (!$error) {
        return;
    }

    $diagnostics['total_time_seconds'] = round(microtime(true) - $startTime, 4);
    $diagnostics['possible_timeout'] = true;
    $diagnostics['logs'][] = [
        'step' => 'shutdown_error',
        'time' => date('c'),
        'elapsed_seconds' => $diagnostics['total_time_seconds']
    ];

    if (ob_get_length() !== false) {
        ob_clean();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Oracle diagnostic failed',
        'oracle_error' => $error,
        'diagnostics' => $diagnostics
    ], JSON_UNESCAPED_UNICODE);
});

try {
    addDiagnosticLog($diagnostics, 'before_load_variables');
    require_once __DIR__ . '/database.php';
    addDiagnosticLog($diagnostics, 'after_load_variables');

    addDiagnosticLog($diagnostics, 'before_load_wallet');
    $walletInfo = prepareOracleWalletForLinux();
    $diagnostics['oracle_tns'] = defined('ORACLE_TNS') ? ORACLE_TNS : null;
    $diagnostics['tns_admin_exists'] = getenv('TNS_ADMIN') !== false || isset($_ENV['TNS_ADMIN']);
    $diagnostics['tns_admin'] = getenv('TNS_ADMIN') ?: ($_ENV['TNS_ADMIN'] ?? null);
    $diagnostics['wallet_path'] = $walletInfo['wallet_path'];
    $diagnostics['wallet_exists'] = file_exists($walletInfo['wallet_path']);
    $diagnostics['wallet_files'] = $walletInfo['wallet_files'];
    $diagnostics['missing_wallet_files'] = $walletInfo['missing_wallet_files'];
    $diagnostics['final_paths'] = [
        'tns_admin' => $walletInfo['tns_admin'],
        'wallet_path' => $walletInfo['wallet_path'],
        'sqlnet_path' => $walletInfo['sqlnet_path'],
        'tnsnames_path' => $walletInfo['wallet_path'] . '/tnsnames.ora',
        'cwallet_path' => $walletInfo['wallet_path'] . '/cwallet.sso',
        'ewallet_path' => $walletInfo['wallet_path'] . '/ewallet.p12',
    ];
    $diagnostics['sqlnet_final_used'] = $walletInfo['sqlnet_final'];
    $diagnostics['sqlnet_write_success'] = $walletInfo['sqlnet_write_success'];
    $diagnostics['sqlnet_file_content_after_write'] = $walletInfo['sqlnet_file_content_after_write'];
    $diagnostics['tnsnames_content'] = file_exists($walletInfo['wallet_path'] . '/tnsnames.ora')
        ? file_get_contents($walletInfo['wallet_path'] . '/tnsnames.ora')
        : null;
    addDiagnosticLog($diagnostics, 'after_load_wallet');

    addDiagnosticLog($diagnostics, 'before_validate_oci8');
    $diagnostics['oci8_loaded'] = extension_loaded('oci8');
    if (!$diagnostics['oci8_loaded']) {
        sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, 'OCI8 extension is not loaded', 500);
    }

    if (!function_exists('oci_connect')) {
        sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, 'oci_connect function does not exist', 500);
    }
    addDiagnosticLog($diagnostics, 'after_validate_oci8');

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use (&$diagnostics) {
            $diagnostics['possible_timeout'] = true;
            throw new RuntimeException('oci_connect exceeded 10 seconds');
        });
    }

    addDiagnosticLog($diagnostics, 'before_oci_connect');
    error_log('test-db before oci_connect ' . date('c'));
    $connectStart = microtime(true);

    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(10);
    }

    $conn = oci_connect(
        ORACLE_USER,
        ORACLE_PASSWORD,
        ORACLE_TNS,
        'AL32UTF8'
    );

    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }

    $diagnostics['connection_time_seconds'] = round(microtime(true) - $connectStart, 4);
    if ($diagnostics['connection_time_seconds'] > 10) {
        $diagnostics['possible_timeout'] = true;
    }
    addDiagnosticLog($diagnostics, 'after_oci_connect');
    error_log('test-db after oci_connect ' . date('c'));

    if (!$conn) {
        $error = function_exists('oci_error') ? oci_error() : null;
        sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, $error, 500);
    }
    $diagnostics['oracle_connected'] = true;

    addDiagnosticLog($diagnostics, 'before_query_simple');
    $queryStart = microtime(true);
    $stmt = oci_parse($conn, "SELECT 'OK' AS STATUS FROM dual");
    if (!$stmt) {
        $error = function_exists('oci_error') ? oci_error($conn) : null;
        sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, $error, 500);
    }

    if (!oci_execute($stmt)) {
        $error = function_exists('oci_error') ? oci_error($stmt) : null;
        sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, $error, 500);
    }

    $row = oci_fetch_assoc($stmt);
    $diagnostics['query_time_seconds'] = round(microtime(true) - $queryStart, 4);
    $diagnostics['select_from_dual_successful'] = (($row['STATUS'] ?? null) === 'OK');
    addDiagnosticLog($diagnostics, 'after_query_simple');

    addDiagnosticLog($diagnostics, 'before_close_connection');
    oci_free_statement($stmt);
    oci_close($conn);
    addDiagnosticLog($diagnostics, 'after_close_connection');

    sendDiagnosticResponse(true, 'Oracle diagnostic completed', $diagnostics, null, 200);
} catch (Throwable $e) {
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }

    $error = function_exists('oci_error') ? oci_error() : null;
    if (!$error) {
        $error = $e->getMessage();
    }

    sendDiagnosticResponse(false, 'Oracle connection failed', $diagnostics, $error, 500);
}
