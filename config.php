<?php
error_reporting(0);
ini_set('display_errors', 0);

define('ORACLE_USER', getenv('ORACLE_USER') ?: 'ADMIN');
define('ORACLE_PASSWORD', getenv('ORACLE_PASSWORD'));
define('ORACLE_TNS', getenv('ORACLE_TNS') ?: 'BC27BNCUDFCGICLB_high');
define('WALLET_PATH', getenv('WALLET_PATH') ?: '/tmp/wallet');

putenv("TNS_ADMIN=" . WALLET_PATH);
$_ENV['TNS_ADMIN'] = WALLET_PATH;

define('TOKEN_SECRET', getenv('TOKEN_SECRET') ?: 'naylex_secret_key_2024_gbd');
