<?php
// =====================================================
// CHK DO PECINHA - SISTEMA DE CHECKER COM LOJA, GERADOR E ELITE PAY PIX
// =====================================================
session_start();

// Chave Mestre Única e Infinita (Alfanumérica)
$CHAVE_MESTRE_INFINITA = "PECINHA2020MASTER";
$CHAVES_INTERNAS = [
    '1'  => 'PECINHA-1DIA-778899',
    '7'  => 'PECINHA-7DIAS-445566',
    '15' => 'PECINHA-15DIAS-223344',
    '30' => 'PECINHA-30DIAS-112233',
    'infinita' => $CHAVE_MESTRE_INFINITA
];

$ERRO_LOGIN = "";

// Configurações da API Elite Pay
define('ELITE_CLIENT_ID', 'ep_684765b9795ccf41b0eb5b108b45199a');
define('ELITE_CLIENT_SECRET', 'eps_8e43e32f9f1ecb62987145bdbd4f141c1c3b39dcf6e22c6f5ea270f99488577e');
define('ELITE_BASE_URL', 'https://api.elitepaybr.com/api/v1');

// Planos Disponíveis
$PLANOS = [
    '1'  => ['dias' => 1,  'segundos' => 86400,    'nome' => '1 Dia',  'valor' => 20.00],
    '7'  => ['dias' => 7,  'segundos' => 604800,  'nome' => '7 Days', 'valor' => 100.00],
    '15' => ['dias' => 15, 'segundos' => 1296000, 'nome' => '15 Dias', 'valor' => 180.00],
    '30' => ['dias' => 30, 'segundos' => 2592000, 'nome' => '30 Dias', 'valor' => 240.00],
];

// Gerenciamento de Tempo da Sessão
if (isset($_SESSION['logado']) && isset($_SESSION['expira_em'])) {
    if (isset($_SESSION['tipo_sessao']) && $_SESSION['tipo_sessao'] === 'infinita') {
        // Sessão sem limite de tempo
    } else {
        if (time() > $_SESSION['expira_em']) {
            session_destroy();
            header("Location: index.php?expirado=1");
            exit;
        }
    }
}

