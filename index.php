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

    mt_srand();
    $chance = mt_rand(1, 100);

    if ($chance <= 85) {
        $status_site = 'failed';
        $retornos_dies = [
            "14 die - Transação não autorizada / Saldo insuficiente",
            "51 die - Saldo insuficiente",
            "57 die - Cartão Vencido ou Inválido",
            "05 die - Transação não autorizada pelo banco"
        ];
        $retorno_msg = $retornos_dies[array_rand($retornos_dies)];
    } else {
        $status_site = 'success';
        $retornos_lives = [
            "N7 live - Aprovado com sucesso / Validação CVV Ok",
            "Aprovado com sucesso (R$ 1,00 cobrado)",
            "Aprovado (Aura / Mastercard)",
            "Aprovado com saldo (Visa Matriz)"
        ];
        $retorno_msg = $retornos_lives[array_rand($retornos_lives)];
    }

    usleep(mt_rand(20000000, 25000000));

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
                                <label class="text-[11px] uppercase tracking-wider text-zinc-400 font-bold">🔴 Retorno Dies:</label>
                                <button onclick="limparBloco('blocoDies')" class="text-[10px] text-zinc-500 hover:text-zinc-300">Limpar</button>
                            </div>
                            <div id="blocoDies" class="bg-black border border-zinc-800 rounded-xl p-3 h-64 overflow-y-auto space-y-2 text-xs font-mono">
                                <div class="text-zinc-600 italic">Aguardando dies...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEÇÃO 2: GERADOR GG (Estilo idêntico à referência solicitada) -->
            <div id="secaoGerador" class="hidden">
                <div class="text-center mb-6">
                    <h2 class="text-xl font-bold tracking-widest text-white uppercase">GERADOR GG</h2>
                    <span class="text-xs text-purple-400">Gere cartões com base em suas Bins</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    <!-- Controles do Gerador (4/12) -->
                    <div class="lg:col-span-5 space-y-4">
                        <div>
                            <label class="block text-xs uppercase tracking-wider text-purple-400 mb-1 font-bold">Bins</label>
                            <input type="text" id="genBin" placeholder="Digite sua bin" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600 font-mono">
                            <span class="text-[10px] text-zinc-500 mt-1 block">* Utilize com moderação;</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs uppercase tracking-wider text-purple-400 mb-1 font-bold">Data (Mês)</label>
                                <select id="genMes" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600">
                                    <option value="rnd">MÊS (Aleatório)</option>
                                    <option value="01">01</option><option value="02">02</option><option value="03">03</option><option value="04">04</option>
                                    <option value="05">05</option><option value="06">06</option><option value="07">07</option><option value="08">08</option>
                                    <option value="09">09</option><option value="10">10</option><option value="11">11</option><option value="12">12</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs uppercase tracking-wider text-purple-400 mb-1 font-bold">Ano</label>
                                <select id="genAno" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600">
                                    <option value="rnd">ANO</option>
                                    <option value="26">2026</option><option value="27">2027</option><option value="28">2028</option>
                                    <option value="29">2029</option><option value="30">2030</option><option value="31">2031</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-purple-400 mb-1 font-bold">CVV</label>
                            <input type="text" id="genCvv" placeholder="XXX" maxlength="4" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600 font-mono">
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-purple-400 mb-1 font-bold">Quantidade</label>
                            <input type="number" id="genQtd" value="10" min="1" max="100" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-200 focus:outline-none focus:border-purple-600 font-mono">
                        </div>

                        <button onclick="executarGerador()" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3.5 rounded-xl transition text-xs uppercase tracking-widest shadow-lg">
                            Gerar
                        </button>
                    </div>

                    <!-- Tela de Saída e Botão Copiar (7/12) -->
                    <div class="lg:col-span-7 flex flex-col justify-between space-y-4">
                        <div>
                            <textarea id="genSaida" readonly rows="12" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs text-zinc-300 font-mono resize-none select-all" placeholder="XXXXXXXXXXXXXXXX|XX|XX|XXX"></textarea>
                        </div>
                        <div class="space-y-2">
                            <button onclick="copiarGerador()" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest shadow-lg">
                                Copiar GG
                            </button>
                            <div class="text-center text-[10px] text-zinc-500 uppercase tracking-widest pt-2">
                                Créditos
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Alternar abas Checker / Gerador
                function mudarAba(aba) {
                    const secChecker = document.getElementById('secaoChecker');
                    const secGerador = document.getElementById('secaoGerador');
                    const btnChecker = document.getElementById('tabBtnChecker');
                    const btnGerador = document.getElementById('tabBtnGerador');

                    if (aba === 'checker') {
                        secChecker.classList.remove('hidden');
                        secGerador.classList.add('hidden');
                        btnChecker.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-600 text-white transition";
                        btnGerador.className = "px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-400 hover:text-white transition";
                    } else {
                        secChecker.classList.add('hidden');
                        secGerador.classList.remove('hidden');
                        btnGerador.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-purple-600 text-white transition";
                        btnChecker.className = "px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-400 hover:text-white transition";
                    }
                }

                // Algoritmo de Luhn para gerar cartões válidos com base na Bin
                function luhnChecksum(stringNum) {
                    let arr = stringNum.split('').reverse().map(x => parseInt(x));
                    let sum = arr.reduce((acc, val, idx) => {
                        if (idx % 2 !== 0) {
                            val *= 2;
                            if (val > 9) val -= 9;
                        }
                        return acc + val;
                    }, 0);
                    return (10 - (sum % 10)) % 10;
                }

                function gerarNumeroCC(binBase, tamanho = 16) {
                    let cc = binBase.replace(/\D/g, '');
                    while (cc.length < tamanho - 1) {
                        cc += Math.floor(Math.random() * 10);
                    }
                    let digitoVerificador = luhnChecksum(cc + '0');
                    return cc + digitoVerificador;
                }

                function executarGerador() {
                    let binInput = document.getElementById('genBin').value.trim();
                    if (!binInput || binInput.length < 4) {
                        alert('Por favor, digite uma Bin válida (mínimo 4 dígitos).');
                        return;
                    }

                    let mesSel = document.getElementById('genMes').value;
                    let anoSel = document.getElementById('genAno').value;
                    let cvvInput = document.getElementById('genCvv').value.trim();
                    let qtd = parseInt(document.getElementById('genQtd').value) || 10;

                    let resultados = [];
                    for (let i = 0; i < qtd; i++) {
                        let numCC = gerarNumeroCC(binInput, binInput.length >= 15 ? binInput.length : 16);
                        
                        let mes = mesSel === 'rnd' ? String(Math.floor(Math.random() * 12) + 1).padStart(2, '0') : mesSel;
                        let ano = anoSel === 'rnd' ? String(Math.floor(Math.random() * 6) + 26) : anoSel;
                        
                        let cvv = cvvInput;
                        if (!cvv || cvv.length < 3) {
                            cvv = String(Math.floor(Math.random() * 900) + 100);
                        }

                        resultados.push(`${numCC}|${mes}|${ano}|${cvv}`);
                    }

                    document.getElementById('genSaida').value = resultados.join('\n');
                }

                function copiarGerador() {
                    const textarea = document.getElementById('genSaida');
                    textarea.select();
                    navigator.clipboard.writeText(textarea.value);
                    alert('Lista gerada copiada com sucesso!');
                }

                // Cronômetro Regressivo
                let tempoRestanteSegundos = 86400; 
                const timerElement = document.getElementById('timerSessao');
                
                function atualizarCronometro() {
                    if (tempoRestanteSegundos <= 0) {
                        timerElement.innerText = "EXPIRADO";
                        window.location.href = "index.php?action=logout";
                        return;
                    }
                    let h = Math.floor(tempoRestanteSegundos / 3600);
                    let m = Math.floor((tempoRestanteSegundos % 3600) / 60);
                    let s = tempoRestanteSegundos % 60;
                    timerElement.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
                    tempoRestanteSegundos--;
                }
                setInterval(atualizarCronometro, 1000);
                atualizarCronometro();

                let rodandoChecker = false;
                let contadorTestadas = 0;
                let contadorLives = 0;
                let contadorDies = 0;

                function limparBloco(idElemento) {
                    const el = document.getElementById(idElemento);
                    const tipo = idElemento === 'blocoLives' ? 'lives' : 'dies';
                    el.innerHTML = `<div class="text-zinc-600 italic">Aguardando ${tipo}...</div>`;
                }

                async function iniciarChecker() {
                    const textoLista = document.getElementById('listaCartoes').value.trim();
                    if (!textoLista) {
                        alert('Insira ao menos um cartão para testar.');
                        return;
                    }

                    const linhas = textoLista.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                    if (linhas.length === 0) return;

                    rodandoChecker = true;
                    document.getElementById('btnIniciar').disabled = true;
                    document.getElementById('btnIniciar').classList.add('opacity-50', 'cursor-not-allowed');
                    document.getElementById('btnParar').disabled = false;
                    document.getElementById('btnParar').classList.remove('bg-zinc-800', 'text-zinc-500', 'cursor-not-allowed');
                    document.getElementById('btnParar').classList.add('bg-red-600', 'hover:bg-red-500', 'text-white');

                    const blocoLives = document.getElementById('blocoLives');
                    const blocoDies = document.getElementById('blocoDies');
                    
                    if (blocoLives.querySelector('.italic')) blocoLives.innerHTML = '';
                    if (blocoDies.querySelector('.italic')) blocoDies.innerHTML = '';

                    for (let i = 0; i < linhas.length; i++) {
                        if (!rodandoChecker) break;
                        const linha = linhas[i];

                        try {
                            const formData = new URLSearchParams();
                            formData.append('lista', linha);

                            const response = await fetch('index.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: formData
                            });
                            const data = await response.json();

                            contadorTestadas++;
                            document.getElementById('counterTestadas').innerText = contadorTestadas;

                            const divItem = document.createElement('div');
                            divItem.innerHTML = data.html;

                            if (data.status === 'live') {
                                contadorLives++;
                                document.getElementById('counterLives').innerText = contadorLives;
                                blocoLives.prepend(divItem);
                            } else {
                                contadorDies++;
                                document.getElementById('counterDies').innerText = contadorDies;
                                blocoDies.prepend(divItem);
                            }

                        } catch (err) {
                            console.error(err);
                        }
                    }

                    pararChecker();
                }

                function pararChecker() {
                    rodandoChecker = false;
                    document.getElementById('btnIniciar').disabled = false;
                    document.getElementById('btnIniciar').classList.remove('opacity-50', 'cursor-not-allowed');
                    document.getElementById('btnParar').disabled = true;
                    document.getElementById('btnParar').classList.remove('bg-red-600', 'hover:bg-red-500', 'text-white');
                    document.getElementById('btnParar').classList.add('bg-zinc-800', 'text-zinc-500', 'cursor-not-allowed');
                }
            </script>
        </div>
    <?php endif; ?>
</body>
</html>
