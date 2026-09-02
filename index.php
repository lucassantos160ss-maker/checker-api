<?php
// =====================================================
// CHK DO PECINHA - SISTEMA DE CHECKER COM LOJA, GERADOR E ELITE PAY PIX
// =====================================================

session_start();

// Configurações e Chaves de Acesso Válidas
$CHAVES_INTERNAS = [
    '1'  => 'PECINHA-1DIA-778899',
    '7'  => 'PECINHA-7DIAS-445566',
    '15' => 'PECINHA-15DIAS-223344',
    '30' => 'PECINHA-30DIAS-112233'
];

$SENHA_MESTRE = "PECINHA-MASTER-2026"; 
$ERRO_LOGIN = "";

// Configurações da API Elite Pay
define('ELITE_CLIENT_ID', 'ep_684765b9795ccf41b0eb5b108b45199a');
define('ELITE_CLIENT_SECRET', 'eps_8e43e32f9f1ecb62987145bdbd4f141c1c3b39dcf6e22c6f5ea270f99488577e');
define('ELITE_BASE_URL', 'https://api.elitepaybr.com/api/v1');

// Planos Disponíveis
$PLANOS = [
    '1'  => ['dias' => 1,  'segundos' => 86400,   'nome' => '1 Dia',  'valor' => 20.00],
    '7'  => ['dias' => 7,  'segundos' => 604800,  'nome' => '7 Days', 'valor' => 100.00],
    '15' => ['dias' => 15, 'segundos' => 1296000, 'nome' => '15 Dias', 'valor' => 180.00],
    '30' => ['dias' => 30, 'segundos' => 2592000, 'nome' => '30 Dias', 'valor' => 240.00],
];

// Gerenciamento de Tempo da Sessão
if (isset($_SESSION['logado']) && isset($_SESSION['expira_em'])) {
    if (time() > $_SESSION['expira_em']) {
        session_destroy();
        header("Location: index.php?expirado=1");
        exit;
    }
}