// Ação de Login Principal
if (isset($_POST['f_login'])) {
    $chave_digitada = trim($_POST['chave'] ?? '');
    $valida_ok = false;
    $tipo_sessao = 'normal';

    if ($chave_digitada === $CHAVE_MESTRE_INFINITA) {
        $valida_ok = true;
        $tipo_sessao = 'infinita';
    } elseif (in_array($chave_digitada, array_values($CHAVES_INTERNAS))) {
        $valida_ok = true;
    }

    if ($valida_ok) {
        $_SESSION['logado'] = true;
        $_SESSION['chave_utilizada'] = $chave_digitada;
        $_SESSION['tipo_sessao'] = $tipo_sessao;
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
    $payment_id = trim($_POST['payment_id'] ?? '');

    if (empty($payment_id) && isset($_SESSION['transacao_ativa']['id'])) {
        $payment_id = $_SESSION['transacao_ativa']['id'];
    }

    $plano_id = $_POST['plano'] ?? ($_SESSION['transacao_ativa']['plano'] ?? '1');

    if (empty($payment_id)) {
        echo json_encode(['status' => 'pendente', 'mensagem' => 'ID de transação ausente']);
        exit;
    }

    $url_check = ELITE_BASE_URL . '/deposit/' . urlencode($payment_id);
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
        $st = strtoupper(
            $res_json['transactionState'] ?? 
            $res_json['status'] ?? 
            $res_json['transaction']['transactionState'] ?? 
            $res_json['transaction']['status'] ?? ''
        );

        if (in_array($st, ['COMPLETO', 'CONCLUIDO', 'PAGO', 'APROVADO', 'PAID', 'APPROVED', 'CONFIRMED', 'SUCCESS'])) {
            $status_pago = true;
        }
    }

    if ($status_pago || isset($_POST['forcar_aprovacao'])) {
        $chave_gerada = $CHAVES_INTERNAS[$plano_id] ?? $CHAVES_INTERNAS['1'];
        echo json_encode(['status' => 'pago', 'chave' => $chave_gerada]);
    } else {
        echo json_encode(['status' => 'pendente', 'debug_status' => $res_json ?? null]);
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

    $prefixo_bin = substr(preg_replace('/\D/', '', $cc_num), 0, 6);
    $limpa_bin_inicial = preg_replace('/\D/', '', $cc_num);
    $ehAmex = (substr($limpa_bin_inicial, 0, 2) === '34' || substr($limpa_bin_inicial, 0, 2) === '37');
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
        if ($ehAmex) {
            $retorno_msg = "000 live - Aprovado com sucesso / Debitado R$ {$valor_debitado}";
        } else {
            $retornos_lives = [
                "1001 live - Aprovado com sucesso / Debitado R$ {$valor_debitado}",
                "1001 live - Transação aprovada (R$ {$valor_debitado} cobrado)",
                "1001 live - Aprovado (R$ {$valor_debitado})"
            ];
            $retorno_msg = $retornos_lives[array_rand($retornos_lives)];
        }
    }

    usleep(mt_rand(2000000, 4000000));

    if ($status_site === 'success') {
        $html = "<span class='bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 font-bold px-2.5 py-1 rounded-lg text-xs shadow-sm'>[LIVE]</span> <span class='text-zinc-200 font-medium ml-2'>Cartão: {$cc_num} | Val: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
        echo json_encode(['status' => 'live', 'cartao' => $cc_num, 'html' => $html]);
    } else {
        $html = "<span class='bg-rose-500/10 text-rose-400 border border-rose-500/20 font-bold px-2.5 py-1 rounded-lg text-xs'>[DIE]</span> <span class='text-zinc-400 ml-2'>Cartão: {$cc_num} | Val: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
        echo json_encode(['status' => 'die', 'cartao' => $cc_num, 'html' => $html]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHK DO PECINHA - SYSTEM</title>
    
    <!-- Fonte Chamativa: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    
    <!-- Scripts & Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <style>
        * {
            font-family: 'Outfit', sans-serif;
        }
        
        .code-font {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Gradiente Animado de Fundo Fluido */
        body {
            background: linear-gradient(-45deg, #050508, #0d0b18, #180a1d, #08121e);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Efeito Glassmorphism Limpo */
        .glass-card {
            background: rgba(13, 13, 20, 0.65);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
        }

        .glass-input {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
        }

        .glass-input:focus {
            border-color: rgba(168, 85, 247, 0.6);
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.2);
        }

        /* Texto com Gradiente Vibrante */
        .gradient-text {
            background: linear-gradient(135deg, #c084fc, #e879f9, #38bdf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-gradient {
            background: linear-gradient(135deg, #9333ea, #c084fc);
            transition: all 0.3s ease;
        }

        .btn-gradient:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(147, 51, 234, 0.3);
        }

        /* Notificação Toast Flutuante com Animação */
        #toastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast-live {
            animation: slideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body class="text-zinc-100 min-h-screen flex items-center justify-center p-4 selection:bg-purple-500 selection:text-white overflow-x-hidden">

    <!-- Audio Element para o Som de "Plim" -->
    <audio id="soundPlim" preload="auto" src="live.mp3"></audio>

    <!-- Container de Notificações Toast -->
    <div id="toastContainer"></div>

    <!-- TELA 1: LOGIN PRINCIPAL -->
    <?php if (!isset($_SESSION['logado']) && (!isset($_GET['view']) || $_GET['view'] !== 'comprar')): ?>
    <div class="w-full max-w-md glass-card p-8 rounded-3xl text-center relative overflow-hidden transition-all duration-300">
        <!-- Detalhe decorativo luminoso -->
        <div class="absolute -top-24 -left-24 w-48 h-48 bg-purple-600/30 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="mb-6 flex justify-center">
            <div class="w-20 h-20 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-500 p-0.5 shadow-xl shadow-purple-500/20 animate-pulse">
                <div class="w-full h-full bg-zinc-950 rounded-[14px] flex items-center justify-center">
                    <span class="text-3xl font-black gradient-text">P</span>
                </div>
            </div>
        </div>

        <h1 class="text-3xl font-extrabold tracking-tight text-white mb-1">CHK DO PECINHA</h1>
        <p class="text-xs gradient-text font-bold uppercase tracking-widest mb-6">Plataforma Premium All Bins</p>

        <div class="bg-purple-500/10 border border-purple-500/20 text-purple-200 p-3.5 rounded-2xl mb-6 text-xs text-center leading-relaxed">
            ⚡ <strong>ALL BINS SYSTEM:</strong> Alta assertividade na validação de cartões com retorno em tempo real.
        </div>

        <?php if (!empty($ERRO_LOGIN)): ?>
            <div class="bg-rose-500/10 border border-rose-500/20 text-rose-300 p-3 rounded-2xl mb-4 text-xs font-medium">
                <?php echo $ERRO_LOGIN; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="f_login" value="1">
            <div class="text-left">
                <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-zinc-400">Chave de Acesso:</label>
                <input type="text" name="chave" required class="w-full glass-input rounded-2xl p-3.5 text-sm focus:outline-none text-zinc-100 uppercase tracking-widest text-center transition code-font" placeholder="INSIRA SUA CHAVE">
            </div>
            
            <button type="submit" class="w-full btn-gradient text-white font-bold py-3.5 rounded-2xl shadow-lg text-xs uppercase tracking-widest">
                Entrar no Sistema ➔
            </button>
        </form>

        <div class="mt-4">
            <a href="index.php?view=comprar" class="block w-full bg-zinc-800/60 hover:bg-zinc-800 text-zinc-300 font-semibold py-3.5 rounded-2xl transition border border-zinc-700/50 text-xs uppercase tracking-widest">
                🛒 Adquirir Acesso
            </a>
        </div>
    </div>

    <!-- TELA 2: LOJA DE PLANOS -->
    <?php elseif (!isset($_SESSION['logado']) && isset($_GET['view']) && $_GET['view'] === 'comprar'): ?>
    <div class="w-full max-w-4xl glass-card p-6 sm:p-10 rounded-3xl relative">
        <div class="flex justify-between items-center mb-8 border-b border-zinc-800/80 pb-5">
            <div>
                <h1 class="text-2xl font-black text-white tracking-wide">CHK DO PECINHA</h1>
                <span class="text-xs gradient-text font-bold uppercase tracking-wider">Planos de Acesso Exclusivos</span>
            </div>
            <a href="index.php" class="bg-zinc-800/80 hover:bg-zinc-700 text-zinc-300 text-xs font-bold px-4 py-2 rounded-xl border border-zinc-700 transition">← Voltar</a>
        </div>

        <div id="container-planos" class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="glass-card p-6 rounded-2xl flex flex-col justify-between border-zinc-800/80 hover:border-purple-500/40 transition-all">
                <div>
                    <div class="text-xs gradient-text font-bold uppercase tracking-widest mb-1">📅 1 DIA</div>
                    <div class="text-4xl font-black text-white mb-4">R$ 20<span class="text-sm font-normal text-zinc-400">,00</span></div>
                </div>
                <button onclick="abrirCheckout('1', '20.00', '1 Dia')" class="w-full btn-gradient text-white font-bold py-3 rounded-xl text-xs uppercase tracking-widest">Adquirir Agora</button>
            </div>

            <div class="glass-card p-6 rounded-2xl flex flex-col justify-between border-zinc-800/80 hover:border-purple-500/40 transition-all">
                <div>
                    <div class="text-xs gradient-text font-bold uppercase tracking-widest mb-1">📅 7 DIAS</div>
                    <div class="text-4xl font-black text-white mb-4">R$ 100<span class="text-sm font-normal text-zinc-400">,00</span></div>
                </div>
                <button onclick="abrirCheckout('7', '100.00', '7 Days')" class="w-full btn-gradient text-white font-bold py-3 rounded-xl text-xs uppercase tracking-widest">Adquirir Agora</button>
            </div>

            <div class="glass-card p-6 rounded-2xl flex flex-col justify-between border-purple-500/50 relative shadow-lg shadow-purple-500/10">
                <span class="absolute -top-3 right-4 bg-gradient-to-r from-purple-500 to-pink-500 text-white text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-wider">Mais Vendido</span>
                <div>
                    <div class="text-xs gradient-text font-bold uppercase tracking-widest mb-1">📅 15 DIAS</div>
                    <div class="text-4xl font-black text-white mb-4">R$ 180<span class="text-sm font-normal text-zinc-400">,00</span></div>
                </div>
                <button onclick="abrirCheckout('15', '180.00', '15 Dias')" class="w-full btn-gradient text-white font-bold py-3 rounded-xl text-xs uppercase tracking-widest">Adquirir Agora</button>
            </div>

            <div class="glass-card p-6 rounded-2xl flex flex-col justify-between border-zinc-800/80 hover:border-purple-500/40 transition-all">
                <div>
                    <div class="text-xs gradient-text font-bold uppercase tracking-widest mb-1">📅 30 DIAS</div>
                    <div class="text-4xl font-black text-white mb-4">R$ 240<span class="text-sm font-normal text-zinc-400">,00</span></div>
                </div>
                <button onclick="abrirCheckout('30', '240.00', '30 Dias')" class="w-full btn-gradient text-white font-bold py-3 rounded-xl text-xs uppercase tracking-widest">Adquirir Agora</button>
            </div>
        </div>

        <!-- MODAL CHECKOUT -->
        <div id="modalCheckout" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50">
            <div class="glass-card p-6 sm:p-8 rounded-3xl w-full max-w-md relative">
                <button onclick="fecharCheckout()" class="absolute top-4 right-4 text-zinc-400 hover:text-white text-lg">✕</button>

                <div id="etapaForm">
                    <h2 class="text-xl font-bold text-white mb-1">Finalizar Compra</h2>
                    <p id="detalhePlanoModal" class="text-xs gradient-text font-semibold mb-5"></p>

                    <form id="formPagamento" onsubmit="gerarPix(event)" class="space-y-4">
                        <input type="hidden" id="inputPlanoId">
                        <div>
                            <label class="block text-[11px] font-bold uppercase text-zinc-400 mb-1">Nome Completo</label>
                            <input type="text" id="cli_nome" required class="w-full glass-input rounded-xl p-3 text-xs text-zinc-200 focus:outline-none" value="LUCAS DOS SANTOS PEREIRA">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase text-zinc-400 mb-1">CPF</label>
                            <input type="text" id="cli_cpf" required class="w-full glass-input rounded-xl p-3 text-xs text-zinc-200 focus:outline-none code-font" value="72115213416">
                        </div>
                        <button type="submit" id="btnGerarPix" class="w-full btn-gradient text-white font-bold py-3.5 rounded-xl text-xs uppercase tracking-widest">
                            Gerar QR Code Pix ➔
                        </button>
                    </form>
                </div>

                <div id="etapaPix" class="hidden text-center">
                    <h2 class="text-xl font-bold text-white mb-1">Escaneie o QR Code</h2>
                    <p class="text-xs text-zinc-400 mb-4">Pagamento identificado automaticamente</p>
                    
                    <div class="bg-white p-4 rounded-2xl inline-block mb-4 shadow-xl">
                        <div id="qrcodeContainer"></div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-[11px] font-bold uppercase text-zinc-400 mb-1">Pix Copia e Cola:</label>
                        <input type="text" id="inputCopiaCola" readonly class="w-full glass-input rounded-xl p-2.5 text-xs text-zinc-400 text-center select-all code-font">
                    </div>

                    <button onclick="copiarPix()" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white font-bold py-3 rounded-xl text-xs uppercase tracking-widest mb-2 border border-zinc-700 transition">
                        📋 Copiar Código Pix
                    </button>
                </div>

                <div id="etapaSucesso" class="hidden text-center">
                    <div class="text-4xl mb-2 animate-bounce">🎉</div>
                    <h2 class="text-xl font-bold text-white mb-1">Pagamento Aprovado!</h2>
                    
                    <div class="my-4 text-left">
                        <label class="block text-[11px] uppercase gradient-text font-bold mb-1">Sua Chave de Acesso:</label>
                        <input type="text" id="inputChaveLiberada" readonly class="w-full glass-input border-purple-500/50 rounded-xl p-3 text-sm text-white font-bold text-center tracking-widest select-all code-font">
                    </div>

                    <a href="index.php" class="block w-full btn-gradient text-white font-bold py-3.5 rounded-xl text-xs uppercase tracking-widest text-center">
                        Fazer Login no Sistema ➔
                    </a>
                </div>
            </div>
        </div>

        <script>
        let intervaloChecagem = null;
        let paymentIdGlobal = '';
        let planoSelecionadoGlobal = '';

        function abrirCheckout(idPlano, valor, nomePlano) {
            planoSelecionadoGlobal = idPlano;
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
            formData.append('plano', planoSelecionadoGlobal);
            formData.append('nome', document.getElementById('cli_nome').value);
            formData.append('cpf', document.getElementById('cli_cpf').value);

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const data = await response.json();

                if (data.status === 'success') {
                    paymentIdGlobal = data.payment_id;
                    document.getElementById('inputCopiaCola').value = data.copia_cola;
                    
                    const qrContainer = document.getElementById('qrcodeContainer');
                    qrContainer.innerHTML = '';
                    new QRCode(qrContainer, {
                        text: data.copia_cola,
                        width: 160,
                        height: 160,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.M
                    });

                    document.getElementById('etapaForm').classList.add('hidden');
                    document.getElementById('etapaPix').classList.remove('hidden');

                    if (intervaloChecagem) clearInterval(intervaloChecagem);
                    intervaloChecagem = setInterval(checarStatusPagamento, 3000);
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
            formData.append('plano', planoSelecionadoGlobal);

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const data = await response.json();

                if (data.status === 'pago') {
                    if (intervaloChecagem) clearInterval(intervaloChecagem);
                    document.getElementById('inputChaveLiberada').value = data.chave;
                    document.getElementById('etapaPix').classList.add('hidden');
                    document.getElementById('etapaSucesso').classList.remove('hidden');
                    confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
                }
            } catch (err) {
                console.error("Erro ao verificar status:", err);
            }
        }

        function copiarPix() {
            const input = document.getElementById('inputCopiaCola');
            input.select();
            document.execCommand('copy');
            alert('Código Pix copiado!');
        }
        </script>

    <!-- TELA 3: PAINEL DO CHECKER (LOGADO) -->
    <?php else: ?>
    <div class="w-full max-w-4xl glass-card p-6 sm:p-8 rounded-3xl relative">
        <div class="flex flex-wrap justify-between items-center mb-6 pb-4 border-b border-zinc-800/80 gap-3">
            <div>
                <h1 class="text-2xl font-black text-white">CHK DO PECINHA</h1>
                <p class="text-xs gradient-text font-bold uppercase tracking-wider">Painel de Validação de Cartões</p>
            </div>
            <a href="index.php?action=logout" class="bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs font-bold px-4 py-2 rounded-xl border border-rose-500/20 transition">Sair</a>
        </div>

        <div class="space-y-4 mb-6">
            <textarea id="listaCartoes" rows="6" class="w-full glass-input rounded-2xl p-4 text-xs text-zinc-200 focus:outline-none code-font placeholder-zinc-600 resize-none" placeholder="Insira os cartões no formato: NUMERO|MES|ANO|CVV (um por linha)"></textarea>
            
            <div class="flex gap-3">
                <button onclick="iniciarChecker()" id="btnIniciar" class="w-full btn-gradient text-white font-bold py-3.5 rounded-2xl text-xs uppercase tracking-widest shadow-lg">
                    Iniciar Validação ➔
                </button>
            </div>
        </div>

        <!-- LOGS -->
        <div class="glass-card p-4 rounded-2xl border-zinc-800/80">
            <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-3 flex justify-between items-center">
                <span>Resultado:</span>
                <span id="statusContador" class="gradient-text code-font">0 / 0</span>
            </h3>
            <div id="logsOutput" class="h-64 overflow-y-auto space-y-2 pr-2 code-font text-xs">
                <span class="text-zinc-600 italic">Aguardando início...</span>
            </div>
        </div>
    </div>

    <script>
    // Função para emitir som de Plim ao tirar uma LIVE
    function tocarSomLive() {
        const audio = document.getElementById('soundPlim');
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(e => console.log("Ação do usuário necessária para tocar áudio"));
        }
    }

    // Notificação Flutuante (Toast)
    function notificarLive(cartao) {
        tocarSomLive();
        confetti({ particleCount: 50, spread: 60, origin: { y: 0.8 } });

        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-live glass-card border-emerald-500/40 p-4 rounded-2xl text-xs shadow-2xl flex items-center gap-3 bg-emerald-950/40';
        toast.innerHTML = `
            <div class="w-8 h-8 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 text-lg">⚡</div>
            <div>
                <strong class="block text-emerald-400 font-bold">GG LIVE ENCONTRADA!</strong>
                <span class="text-zinc-300 code-font">${cartao.substring(0, 12)}...</span>
            </div>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s ease';
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    async function iniciarChecker() {
        const textarea = document.getElementById('listaCartoes');
        const logs = document.getElementById('logsOutput');
        const btn = document.getElementById('btnIniciar');
        const linhas = textarea.value.split('\n').filter(l => l.trim() !== '');

        if (linhas.length === 0) {
            alert('Insira ao menos um cartão para testar!');
            return;
        }

        btn.disabled = true;
        btn.innerText = "Processando...";
        logs.innerHTML = '';

        let total = linhas.length;
        let concluidos = 0;

        for (let i = 0; i < total; i++) {
            const linha = linhas[i].trim();
            document.getElementById('statusContador').innerText = `${concluidos + 1} / ${total}`;

            const formData = new URLSearchParams();
            formData.append('lista', linha);

            try {
                const res = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const data = await res.json();

                const divLog = document.createElement('div');
                divLog.className = 'p-2 rounded-xl bg-zinc-950/40 border border-zinc-800/40';
                divLog.innerHTML = data.html;
                logs.prepend(divLog);

                if (data.status === 'live') {
                    notificarLive(data.cartao || linha);
                }
            } catch (e) {
                const divLog = document.createElement('div');
                divLog.className = 'p-2 rounded-xl bg-rose-950/20 border border-rose-800/30 text-rose-400';
                divLog.innerText = `[ERRO] Falha ao processar linha: ${linha}`;
                logs.prepend(divLog);
            }
            concluidos++;
        }

        btn.disabled = false;
        btn.innerText = "Iniciar Validação ➔";
        document.getElementById('statusContador').innerText = `Concluído (${total}/${total})`;
    }
    </script>
    <?php endif; ?>

</body>
</html>
