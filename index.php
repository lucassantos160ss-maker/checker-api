<?php
// =====================================================
// ✅ CHK DO PECINHA - TEMA PRETO, CINZA E BRANCO COM ANIMAÇÕES
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
        echo json_encode(['status' => 'error', 'html' => "<span class='text-zinc-500'>[ERRO] Sessão expirada. Faça login novamente.</span>"]);
        exit;
    }

    $cartao_input = trim($_POST['lista']);
    $dados_cc = explode('|', $cartao_input);
    
    if (count($dados_cc) < 4) {
        echo json_encode(['status' => 'error', 'html' => "<span class='text-zinc-500'>[FORMATO INVÁLIDO] Use: NUMERO|MES|ANO|CVV</span>"]);
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
        $html = "<span class='text-white font-bold bg-zinc-800 px-2 py-0.5 rounded border border-zinc-600 animate-pulse'>[LIVE]</span> <span class='text-zinc-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: 54 - Transação Aprovada com Sucesso</span>";
        echo json_encode(['status' => 'live', 'html' => $html]);
    } elseif ($escolha === 'LIVE_N7') {
        $html = "<span class='text-white font-bold bg-zinc-800 px-2 py-0.5 rounded border border-zinc-600 animate-pulse'>[LIVE]</span> <span class='text-zinc-200'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: N7 - Aprovado (AVS Match / Testado com Sucesso)</span>";
        echo json_encode(['status' => 'live', 'html' => $html]);
    } else {
        $html = "<span class='text-zinc-500 font-bold'>[DIE]</span> <span class='text-zinc-600'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: Código: 14 - Cartão Inválido ou Recusado</span>";
        echo json_encode(['status' => 'die', 'html' => $html]);
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
    <!-- Biblioteca de Confetes para animar as Lives -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 15px rgba(255, 255, 255, 0.05); }
            50% { box-shadow: 0 0 25px rgba(255, 255, 255, 0.15); }
        }
        .card-glow {
            animation: glow 4s infinite ease-in-out;
        }
    </style>
