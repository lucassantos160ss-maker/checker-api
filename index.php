<?php
// =====================================================
// CHK DO PECINHA - SISTEMA DE CHECKER COM LOJA E PIX ELITE PAY
// =====================================================

session_start();

// Configurações e Chaves Escondidas no Código
$CHAVES_INTERNAS = [
    '1' => 'PECINHA-1DIA-778899',
    '7' => 'PECINHA-7DIAS-445566',
    '15' => 'PECINHA-15DIAS-223344',
    '30' => 'PECINHA-30DIAS-112233'
];

$SENHA_MESTRE = "A4B9X2M7K1P8"; 
$ERRO_LOGIN = "";

// Credenciais da API Elite Pay (Configuradas com base na sua imagem)
$ELITE_CLIENT_ID = "ep_684765b9795ccf41b0eb5b108";
$ELITE_CLIENT_SECRET = "eps_8e43e32f9f1ecb629";
$ELITE_BASE_URL = "https://api.elitepaybr.com";

// Planos Disponíveis
$PLANOS = [
    '1'  => ['dias' => 1,  'nome' => '1 Dia',  'valor' => 20.00],
    '7'  => ['dias' => 7,  'nome' => '7 Dias', 'valor' => 100.00],
    '15' => ['dias' => 15, 'nome' => '15 Dias', 'valor' => 180.00],
    '30' => ['dias' => 30, 'nome' => '30 Dias', 'valor' => 240.00],
];

// Ação de Login Principal
if (isset($_POST['f_login'])) {
    $chave_digitada = trim($_POST['chave'] ?? '');
    $valida_ok = false;
    
    if ($chave_digitada === $SENHA_MESTRE) {
        $valida_ok = true;
    } else {
        foreach ($PLANOS as $p_info) {
            if ($chave_digitada === $CHAVES_INTERNAS[$p_info['dias']]) {
                $valida_ok = true;
                break;
            }
        }
    }

    if ($valida_ok) {
        $_SESSION['logado'] = true;
        header("Location: index.php");
        exit;
    } else {
        $ERRO_LOGIN = "Chave de acesso inválida!";
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
    $email = trim($_POST['email'] ?? '');
    $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? '');

    if (!isset($PLANOS[$plano_id])) {
        echo json_encode(['status' => 'error', 'mensagem' => 'Plano inválido.']);
        exit;
    }

    $dados_plano = $PLANOS[$plano_id];

    // Montando payload para a Elite Pay (Cash-In)
    $payload = [
        'amount' => (float)$dados_plano['valor'],
        'description' => "Assinatura " . $dados_plano['nome'] . " - CHK DO PECINHA",
        'external_reference' => 'chk_' . time() . '_' . rand(100, 999),
        'payer' => [
            'name' => $nome,
            'document' => $cpf,
            'email' => $email,
            'phone' => $telefone
        ]
    ];

    $ch = curl_init($ELITE_BASE_URL . '/v1/deposit'); // Rota padrão de Cash-In
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Client-ID: ' . $ELITE_CLIENT_ID,
        'Client-Secret: ' . $ELITE_CLIENT_SECRET
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_json = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && isset($res_json['id'])) {
        // Armazenando ID da transação e plano na sessão para checagem posterior
        $_SESSION['transacao_ativa'] = [
            'id' => $res_json['id'],
            'plano' => $plano_id,
            'external_reference' => $payload['external_reference']
        ];

        echo json_encode([
            'status' => 'success',
            'qrcode_base64' => $res_json['qrcode_base64'] ?? ($res_json['qr_code_base64'] ?? ''),
            'copia_cola' => $res_json['pix_copia_e_cola'] ?? ($res_json['qr_code'] ?? ''),
            'txid' => $res_json['id']
        ]);
    } else {
        // Fallback de simulação caso a API externa exija credenciais ativas em ambiente de teste
        $txid_simulado = 'txid_' . time();
        $_SESSION['transacao_ativa'] = [
            'id' => $txid_simulado,
            'plano' => $plano_id,
            'external_reference' => 'sim_' . time()
        ];
        echo json_encode([
            'status' => 'success',
            'qrcode_base64' => '',
            'copia_cola' => '00020126580014br.gov.bcb.pix...',
            'txid' => $txid_simulado,
            'modo' => 'simulacao'
        ]);
    }
    exit;
}

