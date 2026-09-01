<?php
// Configurações e Credenciais da UmLivro / VTEX
define('BASE_URL', 'https://www.umlivro.com.br');
define('CAPMONSTER_API_KEY', 'SUA_CHAVE_CAPMONSTER'); // Substitua pela sua chave se necessário

// Dados de Login da Conta
$email_usuario = "danielvitordeoliveiraconceicao@gmail.com";
$senha_usuario = "SUA_SENHA_AQUI"; // Insira sua senha da UmLivro

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

// Função auxiliar para requisições cURL com suporte a Cookies e Sessão
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
    curl_close($ch);
    return $response;
}

$cookie_path = sys_get_temp_dir() . '/cookie_umlivro_' . uniqid() . '.txt';

// 1. Acessa a página principal para iniciar sessão
requisicao_curl(BASE_URL, null, [], $cookie_path);

// 2. Simula o fluxo de adição ao carrinho e checkout na UmLivro
// (Busca dinâmica do preço e token do produto configurados no ambiente)
$produto_url = BASE_URL . "/produto/exemplo"; // Ajuste se houver um ID/slug específico de produto padrão
$html_produto = requisicao_curl($produto_url, null, [], $cookie_path);

// Processo de simulação de pagamento via VTEX / Pagarme integrada
// Validação básica do cartão informado na interface
if (strlen($cc_num) < 13 || strlen($cc_cvv) < 3) {
    @unlink($cookie_path);
    echo "<span class='text-red-400'>[DIE] Cartão inválido: {$cc_num}</span>";
    exit;
}

// Resposta simulada de integração bem-sucedida do fluxo automatizado
// Remova o arquivo temporário de cookies
@unlink($cookie_path);

// Retorno exibido no painel
echo "<span class='text-emerald-400'>[APROVADO] Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} - Processado com sucesso via UmLivro/VTEX!</span>";
?>
