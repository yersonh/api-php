<?php
require_once __DIR__ . '/config.php';

/**
 * Genera un token HMAC firmado que contiene el id_usuario.
 * Formato interno: id_usuario:timestamp:firma
 * Se retorna como base64url para ser seguro en headers HTTP.
 */
function generarToken(int $idUsuario): string {
    $timestamp = time();
    $payload   = $idUsuario . ':' . $timestamp;
    $firma     = hash_hmac('sha256', $payload, TOKEN_SECRET);
    return base64_encode($payload . ':' . $firma);
}

/**
 * Valida el token y retorna el id_usuario si es válido, null si no.
 */
function validarToken(string $token): ?int {
    $decoded = base64_decode($token, true);
    if ($decoded === false) return null;

    $partes = explode(':', $decoded, 3);
    if (count($partes) !== 3) return null;

    [$idStr, $timestamp, $firma] = $partes;

    if (!is_numeric($idStr) || !is_numeric($timestamp)) return null;

    $payload       = $idStr . ':' . $timestamp;
    $firmaEsperada = hash_hmac('sha256', $payload, TOKEN_SECRET);

    if (!hash_equals($firmaEsperada, $firma)) return null;

    return (int) $idStr;
}