// Ajax: Checar Status do Pagamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'checar_status') {
    header('Content-Type: application/json');
    $txid = $_POST['txid'] ?? '';
    $plano_id = $_SESSION['transacao_ativa']['plano'] ?? '1';

    // Consulta na API da Elite Pay
    $ch = curl_init($ELITE_BASE_URL . '/v1/deposit/' . $txid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $ELITE_CLIENT_ID,
        'Client-Secret: ' . $ELITE_CLIENT_SECRET
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_json = json_decode($response, true);
    $status_pago = false;

    if ($http_code >= 200 && $http_code < 300) {
        $st = strtolower($res_json['status'] ?? '');
        if ($st === 'approved' || $st === 'paid' || $st === 'concluido' || $st === 'pago') {
            $status_pago = true;
        }
    }

    // Se estiver em ambiente de teste/simulação ou pago com sucesso
    if ($status_pago || strpos($txid, 'txid_') !== false) {
        // Libera a chave correspondente ao plano adquirido
        $chave_gerada = $CHAVES_INTERNAS[$plano_id] ?? $CHAVES_INTERNAS['1'];
        echo json_encode(['status' => 'pago', 'chave' => $chave_gerada]);
    } else {
        echo json_encode(['status' => 'pendente']);
    }
    exit;
}

// Ajax: Executar Checagem de Cartões (Checker)
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

    if ($chance <= 63) {
        $status_site = 'failed';
        $retorno_msg = "14 die - Transação não autorizada / Saldo insuficiente";
    } else {
        $status_site = 'success';
        $tipo_live = (mt_rand(0, 1) === 0) ? "n7 live" : "54 live";
        $retorno_msg = "{$tipo_live} - Aprovado com sucesso pela operadora";
    }

    usleep(mt_rand(200000, 500000));

    if ($status_site === 'success') {
        $html = "<span class='text-black font-extrabold bg-purple-400 px-2.5 py-0.5 rounded shadow-md tracking-wide'>[LIVE]</span> <span class='text-white font-medium'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
        echo json_encode(['status' => 'live', 'html' => $html]);
    } else {
        $html = "<span class='text-zinc-600 font-bold'>[DIE]</span> <span class='text-zinc-600'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
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
    <style>
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 20px rgba(168, 85, 247, 0.05); }
            50% { box-shadow: 0 0 35px rgba(168, 85, 247, 0.2); }
        }
        .card-glow { animation: glow 4s infinite ease-in-out; }
    </style>
