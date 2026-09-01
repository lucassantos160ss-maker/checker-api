<?php
// =====================================================
// ✅ CHK DO PECINHA - MODO SIMULAÇÃO (RETORNOS DINÂMICOS)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['lista'])) {
    echo "<span class='text-slate-400'>[CHK DO PECINHA] Backend operando normalmente. Aguardando requisições POST do painel.</span>";
    exit;
}

$cartao_input = trim($_POST['lista']);

$dados_cc = explode('|', $cartao_input);
if (count($dados_cc) < 4) {
    echo "<span class='text-red-400'>[FORMATO INVÁLIDO] Use: NUMERO|MES|ANO|CVV</span>";
    exit;
}

$cc_num = trim($dados_cc[0]);
$cc_mes = trim($dados_cc[1]);
$cc_ano = trim($dados_cc[2]);
$cc_cvv = trim($dados_cc[3]);

session_start();
$ultimo_status = $_SESSION['ultimo_status_gerado'] ?? '';

$opcoes = ['LIVE_54', 'LIVE_N7', 'DIE_14'];
if (($key = array_search($ultimo_status, $opcoes)) !== false) {
    unset($opcoes[$key]);
    $opcoes = array_values($opcoes);
}

$escolha = $opcoes[array_rand($opcoes)];
$_SESSION['ultimo_status_gerado'] = $escolha;

if ($escolha === 'LIVE_54') {
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: 54 - Transação Aprovada com Sucesso</span>";
} elseif ($escolha === 'LIVE_N7') {
    echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> <span class='text-slate-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: N7 - Aprovado (AVS Match / Testado com Sucesso)</span>";
} else {
    echo "<span class='text-red-400 font-bold'>[DIE]</span> <span class='text-slate-400'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: 14 - Cartão Inválido ou Transação Recusada pela Operadora</span>";
}
?>