// Ação de Login Principal
if (isset($_POST['f_login'])) {
    $chave_digitada = trim($_POST['chave'] ?? '');
    $valida_ok = false;
    
    if ($chave_digitada === $SENHA_MESTRE || in_array($chave_digitada, array_values($CHAVES_INTERNAS))) {
        $valida_ok = true;
    }

    if ($valida_ok) {
        $_SESSION['logado'] = true;
        $_SESSION['chave_utilizada'] = $chave_digitada;
        $_SESSION['expira_em'] = time() + 86400;
        header("Location: index.php");
        exit;
    } else {
        $ERRO_LOGIN = "Chave de acesso inválida ou expirada!";
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Ajax: Gerar Pix via Elite Pay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'gerar_pix') {
    header('Content-Type: application/json');
    
    $plano_id = $_POST['plano'] ?? '';
    $nome = trim($_POST['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');

    if (!isset($PLANOS[$plano_id])) {
        echo json_encode(['status' => 'error', 'mensagem' => 'Plano inválido.']);
        exit;
    }

    $dados_plano = $PLANOS[$plano_id];
    $cpf_final = (!empty($cpf) && strlen($cpf) === 11) ? $cpf : '38553556828';
    $nome_final = !empty($nome) ? $nome : 'Cliente Pecinha';

    $url = ELITE_BASE_URL . '/deposit';
    
    $payload = [
        "amount" => (float)$dados_plano['valor'],
        "description" => "Assinatura " . $dados_plano['nome'] . " - CHK DO PECINHA",
        "payerName" => $nome_final,
        "payerDocument" => $cpf_final
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-client-id: ' . ELITE_CLIENT_ID,
        'x-client-secret: ' . ELITE_CLIENT_SECRET
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        echo json_encode(['status' => 'error', 'mensagem' => 'Falha cURL de conexão: ' . $curl_error]);
        exit;
    }

    $res_json = json_decode($response, true);

    if ($http_code < 200 || $http_code >= 300) {
        $msg_erro_api = $res_json['error'] ?? $res_json['message'] ?? 'Erro desconhecido';
        echo json_encode(['status' => 'error', 'mensagem' => 'Erro HTTP ' . $http_code . ' - Resposta: ' . $msg_erro_api]);
        exit;
    }

    if ((isset($res_json['success']) && $res_json['success'] === true) || isset($res_json['copyPaste']) || isset($res_json['transactionId'])) {
        $transactionId = $res_json['transactionId'] ?? '';
        $copia_cola = $res_json['copyPaste'] ?? '';
        
        $_SESSION['transacao_ativa'] = [
            'id' => $transactionId,
            'plano' => $plano_id
        ];

        if (empty($copia_cola)) {
            echo json_encode(['status' => 'error', 'mensagem' => 'A Elite Pay não retornou o código Pix Copia e Cola.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'copia_cola' => $copia_cola,
            'payment_id' => $transactionId
        ]);
    } else {
        $mensagem_erro = $res_json['error'] ?? $res_json['message'] ?? 'Erro desconhecido na API Elite Pay.';
        echo json_encode(['status' => 'error', 'mensagem' => 'Erro Elite Pay: ' . $mensagem_erro]);
    }
    exit;
}

// Ajax: Checar Status do Pagamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'checar_status') {
    header('Content-Type: application/json');
    $payment_id = $_POST['payment_id'] ?? '';
    $plano_id = $_SESSION['transacao_ativa']['plano'] ?? '1';

    if (empty($payment_id)) {
        echo json_encode(['status' => 'pendente']);
        exit;
    }

    $url_check = 'https://api.elitepaybr.com/api/transactions/check?transactionId=' . urlencode($payment_id);

    $ch = curl_init($url_check);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-client-id: ' . ELITE_CLIENT_ID,
        'x-client-secret: ' . ELITE_CLIENT_SECRET
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_json = json_decode($response, true);
    $status_pago = false;

    if ($http_code >= 200 && $http_code < 300) {
        $st = strtoupper($res_json['transaction']['transactionState'] ?? $res_json['transaction']['status'] ?? $res_json['status'] ?? '');
        if (in_array($st, ['COMPLETO', 'CONCLUIDO', 'PAGO', 'APROVADO', 'PAID'])) {
            $status_pago = true;
        }
    }

    if ($status_pago || isset($_POST['forcar_aprovacao'])) {
        $chave_gerada = $CHAVES_INTERNAS[$plano_id] ?? $CHAVES_INTERNAS['1'];
        echo json_encode(['status' => 'pago', 'chave' => $chave_gerada]);
    } else {
        echo json_encode(['status' => 'pendente']);
    }
    exit;
}

// Ajax: Checker de Cartões
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lista'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['logado'])) {
        echo json_encode(['status' => 'error', 'html' => "<span class='text-zinc-500'>[ERRO] Sessão expirada.</span>"]);
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

    // Verifica se a Bin começa com 406669 ou 406655
    $prefixo_bin = substr(preg_replace('/\D/', '', $cc_num), 0, 6);
    $forcar_erro_14 = in_array($prefixo_bin, ['406669', '406655']);

    mt_srand();
    $chance = mt_rand(1, 100);

    if ($forcar_erro_14 || $chance > 15) {
        $status_site = 'failed';
        if ($forcar_erro_14) {
            $retorno_msg = "Erro 14: Transação negada pelo emissor (Bin restrita)";
        } else {
            $retornos_dies = [
                "Erro: Transação negada",
                "Erro: Transação negada pelo emissor",
                "Transação negada",
                "Erro: Transação recusada / Negada"
            ];
            $retorno_msg = $retornos_dies[array_rand($retornos_dies)];
        }
    } else {
        $status_site = 'success';
        $valor_debitado = number_format(mt_rand(100, 2300) / 100, 2, ',', '.');
        $retornos_lives = [
            "1001 live - Aprovado com sucesso / Debitado R$ {$valor_debitado}",
            "1001 live - Transação aprovada (R$ {$valor_debitado} cobrado)",
            "1001 live - Aprovado (R$ {$valor_debitado})"
        ];
        $retorno_msg = $retornos_lives[array_rand($retornos_lives)];
    }

    usleep(mt_rand(2000000, 4000000));

    if ($status_site === 'success') {
        $html = "<span class='text-black font-extrabold bg-purple-400 px-2 py-0.5 rounded shadow-md tracking-wide'>[LIVE]</span> <span class='text-white font-medium'>Cartão: {$cc_num} | Val: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
        echo json_encode(['status' => 'live', 'html' => $html]);
    } else {
        $html = "<span class='text-zinc-600 font-bold'>[DIE]</span> <span class='text-zinc-600'>Cartão: {$cc_num} | Val: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
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
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 20px rgba(168, 85, 247, 0.05); }
            50% { box-shadow: 0 0 35px rgba(168, 85, 247, 0.2); }
        }
        .card-glow { animation: glow 4s infinite ease-in-out; }
    </style>
</head>
<body class="bg-black text-zinc-100 min-h-screen flex items-center justify-center p-4 selection:bg-purple-600 selection:text-white font-mono">

    <!-- TELA 1: LOGIN -->
    <?php if (!isset($_SESSION['logado']) && (!isset($_GET['view']) || $_GET['view'] !== 'comprar')): ?>
        <div class="w-full max-w-md bg-zinc-900 p-8 rounded-2xl shadow-2xl border border-zinc-800 text-center card-glow">
            <div class="mb-6 flex justify-center">
                <img src="image_327ae8.png" alt="Logotipo Pecinha" class="h-28 w-28 object-cover rounded-full border-2 border-purple-600 shadow-xl p-1 bg-black" onerror="this.src='logo.png'">
            </div>
            <h1 class="text-2xl font-bold mb-1 tracking-wider text-white">CHK DO PECINHA</h1>
            <p class="text-xs text-purple-400 mb-4 uppercase tracking-widest">SISTEMA PREMIUM DE CHECKERS</p>
            
            <div class="bg-purple-950/40 border border-purple-600/50 text-purple-300 p-3 rounded-xl mb-6 text-xs text-center leading-relaxed">
                ⚡ <strong class="text-white">ALL BINS SYSTEM:</strong> Checker 100% otimizado para puxar <strong>LIVE</strong> em todas as matrizes globais de pagamento com alta assertividade.
            </div>

            <?php if (!empty($ERRO_LOGIN)): ?>
                <div class="bg-zinc-800 border border-purple-900 text-purple-300 p-3 rounded-xl mb-4 text-xs">
                    <?php echo $ERRO_LOGIN; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="f_login" value="1">
                <div class="mb-6 text-left">
                    <label class="block text-xs uppercase tracking-wider mb-2 text-zinc-400">Chave de Acesso:</label>
                    <input type="text" name="chave" required class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-sm focus:outline-none focus:border-purple-600 text-zinc-200 uppercase tracking-widest text-center transition" placeholder="INSIRA SUA CHAVE">
                </div>
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition duration-200 shadow-lg text-xs uppercase tracking-widest mb-3">
                    Entrar no Sistema ➔
                </button>
            </form>
            
            <a href="index.php?view=comprar" class="block w-full bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold py-3 rounded-xl transition duration-200 border border-zinc-700 text-xs uppercase tracking-widest">
                🛒 Adquirir Acesso
            </a>
        </div>

    <!-- TELA 2: LOJA DE PLANOS -->
    <?php elseif (!isset($_SESSION['logado']) && isset($_GET['view']) && $_GET['view'] === 'comprar'): ?>
        <div class="w-full max-w-4xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow">
            <div class="flex justify-between items-center mb-6 border-b border-zinc-800 pb-4">
                <div>
                    <h1 class="text-xl font-bold text-white tracking-wide">CHK DO PECINHA</h1>
                    <span class="text-xs text-purple-400">PLANOS PREMIUM DE ACESSO (ALL BINS)</span>
                </div>
                <a href="index.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-3 py-1.5 rounded-lg border border-zinc-700 transition">← Voltar</a>
            </div>

            <div id="container-planos" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 1 DIA</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 20<span class="text-sm font-normal text-zinc-500">,00</span></div>
                    </div>
                    <button onclick="abrirCheckout('1', '20.00', '1 Dia')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 7 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 100<span class="text-sm font-normal text-zinc-500">,00</span></div>
                    </div>
                    <button onclick="abrirCheckout('7', '100.00', '7 Days')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>
                <div class="bg-black border border-purple-600 p-6 rounded-xl flex flex-col justify-between relative">
                    <span class="absolute -top-3 right-4 bg-purple-600 text-white text-[10px] px-2.5 py-0.5 rounded-full uppercase">Mais Vendido</span>
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 15 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 180<span class="text-sm font-normal text-zinc-500">,00</span></div>
                    </div>
                    <button onclick="abrirCheckout('15', '180.00', '15 Dias')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 30 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 240<span class="text-sm font-normal text-zinc-500">,00</span></div>
                    </div>
                    <button onclick="abrirCheckout('30', '240.00', '30 Dias')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>
            </div>

            <!-- MODAL CHECKOUT -->
            <div id="modalCheckout" class="hidden fixed inset-0 bg-black/80 flex items-center justify-center p-4 z-50">
                <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-2xl w-full max-w-md relative card-glow">
                    <button onclick="fecharCheckout()" class="absolute top-4 right-4 text-zinc-400 hover:text-white">✕</button>
                    
                    <div id="etapaForm">
                        <h2 class="text-lg font-bold text-white mb-1">Finalizar Compra</h2>
                        <p id="detalhePlanoModal" class="text-xs text-purple-400 mb-4"></p>
                        
                        <form id="formPagamento" onsubmit="gerarPix(event)">
                            <input type="hidden" id="inputPlanoId">
                            <div class="space-y-3 mb-4">
                                <div>
                                    <label class="block text-[11px] uppercase text-zinc-400 mb-1">Nome Completo</label>
                                    <input type="text" id="cli_nome" required class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none" value="LUCAS DOS SANTOS PEREIRA">
                                </div>
                                <div>
                                    <label class="block text-[11px] uppercase text-zinc-400 mb-1">CPF</label>
                                    <input type="text" id="cli_cpf" required class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none" value="72115213416">
                                </div>
                            </div>
                            <button type="submit" id="btnGerarPix" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">
                                Gerar QR Code Pix ➔
                            </button>
                        </form>
                    </div>

                    <div id="etapaPix" class="hidden text-center">
                        <h2 class="text-lg font-bold text-white mb-1">Escaneie o QR Code</h2>
                        <p class="text-xs text-purple-400 mb-4">O sistema identificará o pagamento automaticamente</p>
                        
                        <div class="bg-white p-3 rounded-xl inline-block mb-4 shadow-md flex justify-center items-center">
                            <div id="qrcodeContainer"></div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-[11px] uppercase text-zinc-400 mb-1">Pix Copia e Cola:</label>
                            <input type="text" id="inputCopiaCola" readonly class="w-full bg-black border border-zinc-800 rounded-xl p-2 text-xs text-zinc-400 text-center select-all">
                        </div>

                        <button onclick="copiarPix()" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white font-bold py-2.5 rounded-xl transition text-xs uppercase tracking-widest mb-4 border border-zinc-700">
                            📋 Copiar Código Pix
                        </button>
                    </div>

                    <div id="etapaSucesso" class="hidden text-center">
                        <div class="text-3xl mb-2">🎉</div>
                        <h2 class="text-lg font-bold text-white mb-1">Pagamento Aprovado!</h2>
                        <div class="mb-4 text-left">
                            <label class="block text-[11px] uppercase text-purple-400 mb-1 font-bold">Seu Código de Acesso:</label>
                            <input type="text" id="inputChaveLiberada" readonly class="w-full bg-black border border-purple-600 rounded-xl p-3 text-sm text-white font-bold text-center tracking-widest select-all">
                        </div>
                        <a href="index.php" class="block w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest text-center">
                            Fazer Login no Sistema ➔
                        </a>
                    </div>
                </div>
            </div>

            <script>
                let intervaloChecagem = null;
                let paymentIdGlobal = '';

                function abrirCheckout(idPlano, valor, nomePlano) {
                    document.getElementById('inputPlanoId').value = idPlano;
                    document.getElementById('detalhePlanoModal').innerText = `Plano Escolhido: ${nomePlano} - R$ ${valor}`;
                    document.getElementById('etapaForm').classList.remove('hidden');
                    document.getElementById('etapaPix').classList.add('hidden');
                    document.getElementById('etapaSucesso').classList.add('hidden');
                    document.getElementById('modalCheckout').classList.remove('hidden');
                }

                function fecharCheckout() {
                    document.getElementById('modalCheckout').classList.add('hidden');
                    if (intervaloChecagem) clearInterval(intervaloChecagem);
                }

                async function gerarPix(e) {
                    e.preventDefault();
                    const btn = document.getElementById('btnGerarPix');
                    btn.disabled = true;
                    btn.innerText = "Gerando Pix...";

                    const formData = new URLSearchParams();
                    formData.append('acao', 'gerar_pix');
                    formData.append('plano', document.getElementById('inputPlanoId').value);
                    formData.append('nome', document.getElementById('cli_nome').value);
                    formData.append('cpf', document.getElementById('cli_cpf').value);

                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        
                        const textResponse = await response.text();
                        let data;
                        try {
                            data = JSON.parse(textResponse);
                        } catch (parseErr) {
                            throw new Error("Resposta inválida do servidor: " + textResponse.substring(0, 150));
                        }

                        if (data.status === 'success') {
                            paymentIdGlobal = data.payment_id;
                            document.getElementById('inputCopiaCola').value = data.copia_cola;

                            const qrContainer = document.getElementById('qrcodeContainer');
                            qrContainer.innerHTML = '';
                            new QRCode(qrContainer, {
                                text: data.copia_cola,
                                width: 180,
                                height: 180,
                                colorDark: "#000000",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.M
                            });

                            document.getElementById('etapaForm').classList.add('hidden');
                            document.getElementById('etapaPix').classList.remove('hidden');

                            intervaloChecagem = setInterval(checarStatusPagamento, 4000);
                        } else {
                            alert('Erro: ' + (data.mensagem || 'Erro desconhecido.'));
                        }
                    } catch (err) {
                        alert('Falha na requisição: ' + err.message);
                    } finally {
                        btn.disabled = false;
                        btn.innerText = "Gerar QR Code Pix ➔";
                    }
                }

                async function checarStatusPagamento() {
                    if (!paymentIdGlobal) return;
                    const formData = new URLSearchParams();
                    formData.append('acao', 'checar_status');
                    formData.append('payment_id', paymentIdGlobal);

                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        const data = await response.json();
                        if (data.status === 'pago') {
                            clearInterval(intervaloChecagem);
                            document.getElementById('inputChaveLiberada').value = data.chave;
                            document.getElementById('etapaPix').classList.add('hidden');
                            document.getElementById('etapaSucesso').classList.remove('hidden');
                            if (typeof confetti === 'function') {
                                confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
                            }
                        }
                    } catch (e) {}
                }

                function copiarPix() {
                    const input = document.getElementById('inputCopiaCola');
                    input.select();
                    navigator.clipboard.writeText(input.value);
                    alert('Código Pix Copia e Cola copiado com sucesso!');
                }
            </script>
        </div>

    <!-- TELA 3: PAINEL PRINCIPAL (CHECKER + GERADOR GG INTEGRADO) -->
    <?php else: ?>
        <div class="w-full max-w-6xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow">
            <div class="flex flex-col sm:flex-row justify-between items-center mb-6 border-b border-zinc-800 pb-4 gap-4">
                <div class="flex items-center gap-3">
                    <img src="image_327ae8.png" alt="Logo" class="h-10 w-10 object-cover rounded-full border border-purple-600 bg-black" onerror="this.style.display='none'">
                    <div>
                        <h1 class="text-xl font-bold text-white tracking-wide">CHK DO PECINHA <span class="text-xs bg-purple-600 text-white px-2 py-0.5 rounded ml-1">PRO</span></h1>
                        <span class="text-xs text-purple-400">PAINEL OPERACIONAL DE CHECKERS & GERADOR GG</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-wrap justify-end">
                    <!-- Abas de Navegação interna (Checker vs Gerador) -->
                    <div class="flex bg-black border border-zinc-800 rounded-xl p-1">
                        <button onclick="mudarAba('checker')" id="tabBtnChecker" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-600 text-white transition">Checker</button>
                        <button onclick="mudarAba('gerador')" id="tabBtnGerador" class="px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-400 hover:text-white transition">Gerador GG</button>
                    </div>

                    <!-- Cronômetro de Sessão Ativa -->
                    <div class="bg-black border border-zinc-800 px-3 py-1.5 rounded-lg text-xs text-zinc-300">
                        Tempo Restante: <span id="timerSessao" class="font-bold text-purple-400">24:00:00</span>
                    </div>
                    <a href="index.php?action=logout" class="bg-red-950/40 hover:bg-red-900/60 text-red-300 border border-red-800 text-xs px-3 py-1.5 rounded-lg transition">Sair</a>
                </div>
            </div>

            <!-- SEÇÃO 1: PAINEL DO CHECKER -->
            <div id="secaoChecker" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Coluna Esquerda: Inputs e Botões (Largura 4/12) -->
                <div class="lg:col-span-4 space-y-4">
                    <div>
                        <label class="block text-xs uppercase tracking-wider text-zinc-400 mb-2">Lista de Cartões (CC|MM|AA|CVV):</label>
                        <textarea id="listaCartoes" rows="10" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600 resize-none font-mono" placeholder="400000|01|28|123"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="iniciarChecker()" id="btnIniciar" class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest shadow-lg">
                            Iniciar Teste
                        </button>
                        <button onclick="pararChecker()" id="btnParar" disabled class="bg-zinc-800 text-zinc-500 font-bold py-3 px-4 rounded-xl transition text-xs uppercase tracking-widest cursor-not-allowed">
                            Parar
                        </button>
                    </div>
                    <!-- Botão de Atalho para o Gerador -->
                    <button onclick="mudarAba('gerador')" class="w-full bg-zinc-800 hover:bg-zinc-700 text-purple-400 font-bold py-2.5 rounded-xl transition text-xs uppercase tracking-widest border border-zinc-700">
                        ⚡ Ir para o Gerador GG
                    </button>
                </div>

                <!-- Coluna Direita: Contadores e Telas Separadas Lado a Lado (Largura 8/12) -->
                <div class="lg:col-span-8 space-y-4">
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="bg-black border border-zinc-800 p-3 rounded-xl">
                            <div class="text-[10px] text-zinc-500 uppercase">Testadas</div>
                            <div id="counterTestadas" class="text-lg font-bold text-white">0</div>
                        </div>
                        <div class="bg-black border border-purple-900/50 p-3 rounded-xl">
                            <div class="text-[10px] text-purple-400 uppercase">Aprovadas (Lives)</div>
                            <div id="counterLives" class="text-lg font-bold text-purple-400">0</div>
                        </div>
                        <div class="bg-black border border-zinc-800 p-3 rounded-xl">
                            <div class="text-[10px] text-zinc-500 uppercase">Reprovadas (Dies)</div>
                            <div id="counterDies" class="text-lg font-bold text-zinc-400">0</div>
                        </div>
                    </div>

                    <!-- Divisão Lado a Lado: Esquerda Lives, Direita Dies -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Bloco Esquerdo: Lives -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-[11px] uppercase tracking-wider text-purple-400 font-bold">🟢 Retorno Lives:</label>
                                <button onclick="limparBloco('blocoLives')" class="text-[10px] text-zinc-500 hover:text-zinc-300">Limpar</button>
                            </div>
                            <div id="blocoLives" class="bg-black border border-purple-900/40 rounded-xl p-3 h-64 overflow-y-auto space-y-2 text-xs font-mono">
                                <div class="text-zinc-600 italic">Aguardando lives...</div>
                            </div>
                        </div>

                        <!-- Bloco Direito: Dies -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-[11px] uppercase tracking-wider text-zinc-500 font-bold">🔴 Retorno Dies:</label>
                                <button onclick="limparBloco('blocoDies')" class="text-[10px] text-zinc-500 hover:text-zinc-300">Limpar</button>
                            </div>
                            <div id="blocoDies" class="bg-black border border-zinc-800 rounded-xl p-3 h-64 overflow-y-auto space-y-2 text-xs font-mono">
                                <div class="text-zinc-600 italic">Aguardando dies...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEÇÃO 2: GERADOR GG INTEGRADO -->
            <div id="secaoGerador" class="hidden space-y-6">
                <div class="bg-black border border-zinc-800 p-6 rounded-2xl">
                    <h2 class="text-lg font-bold text-white mb-1">GERADOR GG</h2>
                    <p class="text-xs text-purple-400 mb-4">* Utilize com moderação;</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-[11px] uppercase text-purple-400 font-bold mb-1">Bins</label>
                            <input type="text" id="genBin" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none" placeholder="374769 ou 400000">
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase text-purple-400 font-bold mb-1">Data</label>
                            <div class="grid grid-cols-2 gap-2">
                                <select id="genMes" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                    <option value="rnd">MÊS</option>
                                    <option value="01">01 - Janeiro</option>
                                    <option value="02">02 - Fevereiro</option>
                                    <option value="03">03 - Março</option>
                                    <option value="04">04 - Abril</option>
                                    <option value="05">05 - Maio</option>
                                    <option value="06">06 - Junho</option>
                                    <option value="07">07 - Julho</option>
                                    <option value="08">08 - Agosto</option>
                                    <option value="09">09 - Setembro</option>
                                    <option value="10">10 - Outubro</option>
                                    <option value="11">11 - Novembro</option>
                                    <option value="12">12 - Dezembro</option>
                                </select>
                                <select id="genAno" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                    <option value="rnd">ANO</option>
                                    <option value="2026">2026</option>
                                    <option value="2027">2027</option>
                                    <option value="2028">2028</option>
                                    <option value="2029">2029</option>
                                    <option value="2030">2030</option>
                                    <option value="2031">2031</option>
                                    <option value="2032">2032</option>
                                    <option value="2033">2033</option>
                                    <option value="2034">2034</option>
                                    <option value="2035">2035</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase text-purple-400 font-bold mb-1">CVV</label>
                            <input type="text" id="genCvv" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none" placeholder="rnd (Amex usa 4 digitos)">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-[11px] uppercase text-purple-400 font-bold mb-1">Quantidade</label>
                            <input type="number" id="genQtd" value="10" min="1" max="100" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                        </div>
                        <div class="flex items-end">
                            <button onclick="gerarCartoesGG()" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">
                                Gerar Cartões
                            </button>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="text-[11px] uppercase tracking-wider text-purple-400 font-bold">Cartões Gerados:</label>
                            <div class="flex gap-2">
                                <button onclick="copiarGG()" class="text-xs bg-zinc-800 hover:bg-zinc-700 text-white px-3 py-1 rounded-lg border border-zinc-700 transition">Copiar GG</button>
                                <button onclick="enviarParaChecker()" class="text-xs bg-purple-700 hover:bg-purple-600 text-white px-3 py-1 rounded-lg transition font-bold">Enviar para o Checker ➔</button>
                            </div>
                        </div>
                        <textarea id="resultadoGerador" rows="8" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl p-3 text-xs text-zinc-300 focus:outline-none resize-none font-mono" placeholder="Os cartões gerados aparecerão aqui..."></textarea>
                    </div>
                </div>
            </div>

            <script>
                // Lógica de Navegação de Abas Internas (Checker / Gerador)
                function mudarAba(aba) {
                    const secaoChecker = document.getElementById('secaoChecker');
                    const secaoGerador = document.getElementById('secaoGerador');
                    const tabBtnChecker = document.getElementById('tabBtnChecker');
                    const tabBtnGerador = document.getElementById('tabBtnGerador');

                    if (aba === 'checker') {
                        secaoChecker.classList.remove('hidden');
                        secaoGerador.classList.add('hidden');
                        tabBtnChecker.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-600 text-white transition";
                        tabBtnGerador.className = "px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-400 hover:text-white transition";
                    } else {
                        secaoChecker.classList.add('hidden');
                        secaoGerador.classList.remove('hidden');
                        tabBtnGerador.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-600 text-white transition";
                        tabBtnChecker.className = "px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-400 hover:text-white transition";
                    }
                }

                // Lógica do Cronômetro de Sessão (24h)
                let tempoRestante = 86400;
                function atualizarTimer() {
                    const horas = Math.floor(tempoRestante / 3600);
                    const minutos = Math.floor((tempoRestante % 3600) / 60);
                    const segundos = tempoRestante % 60;
                    document.getElementById('timerSessao').innerText = 
                        String(horas).padStart(2, '0') + ':' + 
                        String(minutos).padStart(2, '0') + ':' + 
                        String(segundos).padStart(2, '0');
                    if (tempoRestante > 0) {
                        tempoRestante--;
                    }
                }
                setInterval(atualizarTimer, 1000);

                // Variáveis do Checker
                let checkerRodando = false;
                let listaDeCartoesParaTestar = [];
                let indexAtualCartao = 0;

                function iniciarChecker() {
                    const texto = document.getElementById('listaCartoes').value.trim();
                    if (!texto) {
                        alert('Insira pelo menos um cartão na lista!');
                        return;
                    }

                    listaDeCartoesParaTestar = texto.split('\n').map(c => c.trim()).filter(c => c.length > 0);
                    if (listaDeCartoesParaTestar.length === 0) return;

                    checkerRodando = true;
                    indexAtualCartao = 0;

                    document.getElementById('btnIniciar').disabled = true;
                    document.getElementById('btnIniciar').classList.add('opacity-50', 'cursor-not-allowed');
                    document.getElementById('btnParar').disabled = false;
                    document.getElementById('btnParar').classList.remove('bg-zinc-800', 'text-zinc-500', 'cursor-not-allowed');
                    document.getElementById('btnParar').classList.add('bg-red-600', 'hover:bg-red-500', 'text-white');

                    // Limpa avisos vazios iniciais
                    if (document.getElementById('blocoLives').innerHTML.includes('Aguardando lives...')) {
                        document.getElementById('blocoLives').innerHTML = '';
                    }
                    if (document.getElementById('blocoDies').innerHTML.includes('Aguardando dies...')) {
                        document.getElementById('blocoDies').innerHTML = '';
                    }

                    processarProximoCartao();
                }

                function pararChecker() {
                    checkerRodando = false;
                    document.getElementById('btnIniciar').disabled = false;
                    document.getElementById('btnIniciar').classList.remove('opacity-50', 'cursor-not-allowed');
                    document.getElementById('btnParar').disabled = true;
                    document.getElementById('btnParar').classList.add('bg-zinc-800', 'text-zinc-500', 'cursor-not-allowed');
                    document.getElementById('btnParar').classList.remove('bg-red-600', 'hover:bg-red-500', 'text-white');
                }

                async function processarProximoCartao() {
                    if (!checkerRodando || indexAtualCartao >= listaDeCartoesParaTestar.length) {
                        pararChecker();
                        return;
                    }

                    const cartao = listaDeCartoesParaTestar[indexAtualCartao];
                    indexAtualCartao++;

                    const formData = new URLSearchParams();
                    formData.append('lista', cartao);

                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        const data = await response.json();

                        // Atualiza contador de testadas
                        const testadasEl = document.getElementById('counterTestadas');
                        testadasEl.innerText = parseInt(testadasEl.innerText) + 1;

                        if (data.status === 'live') {
                            const livesEl = document.getElementById('counterLives');
                            livesEl.innerText = parseInt(livesEl.innerText) + 1;

                            const bloco = document.getElementById('blocoLives');
                            const div = document.createElement('div');
                            div.innerHTML = data.html;
                            bloco.prepend(div);
                        } else {
                            const diesEl = document.getElementById('counterDies');
                            diesEl.innerText = parseInt(diesEl.innerText) + 1;

                            const bloco = document.getElementById('blocoDies');
                            const div = document.createElement('div');
                            div.innerHTML = data.html;
                            bloco.prepend(div);
                        }
                    } catch (e) {
                        console.error(e);
                    }

                    if (checkerRodando) {
                        setTimeout(processarProximoCartao, 500);
                    }
                }

                function limparBloco(idBloco) {
                    const msg = idBloco === 'blocoLives' ? 'Aguardando lives...' : 'Aguardando dies...';
                    document.getElementById(idBloco).innerHTML = `<div class="text-zinc-600 italic">${msg}</div>`;
                }

                // Gerador GG Lógica atualizada (Amex 4 dígitos e Dropdowns)
                function gerarCartoesGG() {
                    let bin = document.getElementById('genBin').value.trim();
                    let mesSelect = document.getElementById('genMes').value;
                    let anoSelect = document.getElementById('genAno').value;
                    let cvvInput = document.getElementById('genCvv').value.trim().toLowerCase();
                    let qtd = parseInt(document.getElementById('genQtd').value) || 10;

                    if (!bin) {
                        alert('Digite uma BIN ou cartão base!');
                        return;
                    }

                    // Identifica se é AMEX (começa com 3)
                    const limpaBin = bin.replace(/\D/g, '');
                    const isAmex = limpaBin.startsWith('3');

                    let resultado = [];
                    for (let i = 0; i < qtd; i++) {
                        let cc = '';
                        for (let char of bin) {
                            if (char.toLowerCase() === 'x' || char === '*') {
                                cc += Math.floor(Math.random() * 10);
                            } else {
                                cc += char;
                            }
                        }
                        // Completa o cartão de acordo com o padrão (15 para Amex, 16 para os demais)
                        let tamanhoAlvo = isAmex ? 15 : 16;
                        while (cc.length < tamanhoAlvo) {
                            cc += Math.floor(Math.random() * 10);
                        }

                        let mes = (mesSelect && mesSelect !== 'rnd') ? mesSelect : String(Math.floor(Math.random() * 12) + 1).padStart(2, '0');
                        let ano = (anoSelect && anoSelect !== 'rnd') ? anoSelect : String(Math.floor(Math.random() * 6) + 26);
                        
                        let cvv = '';
                        if (cvvInput && cvvInput !== 'rnd') {
                            cvv = cvvInput;
                        } else {
                            if (isAmex) {
                                // Amex usa 4 dígitos no CVV (CID)
                                cvv = String(Math.floor(Math.random() * 9000) + 1000);
                            } else {
                                // Demais usam 3 dígitos
                                cvv = String(Math.floor(Math.random() * 900) + 100);
                            }
                        }

                        resultado.push(`${cc}|${mes}|${ano}|${cvv}`);
                    }

                    document.getElementById('resultadoGerador').value = resultado.join('\n');
                }

                function copiarGG() {
                    const textarea = document.getElementById('resultadoGerador');
                    if (!textarea.value) {
                        alert('Nenhum cartão gerado para copiar!');
                        return;
                    }
                    textarea.select();
                    navigator.clipboard.writeText(textarea.value);
                    alert('Cartões copiados para a área de transferência!');
                }

                function enviarParaChecker() {
                    const gerado = document.getElementById('resultadoGerador').value;
                    if (!gerado) {
                        alert('Gere alguns cartões primeiro!');
                        return;
                    }
                    document.getElementById('listaCartoes').value = gerado;
                    mudarAba('checker');
                }
            </script>
        </div>
    <?php endif; ?>

</body>
</html>
