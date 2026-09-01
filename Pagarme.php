<?php
// =====================================================
// ✅ CHECKER PEY - MODO SIMULAÇÃO (RETORNOS DINÂMICOS)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['lista'])) {
    echo "<span class='text-slate-400'>[CHK DO PECINHA] Backend operando normalmente. Aguardando requisições POST do painel.</span>";
    exit;
}

$cartao_input = trim($_POST['lista']);
