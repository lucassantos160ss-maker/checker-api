<?php
session_start();

// Configurações da API Elite PAY br
define('ELITE_CLIENT_ID', 'ep_684765b9795ccf41b0eb5b108b45199a');
define('ELITE_CLIENT_SECRET', 'eps_8e432f9f1ecb62987145bdbd4f141c1c3b39dcf6e22c6f5ea270f99488577e');
define('ELITE_BASE_URL', 'https://api.elitepaybr.com/api/v1');

// Senha Mestre do Painel
$SENHA_MESTRE = "sua_senha_aqui";

// Arquivos de banco de dados simples (TXT)
$ARQUIVO_CHAVES = __DIR__ . '/chaves_estoque.txt';
$ARQUIVO_VENDIDAS = __DIR__ . '/chaves_vendidas.txt';

// Inicializa arquivos se não existirem
if (!file_exists($ARQUIVO_CHAVES)) file_put_contents($ARQUIVO_CHAVES, "CHAVE-EXEMPLO-1DIA-12345\nCHAVE-EXEMPLO-1DIA-67890");
if (!file_exists($ARQUIVO_VENDIDAS)) file_put_contents($ARQUIVO_VENDIDAS, "");

// Lógica de Login Simples
if (isset($_POST['login_senha'])) {
    if ($_POST['login_senha'] === $SENHA_MESTRE) {
        $_SESSION['logado'] = true;
        $_SESSION['expira_em'] = time() + 86400;
    } else {
        $erro_login = "Senha incorreta!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$logado = isset($_SESSION['logado']) && $_SESSION['logado'] === true;

/**
 * Função para criar a cobrança Pix via Elite PAY
 */
function criarPagamentoPix($valor, $descricao, $nomePagador, $cpfPagador) {
    $url = ELITE_BASE_URL . '/deposit';
    
    $dados = [
        "amount" => (float)$valor,
        "description" => $descricao,
        "payerName" => $nomePagador,
        "payerDocument" => preg_replace('/[^0-9]/', '', $cpfPagador)
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-client-id: ' . ELITE_CLIENT_ID,
        'x-client-secret: ' . ELITE_CLIENT_SECRET
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        $resultado = json_decode($response, true);
        if (isset($resultado['success']) && $resultado['success'] === true) {
            return [
                'sucesso' => true,
                'transactionId' => $resultado['transactionId'],
                'copia_e_cola' => $resultado['copyPaste'],
                'qrcode_base64' => $resultado['base64'] ?? null,
                'status' => $resultado['status']
            ];
        }
    }

    return [
        'sucesso' => false,
        'erro' => 'Falha ao gerar Pix. Resposta: ' . $response
    ];
}

/**
 * Endpoint AJAX para requisições assíncronas
 */
if (isset($_POST['acao'])) {
    header('Content-Type: application/json');
    
    // 1. Gerar Pix
    if ($_POST['acao'] === 'gerar_pix') {
        $valor = $_POST['valor'] ?? 10.00;
        $plano = $_POST['plano'] ?? 'Plano Teste';
        
        $resultadoPix = criarPagamentoPix($valor, "Compra de Acesso - " . $plano, "Cliente Loja", "00000000000");
        
        if ($resultadoPix['sucesso']) {
            // Salva o transactionId na sessão temporariamente para rastreio
            $_SESSION['transacao_atual'] = $resultadoPix['transactionId'];
        }
        
        echo json_encode($resultadoPix);
        exit;
    }

    // 2. Checar Status do Pagamento (Polling) e Entregar a Chave
    if ($_POST['acao'] === 'checar_status') {
        $transactionId = $_SESSION['transacao_atual'] ?? '';
        
        if (empty($transactionId)) {
            echo json_encode(['status' => 'PENDENTE']);
            exit;
        }

        // Consulta a API da Elite Pay para verificar o status do depósito
        $url = ELITE_BASE_URL . '/deposit/' . $transactionId; // Verifique na documentação se a rota de consulta usa exatamente este padrão ou /transaction/
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-client-id: ' . ELITE_CLIENT_ID,
            'x-client-secret: ' . ELITE_CLIENT_SECRET
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $pago = false;
        if ($httpCode === 200) {
            $res = json_decode($response, true);
            // Altere conforme o status exato que a API retorna quando aprovado (ex: "CONCLUIDO", "PAGO", "APROVADO")
            if (isset($res['status']) && in_array(strtoupper($res['status']), ['CONCLUIDO', 'PAGO', 'APROVADO', 'PAID'])) {
                $pago = true;
            }
        }

        // SIMULAÇÃO DE TESTE LOCAL caso a API de consulta retorne pendente em ambiente de testes:
        // Remova a linha abaixo ($pago = true;) quando colocar em produção real.
        // $pago = true; 

        if ($pago) {
            // Pega uma chave do estoque
            $linhas = file($ARQUIVO_CHAVES, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            if (count($linhas) > 0) {
                $chaveEntregue = array_shift($linhas); // Remove a primeira chave do estoque
                
                // Salva de volta o estoque atualizado
                file_put_contents($ARQUIVO_CHAVES, implode(PHP_EOL, $linhas) . (count($linhas) > 0 ? PHP_EOL : ''));
                
                // Registra como vendida
                file_put_contents($ARQUIVO_VENDIDAS, date('Y-m-d H:i:s') . " - " . $chaveEntregue . PHP_EOL, FILE_APPEND);
                
                unset($_SESSION['transacao_atual']); // Limpa a sessão
                
                echo json_encode([
                    'status' => 'APROVADO',
                    'chave' => $chaveEntregue
                ]);
                exit;
            } else {
                echo json_encode([
                    'status' => 'ERRO_ESTOQUE',
                    'mensagem' => 'Pagamento aprovado, mas o estoque de chaves acabou! Contate o suporte.'
                ]);
                exit;
            }
        }

        echo json_encode(['status' => 'PENDENTE']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Checker & Loja Pix Automática</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between">

    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <header class="flex justify-between items-center mb-8 border-b border-slate-800 pb-4">
            <h1 class="text-2xl font-bold bg-gradient-to-r from-purple-400 to-indigo-500 bg-clip-text text-transparent">Elite Checker & Store</h1>
            <?php if ($logado): ?>
                <a href="?logout=true" class="bg-red-600/20 text-red-400 hover:bg-red-600/30 px-4 py-2 rounded-lg text-sm transition">Sair</a>
            <?php endif; ?>
        </header>

        <?php if (! $logado): ?>
            <div class="grid md:grid-cols-2 gap-8">
                <!-- Login -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <h2 class="text-xl font-semibold mb-4 text-purple-400">Acesso Restrito</h2>
                    <?php if (isset($erro_login)): ?>
                        <p class="text-red-400 text-sm mb-4 bg-red-950/50 p-3 rounded border border-red-900"><?= $erro_login ?></p>
                    <?php endif; ?>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label class="block text-sm text-slate-400 mb-1">Senha Mestre</label>
                            <input type="password" name="login_senha" required class="w-full bg-slate-950 border border-slate-800 rounded-lg px-4 py-2.5 focus:outline-none focus:border-purple-500">
                        </div>
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 font-medium py-2.5 rounded-lg transition">Entrar no Painel</button>
                    </form>
                </div>

                <!-- Loja / Compra -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl flex flex-col justify-between">
                    <div>
                        <h2 class="text-xl font-semibold mb-2 text-indigo-400">Adquirir Chave de Acesso</h2>
                        <p class="text-slate-400 text-sm mb-4">O sistema entrega a chave automaticamente após a confirmação do Pix.</p>
                        
                        <div class="space-y-3 mb-4">
                            <label class="block text-sm text-slate-400">Selecione o Plano:</label>
                            <select id="select-plano" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-4 py-2.5 text-sm">
                                <option value="25.00" data-nome="Plano 1 Dia">Plano 1 Dia - R$ 25,00</option>
                                <option value="60.00" data-nome="Plano 7 Dias">Plano 7 Dias - R$ 60,00</option>
                                <option value="120.00" data-nome="Plano 30 Dias">Plano 30 Dias - R$ 120,00</option>
                            </select>
                        </div>
                    </div>
                    
                    <button onclick="comprarPlano()" class="w-full bg-emerald-600 hover:bg-emerald-700 font-medium py-2.5 rounded-lg transition">Gerar Pix de Pagamento</button>
                </div>
            </div>

            <!-- Modal do Pix & Entrega Automática -->
            <div id="area-pix" class="hidden mt-8 bg-slate-900 border border-slate-800 rounded-xl p-6 text-center">
                <div id="checkout-content">
                    <h3 class="text-lg font-semibold mb-2 text-emerald-400">Escaneie o QR Code ou Copie o Pix</h3>
                    <div id="qrcode-container" class="my-4 flex justify-center"></div>
                    <textarea id="copia-cola" readonly class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs text-slate-300 h-20 mb-3 resize-none"></textarea>
                    <button onclick="copiarPix()" class="bg-slate-800 hover:bg-slate-700 px-4 py-2 rounded-lg text-sm transition">Copiar Código Pix</button>
                    <p id="status-pagamento" class="text-yellow-500 text-sm mt-4 animate-pulse">Aguardando pagamento via Pix...</p>
                </div>

                <!-- Área onde a chave é exibida após aprovação -->
                <div id="area-sucesso-chave" class="hidden">
                    <h3 class="text-xl font-bold text-emerald-400 mb-2">🎉 Pagamento Aprovado com Sucesso!</h3>
                    <p class="text-slate-300 text-sm mb-4">Sua chave de acesso foi gerada e entregue abaixo:</p>
                    <input type="text" id="chave-entregue" readonly class="w-full bg-slate-950 border border-emerald-500 rounded-lg p-3 text-center text-emerald-300 font-mono text-lg mb-4">
                    <button onclick="copiarChave()" class="bg-purple-600 hover:bg-purple-700 px-6 py-2.5 rounded-lg text-sm font-medium transition">Copiar Chave de Acesso</button>
                </div>
            </div>

        <?php else: ?>
            <!-- PAINEL DO CHECKER (LOGADO) -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                <h2 class="text-xl font-semibold mb-4 text-purple-400">Módulo Checker CC</h2>
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm text-slate-400 mb-2">Insira sua lista (FORMATO: NUMERO|MES|ANO|CVV)</label>
                        <textarea id="lista-ccs" rows="8" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-sm font-mono focus:outline-none focus:border-purple-500" placeholder="4000000000000000|01|28|123"></textarea>
                        <button onclick="iniciarChecker()" class="mt-4 w-full bg-purple-600 hover:bg-purple-700 font-medium py-2.5 rounded-lg transition">Iniciar Testes</button>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-2">Console / Retorno</label>
                        <div id="console-checker" class="w-full h-48 bg-slate-950 border border-slate-800 rounded-lg p-3 text-xs font-mono overflow-y-auto text-slate-300">
                            Aguardando início...
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let intervaloVerificacao = null;

        function comprarPlano() {
            const select = document.getElementById('select-plano');
            const valor = select.value;
            const planoNome = select.options[select.selectedIndex].getAttribute('data-nome');

            const formData = new FormData();
            formData.append('acao', 'gerar_pix');
            formData.append('valor', valor);
            formData.append('plano', planoNome);

            fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.sucesso) {
                    document.getElementById('area-pix').classList.remove('hidden');
                    if (data.qrcode_base64) {
                        document.getElementById('qrcode-container').innerHTML = `<img src="data:image/png;base64,${data.qrcode_base64}" class="w-48 h-48 rounded bg-white p-2">`;
                    }
                    document.getElementById('copia-cola').value = data.copia_e_cola;
                    
                    // Inicia o Polling (checa a cada 5 segundos se o Pix foi pago)
                    if (intervaloVerificacao) clearInterval(intervaloVerificacao);
                    intervaloVerificacao = setInterval(checarStatusPagamento, 5000);
                } else {
                    alert(data.erro);
                }
            });
        }

        function checarStatusPagamento() {
            const formData = new FormData();
            formData.append('acao', 'checar_status');

            fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'APROVADO') {
                    clearInterval(intervaloVerificacao);
                    
                    // Oculta dados do pix e mostra a chave entregue
                    document.getElementById('checkout-content').classList.add('hidden');
                    document.getElementById('area-sucesso-chave').classList.remove('hidden');
                    document.getElementById('chave-entregue').value = data.chave;

                    // Efeito de confete comemorativo
                    confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
                } else if (data.status === 'ERRO_ESTOQUE') {
                    clearInterval(intervaloVerificacao);
                    document.getElementById('status-pagamento').innerText = data.mensagem;
                    document.getElementById('status-pagamento').className = "text-red-400 text-sm mt-4 font-bold";
                }
            });
        }

        function copiarPix() {
            const copyText = document.getElementById('copia-cola');
            copyText.select();
            document.execCommand('copy');
            alert('Código Pix copiado!');
        }

        function copiarChave() {
            const copyText = document.getElementById('chave-entregue');
            copyText.select();
            document.execCommand('copy');
            alert('Chave de acesso copiada com sucesso!');
        }

        function iniciarChecker() {
            const lista = document.getElementById('lista-ccs').value.trim().split('\n');
            const consoleBox = document.getElementById('console-checker');
            consoleBox.innerHTML = '';
            
            if (!lista.length || lista[0] === '') {
                consoleBox.innerHTML = '<span class="text-red-400">Insira ao menos uma linha válida.</span>';
                return;
            }

            lista.forEach((cc, index) => {
                setTimeout(() => {
                    const status = Math.random() > 0.5 ? '<span class="text-emerald-400">APROVADO (LIVE)</span>' : '<span class="text-red-400">RECUSADO (DIE)</span>';
                    consoleBox.innerHTML += `[${index + 1}] ${cc} - ${status}<br>`;
                    consoleBox.scrollTop = consoleBox.scrollHeight;
                }, index * 1000);
            });
        }
    </script>
</body>
</html>
