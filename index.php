<?php
// =====================================================
// ✅ CHK DO PECINHA - SISTEMA ÚNICO (LOGIN + SIMULAÇÃO)
// =====================================================

session_start();

// Chave de acesso única do sistema
$chave_valida = "A4B9X2M7K1P8"; 
$erro = "";

// 1. Processar Login por Chave
if (isset($_POST['f_login'])) {
    $chave_digitada = trim($_POST['chave'] ?? '');
    
    if ($chave_digitada === $chave_valida) {
        $_SESSION['logado'] = true;
        header("Location: index.php");
        exit;
    } else {
        $erro = "Chave de acesso inválida! Verifique os 12 caracteres.";
    }
}

// 2. Processar Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 3. Processar Requisição do Checker (POST AJAX enviado pelo painel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lista'])) {
    if (!isset($_SESSION['logado'])) {
        echo "<span class='text-red-400'>[ERRO] Sessão expirada. Faça login novamente.</span>";
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

    // Controle para simular retornos dinâmicos sem repetições em sequência exata
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
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHK DO PECINHA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <?php if (!isset($_SESSION['logado'])): ?>
        <!-- TELA DE LOGIN POR CHAVE -->
        <div class="w-full max-w-md bg-slate-800 p-8 rounded-xl shadow-2xl border border-slate-700 text-center">
            
            <div class="mb-6 flex justify-center">
                <img src="sua-logo.png" alt="Logotipo" class="h-20 w-auto object-contain rounded-lg border border-slate-700 p-1 bg-slate-900" onerror="this.style.display='none'">
            </div>

            <h1 class="text-2xl font-bold mb-2 text-emerald-400">CHK DO PECINHA</h1>
            <p class="text-xs text-slate-400 mb-6">Insira sua chave de acesso</p>
            
            <?php if (!empty($erro)): ?>
                <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg mb-4 text-sm text-center">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="f_login" value="1">
                <div class="mb-6 text-left">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Chave de Acesso:</label>
                    <input type="text" name="chave" required maxlength="12" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200 uppercase tracking-widest text-center font-mono" placeholder="DIGITE SUA CHAVE">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition duration-200 shadow-lg">
                    Entrar no Sistema
                </button>
            </form>
        </div>

    <?php else: ?>
        <!-- PAINEL PRINCIPAL -->
        <div class="w-full max-w-2xl bg-slate-800 p-6 rounded-xl shadow-2xl border border-slate-700">
            <div class="flex justify-between items-center mb-6 border-b border-slate-700 pb-4">
                <div class="flex items-center gap-3">
                    <img src="sua-logo.png" alt="Logo" class="h-10 w-auto object-contain rounded" onerror="this.style.display='none'">
                    <h1 class="text-xl font-bold text-emerald-400">CHK DO PECINHA</h1>
                </div>
                <a href="index.php?action=logout" class="bg-red-600/20 hover:bg-red-600/30 text-red-400 text-xs px-3 py-1.5 rounded-lg border border-red-500/30 transition">Sair</a>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2 text-slate-300">Cole sua lista (NUMERO|MES|ANO|CVV):</label>
                <textarea id="lista" rows="5" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200 font-mono" placeholder="4066699932589171|04|2031|829"></textarea>
            </div>

            <div class="mb-4 flex items-center gap-4">
                <div class="w-1/2">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Intervalo de Segurança:</label>
                    <select id="delay" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-2.5 text-sm text-slate-200 focus:outline-none">
                        <option value="500">0.5 Segundos</option>
                        <option value="1000" selected>1 Segundo</option>
                        <option value="2000">2 Segundos</option>
                        <option value="3000">3 Segundos</option>
                    </select>
                </div>
                <div class="w-1/2 flex items-end">
                    <button onclick="iniciarChecagem()" id="btnChecar" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition duration-200 shadow-lg">
                        Iniciar Checagem
                    </button>
                </div>
            </div>

            <div class="mt-6">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-medium text-slate-300">Resultados:</label>
                    <span id="contador" class="text-xs text-slate-400">Progresso: 0 / 0</span>
                </div>
                <div id="resultado" class="w-full h-52 bg-slate-900 border border-slate-700 rounded-lg p-3 text-xs font-mono overflow-y-auto text-slate-300 space-y-1">
                    Aguardando início...
                </div>
            </div>
        </div>

        <script>
            async function iniciarChecagem() {
                const texto = document.getElementById('lista').value.trim();
                const resDiv = document.getElementById('resultado');
                const btn = document.getElementById('btnChecar');
                const delayMs = parseInt(document.getElementById('delay').value);
                const contador = document.getElementById('contador');

                if (!texto) {
                    alert('Cole uma lista válida!');
                    return;
                }

                const linhas = texto.split('\n').map(l => l.trim()).filter(l => l !== '');
                if (linhas.length === 0) return;

                btn.disabled = true;
                btn.innerText = "Checando...";
                resDiv.innerHTML = "";
                
                for (let i = 0; i < linhas.length; i++) {
                    const linhaAtual = linhas[i];
                    contador.innerText = `Progresso: ${i + 1} / ${linhas.length}`;

                    try {
                        let response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'lista=' + encodeURIComponent(linhaAtual)
                        });
                        let resultadoHtml = await response.text();
                        
                        resDiv.innerHTML += `<div>${resultadoHtml}</div>`;
                        resDiv.scrollTop = resDiv.scrollHeight;

                    } catch (err) {
                        resDiv.innerHTML += `<div class='text-red-400'>[ERRO] Falha na requisição: ${linhaAtual}</div>`;
                    }

                    if (i < linhas.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, delayMs));
                    }
                }

                btn.disabled = false;
                btn.innerText = "Iniciar Checagem";
            }
        </script>
    <?php endif; ?>
</body>
</html>