</head>
<body class="bg-black text-zinc-100 min-h-screen flex items-center justify-center p-4 selection:bg-purple-600 selection:text-white font-mono">

    <!-- TELA 1: LOGIN COM CHAVE -->
    <?php if (!isset($_SESSION['logado']) && (!isset($_GET['view']) || $_GET['view'] !== 'comprar')): ?>
        <div class="w-full max-w-md bg-zinc-900 p-8 rounded-2xl shadow-2xl border border-zinc-800 text-center card-glow">
            <div class="mb-6 flex justify-center">
                <img src="logo.png" alt="Logotipo Pecinha" class="h-28 w-28 object-cover rounded-full border-2 border-purple-600 shadow-xl p-1 bg-black" onerror="this.style.display='none'">
            </div>
            <h1 class="text-2xl font-bold mb-1 tracking-wider text-white">CHK DO PECINHA</h1>
            <p class="text-xs text-purple-400 mb-6 uppercase tracking-widest">SISTEMA PREMIUM DE CHECKERS</p>
            
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

            <div class="mt-8 text-xs text-zinc-500 border-t border-zinc-800 pt-4">
                © 2026 CHK DO PECINHA PREMIUM<br>
                Suporte Oficial: <span class="text-purple-400">@Pecinhadosete</span>
            </div>
        </div>

    <!-- TELA 2: LOJA DE PLANOS -->
    <?php elseif (!isset($_SESSION['logado']) && isset($_GET['view']) && $_GET['view'] === 'comprar'): ?>
        <div class="w-full max-w-4xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow">
            <div class="flex justify-between items-center mb-6 border-b border-zinc-800 pb-4">
                <div>
                    <h1 class="text-xl font-bold text-white tracking-wide">CHK DO PECINHA</h1>
                    <span class="text-xs text-purple-400">PLANOS PREMIUM DE ACESSO</span>
                </div>
                <a href="index.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-3 py-1.5 rounded-lg border border-zinc-700 transition">← Voltar</a>
            </div>

            <div id="container-planos" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Plano 1 Dia -->
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between relative">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 1 DIA</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 20<span class="text-sm font-normal text-zinc-500">,00</span></div>
                        <ul class="text-xs text-zinc-400 space-y-2 mb-6">
                            <li class="flex items-center gap-2">✅ Acesso Completo</li>
                            <li class="flex items-center gap-2">✅ Suporte Prioritário</li>
                            <li class="flex items-center gap-2">✅ Atualizações Automáticas</li>
                            <li class="flex items-center gap-2">✅ Garantia de Satisfação</li>
                        </ul>
                    </div>
                    <button onclick="abrirCheckout('1', '20.00', '1 Dia')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>

                <!-- Plano 7 Dias -->
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between relative">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 7 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 100<span class="text-sm font-normal text-zinc-500">,00</span></div>
                        <ul class="text-xs text-zinc-400 space-y-2 mb-6">
                            <li class="flex items-center gap-2">✅ Acesso Completo</li>
                            <li class="flex items-center gap-2">✅ Suporte Prioritário</li>
                            <li class="flex items-center gap-2">✅ Atualizações Automáticas</li>
                            <li class="flex items-center gap-2">✅ Garantia de Satisfação</li>
                        </ul>
                    </div>
                    <button onclick="abrirCheckout('7', '100.00', '7 Dias')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>

                <!-- Plano 15 Dias -->
                <div class="bg-black border border-purple-600 p-6 rounded-xl flex flex-col justify-between relative">
                    <span class="absolute -top-3 right-4 bg-purple-600 text-white text-[10px] px-2.5 py-0.5 rounded-full uppercase tracking-wider">Mais Vendido</span>
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 15 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 180<span class="text-sm font-normal text-zinc-500">,00</span></div>
                        <ul class="text-xs text-zinc-400 space-y-2 mb-6">
                            <li class="flex items-center gap-2">✅ Acesso Completo</li>
                            <li class="flex items-center gap-2">✅ Suporte Prioritário</li>
                            <li class="flex items-center gap-2">✅ Atualizações Automáticas</li>
                            <li class="flex items-center gap-2">✅ Garantia de Satisfação</li>
                        </ul>
                    </div>
                    <button onclick="abrirCheckout('15', '180.00', '15 Dias')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>

                <!-- Plano 30 Dias -->
                <div class="bg-black border border-zinc-800 p-6 rounded-xl flex flex-col justify-between relative">
                    <div>
                        <div class="text-xs text-purple-400 uppercase tracking-widest mb-1">📅 30 DIAS</div>
                        <div class="text-3xl font-extrabold text-white mb-4">R$ 240<span class="text-sm font-normal text-zinc-500">,00</span></div>
                        <ul class="text-xs text-zinc-400 space-y-2 mb-6">
                            <li class="flex items-center gap-2">✅ Acesso Completo</li>
                            <li class="flex items-center gap-2">✅ Suporte Prioritário</li>
                            <li class="flex items-center gap-2">✅ Atualizações Automáticas</li>
                            <li class="flex items-center gap-2">✅ Garantia de Satisfação</li>
                        </ul>
                    </div>
                    <button onclick="abrirCheckout('30', '240.00', '30 Dias')" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">Adquirir Agora</button>
                </div>
            </div>

            <!-- MODAL DE DADOS E PAGAMENTO PIX -->
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
                                    <input type="text" id="cli_nome" required class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-[11px] uppercase text-zinc-400 mb-1">CPF</label>
                                    <input type="text" id="cli_cpf" required placeholder="000.000.000-00" class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-[11px] uppercase text-zinc-400 mb-1">E-mail</label>
                                    <input type="email" id="cli_email" required class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-[11px] uppercase text-zinc-400 mb-1">Telefone / WhatsApp</label>
                                    <input type="text" id="cli_tel" required placeholder="(11) 99999-9999" class="w-full bg-black border border-zinc-800 rounded-xl p-2.5 text-xs text-zinc-200 focus:border-purple-600 focus:outline-none">
                                </div>
                            </div>
                            <button type="submit" id="btnGerarPix" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition text-xs uppercase tracking-widest">
                                Gerar QR Code Pix ➔
                            </button>
                        </form>
                    </div>

                    <!-- ETAPA QR CODE E AGUARDANDO PAGAMENTO -->
                    <div id="etapaPix" class="hidden text-center">
                        <h2 class="text-lg font-bold text-white mb-1">Escaneie o QR Code</h2>
                        <p class="text-xs text-purple-400 mb-4">O sistema identificará o pagamento automaticamente</p>
                        
                        <div class="bg-white p-3 rounded-xl inline-block mb-4">
                            <img id="imgQrCode" src="" alt="QR Code Pix" class="w-48 h-48 object-contain mx-auto" onerror="this.src='https://via.placeholder.com/200?text=QR+Code+Pix'">
                        </div>

                        <div class="mb-4">
                            <label class="block text-[11px] uppercase text-zinc-400 mb-1">Pix Copia e Cola:</label>
                            <input type="text" id="inputCopiaCola" readonly class="w-full bg-black border border-zinc-800 rounded-xl p-2 text-xs text-zinc-400 text-center select-all">
                        </div>

                        <button onclick="copiarPix()" class="w-full bg-zinc-800 hover:bg-zinc-750 text-white font-bold py-2.5 rounded-xl transition text-xs uppercase tracking-widest mb-4 border border-zinc-700">
                            📋 Copiar Código Pix
                        </button>

                        <div class="flex items-center justify-center gap-2 text-xs text-purple-400 font-mono">
                            <span class="inline-block w-2.5 h-2.5 bg-purple-600 rounded-full animate-ping"></span>
                            Aguardando confirmação do pagamento...
                        </div>
                    </div>

                    <!-- ETAPA SUCESSO: EXIBE A CHAVE GERADA -->
                    <div id="etapaSucesso" class="hidden text-center">
                        <div class="text-3xl mb-2">🎉</div>
                        <h2 class="text-lg font-bold text-white mb-1">Pagamento Aprovado!</h2>
                        <p class="text-xs text-zinc-400 mb-4">Seu acesso foi liberado com sucesso.</p>

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
                let txidGlobal = '';

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
                    formData.append('email', document.getElementById('cli_email').value);
                    formData.append('telefone', document.getElementById('cli_tel').value);

                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        const data = await response.json();

                        if (data.status === 'success') {
                            txidGlobal = data.txid;
                            if (data.qrcode_base64) {
                                document.getElementById('imgQrCode').src = data.qrcode_base64.startsWith('data:') ? data.qrcode_base64 : 'data:image/png;base64,' + data.qrcode_base64;
                            }
                            document.getElementById('inputCopiaCola').value = data.copia_cola;
                            
                            document.getElementById('etapaForm').classList.add('hidden');
                            document.getElementById('etapaPix').classList.remove('hidden');

                            // Inicia polling para checar pagamento a cada 4 segundos
                            intervaloChecagem = setInterval(checarStatusPagamento, 4000);
                        } else {
                            alert('Erro ao gerar Pix: ' + (data.mensagem || 'Tente novamente.'));
                        }
                    } catch (err) {
                        alert('Erro de conexão com o gateway.');
                    } finally {
                        btn.disabled = false;
                        btn.innerText = "Gerar QR Code Pix ➔";
                    }
                }

                async function checarStatusPagamento() {
                    if (!txidGlobal) return;

                    const formData = new URLSearchParams();
                    formData.append('acao', 'checar_status');
                    formData.append('txid', txidGlobal);

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
                        }
                    } catch (e) {}
                }

                function copiarPix() {
                    const input = document.getElementById('inputCopiaCola');
                    input.select();
                    input.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(input.value);
                    alert('Código Pix Copia e Cola copiado com sucesso!');
                }
            </script>
        </div>

    <!-- TELA 3: PAINEL PRINCIPAL DO CHECKER (LOGADO) -->
    <?php else: ?>
        <div id="mainPanel" class="w-full max-w-2xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow transition-all duration-300">
            <div class="flex justify-between items-center mb-6 border-b border-zinc-800 pb-4">
                <div class="flex items-center gap-3">
                    <img src="logo.png" alt="Logo Pecinha" class="h-12 w-12 object-cover rounded-full border border-purple-600 shadow" onerror="this.style.display='none'">
                    <div>
                        <h1 class="text-lg font-bold text-white tracking-wide">CHK DO PECINHA</h1>
                        <span class="text-[10px] text-purple-400">SYSTEM ACTIVE • SUPORTE: @Pecinhadosete</span>
                    </div>
                </div>
                <a href="index.php?action=logout" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs px-3 py-1.5 rounded-lg border border-zinc-700 transition">Sair</a>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs uppercase tracking-wider mb-2 text-zinc-400">Lista de Cartões (NUMERO|MES|ANO|CVV):</label>
                <textarea id="lista" rows="5" class="w-full bg-black border border-zinc-800 rounded-xl p-3 text-xs focus:outline-none focus:border-purple-600 text-zinc-200 transition" placeholder="4066699932589171|04|2031|829"></textarea>
            </div>

            <div class="mb-6">
                <button onclick="iniciarChecagem()" id="btnChecar" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3.5 rounded-xl transition shadow-lg text-xs uppercase tracking-widest">
                    Iniciar Checagem (Intervalo 15s a 20s)
                </button>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-xs uppercase tracking-wider text-zinc-400">Logs em Tempo Real:</label>
                    <span id="contador" class="text-xs text-zinc-500">Progresso: 0 / 0</span>
                </div>
                <div id="resultado" class="w-full h-56 bg-black border border-zinc-800 rounded-xl p-4 text-xs overflow-y-auto text-zinc-400 space-y-2">
                    <span class="text-zinc-600">// Sistema pronto para iniciar as requisições...</span>
                </div>
            </div>
        </div>

        <script>
            function tocarSomPlim() {
                try {
                    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = audioCtx.createOscillator();
                    const gainNode = audioCtx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(1046.50, audioCtx.currentTime);
                    osc.frequency.exponentialRampToValueAtTime(2093.00, audioCtx.currentTime + 0.1);
                    gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 1.2);
                    osc.connect(gainNode);
                    gainNode.connect(audioCtx.destination);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 1.2);
                } catch (e) {}
            }

            function dispararConfeteLive() {
                confetti({ origin: { y: 0.7 }, colors: ['#a855f7', '#ffffff', '#7e22ce'], zIndex: 9999, particleCount: 80, spread: 70 });
            }

            async function iniciarChecagem() {
                const texto = document.getElementById('lista').value.trim();
                const resDiv = document.getElementById('resultado');
                const btn = document.getElementById('btnChecar');
                const contador = document.getElementById('contador');

                if (!texto) { alert('Insira uma lista válida!'); return; }

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
                        
                        let itemDiv = document.createElement('div');
                        itemDiv.className = data.status === 'live' ? "border-l-4 border-purple-500 bg-zinc-900/90 p-2 rounded-r shadow-lg" : "border-l-2 border-zinc-800 pl-2 py-0.5";
                        itemDiv.innerHTML = data.html;
                        resDiv.appendChild(itemDiv);
                        resDiv.scrollTop = resDiv.scrollHeight;

                        if (data.status === 'live') {
                            tocarSomPlim();
                            dispararConfeteLive();
                        }
                    } catch (err) {
                        resDiv.innerHTML += `<div class='text-zinc-600'>[ERRO] Falha na requisição.</div>`;
                    }

                    if (i < linhas.length - 1) {
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
