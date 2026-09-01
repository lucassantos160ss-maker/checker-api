<?php
session_start();

// Usuário e senha padrão para você entrar no checker
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

// Processar a Checagem (quando o botão de checar for acionado via JavaScript)
if (isset($_POST['ajax_checar'])) {
    if (!isset($_SESSION['logado'])) {
        exit("Sessão expirada. Faça login novamente.");
    }
    
    $lista = $_POST['lista'] ?? '';
    $linhas = explode("\n", trim($lista));
    
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if (empty($linha)) continue;
        
        // --- AQUI ENTRA A SUA LÓGICA DE cURL E VALIDAÇÃO ---
        // Exemplo de requisição cURL para testar a linha:
        /*
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "SUA_API_DE_CHECK_AQUI");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['dados' => $linha]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resposta = curl_exec($ch);
        curl_close($ch);
        */
        
        // Exibindo o resultado linha por linha na tela de forma visual
        echo "<span class='text-emerald-400'>[APROVADO]</span> $linha -> Retorno OK<br>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Checker Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <?php if (!isset($_SESSION['logado'])): ?>
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
            <div class="mt-4 text-center text-xs text-slate-500">
                Usuário padrão: <b>admin</b> | Senha padrão: <b>123</b>
            </div>
        </div>

    <?php else: ?>
        <div class="w-full max-w-2xl bg-slate-800 p-6 rounded-xl shadow-2xl border border-slate-700">
            <div class="flex justify-between items-center mb-6 border-b border-slate-700 pb-4">
                <h1 class="text-xl font-bold text-emerald-400">Painel de Checagem</h1>
                <a href="index.php?action=logout" class="bg-red-600/20 hover:bg-red-600/30 text-red-400 text-xs px-3 py-1.5 rounded-lg border border-red-500/30 transition">Sair</a>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2 text-slate-300">Cole sua lista de números (um por linha):</label>
                <textarea id="lista" rows="6" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:outline-none focus:border-emerald-500 text-slate-200 font-mono" placeholder="NUMERO|MES|ANO|CVV"></textarea>
            </div>

            <button onclick="executarChecagem()" id="btnChecar" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition duration-200 shadow-lg">
                Iniciar Checagem Automática
            </button>

            <div class="mt-6">
                <label class="block text-sm font-medium mb-2 text-slate-300">Resultados em Tempo Real:</label>
                <div id="resultado" class="w-full h-48 bg-slate-900 border border-slate-700 rounded-lg p-3 text-xs font-mono overflow-y-auto text-slate-300">
                    Aguardando dados...
                </div>
            </div>
        </div>

        <script>
            function executarChecagem() {
                const lista = document.getElementById('lista').value;
                const resDiv = document.getElementById('resultado');
                const btn = document.getElementById('btnChecar');

                if (!lista.trim()) {
                    alert('Cole uma lista válida antes de iniciar!');
                    return;
                }

                btn.disabled = true;
                btn.innerText = "Checando...";
                resDiv.innerHTML = "Iniciando processo...\n";

                fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'ajax_checar=1&lista=' + encodeURIComponent(lista)
                })
                .then(response => response.text())
                .then(data => {
                    resDiv.innerHTML = data;
                    btn.disabled = false;
                    btn.innerText = "Iniciar Checagem Automática";
                })
                .catch(error => {
                    resDiv.innerHTML += "<br><span class='text-red-400'>Erro na requisição.</span>";
                    btn.disabled = false;
                    btn.innerText = "Iniciar Checagem Automática";
                });
            }
        </script>
    <?php endif; ?>

</body>
</html>
