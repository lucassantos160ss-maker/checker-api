<?php
session_start();

$usuario_padrao = "admin";
$senha_padrao = "123";
$erro = "";

// Processar Login
if (isset($_POST['f_login'])) {
    $user = $_POST['usuario'] ?? '';
    $pass = $_POST['senha'] ?? '';
    
    if ($user === $usuario_padrao && $pass === $senha_padrao) {
        $_SESSION['logado'] = true;
        header("Location: index.php");
        exit;
    } else {
        $erro = "Usuário ou senha incorretos!";
    }
}

// Processar Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Processar cada linha individualmente enviada pelo JavaScript
if (isset($_POST['ajax_linha'])) {
    if (!isset($_SESSION['logado'])) {
        exit("Sessão expirada.");
    }
    
    $linha = trim($_POST['ajax_linha']);
    if (empty($linha)) exit;

    // Quebra os dados caso venha no formato: NUMERO|MES|ANO|CVV
    $partes = explode('|', $linha);
    $numero = $partes[0] ?? '';
    $mes = $partes[1] ?? '';
    $ano = $partes[2] ?? '';
    $cvv = $partes[3] ?? '';

    // --- COLOQUE SUA LÓGICA DE cURL REAL AQUI ---
    /*
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "SUA_API_EXTERNA_AQUI?numero=$numero&mes=$mes&ano=$ano&cvv=$cvv");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resposta = curl_exec($ch);
    curl_close($ch);
    
    // Exemplo de lógica baseada no retorno da API:
    if (strpos($resposta, 'sucesso') !== false) {
        echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> $linha -> Aprovado!";
    } else {
        echo "<span class='text-red-400 font-bold'>[DIE]</span> $linha -> Reprovado!";
    }
    */

    // Simulação temporária de Live/Die para teste visual (Remova quando colocar sua API cURL)
    $status_rand = (rand(1, 2) === 1);
    if ($status_rand) {
        echo "<span class='text-emerald-400 font-bold'>[LIVE]</span> $linha -> Aprovado com sucesso!";
    } else {
        echo "<span class='text-red-400 font-bold'>[DIE]</span> $linha -> Cartão ou dados recusados.";
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Checker Pro - Realtime</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <?php if (!isset($_SESSION['logado'])): ?>
        <!-- TELA DE LOGIN -->
        <div class="w-full max-w-md bg-slate-800 p-8 rounded-xl shadow-2xl border border-slate-700">
            <h1 class="text-2xl font-bold mb-6 text-center text-emerald-400">Login - Checker</h1>
            
            <?php if (!empty($erro)): ?>
                <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg mb-4 text-sm text-center">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="f_login" value="1">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Usuário:</label>
                    <input type="text" name="usuario" required class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200" placeholder="Ex: admin">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Senha:</label>
                    <input type="password" name="senha" required class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200" placeholder="••••••••">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition duration-200">
                    Entrar no Sistema
                </button>
            </form>
        </div>

    <?php else: ?>
        <!-- PAINEL PRINCIPAL -->
        <div class="w-full max-w-2xl bg-slate-800 p-6 rounded-xl shadow-2xl border border-slate-700">
            <div class="flex justify-between items-center mb-6 border-b border-slate-700 pb-4">
                <h1 class="text-xl font-bold text-emerald-400">Painel de Checagem em Tempo Real</h1>
                <a href="index.php?action=logout" class="bg-red-600/20 hover:bg-red-600/30 text-red-400 text-xs px-3 py-1.5 rounded-lg border border-red-500/30 transition">Sair</a>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2 text-slate-300">Cole sua lista (NUMERO|MES|ANO|CVV):</label>
                <textarea id="lista" rows="5" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200 font-mono" placeholder="4066699932589171|04|2031|829"></textarea>
            </div>

            <div class="mb-4 flex items-center gap-4">
                <div class="w-1/2">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Intervalo entre requisições:</label>
                    <select id="delay" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-2.5 text-sm text-slate-200 focus:outline-none">
                        <option value="1000">1 Segundo</option>
                        <option value="3000">3 Segundos</option>
                        <option value="5000" selected>5 Segundos (Recomendado)</option>
                        <option value="8000">8 Segundos</option>
                        <option value="10000">10 Segundos</option>
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
            let cancelado = false;

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
                
                let processados = 0;

                for (let i = 0; i < linhas.length; i++) {
                    const linhaAtual = linhas[i];
                    contador.innerText = `Progresso: ${i + 1} / ${linhas.length}`;

                    try {
                        let response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'ajax_linha=' + encodeURIComponent(linhaAtual)
                        });
                        let resultadoHtml = await response.text();
                        
                        // Adiciona o resultado linha por linha descendo a tela
                        resDiv.innerHTML += `<div>${resultadoHtml}</div>`;
                        resDiv.scrollTop = resDiv.scrollHeight; // Mantém rolando para o final automático

                    } catch (err) {
                        resDiv.innerHTML += `<div class='text-red-400'>[ERRO] Falha ao testar: ${linhaAtual}</div>`;
                    }

                    // Se não for o último item, aguarda o tempo estipulado (delay) para não estourar a chave/API
                    if (i < linhas.length - 1) {
                        await new Promise(resolve => setTimeout(resolve, delayMs));
                    }
                }

                btn.disabled = false;
                btn.innerText = "Iniciar Checagem";
                alert("Checagem finalizada!");
            }
        </script>
    <?php endif; ?>
</body>
</html>