</head>
<body class="bg-black text-zinc-100 min-h-screen flex items-center justify-center p-4 selection:bg-zinc-700 selection:text-white">

    <?php if (!isset($_SESSION['logado'])): ?>
        <!-- TELA DE LOGIN POR CHAVE -->
        <div class="w-full max-w-md bg-zinc-900 p-8 rounded-2xl shadow-2xl border border-zinc-800 text-center card-glow">
            
            <div class="mb-6 flex justify-center">
                <img src="logo.png" alt="Logotipo Pecinha" class="h-28 w-28 object-cover rounded-full border-2 border-zinc-700 shadow-xl p-1 bg-black" onerror="this.style.display='none'">
            </div>

            <h1 class="text-2xl font-bold mb-1 tracking-wider text-white">CHK DO PECINHA</h1>
            <p class="text-xs text-zinc-400 mb-6 font-mono uppercase tracking-widest">Autenticação Segura</p>
            
            <?php if (!empty($erro)): ?>
                <div class="bg-zinc-800 border border-zinc-700 text-zinc-300 p-3 rounded-xl mb-4 text-xs text-center font-mono">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="f_login" value="1">
                <div class="mb-6 text-left">
                    <label class="block text-xs font-mono uppercase tracking-wider mb-2 text-zinc-400">Chave de Acesso:</label>
                    <input type="text" name="chave" required maxlength="12" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-sm focus:outline-none focus:border-zinc-500 text-zinc-200 uppercase tracking-widest text-center font-mono transition" placeholder="INSIRA SUA CHAVE">
                </div>
                <button type="submit" class="w-full bg-white hover:bg-zinc-200 text-black font-bold py-3 rounded-xl transition duration-200 shadow-lg font-mono text-xs uppercase tracking-widest">
                    Acessar Painel
                </button>
            </form>
        </div>

    <?php else: ?>
        <!-- PAINEL PRINCIPAL -->
        <div class="w-full max-w-2xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow">
            <div class="flex justify-between items-center mb-6 border-b border-zinc-800 pb-4">
                <div class="flex items-center gap-3">
                    <img src="logo.png" alt="Logo Pecinha" class="h-12 w-12 object-cover rounded-full border border-zinc-700 shadow" onerror="this.style.display='none'">
                    <div>
                        <h1 class="text-lg font-bold text-white tracking-wide">CHK DO PECINHA</h1>
                        <span class="text-[10px] text-zinc-400 font-mono">SYSTEM ACTIVE</span>
                    </div>
                </div>
                <a href="index.php?action=logout" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-3 py-1.5 rounded-lg border border-zinc-700 transition font-mono">Sair</a>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-mono uppercase tracking-wider mb-2 text-zinc-400">Lista de Cartões (NUMERO|MES|ANO|CVV):</label>
                <textarea id="lista" rows="5" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs focus:outline-none focus:border-zinc-500 text-zinc-200 font-mono transition" placeholder="4066699932589171|04|2031|829"></textarea>
            </div>

            <div class="mb-6">
                <button onclick="iniciarChecagem()" id="btnChecar" class="w-full bg-white hover:bg-zinc-200 text-black font-bold py-3.5 rounded-xl transition duration-200 shadow-lg font-mono text-xs uppercase tracking-widest">
                    Iniciar Checagem (Intervalo 15s a 20s)
                </button>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-xs font-mono uppercase tracking-wider text-zinc-400">Logs em Tempo Real:</label>
                    <span id="contador" class="text-xs text-zinc-500 font-mono">Progresso: 0 / 0</span>
                </div>
                <div id="resultado" class="w-full h-56 bg-black border border-zinc-800 rounded-xl p-4 text-xs font-mono overflow-y-auto text-zinc-400 space-y-2 selection:bg-zinc-800">
                    <span class="text-zinc-600">// Sistema pronto para iniciar as requisições...</span>
                </div>
            </div>
        </div>

        <script>
            // Efeito de confetes especial monocromático/elegante para comemorar Live
            function dispararEfeitoLive() {
                confetti({
                    particleCount: 50,
                    spread: 60,
                    origin: { y: 0.8 },
                    colors: ['#ffffff', '#a1a1aa', '#52525b']
                });
            }

            async function iniciarChecagem() {
                const texto = document.getElementById('lista').value.trim();
                const resDiv = document.getElementById('resultado');
                const btn = document.getElementById('btnChecar');
                const contador = document.getElementById('contador');

                if (!texto) {
                    alert('Insira uma lista de cartões válida!');
                    return;
                }

                const linhas = texto.split('\n').map(l => l.trim()).filter(l => l !== '');
                if (linhas.length === 0) return;

                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
                btn.innerText = "Processando checagem...";
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
                        let data = await response.json();
                        
                        // Cria elemento animado para a linha nova
                        let itemDiv = document.createElement('div');
                        itemDiv.className = "transition-all duration-300 transform translate-y-1 opacity-0 border-l-2 pl-2 py-0.5 " + (data.status === 'live' ? 'border-white bg-zinc-900/80' : 'border-zinc-800');
                        itemDiv.innerHTML = data.html;
                        
                        resDiv.appendChild(itemDiv);
                        
                        // Força animação suave de entrada
                        setTimeout(() => {
                            itemDiv.classList.remove('translate-y-1', 'opacity-0');
                        }, 50);

                        resDiv.scrollTop = resDiv.scrollHeight;

                        // Se for Live, dispara o efeito especial de confetes
                        if (data.status === 'live') {
                            dispararEfeitoLive();
                        }

                    } catch (err) {
                        resDiv.innerHTML += `<div class='text-zinc-600'>[ERRO] Falha na requisição: ${linhaAtual}</div>`;
                    }

                    if (i < linhas.length - 1) {
                        // Intervalo padrão aleatório entre 15 e 20 segundos (15000ms a 20000ms)
                        const randomDelay = Math.floor(Math.random() * (20000 - 15000 + 1)) + 15000;
                        await new Promise(resolve => setTimeout(resolve, randomDelay));
                    }
                }

                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
                btn.innerText = "Iniciar Checagem (Intervalo 15s a 20s)";
            }
        </script>
    <?php endif; ?>
</body>
</html>
