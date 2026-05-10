<?php
require_once __DIR__ . '/config.php';

function prepareOracleWalletForLinux() {
    $walletPath = '/tmp/wallet';
    $sqlnetPath = $walletPath . '/sqlnet.ora';
    $defaultSqlnet = "WALLET_LOCATION =\n" .
        "(SOURCE =\n" .
        "(METHOD = file)\n" .
        "(METHOD_DATA =\n" .
        "(DIRECTORY=/tmp/wallet)\n" .
        ")\n" .
        ")\n" .
        "\n" .
        "SSL_SERVER_DN_MATCH=yes\n";

    putenv("TNS_ADMIN=/tmp/wallet");
    $_ENV['TNS_ADMIN'] = '/tmp/wallet';

    if (!is_dir($walletPath)) {
        mkdir($walletPath, 0700, true);
    }

    $walletFiles = [
        'cwallet.sso' => file_exists($walletPath . '/cwallet.sso'),
        'ewallet.p12' => file_exists($walletPath . '/ewallet.p12'),
        'sqlnet.ora' => file_exists($sqlnetPath),
        'tnsnames.ora' => file_exists($walletPath . '/tnsnames.ora'),
    ];

    $sqlnetContent = $walletFiles['sqlnet.ora'] ? file_get_contents($sqlnetPath) : '';
    if ($sqlnetContent === false || trim($sqlnetContent) === '') {
        $sqlnetContent = $defaultSqlnet;
    }

    $sqlnetContent = str_replace('?/network/admin', '/tmp/wallet', $sqlnetContent);
    $sqlnetContent = preg_replace('/DIRECTORY\s*=\s*"?[A-Za-z]:[^)\r\n"]*"?/i', 'DIRECTORY=/tmp/wallet', $sqlnetContent);
    $sqlnetContent = preg_replace('/[A-Za-z]:[\\\\\/][^)\r\n"]*/', '/tmp/wallet', $sqlnetContent);

    if (strpos($sqlnetContent, '/tmp/wallet') === false) {
        $sqlnetContent = $defaultSqlnet;
    }

    $sqlnetWriteResult = file_put_contents($sqlnetPath, $sqlnetContent, LOCK_EX);
    $walletFiles['sqlnet.ora'] = file_exists($sqlnetPath);
    $missingWalletFiles = [];
    foreach ($walletFiles as $file => $exists) {
        if (!$exists) {
            $missingWalletFiles[] = $file;
        }
    }

    return [
        'tns_admin' => getenv('TNS_ADMIN'),
        'wallet_path' => $walletPath,
        'wallet_files' => $walletFiles,
        'missing_wallet_files' => $missingWalletFiles,
        'sqlnet_path' => $sqlnetPath,
        'sqlnet_write_success' => $sqlnetWriteResult !== false,
        'sqlnet_final' => $sqlnetContent,
        'sqlnet_file_content_after_write' => file_exists($sqlnetPath) ? file_get_contents($sqlnetPath) : null,
    ];
}

function getConnection() {
    prepareOracleWalletForLinux();

    $conn = oci_connect(
        ORACLE_USER,
        ORACLE_PASSWORD,
        ORACLE_TNS,
        'AL32UTF8'
    );
    
    if (!$conn) {
        $e = oci_error();
        throw new Exception("Error Oracle: " . ($e['message'] ?? 'desconocido'));
    }
    
    return $conn;
}
