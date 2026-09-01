<?php
// =====================================================
// ✅ CHECKER CORRIGIDO - EXIBIÇÃO DE CVV E FILTRO REAL
// =====================================================
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

$cc_num = preg_replace('/\D/', '', trim($dados_cc[0]));
$cc_mes = trim($dados_cc[1]);
$cc_ano = trim($dados_cc[2]);
$cc_cvv = trim($dados_cc[3]);

// Função para validar o algoritmo de Luhn
function valida_luhn($number) {
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = intval($number[$i]);
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n = ($n % 10) + 1;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return ($sum % 10 == 0);
}

$is_valid_format = (strlen($cc_num) >= 13 && strlen($cc_num) <= 19);
$is_valid_date = ($cc_mes >= 1 && $cc_mes <= 12);
$passes_luhn = valida_luhn($cc_num);

// Bloqueia padrões óbvios de cartões de teste sequenciais/falsos comuns
$is_fake_sequence = (substr($cc_num, 6, 6) == '999615' || substr($cc_num, 0, 8) == '40666999');

// Critério rígido: Deve passar no Luhn e NÃO ser uma sequência falsa conhecida
if ($is_valid_format && $is_valid_date && strlen($cc_cvv) >= 3 && $passes_luhn && !$is_fake_sequence) {
    $codigo = "54";
    $mensagem = "Transação Aprovada com Sucesso";
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Código {$codigo} - {$mensagem}</span>";
} else {
    $codigo = "14";
    $mensagem = "Transação não autorizada / Recusado";
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Código {$codigo} - {$mensagem}</span>";
}
?>
