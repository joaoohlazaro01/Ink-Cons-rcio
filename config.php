<?php
/**
 * ==========================================================
 * CONFIGURAÇÕES DO SISTEMA
 * ==========================================================
 */

// ===========================
// BANCO DE DADOS
// ===========================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'tatuagem_consorcio');


// ===========================
// MERCADO PAGO
// ===========================

// Access Token de Produção
define('MP_ACCESS_TOKEN', 'APP_USR-7696953199279598-062815-3022f146567a7e2e6e27d691cee96f5d-3504787088');

// Public Key
define('MP_PUBLIC_KEY', 'APP_USR-adbb82c3-220c-4fd3-826d-5dde4c451053');

// Ambiente
// false = Produção
// true = Sandbox/Testes
define('MP_SANDBOX', false);


// ===========================
// SIMULAÇÃO
// ===========================

// false = utiliza Mercado Pago real
// true = utiliza simulador local
define('SIMULACAO_PAGAMENTO', false);


// ===========================
// DADOS DO ESTÚDIO
// ===========================
define('STUDIO_NOME', 'Ink Consórcio & Tattoo Studio');

define('STUDIO_WHATSAPP', '5511999999999');


// ===========================
// CONFIGURAÇÕES GERAIS
// ===========================
date_default_timezone_set('America/Sao_Paulo');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// ===========================
// URL BASE
// ===========================
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';

define(
    'BASE_URL',
    $protocolo .
    $_SERVER['HTTP_HOST'] .
    str_replace(
        '\\',
        '/',
        dirname($_SERVER['SCRIPT_NAME'])
    )
);


// ===========================
// CONFIGURAÇÕES DO CURL
// ===========================
define('CURL_TIMEOUT', 30);

define('CURL_SSL_VERIFY', false);


// ===========================
// CABEÇALHOS PADRÃO MERCADO PAGO
// ===========================
function mpHeaders()
{
    return [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . uniqid('', true)
    ];
}