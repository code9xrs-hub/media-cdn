<?php
/**
 * ============================================================================
 * Vidmoly Telegram Upload Bot (Firebase Realtime Database Edition)
 * ============================================================================
 * Production backend for Telegram Bot & Vidmoly API powered by Firebase Realtime DB.
 *
 * Features:
 * - Powered by Firebase Realtime Database (No MySQL or local DB needed!)
 * - Multi-API management per user with AES-256-CBC encryption at rest
 * - Force Subscribe System for Telegram Channel with member verification
 * - Multi-Language Support: 🇬🇧 English, 🇧🇩 বাংলা (Bangla), 🇮🇳 हिन्दी (Hindi)
 * - First-time user language selection prompt with country flags
 * - In-depth user guides for every command in all 3 languages
 * - Direct Telegram video & remote URL uploads
 * - Full file and folder manager with pagination & renaming
 * - Admin panel with stats, user blocking, broadcast & maintenance mode
 *
 * Requirements:
 * - PHP 8.0+
 * - cURL Extension
 * - OpenSSL Extension
 *
 * Version: 3.0.0
 * ============================================================================
 */

// Strict error reporting for internal logging, suppressed for end-user HTTP response
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone & content type
date_default_timezone_set('UTC');

// ----------------------------------------------------------------------------
// 1. CONFIGURATION & ENVIRONMENT LOADER
// ----------------------------------------------------------------------------

/**
 * Lightweight .env file loader.
 */
function loadEnvFile(string $filePath): void {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Load .env if present
loadEnvFile(__DIR__ . '/.env');

/**
 * Retrieve configuration value with fallback.
 */
function config(string $key, mixed $default = null): mixed {
    $val = getenv($key);
    if ($val !== false) {
        return $val;
    }
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

// Telegram Bot Settings
define('BOT_TOKEN', (string)config('BOT_TOKEN', '8751725729:AAFgIlWsFaLCfIA44H1KevDumLwXUOC8rZE'));
define('BOT_SECRET_TOKEN', (string)config('BOT_SECRET_TOKEN', ''));
define('ADMIN_IDS', (string)config('ADMIN_IDS', '6966969676')); // Comma separated

// Vidmoly API Settings
define('VIDMOLY_API_BASE', (string)config('VIDMOLY_API_BASE', 'https://vidmoly.me'));
define('MAX_APIS_PER_USER', (int)config('MAX_APIS_PER_USER', 5));
define('API_CACHE_SECONDS', (int)config('API_CACHE_SECONDS', 300));

// Application Security & Encryption Key (AES-256-CBC 32 chars)
define('APP_ENCRYPTION_KEY', (string)config('APP_ENCRYPTION_KEY', 'v1Dm0Ly_SecUr3_K3y_8751725729_x9Z'));

// Firebase Realtime Database Settings (Configured)
define('FIREBASE_DB_URL', (string)config('FIREBASE_DB_URL', 'https://mediacdn-f36e3-default-rtdb.asia-southeast1.firebasedatabase.app'));
define('FIREBASE_API_KEY', (string)config('FIREBASE_API_KEY', 'AIzaSyBUK9txoYiiFABssphG4XvhuW7MORkrV5s'));
define('FIREBASE_PROJECT_ID', (string)config('FIREBASE_PROJECT_ID', 'mediacdn-f36e3'));

// Operational Settings
define('DEBUG_MODE', (bool)config('DEBUG_MODE', false));
define('MAINTENANCE_MODE_DEFAULT', (bool)config('MAINTENANCE_MODE', false));

// ----------------------------------------------------------------------------
// 2. FIREBASE REALTIME DATABASE LAYER (REST API)
// ----------------------------------------------------------------------------

/**
 * Execute HTTP request to Firebase Realtime Database REST API.
 */
function firebaseRequest(string $path, string $method = 'GET', mixed $data = null): mixed {
    $baseUrl = rtrim(FIREBASE_DB_URL, '/');
    $cleanPath = ltrim($path, '/');
    $url = $baseUrl . '/' . $cleanPath . '.json';

    if (defined('FIREBASE_API_KEY') && FIREBASE_API_KEY !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'auth=' . FIREBASE_API_KEY;
    }

    $ch = curl_init($url);
    $method = strtoupper($method);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if ($data !== null) {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        safeLog("Firebase cURL error ($method $path): " . $curlErr);
        return null;
    }

    $decoded = json_decode($raw, true);
    return $decoded;
}

/**
 * Get dynamic bot setting from Firebase with fallback.
 */
function getBotSetting(string $key, ?string $default = null): ?string {
    $val = firebaseRequest("settings/{$key}", 'GET');
    if ($val === null || (is_array($val) && empty($val))) {
        return $default;
    }
    return is_scalar($val) ? (string)$val : $default;
}

/**
 * Set dynamic bot setting in Firebase.
 */
function setBotSetting(string $key, ?string $value): bool {
    $res = firebaseRequest("settings/{$key}", 'PUT', $value ?? '');
    return ($res !== null);
}

// ----------------------------------------------------------------------------
// 3. SECURITY & CRYPTOGRAPHY UTILITIES
// ----------------------------------------------------------------------------

/**
 * Safely log messages without exposing sensitive tokens or keys.
 */
function safeLog(string $message): void {
    $safeMessage = preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/i', 'bot[REDACTED]', $message);
    $safeMessage = preg_replace('/key=[a-zA-Z0-9_-]{10,}/i', 'key=[REDACTED]', $safeMessage);
    error_log('[VidmolyBot] ' . $safeMessage);
}

/**
 * Encrypt sensitive string using AES-256-CBC.
 */
function encryptSecret(string $plainText): string {
    if ($plainText === '') return '';
    $key = hash('sha256', APP_ENCRYPTION_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $cipherText = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipherText === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $cipherText);
}

/**
 * Decrypt string encrypted with AES-256-CBC.
 */
function decryptSecret(string $cipherPayload): string {
    if ($cipherPayload === '') return '';
    $raw = base64_decode($cipherPayload, true);
    if ($raw === false) return '';
    $key = hash('sha256', APP_ENCRYPTION_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($raw) < $ivLength) return '';
    $iv = substr($raw, 0, $ivLength);
    $cipherText = substr($raw, $ivLength);
    $plain = openssl_decrypt($cipherText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return ($plain === false) ? '' : $plain;
}

/**
 * Mask an API key for safe UI display.
 */
function maskApiKey(string $key): string {
    $len = strlen($key);
    if ($len <= 6) {
        return str_repeat('•', $len);
    }
    $start = substr($key, 0, 4);
    $end = substr($key, -2);
    $maskLen = max(6, min(10, $len - 6));
    return $start . str_repeat('•', $maskLen) . $end;
}

/**
 * Validate URL against SSRF attacks.
 */
function isSafeRemoteUrl(string $url): bool {
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
        return false;
    }

    $scheme = strtolower($parsed['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = $parsed['host'];
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return false;
    }

    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }

    if ($ip === '169.254.169.254' || str_starts_with($ip, '169.254.')) {
        return false;
    }

    return true;
}

/**
 * Check if a given Telegram user is an administrator.
 */
function isTelegramAdmin(int|string $telegramUserId): bool {
    $adminList = getBotSetting('admin_ids', ADMIN_IDS);
    $admins = array_map('trim', explode(',', (string)$adminList));
    return in_array((string)$telegramUserId, $admins, true);
}

// ----------------------------------------------------------------------------
// 4. USER & STATE MANAGEMENT (FIREBASE)
// ----------------------------------------------------------------------------

/**
 * Fetch or register a user by their Telegram profile.
 */
function getOrCreateUser(array $tgUser): ?array {
    $tgId = (string)$tgUser['id'];
    $username = $tgUser['username'] ?? null;
    $firstName = $tgUser['first_name'] ?? null;
    $lastName = $tgUser['last_name'] ?? null;

    $existing = firebaseRequest("users/{$tgId}", 'GET');

    if (is_array($existing) && !empty($existing['telegram_user_id'])) {
        // Update profile if changed
        $updated = [
            'username'   => $username,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        firebaseRequest("users/{$tgId}", 'PATCH', $updated);

        $existing['username'] = $username;
        $existing['first_name'] = $firstName;
        $existing['last_name'] = $lastName;
        $existing['is_new'] = false;
        return $existing;
    }

    // New user
    $newUser = [
        'id'               => (int)$tgId,
        'telegram_user_id' => (int)$tgId,
        'username'         => $username,
        'first_name'       => $firstName,
        'last_name'        => $lastName,
        'language'         => 'en',
        'is_blocked'       => 0,
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s')
    ];

    firebaseRequest("users/{$tgId}", 'PUT', $newUser);

    // Initialize state
    setUserState((int)$tgId, 'selecting_language');

    $newUser['is_new'] = true;
    return $newUser;
}

/**
 * Update user language preference in Firebase.
 */
function setUserLanguage(int|string $userId, string $lang): bool {
    $allowed = ['en', 'bn', 'hi'];
    if (!in_array($lang, $allowed, true)) {
        $lang = 'en';
    }
    $res = firebaseRequest("users/{$userId}", 'PATCH', [
        'language'   => $lang,
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    return ($res !== null);
}

/**
 * Retrieve user current conversation state from Firebase.
 */
function getUserState(int|string $userId): array {
    $res = firebaseRequest("user_states/{$userId}", 'GET');
    if (is_array($res) && isset($res['state'])) {
        return [
            'state' => $res['state'] ?: 'idle',
            'data'  => is_array($res['state_data'] ?? null) ? $res['state_data'] : []
        ];
    }
    return ['state' => 'idle', 'data' => []];
}

/**
 * Set user conversational state in Firebase.
 */
function setUserState(int|string $userId, string $state, array $data = []): bool {
    $res = firebaseRequest("user_states/{$userId}", 'PUT', [
        'state'      => $state,
        'state_data' => $data,
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    return ($res !== null);
}

/**
 * Reset user state back to idle.
 */
function clearUserState(int|string $userId): bool {
    return setUserState($userId, 'idle', []);
}

// ----------------------------------------------------------------------------
// 5. VIDMOLY API MANAGEMENT (FIREBASE)
// ----------------------------------------------------------------------------

/**
 * Retrieve all registered APIs for a user from Firebase.
 */
function getUserApis(int|string $userId): array {
    $res = firebaseRequest("vidmoly_apis/{$userId}", 'GET');
    if (!is_array($res)) {
        return [];
    }
    $list = [];
    foreach ($res as $key => $api) {
        if (is_array($api)) {
            $api['id'] = (string)$key;
            $list[] = $api;
        }
    }
    return $list;
}

/**
 * Retrieve the active Vidmoly API for a user, with decrypted key.
 */
function getActiveApi(int|string $userId): ?array {
    $apis = getUserApis($userId);
    foreach ($apis as $api) {
        if (!empty($api['is_active'])) {
            $api['decrypted_key'] = decryptSecret($api['api_key']);
            return $api;
        }
    }
    return null;
}

/**
 * Switch active API for a user in Firebase.
 */
function switchActiveApi(int|string $userId, string $targetApiId): bool {
    $apis = getUserApis($userId);
    foreach ($apis as $api) {
        $apiId = (string)$api['id'];
        $isActive = ($apiId === (string)$targetApiId) ? 1 : 0;
        firebaseRequest("vidmoly_apis/{$userId}/{$apiId}", 'PATCH', [
            'is_active'  => $isActive,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
    return true;
}

/**
 * Remove an API key for a user and re-assign active API in Firebase.
 */
function removeUserApi(int|string $userId, string $apiId): bool {
    $target = firebaseRequest("vidmoly_apis/{$userId}/{$apiId}", 'GET');
    if (!is_array($target)) {
        return false;
    }
    $wasActive = !empty($target['is_active']);
    firebaseRequest("vidmoly_apis/{$userId}/{$apiId}", 'DELETE');

    if ($wasActive) {
        $remaining = getUserApis($userId);
        if (!empty($remaining)) {
            $firstId = (string)$remaining[0]['id'];
            firebaseRequest("vidmoly_apis/{$userId}/{$firstId}", 'PATCH', [
                'is_active'  => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    return true;
}

// ----------------------------------------------------------------------------
// 6. VIDMOLY API CLIENT
// ----------------------------------------------------------------------------

/**
 * Central Vidmoly HTTP Client.
 */
function vidmolyRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $apiKey = null): array {
    $baseUrl = rtrim(VIDMOLY_API_BASE, '/');
    $url = $baseUrl . '/' . ltrim($endpoint, '/');

    if ($apiKey !== null && !isset($params['key'])) {
        $params['key'] = $apiKey;
    }

    $ch = curl_init();
    $method = strtoupper($method);

    if ($method === 'GET') {
        if (!empty($params)) {
            $query = http_build_query($params);
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'VidmolyTelegramBot/3.0',
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        safeLog("Vidmoly cURL failure: $curlError on endpoint $endpoint");
        return [
            'success' => false,
            'status'  => 0,
            'message' => 'Network error or timeout: ' . $curlError,
            'data'    => null
        ];
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        safeLog("Vidmoly invalid JSON ($httpCode) on $endpoint: " . substr($rawResponse, 0, 200));
        return [
            'success' => false,
            'status'  => $httpCode,
            'message' => 'Vidmoly returned an invalid non-JSON response.',
            'raw'     => $rawResponse,
            'data'    => null
        ];
    }

    $status = $decoded['status'] ?? $httpCode;
    $msg = $decoded['msg'] ?? $decoded['message'] ?? '';
    $isSuccess = ($status == 200) || (strtolower((string)$msg) === 'ok');

    return [
        'success' => $isSuccess,
        'status'  => $status,
        'message' => $msg,
        'result'  => $decoded['result'] ?? null,
        'data'    => $decoded
    ];
}

// ----------------------------------------------------------------------------
// 7. TELEGRAM BOT API CLIENT & FORCE SUBSCRIBE VERIFICATION
// ----------------------------------------------------------------------------

/**
 * Send request to Telegram Bot API.
 */
function telegramRequest(string $method, array $params = []): ?array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        safeLog("Telegram API error ($method): $curlErr");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['ok'])) {
        safeLog("Telegram API responded not ok ($method - HTTP $httpCode): " . ($data['description'] ?? ''));
    }
    return $data;
}

/**
 * Send text message to chat.
 */
function sendTelegramMessage(int|string $chatId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = $keyboard;
    }
    return telegramRequest('sendMessage', $params);
}

/**
 * Edit existing message text and keyboard.
 */
function editTelegramMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $text,
        'parse_mode'               => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = $keyboard;
    }
    return telegramRequest('editMessageText', $params);
}

/**
 * Answer Telegram callback query.
 */
function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void {
    telegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text'              => $text,
        'show_alert'        => $showAlert
    ]);
}

/**
 * Send chat action.
 */
function sendChatAction(int|string $chatId, string $action = 'typing'): void {
    telegramRequest('sendChatAction', [
        'chat_id' => $chatId,
        'action'  => $action
    ]);
}

/**
 * Check if a user has subscribed to the Force Subscribe channel.
 */
function checkUserSubscription(int|string $telegramUserId): bool {
    if (isTelegramAdmin($telegramUserId)) {
        return true;
    }

    $channel = trim((string)getBotSetting('force_sub_channel', ''));
    if ($channel === '') {
        return true;
    }

    $res = telegramRequest('getChatMember', [
        'chat_id' => $channel,
        'user_id' => $telegramUserId
    ]);

    if (!$res || empty($res['ok']) || empty($res['result'])) {
        return false;
    }

    $status = $res['result']['status'] ?? '';
    return in_array($status, ['creator', 'administrator', 'member', 'restricted'], true);
}

/**
 * Render Force Subscribe Required Alert.
 */
function sendForceSubscribeNotice(int|string $chatId, string $lang = 'en'): void {
    $channel = trim((string)getBotSetting('force_sub_channel', ''));
    $channelLink = str_starts_with($channel, '@') ? 'https://t.me/' . substr($channel, 1) : 'https://t.me/' . ltrim($channel, '@');

    $texts = [
        'en' => [
            'msg' => "⚠️ <b>Channel Subscription Required!</b>\n\nTo use this bot, you must first subscribe to our official Telegram channel.\n\n👇 Click the button below to join, then tap <b>Check Membership</b> to continue:",
            'btn_join' => '📢 Join Channel',
            'btn_check' => '🔄 I Have Joined / Check Membership'
        ],
        'bn' => [
            'msg' => "⚠️ <b>চ্যানেল সাবস্ক্রিপশন বাধ্যতামূলক!</b>\n\nএই বটটি ব্যবহার করতে অনুগ্রহ করে প্রথমে আমাদের অফিশিয়াল টেলিগ্রাম চ্যানেলে জয়েন করুন।\n\n👇 নিচের বাটনে চাপ দিয়ে চ্যানেলে যুক্ত হন এবং এরপর <b>যাচাই করুন</b> বাটনে চাপুন:",
            'btn_join' => '📢 চ্যানেলে জয়েন করুন',
            'btn_check' => '🔄 আমি জয়েন করেছি / যাচাই করুন'
        ],
        'hi' => [
            'msg' => "⚠️ <b>चैनल सदस्यता अनिवार्य है!</b>\n\nइस बॉट का उपयोग करने के लिए आपको पहले हमारे आधिकारिक टेलीग्राम चैनल से जुड़ना होगा।\n\n👇 नीचे दिए गए बटन पर क्लिक करके चैनल से जुड़ें और फिर <b>सदस्यता जांचें</b> पर टैप करें:",
            'btn_join' => '📢 चैनल से जुड़ें',
            'btn_check' => '🔄 मैं जुड़ गया हूँ / सदस्यता जांचें'
        ]
    ];

    $t = $texts[$lang] ?? $texts['en'];

    $keyboard = [
        'inline_keyboard' => [
            [['text' => $t['btn_join'], 'url' => $channelLink]],
            [['text' => $t['btn_check'], 'callback_data' => 'check_force_sub']]
        ]
    ];

    sendTelegramMessage($chatId, $t['msg'], $keyboard);
}

// ----------------------------------------------------------------------------
// 8. MULTI-LANGUAGE KEYBOARDS & LOCALIZED TEXT SYSTEM
// ----------------------------------------------------------------------------

/**
 * Language Selection Keyboard with country flags.
 */
function buildLanguageSelectionKeyboard(): array {
    return [
        'inline_keyboard' => [
            [['text' => '🇬🇧 English', 'callback_data' => 'set_lang_en']],
            [['text' => '🇧🇩 বাংলা (Bangla)', 'callback_data' => 'set_lang_bn']],
            [['text' => '🇮🇳 हिन्दी (Hindi)', 'callback_data' => 'set_lang_hi']]
        ]
    ];
}

/**
 * Main Menu Inline Keyboard with localized buttons.
 */
function buildMainMenuKeyboard(string $lang = 'en'): array {
    $btn = [
        'en' => [
            'upload'  => '📤 Upload Video',
            'remote'  => '🌐 Remote Upload',
            'files'   => '📁 My Files',
            'finfo'   => '🔎 File Info',
            'apis'    => '🔑 My APIs',
            'addapi'  => '➕ Add API',
            'switch'  => '🔄 Switch API',
            'remove'  => '🗑️ Remove API',
            'account' => '👤 Account Info',
            'stats'   => '📊 Statistics',
            'folders' => '📂 Folders',
            'lang'    => '🌐 Language',
            'help'    => '❓ User Guide'
        ],
        'bn' => [
            'upload'  => '📤 ভিডিও আপলোড',
            'remote'  => '🌐 রিমোট আপলোড',
            'files'   => '📁 আমার ফাইলসমূহ',
            'finfo'   => '🔎 ফাইল তথ্য',
            'apis'    => '🔑 সংরক্ষিত API',
            'addapi'  => '➕ API যোগ করুন',
            'switch'  => '🔄 API পরিবর্তন',
            'remove'  => '🗑️ API মুছুন',
            'account' => '👤 অ্যাকাউন্ট তথ্য',
            'stats'   => '📊 পরিসংখ্যান',
            'folders' => '📂 ফোল্ডারসমূহ',
            'lang'    => '🌐 ভাষা পরিবর্তন',
            'help'    => '❓ সহায়িকা ও গাইড'
        ],
        'hi' => [
            'upload'  => '📤 वीडियो अपलोड',
            'remote'  => '🌐 रिमोट अपलोड',
            'files'   => '📁 मेरी फाइलें',
            'finfo'   => '🔎 फाइल विवरण',
            'apis'    => '🔑 मेरी API',
            'addapi'  => '➕ API जोड़ें',
            'switch'  => '🔄 API बदलें',
            'remove'  => '🗑️ API हटाएं',
            'account' => '👤 खाता विवरण',
            'stats'   => '📊 आंकड़े',
            'folders' => '📂 फ़ोल्डर्स',
            'lang'    => '🌐 भाषा बदलें',
            'help'    => '❓ सहायता गाइड'
        ]
    ];

    $b = $btn[$lang] ?? $btn['en'];

    return [
        'inline_keyboard' => [
            [
                ['text' => $b['upload'], 'callback_data' => 'menu_upload'],
                ['text' => $b['remote'], 'callback_data' => 'menu_remote_upload']
            ],
            [
                ['text' => $b['files'], 'callback_data' => 'menu_files'],
                ['text' => $b['finfo'], 'callback_data' => 'menu_file_info']
            ],
            [
                ['text' => $b['apis'], 'callback_data' => 'menu_apis'],
                ['text' => $b['addapi'], 'callback_data' => 'api_add']
            ],
            [
                ['text' => $b['switch'], 'callback_data' => 'api_switch'],
                ['text' => $b['remove'], 'callback_data' => 'api_remove']
            ],
            [
                ['text' => $b['account'], 'callback_data' => 'menu_account'],
                ['text' => $b['stats'], 'callback_data' => 'menu_stats']
            ],
            [
                ['text' => $b['folders'], 'callback_data' => 'menu_folders'],
                ['text' => $b['lang'], 'callback_data' => 'menu_language']
            ],
            [
                ['text' => $b['help'], 'callback_data' => 'menu_help']
            ]
        ]
    ];
}

/**
 * Return to main menu button.
 */
function buildBackToMenuKeyboard(string $lang = 'en'): array {
    $lbl = [
        'en' => '🔙 Back to Menu',
        'bn' => '🔙 প্রধান মেনু',
        'hi' => '🔙 मुख्य मेनू'
    ];
    return [
        'inline_keyboard' => [
            [['text' => $lbl[$lang] ?? $lbl['en'], 'callback_data' => 'menu_main']]
        ]
    ];
}

// ----------------------------------------------------------------------------
// 9. COMMAND HANDLERS & IN-DEPTH USER GUIDES (MULTILINGUAL)
// ----------------------------------------------------------------------------

/**
 * Prompt user to select language.
 */
function handleLanguageSelectionPrompt(int|string $chatId): void {
    $text = "🌐 <b>Select Your Preferred Language / আপনার ভাষা নির্বাচন করুন / अपनी भाषा चुनें:</b>\n\n"
          . "🇬🇧 <b>English:</b> Default international language.\n"
          . "🇧🇩 <b>বাংলা (Bangla):</b> সম্পূর্ণ বাংলা ইন্টারফেস ও গাইড।\n"
          . "🇮🇳 <b>हिन्दी (Hindi):</b> पूर्ण हिंदी इंटरफ़ेस और गाइड।\n\n"
          . "👇 <i>Tap one of the buttons below to choose:</i>";

    sendTelegramMessage($chatId, $text, buildLanguageSelectionKeyboard());
}

/**
 * Handle /start command.
 */
function handleStartCommand(array $user, int|string $chatId): void {
    if (!empty($user['is_new'])) {
        setUserState($user['id'], 'selecting_language');
        handleLanguageSelectionPrompt($chatId);
        return;
    }

    clearUserState($user['id']);
    $lang = $user['language'] ?: 'en';
    $name = htmlspecialchars($user['first_name'] ?: ($user['username'] ?: 'Friend'));

    if ($lang === 'bn') {
        $text = "🎬 <b>স্বাগতম Vidmoly Telegram Bot-এ</b>, {$name}!\n\n"
              . "টেলিগ্রাম থেকেই সহজে এবং নিরাপদে আপনার Vidmoly ভিডিও অ্যাকাউন্টের সবকিছু পরিচালনা করুন।\n\n"
              . "🚀 <b>কুইক স্টার্ট গাইড:</b>\n"
              . "1️⃣ <b>API সংযুক্ত করুন:</b> /addapi কমান্ড ব্যবহার করে Vidmoly API কী যুক্ত করুন।\n"
              . "2️⃣ <b>ভিডিও আপলোড করুন:</b> /upload দিয়ে সরাসরি ভিডিও লিংক পাঠান অথবা চ্যাটে ভিডিও ফাইল ফরওয়ার্ড করুন।\n"
              . "3️⃣ <b>ফাইল পরিচালনা:</b> /files দিয়ে আপনার ভিডিও ব্রাউজ করুন, রিনেম করুন বা ফোল্ডারে রাখুন।\n\n"
              . "👇 <b>নিচের মেনু থেকে আপনার কাঙ্ক্ষিত অপশন বেছে নিন:</b>";
    } elseif ($lang === 'hi') {
        $text = "🎬 <b>Vidmoly Telegram Bot में आपका स्वागत है</b>, {$name}!\n\n"
              . "टेलीग्राम से ही आसानी और सुरक्षा से अपने Vidmoly वीडियो खाते को प्रबंधित करें।\n\n"
              . "🚀 <b>क्विक स्टार्ट गाइड:</b>\n"
              . "1️⃣ <b>API जोड़ें:</b> /addapi का उपयोग करके अपनी Vidmoly API कुंजी लिंक करें।\n"
              . "2️⃣ <b>वीडियो अपलोड करें:</b> /upload द्वारा डायरेक्ट लिंक भेजें या सीधे वीडियो फाइल भेजें।\n"
              . "3️⃣ <b>फाइल प्रबंधन:</b> /files द्वारा अपनी वीडियो ब्राउज करें, नाम बदलें या व्यवस्थित करें।\n\n"
              . "👇 <b>शुरू करने के लिए नीचे दिए गए विकल्पों में से चुनें:</b>";
    } else {
        $text = "🎬 <b>Welcome to Vidmoly Telegram Bot</b>, {$name}!\n\n"
              . "Manage your Vidmoly video hosting account directly from Telegram with high speed and security.\n\n"
              . "🚀 <b>Quick Start Guide:</b>\n"
              . "1️⃣ <b>Link API:</b> Use /addapi to connect your Vidmoly account API key.\n"
              . "2️⃣ <b>Upload Videos:</b> Use /upload for direct video URLs or send a video file directly to this bot.\n"
              . "3️⃣ <b>Manage Files:</b> Use /files to browse, rename, and organize your uploaded videos.\n\n"
              . "👇 <b>Select an option from the menu below:</b>";
    }

    sendTelegramMessage($chatId, $text, buildMainMenuKeyboard($lang));
}

/**
 * Handle /help command.
 */
function handleHelpCommand(int|string $chatId, string $lang = 'en'): void {
    if ($lang === 'bn') {
        $text = "📖 <b>Vidmoly Bot — সম্পূর্ণ কমান্ড সহায়িকা ও ইউজার গাইড</b>\n\n"
              . "<b>🔑 API ব্যবস্থাপনা কমান্ড:</b>\n"
              . "• /addapi — <i>নতুন Vidmoly API কী যোগ করার নির্দেশিকা।</i>\n"
              . "• /myapis — <i>সংরক্ষিত সমস্ত API অ্যাকাউন্টের তালিকা ও ব্যালেন্স দেখুন।</i>\n"
              . "• /switchapi — <i>কোন অ্যাকাউন্টে আপলোড হবে তা নির্বাচন ও পরিবর্তন করুন।</i>\n"
              . "• /removeapi — <i>সংরক্ষিত অপ্রয়োজনীয় API কী নিরাপদভাবে মুছে ফেলুন।</i>\n\n"
              . "<b>👤 অ্যাকাউন্ট ও পরিসংখ্যান:</b>\n"
              . "• /account — <i>বর্তমান অ্যাকাউন্টের ব্যালেন্স, প্রিমিয়াম স্টেটাস ও স্টোরেজ দেখুন।</i>\n"
              . "• /stats — <i>গত ৭ বা ৩০ দিনের ভিউ, ডাউনলোড ও আয়ের রিপোর্ট দেখুন।</i>\n\n"
              . "<b>📤 ভিডিও আপলোড:</b>\n"
              . "• /upload — <i>রিমোট ইউআরএল আপলোড। সরাসরি ভিডিও লিঙ্ক (MP4/MKV) দিয়ে সার্ভারে যুক্ত করুন।</i>\n"
              . "• <b>ডাইরেক্ট ভিডিও আপলোড:</b> <i>যেকোনো ভিডিও বা ডকুমেন্ট সরাসরি এই চ্যাটে ফরওয়ার্ড করুন।</i>\n\n"
              . "<b>📁 ফাইল ও ফোল্ডার পরিচালনা:</b>\n"
              . "• /files — <i>আপলোড করা সমস্ত ফাইলের তালিকা দেখুন ও পেজ নেভিগেট করুন।</i>\n"
              . "• /fileinfo — <i>ফাইল কোড দিয়ে ভিডিও সাইজ, ভিউ সংখ্যা ও ওয়াচ লিঙ্ক দেখুন।</i>\n"
              . "• /rename — <i>Vidmoly সার্ভারে ভিডিওর টাইটেল পরিবর্তন করুন।</i>\n"
              . "• /folders — <i>আপনার তৈরি সমস্ত ফোল্ডারের তালিকা দেখুন।</i>\n"
              . "• /addfolder — <i>ভিডিও সাজিয়ে রাখতে নতুন ফোল্ডার তৈরি করুন।</i>\n\n"
              . "<b>🌐 ভাষা ও সেটিংস:</b>\n"
              . "• /language — <i>বটের ভাষা পরিবর্তন করুন (English, বাংলা, हिन्दी)।</i>\n"
              . "• /cancel — <i>যেকোনো চলমান ইনপুট বা প্রম্পট বাতিল করুন।</i>";
    } elseif ($lang === 'hi') {
        $text = "📖 <b>Vidmoly Bot — संपूर्ण कमांड सहायता और उपयोगकर्ता गाइड</b>\n\n"
              . "<b>🔑 API प्रबंधन कमांड:</b>\n"
              . "• /addapi — <i>नई Vidmoly API कुंजी जोड़ने की चरण-दर-चरण मार्गदर्शिका।</i>\n"
              . "• /myapis — <i>सहेजे गए सभी API खातों और उनकी स्थिति की सूची देखें।</i>\n"
              . "• /switchapi — <i>अपलोड के लिए सक्रिय खाता चुनें और बदलें।</i>\n"
              . "• /removeapi — <i>सहेजी गई API कुंजी को सुरक्षित रूप से हटाएं।</i>\n\n"
              . "<b>👤 खाता और आंकड़े:</b>\n"
              . "• /account — <i>वर्तमान खाते का बैलेंस, प्रीमियम स्थिति और स्टोरेज देखें।</i>\n"
              . "• /stats — <i>पिछले 7 या 30 दिनों के व्यूज, डाउनलोड और कमाई की रिपोर्ट देखें।</i>\n\n"
              . "<b>📤 वीडियो अपलोड:</b>\n"
              . "• /upload — <i>रिमोट यूआरएल अपलोड। सीधे वीडियो लिंक (MP4/MKV) से अपलोड करें।</i>\n"
              . "• <b>सीधा वीडियो अपलोड:</b> <i>कोई भी वीडियो या दस्तावेज सीधे इस चैट में भेजें।</i>\n\n"
              . "<b>📁 फाइल और फ़ोल्डर प्रबंधन:</b>\n"
              . "• /files — <i>अपलोड की गई सभी फाइलों की सूची देखें।</i>\n"
              . "• /fileinfo — <i>फाइल कोड से वीडियो का आकार, व्यूज और वॉच लिंक देखें।</i>\n"
              . "• /rename — <i>Vidmoly पर वीडियो का शीर्षक बदलें।</i>\n"
              . "• /folders — <i>अपने सभी फ़ोल्डर्स की सूची देखें।</i>\n"
              . "• /addfolder — <i>नया फ़ोल्डर बनाएं।</i>\n\n"
              . "<b>🌐 भाषा और सेटिंग्स:</b>\n"
              . "• /language — <i>बॉट की भाषा बदलें (English, বাংলা, हिन्दी)।</i>\n"
              . "• /cancel — <i>चल रही प्रक्रिया को रद्द करें।</i>";
    } else {
        $text = "📖 <b>Vidmoly Bot — Complete Command Reference & User Guide</b>\n\n"
              . "<b>🔑 API Management:</b>\n"
              . "• /addapi — <i>Step-by-step guide to connect a new Vidmoly API key.</i>\n"
              . "• /myapis — <i>View all saved API keys with masked credentials & balances.</i>\n"
              . "• /switchapi — <i>Switch active Vidmoly account for new uploads.</i>\n"
              . "• /removeapi — <i>Safely delete a stored API key from the bot.</i>\n\n"
              . "<b>👤 Account & Analytics:</b>\n"
              . "• /account — <i>View real-time account status: email, balance, tier, and storage.</i>\n"
              . "• /stats — <i>View views, downloads, and earnings for the last 7 or 30 days.</i>\n\n"
              . "<b>📤 Uploading Content:</b>\n"
              . "• /upload — <i>Remote URL upload. Send any direct video link (MP4, MKV, etc.).</i>\n"
              . "• <b>Direct Video Upload:</b> <i>Send or forward any video directly to this chat.</i>\n\n"
              . "<b>📁 File & Folder Operations:</b>\n"
              . "• /files — <i>Interactive paginated browser of your uploaded videos.</i>\n"
              . "• /fileinfo — <i>Lookup video details (size, status, views, link) by file code.</i>\n"
              . "• /rename — <i>Rename any video file title stored on Vidmoly.</i>\n"
              . "• /folders — <i>List all folders in your account.</i>\n"
              . "• /addfolder — <i>Create a new folder to organize videos.</i>\n\n"
              . "<b>🌐 Settings & Utilities:</b>\n"
              . "• /language — <i>Switch bot language (English, বাংলা, हिन्दी).</i>\n"
              . "• /cancel — <i>Abort current operation anytime.</i>";
    }

    sendTelegramMessage($chatId, $text, buildBackToMenuKeyboard($lang));
}

/**
 * Handle /addapi command.
 */
function handleAddApiCommand(array $user, int|string $chatId): void {
    $lang = $user['language'] ?: 'en';
    $apis = getUserApis($user['id']);
    $maxApis = (int)getBotSetting('max_apis_per_user', (string)MAX_APIS_PER_USER);

    if (count($apis) >= $maxApis) {
        $msgs = [
            'en' => "⚠️ <b>API Limit Reached</b>\nYou have already added the maximum of {$maxApis} API keys. Use /removeapi to remove an unused key.",
            'bn' => "⚠️ <b>API সীমা পূর্ণ হয়েছে</b>\nআপনি ইতিমধ্যে সর্বোচ্চ {$maxApis} টি API কী যুক্ত করেছেন। নতুন কী যোগ করতে /removeapi দিয়ে অব্যবহৃত কী মুছুন।",
            'hi' => "⚠️ <b>API सीमा समाप्त</b>\nआप पहले ही अधिकतम {$maxApis} API कुंजियां जोड़ चुके हैं। नई कुंजी जोड़ने के लिए /removeapi का उपयोग करें।"
        ];
        sendTelegramMessage($chatId, $msgs[$lang] ?? $msgs['en'], buildBackToMenuKeyboard($lang));
        return;
    }

    setUserState($user['id'], 'waiting_api_key');

    if ($lang === 'bn') {
        $text = "🔑 <b>Vidmoly API কী যোগ করুন — ইউজার গাইড</b>\n\n"
              . "<b>যেভাবে আপনার API Key সংগ্রহ করবেন:</b>\n"
              . "1. ব্রাউজারে গিয়ে <a href=\"https://vidmoly.me\">Vidmoly.me</a> অ্যাকাউন্টে লগইন করুন।\n"
              . "2. <b>My Account</b> অথবা <b>Settings</b> পেজে যান।\n"
              . "3. সেখানে <b>API Key</b> অপশনটি খুঁজে বের করে কী কপি করুন।\n\n"
              . "🛡️ <b>নিরাপত্তা নিশ্চয়তা:</b>\n"
              . "• আপনার কী AES-256-CBC দিয়ে এনক্রিপ্ট করে সুরক্ষিতভাবে রাখা হয়।\n"
              . "• কী কখনোই টেলিগ্রাম চ্যাটে সম্পূর্ণ দেখানো হয় না (যেমন: <code>6200••••••••••mx</code>)।\n\n"
              . "👉 <b>এখন আপনার Vidmoly API কী লিখে পাঠান:</b>\n"
              . "<i>(বাতিল করতে /cancel লিখুন)</i>";
    } elseif ($lang === 'hi') {
        $text = "🔑 <b>Vidmoly API कुंजी जोड़ें — उपयोगकर्ता गाइड</b>\n\n"
              . "<b>अपनी API कुंजी कैसे प्राप्त करें:</b>\n"
              . "1. अपने ब्राउज़र में <a href=\"https://vidmoly.me\">Vidmoly.me</a> पर लॉग इन करें।\n"
              . "2. <b>My Account</b> या <b>Settings</b> पर जाएं।\n"
              . "3. <b>API Key</b> अनुभाग ढूंढें और अपनी कुंजी कॉपी करें।\n\n"
              . "🛡️ <b>सुरक्षा आश्वासन:</b>\n"
              . "• आपकी कुंजी AES-256-CBC एन्क्रिप्शन के साथ सुरक्षित रूप से संग्रहीत की जाती है।\n"
              . "• टेलीग्राम संदेशों में कुंजी को कभी भी पूरा नहीं दिखाया जाता है।\n\n"
              . "👉 <b>कृपया अब अपनी Vidmoly API कुंजी भेजें:</b>\n"
              . "<i>(रद्द करने के लिए /cancel टाइप करें)</i>";
    } else {
        $text = "🔑 <b>Add Vidmoly API Key — User Guide</b>\n\n"
              . "<b>Step-by-step instructions to obtain your API Key:</b>\n"
              . "1. Open your browser and log in to <a href=\"https://vidmoly.me\">Vidmoly.me</a>.\n"
              . "2. Navigate to <b>My Account</b> or <b>Settings</b>.\n"
              . "3. Locate the <b>API Key</b> section and copy your key.\n\n"
              . "🛡️ <b>Security Assurance:</b>\n"
              . "• Your API key is encrypted at rest using AES-256-CBC.\n"
              . "• Keys are masked in Telegram messages (e.g. <code>6200••••••••••mx</code>) and never logged in plain text.\n\n"
              . "👉 <b>Please send your Vidmoly API Key now:</b>\n"
              . "<i>(Type /cancel to abort at any time)</i>";
    }

    $cancelBtn = ['en' => '❌ Cancel', 'bn' => '❌ বাতিল', 'hi' => '❌ रद्द करें'];
    $keyboard = [
        'inline_keyboard' => [
            [['text' => $cancelBtn[$lang] ?? $cancelBtn['en'], 'callback_data' => 'menu_main']]
        ]
    ];
    sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle text input when user is in waiting_api_key state.
 */
function handleProcessApiKeyInput(array $user, int|string $chatId, string $rawKey): void {
    $lang = $user['language'] ?: 'en';
    $apiKey = trim($rawKey);
    if ($apiKey === '' || strlen($apiKey) < 8) {
        $err = [
            'en' => "❌ <b>Invalid API Key Format</b>\nPlease send a valid Vidmoly API key or type /cancel to abort.",
            'bn' => "❌ <b>অবৈধ API কী ফরম্যাট</b>\nঅনুগ্রহ করে সঠিক Vidmoly API কী পাঠান অথবা বাতিল করতে /cancel লিখুন।",
            'hi' => "❌ <b>अमान्य API कुंजी प्रारूप</b>\nकृपया एक वैध Vidmoly API कुंजी भेजें या रद्द करने के लिए /cancel टाइप करें।"
        ];
        sendTelegramMessage($chatId, $err[$lang] ?? $err['en']);
        return;
    }

    sendChatAction($chatId, 'typing');

    // 1. Check duplicate API
    $existingApis = getUserApis($user['id']);
    foreach ($existingApis as $ea) {
        if (decryptSecret($ea['api_key']) === $apiKey) {
            clearUserState($user['id']);
            $dup = [
                'en' => "⚠️ <b>Duplicate API Detected</b>\nThis API Key is already saved under account: <code>" . htmlspecialchars($ea['account_email'] ?: ($ea['account_login'] ?: 'Saved')) . "</code>",
                'bn' => "⚠️ <b>ডুপ্লিকেট API শনাক্ত হয়েছে</b>\nএই API কী ইতিমধ্যে সংরক্ষিত আছে: <code>" . htmlspecialchars($ea['account_email'] ?: ($ea['account_login'] ?: 'Saved')) . "</code>",
                'hi' => "⚠️ <b>डुप्लिकेट API मिली</b>\nयह API कुंजी पहले से सहेजी गई है: <code>" . htmlspecialchars($ea['account_email'] ?: ($ea['account_login'] ?: 'Saved')) . "</code>"
            ];
            sendTelegramMessage($chatId, $dup[$lang] ?? $dup['en'], buildMainMenuKeyboard($lang));
            return;
        }
    }

    // 2. Validate API Key with Vidmoly /api/account/info
    $res = vidmolyRequest('/api/account/info', [], 'GET', $apiKey);

    if (!$res['success']) {
        $fail = [
            'en' => "❌ <b>API Key Validation Failed</b>\nVidmoly API rejected this key: " . htmlspecialchars($res['message'] ?: 'Authentication failed') . "\nPlease check your key and try again or send /cancel.",
            'bn' => "❌ <b>API কী যাচাইকরণ ব্যর্থ হয়েছে</b>\nVidmoly সার্ভার এই কী গ্রহণ করেনি: " . htmlspecialchars($res['message'] ?: 'অননুমোদিত') . "\nঅনুগ্রহ করে কী যাচাই করে পুনরায় পাঠান অথবা /cancel লিখুন।",
            'hi' => "❌ <b>API कुंजी सत्यापन विफल</b>\nVidmoly सर्वर ने इस कुंजी को अस्वीकार कर दिया: " . htmlspecialchars($res['message'] ?: 'प्रमाणीकरण विफल') . "\nकृपया अपनी कुंजी की जांच करें या /cancel भेजें।"
        ];
        sendTelegramMessage($chatId, $fail[$lang] ?? $fail['en']);
        return;
    }

    $result = $res['result'] ?? [];
    $email = $result['email'] ?? null;
    $login = $result['login'] ?? null;
    $balance = isset($result['balance']) ? (float)$result['balance'] : 0.0;
    $premium = !empty($result['premium']) ? 1 : 0;
    $diskUsage = $result['storage_used'] ?? $result['disk_usage'] ?? null;

    // 3. Encrypt and save to Firebase
    $encryptedKey = encryptSecret($apiKey);
    $isActive = (count($existingApis) === 0) ? 1 : 0;
    $apiUniqueId = 'api_' . time() . '_' . substr(md5($apiKey), 0, 6);

    $apiData = [
        'id'            => $apiUniqueId,
        'user_id'       => $user['id'],
        'api_name'      => $login ?: ($email ?: 'Account #' . (count($existingApis) + 1)),
        'api_key'       => $encryptedKey,
        'account_email' => $email,
        'account_login' => $login,
        'balance'       => $balance,
        'premium'       => $premium,
        'disk_usage'    => $diskUsage,
        'is_active'     => $isActive,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s')
    ];

    firebaseRequest("vidmoly_apis/{$user['id']}/{$apiUniqueId}", 'PUT', $apiData);
    clearUserState($user['id']);

    $statusBadge = $isActive ? "⭐ Active" : "⚪ Inactive";
    $premiumBadge = $premium ? "💎 Premium" : "Free";

    if ($lang === 'bn') {
        $text = "✅ <b>Vidmoly API সফলভাবে যুক্ত হয়েছে!</b>\n\n"
              . "👤 <b>অ্যাকাউন্ট:</b> " . htmlspecialchars($email ?: ($login ?: 'N/A')) . "\n"
              . "🔑 <b>কী:</b> <code>" . maskApiKey($apiKey) . "</code>\n"
              . "💰 <b>ব্যালেন্স:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>মেম্বারশিপ:</b> {$premiumBadge}\n"
              . "💾 <b>স্টোরেজ ব্যবহার:</b> " . htmlspecialchars($diskUsage ?: 'N/A') . "\n"
              . "📌 <b>স্ট্যাটাস:</b> {$statusBadge}\n\n"
              . "💡 <i>আপনি এখন /upload দিয়ে ভিডিও আপলোড বা /files দিয়ে ফাইল পরিচালনা করতে পারেন!</i>";
    } elseif ($lang === 'hi') {
        $text = "✅ <b>Vidmoly API सफलतापूर्वक लिंक किया गया!</b>\n\n"
              . "👤 <b>खाता:</b> " . htmlspecialchars($email ?: ($login ?: 'N/A')) . "\n"
              . "🔑 <b>कुंजी:</b> <code>" . maskApiKey($apiKey) . "</code>\n"
              . "💰 <b>बैलेंस:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>सदस्यता:</b> {$premiumBadge}\n"
              . "💾 <b>स्टोरेज उपयोग:</b> " . htmlspecialchars($diskUsage ?: 'N/A') . "\n"
              . "📌 <b>स्थिति:</b> {$statusBadge}\n\n"
              . "💡 <i>अब आप /upload से वीडियो अपलोड या /files से फाइल प्रबंधित कर सकते हैं!</i>";
    } else {
        $text = "✅ <b>Vidmoly API Linked Successfully!</b>\n\n"
              . "👤 <b>Account Email:</b> " . htmlspecialchars($email ?: ($login ?: 'N/A')) . "\n"
              . "🔑 <b>Masked Key:</b> <code>" . maskApiKey($apiKey) . "</code>\n"
              . "💰 <b>Balance:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>Membership:</b> {$premiumBadge}\n"
              . "💾 <b>Storage Used:</b> " . htmlspecialchars($diskUsage ?: 'N/A') . "\n"
              . "📌 <b>Status:</b> {$statusBadge}\n\n"
              . "💡 <i>You can now start uploading videos using /upload or browse files with /files!</i>";
    }

    sendTelegramMessage($chatId, $text, buildMainMenuKeyboard($lang));
}

/**
 * Handle /myapis command and callback.
 */
function handleMyApis(array $user, int|string $chatId, ?int $messageId = null): void {
    $lang = $user['language'] ?: 'en';
    $apis = getUserApis($user['id']);

    if (empty($apis)) {
        $noApi = [
            'en' => "🔑 <b>Your Vidmoly APIs</b>\n\nNo Vidmoly API keys connected. Click below to add your first key:",
            'bn' => "🔑 <b>সংরক্ষিত Vidmoly API</b>\n\nকোনো API কী যুক্ত করা নেই। আপনার অ্যাকাউন্ট যুক্ত করতে নিচের বাটনে চাপুন:",
            'hi' => "🔑 <b>आपकी Vidmoly API</b>\n\nकोई API कुंजी नहीं जोड़ी गई है। पहली कुंजी जोड़ने के लिए नीचे क्लिक करें:"
        ];
        $btnText = ['en' => '➕ Add API Key', 'bn' => '➕ API যোগ করুন', 'hi' => '➕ API जोड़ें'];
        $keyboard = [
            'inline_keyboard' => [
                [['text' => $btnText[$lang] ?? $btnText['en'], 'callback_data' => 'api_add']],
                [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
            ]
        ];
        $text = $noApi[$lang] ?? $noApi['en'];
    } else {
        $headers = [
            'en' => "🔑 <b>Your Saved Vidmoly APIs (" . count($apis) . "/" . MAX_APIS_PER_USER . ")</b>\n💡 <i>Active (⭐) account receives all new uploads.</i>\n\n",
            'bn' => "🔑 <b>সংরক্ষিত Vidmoly API (" . count($apis) . "/" . MAX_APIS_PER_USER . ")</b>\n💡 <i>অ্যাক্টিভ (⭐) অ্যাকাউন্টে পরবর্তী সমস্ত আপলোড যুক্ত হবে।</i>\n\n",
            'hi' => "🔑 <b>सहेजी गई Vidmoly API (" . count($apis) . "/" . MAX_APIS_PER_USER . ")</b>\n💡 <i>सक्रिय (⭐) खाते में सभी नए अपलोड प्राप्त होंगे।</i>\n\n"
        ];
        $text = $headers[$lang] ?? $headers['en'];

        $i = 1;
        foreach ($apis as $api) {
            $decKey = decryptSecret($api['api_key']);
            $masked = maskApiKey($decKey);
            $account = htmlspecialchars($api['account_email'] ?: ($api['account_login'] ?: 'Unnamed'));
            $status = !empty($api['is_active']) ? '⭐ <b>ACTIVE</b>' : '⚪ Inactive';
            $balance = number_format((float)($api['balance'] ?? 0), 2);

            $text .= "<b>{$i}️⃣ {$account}</b>\n"
                   . "   🔑 <code>{$masked}</code>\n"
                   . "   📌 Status: {$status} | 💰 Bal: \${$balance}\n\n";
            $i++;
        }

        $btnLabels = [
            'en' => ['switch' => '🔄 Switch Active', 'add' => '➕ Add API', 'refresh' => '🔄 Refresh Info', 'del' => '🗑️ Remove API'],
            'bn' => ['switch' => '🔄 অ্যাক্টিভ পরিবর্তন', 'add' => '➕ API যোগ করুন', 'refresh' => '🔄 তথ্য রিফ্রেশ', 'del' => '🗑️ API মুছুন'],
            'hi' => ['switch' => '🔄 सक्रिय बदलें', 'add' => '➕ API जोड़ें', 'refresh' => '🔄 जानकारी ताज़ा करें', 'del' => '🗑️ API हटाएं']
        ];
        $bl = $btnLabels[$lang] ?? $btnLabels['en'];

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $bl['switch'], 'callback_data' => 'api_switch'],
                    ['text' => $bl['add'], 'callback_data' => 'api_add']
                ],
                [
                    ['text' => $bl['refresh'], 'callback_data' => 'api_refresh'],
                    ['text' => $bl['del'], 'callback_data' => 'api_remove']
                ],
                [
                    ['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']
                ]
            ]
        ];
    }

    if ($messageId !== null) {
        editTelegramMessage($chatId, $messageId, $text, $keyboard);
    } else {
        sendTelegramMessage($chatId, $text, $keyboard);
    }
}

/**
 * Handle /switchapi command and callback.
 */
function handleSwitchApi(array $user, int|string $chatId, ?int $messageId = null): void {
    $lang = $user['language'] ?: 'en';
    $apis = getUserApis($user['id']);

    if (empty($apis)) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $buttons = [];
    foreach ($apis as $index => $api) {
        $title = ($index + 1) . '. ' . ($api['account_email'] ?: ($api['account_login'] ?: 'API #' . $api['id']));
        if (!empty($api['is_active'])) {
            $title .= ' ⭐ (Active)';
        }
        $buttons[] = [['text' => $title, 'callback_data' => 'set_active_' . $api['id']]];
    }
    $buttons[] = [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_apis']];

    $prompts = [
        'en' => "🔄 <b>Switch Active Vidmoly Account</b>\n\nSelect the account you want to make active for new uploads and file actions:",
        'bn' => "🔄 <b>অ্যাক্টিভ Vidmoly অ্যাকাউন্ট পরিবর্তন করুন</b>\n\nনতুন আপলোড ও ফাইল ব্যবহারের জন্য কোন অ্যাকাউন্টটি সক্রিয় করতে চান তা নির্বাচন করুন:",
        'hi' => "🔄 <b>सक्रिय Vidmoly खाता बदलें</b>\n\nनए अपलोड और फाइल संचालन के लिए कौन सा खाता सक्रिय करना चाहते हैं चुनें:"
    ];

    $keyboard = ['inline_keyboard' => $buttons];
    $text = $prompts[$lang] ?? $prompts['en'];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /removeapi command and callback.
 */
function handleRemoveApi(array $user, int|string $chatId, ?int $messageId = null): void {
    $lang = $user['language'] ?: 'en';
    $apis = getUserApis($user['id']);

    if (empty($apis)) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $buttons = [];
    foreach ($apis as $index => $api) {
        $title = '🗑️ ' . ($index + 1) . '. ' . ($api['account_email'] ?: ($api['account_login'] ?: 'API #' . $api['id']));
        $buttons[] = [['text' => $title, 'callback_data' => 'confirm_del_' . $api['id']]];
    }
    $buttons[] = [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_apis']];

    $prompts = [
        'en' => "🗑️ <b>Remove API Key — User Guide</b>\n\nChoose an API key to remove from the bot. <i>(Your videos on Vidmoly servers remain completely safe)</i>:",
        'bn' => "🗑️ <b>API কী মুছে ফেলুন — ইউজার গাইড</b>\n\nবট থেকে যে API মুছে ফেলতে চান সেটি নির্বাচন করুন। <i>(Vidmoly সার্ভারে আপনার ভিডিও সম্পূর্ণ সুরক্ষিত থাকবে)</i>:",
        'hi' => "🗑️ <b>API कुंजी हटाएं — गाइड</b>\n\nबॉट से हटाने के लिए API कुंजी चुनें। <i>(Vidmoly सर्वर पर आपके वीडियो सुरक्षित रहेंगे)</i>:"
    ];

    $keyboard = ['inline_keyboard' => $buttons];
    $text = $prompts[$lang] ?? $prompts['en'];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /account command.
 */
function handleAccountCommand(array $user, int|string $chatId, ?int $messageId = null, bool $forceRefresh = false): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);

    if (!$activeApi) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $res = vidmolyRequest('/api/account/info', [], 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        $err = [
            'en' => "❌ <b>Account Fetch Failed:</b> " . htmlspecialchars($res['message'] ?: 'Connection failure'),
            'bn' => "❌ <b>অ্যাকাউন্ট তথ্য আনতে ব্যর্থ:</b> " . htmlspecialchars($res['message'] ?: 'সার্ভার সংযোগ সমস্যা'),
            'hi' => "❌ <b>खाता लोड करने में विफल:</b> " . htmlspecialchars($res['message'] ?: 'सर्वर कनेक्शन त्रुटि')
        ];
        $keyboard = buildBackToMenuKeyboard($lang);
        if ($messageId) editTelegramMessage($chatId, $messageId, $err[$lang] ?? $err['en'], $keyboard);
        else sendTelegramMessage($chatId, $err[$lang] ?? $err['en'], $keyboard);
        return;
    }

    $result = $res['result'] ?? [];
    $email = $result['email'] ?? $activeApi['account_email'] ?? 'N/A';
    $login = $result['login'] ?? $activeApi['account_login'] ?? 'N/A';
    $balance = isset($result['balance']) ? (float)$result['balance'] : (float)$activeApi['balance'];
    $premium = !empty($result['premium']);
    $diskUsage = $result['storage_used'] ?? $result['disk_usage'] ?? $activeApi['disk_usage'] ?? 'N/A';

    // Update cache in Firebase
    firebaseRequest("vidmoly_apis/{$user['id']}/{$activeApi['id']}", 'PATCH', [
        'account_email' => $email,
        'account_login' => $login,
        'balance'       => $balance,
        'premium'       => $premium ? 1 : 0,
        'disk_usage'    => $diskUsage,
        'updated_at'    => date('Y-m-d H:i:s')
    ]);

    $masked = maskApiKey($activeApi['decrypted_key']);
    $premiumStatus = $premium ? "💎 Premium (Active)" : "⚪ Free Tier";

    if ($lang === 'bn') {
        $text = "👤 <b>Vidmoly অ্যাকাউন্ট বিবরণ</b>\n\n"
              . "📧 <b>ইমেইল:</b> " . htmlspecialchars((string)$email) . "\n"
              . "👤 <b>ইউজারনেম:</b> " . htmlspecialchars((string)$login) . "\n"
              . "🔑 <b>সক্রিয় কী:</b> <code>{$masked}</code>\n"
              . "💰 <b>বর্তমান ব্যালেন্স:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>মেম্বারশিপ:</b> {$premiumStatus}\n"
              . "💾 <b>স্টোরেজ ব্যবহার:</b> " . htmlspecialchars((string)$diskUsage) . "\n\n"
              . "🕒 <i>হালনাগাদ: " . date('Y-m-d H:i:s') . " UTC</i>";
    } elseif ($lang === 'hi') {
        $text = "👤 <b>Vidmoly खाता विवरण</b>\n\n"
              . "📧 <b>ईमेल:</b> " . htmlspecialchars((string)$email) . "\n"
              . "👤 <b>उपयोगकर्ता नाम:</b> " . htmlspecialchars((string)$login) . "\n"
              . "🔑 <b>सक्रिय कुंजी:</b> <code>{$masked}</code>\n"
              . "💰 <b>वर्तमान बैलेंस:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>सदस्यता:</b> {$premiumStatus}\n"
              . "💾 <b>स्टोरेज उपयोग:</b> " . htmlspecialchars((string)$diskUsage) . "\n\n"
              . "🕒 <i>अद्यतन: " . date('Y-m-d H:i:s') . " UTC</i>";
    } else {
        $text = "👤 <b>Vidmoly Account Information</b>\n\n"
              . "📧 <b>Email:</b> " . htmlspecialchars((string)$email) . "\n"
              . "👤 <b>Username:</b> " . htmlspecialchars((string)$login) . "\n"
              . "🔑 <b>Active Key:</b> <code>{$masked}</code>\n"
              . "💰 <b>Current Balance:</b> $" . number_format($balance, 2) . "\n"
              . "💎 <b>Membership Tier:</b> {$premiumStatus}\n"
              . "💾 <b>Storage Used:</b> " . htmlspecialchars((string)$diskUsage) . "\n\n"
              . "🕒 <i>Updated: " . date('Y-m-d H:i:s') . " UTC</i>";
    }

    $refBtn = ['en' => '🔄 Refresh Data', 'bn' => '🔄 রিফ্রেশ করুন', 'hi' => '🔄 ताज़ा करें'];
    $statBtn = ['en' => '📊 View Statistics', 'bn' => '📊 পরিসংখ্যান দেখুন', 'hi' => '📊 आंकड़े देखें'];

    $keyboard = [
        'inline_keyboard' => [
            [['text' => $refBtn[$lang] ?? $refBtn['en'], 'callback_data' => 'menu_account']],
            [['text' => $statBtn[$lang] ?? $statBtn['en'], 'callback_data' => 'menu_stats']],
            [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
        ]
    ];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /stats command.
 */
function handleStatsCommand(array $user, int|string $chatId, ?int $messageId = null, int $days = 7): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);

    if (!$activeApi) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $res = vidmolyRequest('/api/account/stats', ['last' => $days], 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        $text = "❌ <b>Statistics Error:</b> " . htmlspecialchars($res['message'] ?: 'Failed to fetch data');
        $keyboard = buildBackToMenuKeyboard($lang);
        if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
        else sendTelegramMessage($chatId, $text, $keyboard);
        return;
    }

    $result = $res['result'] ?? [];
    $views = 0;
    $downloads = 0;
    $earnings = 0.0;

    if (is_array($result)) {
        if (isset($result[0]) && is_array($result[0])) {
            foreach ($result as $dayStat) {
                $views += (int)($dayStat['views'] ?? 0);
                $downloads += (int)($dayStat['downloads'] ?? 0);
                $earnings += (float)($dayStat['profit'] ?? $dayStat['earnings'] ?? 0);
            }
        } else {
            $views = (int)($result['views'] ?? 0);
            $downloads = (int)($result['downloads'] ?? 0);
            $earnings = (float)($result['profit'] ?? $result['earnings'] ?? 0);
        }
    }

    $account = htmlspecialchars($activeApi['account_email'] ?: ($activeApi['account_login'] ?: 'Active Account'));

    if ($lang === 'bn') {
        $text = "📊 <b>Vidmoly পারফরম্যান্স পরিসংখ্যান</b>\n\n"
              . "👤 <b>অ্যাকাউন্ট:</b> {$account}\n"
              . "📅 <b>সময়কাল:</b> বিগত {$days} দিন\n\n"
              . "👁 <b>মোট ভিউ:</b> " . number_format($views) . "\n"
              . "⬇️ <b>মোট ডাউনলোড:</b> " . number_format($downloads) . "\n"
              . "💰 <b>আনুমানিক আয়:</b> $" . number_format($earnings, 4);
    } elseif ($lang === 'hi') {
        $text = "📊 <b>Vidmoly प्रदर्शन आंकड़े</b>\n\n"
              . "👤 <b>खाता:</b> {$account}\n"
              . "📅 <b>अवधि:</b> पिछले {$days} दिन\n\n"
              . "👁 <b>कुल व्यूज:</b> " . number_format($views) . "\n"
              . "⬇️ <b>कुल डाउनलोड:</b> " . number_format($downloads) . "\n"
              . "💰 <b>अनुमानित कमाई:</b> $" . number_format($earnings, 4);
    } else {
        $text = "📊 <b>Vidmoly Performance Statistics</b>\n\n"
              . "👤 <b>Account:</b> {$account}\n"
              . "📅 <b>Timeframe:</b> Last {$days} Days\n\n"
              . "👁 <b>Total Views:</b> " . number_format($views) . "\n"
              . "⬇️ <b>Total Downloads:</b> " . number_format($downloads) . "\n"
              . "💰 <b>Estimated Earnings:</b> $" . number_format($earnings, 4);
    }

    $d7 = ($days === 7) ? '• 7 Days •' : '7 Days';
    $d30 = ($days === 30) ? '• 30 Days •' : '30 Days';

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $d7, 'callback_data' => 'stats_7'],
                ['text' => $d30, 'callback_data' => 'stats_30']
            ],
            [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
        ]
    ];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /upload command.
 */
function handleUploadCommand(array $user, int|string $chatId): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        handleMyApis($user, $chatId);
        return;
    }

    setUserState($user['id'], 'waiting_remote_url');

    if ($lang === 'bn') {
        $text = "🌐 <b>রিমোট ইউআরএল আপলোড — ইউজার গাইড</b>\n\n"
              . "<b>যেভাবে আপলোড করবেন:</b>\n"
              . "1. যেকোনো সরাসরি ডাউনলোডযোগ্য ভিডিওর লিংক কপি করুন।\n"
              . "2. সমর্থিত প্রোটোকল: <code>http://</code> বা <code>https://</code>\n"
              . "3. সমর্থিত ফরম্যাট: <code>.mp4</code>, <code>.mkv</code>, <code>.webm</code>, <code>.avi</code>\n"
              . "4. উদাহরণ লিংক:\n"
              . "   <code>https://example.com/videos/movie.mp4</code>\n\n"
              . "👉 <b>এখন সরাসরি ভিডিও লিংকটি মেসেজ হিসেবে পাঠান:</b>\n"
              . "<i>(বাতিল করতে /cancel লিখুন)</i>";
    } elseif ($lang === 'hi') {
        $text = "🌐 <b>रिमोट यूआरएल अपलोड — गाइड</b>\n\n"
              . "<b>अपलोड कैसे करें:</b>\n"
              . "1. किसी भी डायरेक्ट वीडियो डाउनलोड लिंक को कॉपी करें।\n"
              . "2. समर्थित प्रोटोकॉल: <code>http://</code> या <code>https://</code>\n"
              . "3. समर्थित प्रारूप: <code>.mp4</code>, <code>.mkv</code>, <code>.webm</code>\n"
              . "4. उदाहरण लिंक:\n"
              . "   <code>https://example.com/videos/movie.mp4</code>\n\n"
              . "👉 <b>अब सीधा वीडियो यूआरएल भेजें:</b>\n"
              . "<i>(रद्द करने के लिए /cancel टाइप करें)</i>";
    } else {
        $text = "🌐 <b>Remote URL Upload — User Guide</b>\n\n"
              . "<b>How Remote Upload works:</b>\n"
              . "1. Obtain a direct public video link.\n"
              . "2. Supported protocols: <code>http://</code> or <code>https://</code>\n"
              . "3. Supported formats: <code>.mp4</code>, <code>.mkv</code>, <code>.webm</code>, <code>.avi</code>\n"
              . "4. Example link format:\n"
              . "   <code>https://example.com/videos/sample.mp4</code>\n\n"
              . "👉 <b>Please send the video URL now:</b>\n"
              . "<i>(Type /cancel to abort at any time)</i>";
    }

    $cancelBtn = ['en' => '❌ Cancel', 'bn' => '❌ বাতিল', 'hi' => '❌ रद्द करें'];
    $keyboard = [
        'inline_keyboard' => [
            [['text' => $cancelBtn[$lang] ?? $cancelBtn['en'], 'callback_data' => 'menu_main']]
        ]
    ];
    sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Process remote upload URL input.
 */
function handleProcessRemoteUrl(array $user, int|string $chatId, string $url): void {
    $lang = $user['language'] ?: 'en';
    $url = trim($url);

    if (!isSafeRemoteUrl($url)) {
        $bad = [
            'en' => "❌ <b>Invalid or Disallowed URL</b>\nBlocked by SSRF protection. Please provide a public HTTP/HTTPS direct video link.",
            'bn' => "❌ <b>অবৈধ বা অনিরাপদ লিঙ্ক</b>\nSSRF সুরক্ষার কারণে লিঙ্কটি ব্লক করা হয়েছে। অনুগ্রহ করে একটি পাবলিক সরাসরি ভিডিও লিংক দিন।",
            'hi' => "❌ <b>अमान्य या अस्वीकृत यूआरएल</b>\nSSRF सुरक्षा द्वारा ब्लॉक किया गया। कृपया एक सार्वजनिक वीडियो लिंक प्रदान करें।"
        ];
        sendTelegramMessage($chatId, $bad[$lang] ?? $bad['en']);
        return;
    }

    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        clearUserState($user['id']);
        sendTelegramMessage($chatId, "⚠️ No active Vidmoly API found. Please use /addapi first.");
        return;
    }

    sendChatAction($chatId, 'upload_video');
    $subMsg = [
        'en' => "⏳ <b>Submitting Remote Upload to Vidmoly queue...</b>",
        'bn' => "⏳ <b>Vidmoly কিউতে রিমোট আপলোড জমা দেওয়া হচ্ছে...</b>",
        'hi' => "⏳ <b>Vidmoly कतार में रिमोट अपलोड सबमिट किया जा रहा है...</b>"
    ];
    sendTelegramMessage($chatId, $subMsg[$lang] ?? $subMsg['en']);

    $res = vidmolyRequest('/api/upload/url', ['url' => $url], 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        // Record failed upload in Firebase
        $uplId = 'upl_' . time() . '_' . rand(100, 999);
        firebaseRequest("uploads/{$uplId}", 'PUT', [
            'id'            => $uplId,
            'user_id'       => $user['id'],
            'api_id'        => $activeApi['id'],
            'status'        => 'failed',
            'source_type'   => 'url',
            'source_url'    => $url,
            'error_message' => $res['message'],
            'created_at'    => date('Y-m-d H:i:s')
        ]);

        clearUserState($user['id']);
        sendTelegramMessage($chatId, "❌ <b>Remote Upload Failed:</b> " . htmlspecialchars($res['message'] ?: 'Unknown error'), buildMainMenuKeyboard($lang));
        return;
    }

    $result = $res['result'] ?? [];
    $fileCode = $result['filecode'] ?? $result['file_code'] ?? null;

    // Record upload in Firebase
    $uplId = 'upl_' . time() . '_' . rand(100, 999);
    firebaseRequest("uploads/{$uplId}", 'PUT', [
        'id'          => $uplId,
        'user_id'     => $user['id'],
        'api_id'      => $activeApi['id'],
        'file_code'   => $fileCode,
        'status'      => 'processing',
        'source_type' => 'url',
        'source_url'  => $url,
        'created_at'  => date('Y-m-d H:i:s')
    ]);

    clearUserState($user['id']);

    $codeText = $fileCode ? "🆔 <b>File Code:</b> <code>{$fileCode}</code>\n" : "";

    if ($lang === 'bn') {
        $text = "✅ <b>রিমোট আপলোড সফলভাবে কিউতে যুক্ত হয়েছে!</b>\n\n"
              . "🔗 <b>লিঙ্ক:</b> <code>" . htmlspecialchars(substr($url, 0, 50)) . "...</code>\n"
              . $codeText
              . "⏳ Vidmoly ব্যাকগ্রাউন্ডে ভিডিও ডাউনলোড ও রূপান্তর করছে। সম্পন্ন হলে /files এ পাওয়া যাবে।";
    } elseif ($lang === 'hi') {
        $text = "✅ <b>रिमोट अपलोड सफलतापूर्वक कतार में जोड़ा गया!</b>\n\n"
              . "🔗 <b>लिंक:</b> <code>" . htmlspecialchars(substr($url, 0, 50)) . "...</code>\n"
              . $codeText
              . "⏳ Vidmoly पृष्ठभूमि में वीडियो डाउनलोड और प्रोसेस कर रहा है। पूर्ण होने पर /files में दिखाई देगा।";
    } else {
        $text = "✅ <b>Remote Upload Successfully Queued!</b>\n\n"
              . "🔗 <b>Source URL:</b> <code>" . htmlspecialchars(substr($url, 0, 50)) . "...</code>\n"
              . $codeText
              . "⏳ Vidmoly is downloading and processing the video in the background. Check /files once completed.";
    }

    $vfBtn = ['en' => '📁 View My Files', 'bn' => '📁 আমার ফাইলসমূহ', 'hi' => '📁 मेरी फाइलें'];
    $keyboard = [
        'inline_keyboard' => [
            [['text' => $vfBtn[$lang] ?? $vfBtn['en'], 'callback_data' => 'menu_files']],
            [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
        ]
    ];
    sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle incoming direct Telegram Video / Document.
 */
function handleDirectTelegramUpload(array $user, int|string $chatId, array $message): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        handleMyApis($user, $chatId);
        return;
    }

    $videoObj = $message['video'] ?? null;
    $docObj = $message['document'] ?? null;

    $fileId = $videoObj['file_id'] ?? $docObj['file_id'] ?? null;
    $uniqueId = $videoObj['file_unique_id'] ?? $docObj['file_unique_id'] ?? null;
    $fileName = $videoObj['file_name'] ?? $docObj['file_name'] ?? 'telegram_video_' . time() . '.mp4';
    $fileSize = $videoObj['file_size'] ?? $docObj['file_size'] ?? 0;

    if (!$fileId) {
        sendTelegramMessage($chatId, "❌ File could not be identified.");
        return;
    }

    sendChatAction($chatId, 'upload_video');
    sendTelegramMessage($chatId, "⏳ <b>Processing:</b> <code>" . htmlspecialchars($fileName) . "</code>\nContacting Vidmoly gateway...");

    $serverRes = vidmolyRequest('/api/upload/server', [], 'GET', $activeApi['decrypted_key']);

    if (!$serverRes['success']) {
        sendTelegramMessage($chatId, "❌ Vidmoly upload server unavailable: " . htmlspecialchars($serverRes['message'] ?: 'Failed'), buildMainMenuKeyboard($lang));
        return;
    }

    $uploadServerUrl = $serverRes['result'] ?? $serverRes['data']['result'] ?? null;

    if (empty($uploadServerUrl) || !is_string($uploadServerUrl)) {
        $notice = [
            'en' => "ℹ️ <b>Direct Upload Protocol Notice</b>\nVidmoly did not return a multipart push URL.\n💡 <b>Tip:</b> Please use /upload with a direct video URL for guaranteed high speed!",
            'bn' => "ℹ️ <b>সরাসরি আপলোড নোটিস</b>\nVidmoly সরাসরি ফাইল আপলোড গেটওয়ে দেয়নি।\n💡 <b>টিপ:</b> দ্রুততম ও নির্ভরযোগ্য আপলোডের জন্য /upload দিয়ে ডাইরেক্ট ভিডিও লিঙ্ক পাঠান!",
            'hi' => "ℹ️ <b>डायरेक्ट अपलोड सूचना</b>\nVidmoly ने डायरेक्ट अपलोड गेटवे नहीं दिया।\n💡 <b>सुझाव:</b> बेहतरीन गति के लिए कृपया /upload का उपयोग करके डायरेक्ट वीडियो लिंक भेजें!"
        ];
        sendTelegramMessage($chatId, $notice[$lang] ?? $notice['en'], buildMainMenuKeyboard($lang));
        return;
    }

    $tgFileRes = telegramRequest('getFile', ['file_id' => $fileId]);
    $filePath = $tgFileRes['result']['file_path'] ?? null;

    if (!$filePath) {
        sendTelegramMessage($chatId, "❌ File exceeds Telegram Bot 20MB limit. Use /upload for large files.");
        return;
    }

    $telegramDownloadUrl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $filePath;
    $remoteUploadRes = vidmolyRequest('/api/upload/url', ['url' => $telegramDownloadUrl], 'GET', $activeApi['decrypted_key']);

    if ($remoteUploadRes['success']) {
        $fileCode = $remoteUploadRes['result']['filecode'] ?? $remoteUploadRes['result']['file_code'] ?? null;

        $uplId = 'upl_' . time() . '_' . rand(100, 999);
        firebaseRequest("uploads/{$uplId}", 'PUT', [
            'id'                      => $uplId,
            'user_id'                 => $user['id'],
            'api_id'                  => $activeApi['id'],
            'telegram_file_id'        => $fileId,
            'telegram_file_unique_id' => $uniqueId,
            'original_name'           => $fileName,
            'file_code'               => $fileCode,
            'status'                  => 'processing',
            'source_type'             => 'telegram',
            'created_at'              => date('Y-m-d H:i:s')
        ]);

        $codeText = $fileCode ? "🆔 <b>File Code:</b> <code>{$fileCode}</code>\n" : "";
        $text = "✅ <b>Upload Initiated!</b>\n\n"
              . "🎬 <b>File:</b> " . htmlspecialchars($fileName) . "\n"
              . $codeText
              . "📊 <b>Size:</b> " . round($fileSize / (1024 * 1024), 2) . " MB\n\n"
              . "⏳ Vidmoly is importing the file. Check /files once finished.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📁 View Files', 'callback_data' => 'menu_files']],
                [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
            ]
        ];
        sendTelegramMessage($chatId, $text, $keyboard);
    } else {
        sendTelegramMessage($chatId, "❌ Upload error: " . htmlspecialchars($remoteUploadRes['message'] ?: 'Failed'), buildMainMenuKeyboard($lang));
    }
}

/**
 * Handle /files command and pagination.
 */
function handleFilesCommand(array $user, int|string $chatId, ?int $messageId = null, int $page = 1): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $params = ['page' => $page];
    $res = vidmolyRequest('/api/file/list', $params, 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        $text = "❌ <b>Failed to Fetch Files:</b> " . htmlspecialchars($res['message'] ?: 'Unknown error');
        $keyboard = buildBackToMenuKeyboard($lang);
        if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
        else sendTelegramMessage($chatId, $text, $keyboard);
        return;
    }

    $result = $res['result'] ?? [];
    $files = $result['files'] ?? (isset($result[0]) ? $result : []);

    if (empty($files)) {
        $empty = [
            'en' => "📁 <b>My Files (Empty)</b>\nYou do not have any uploaded videos in this account yet. Use /upload to add videos!",
            'bn' => "📁 <b>আমার ফাইলসমূহ (খালি)</b>\nএই অ্যাকাউন্টে এখনো কোনো ভিডিও আপলোড করা নেই। ভিডিও যোগ করতে /upload ব্যবহার করুন!",
            'hi' => "📁 <b>मेरी फाइलें (खाली)</b>\nइस खाते में अभी कोई वीडियो अपलोड नहीं है। वीडियो जोड़ने के लिए /upload का उपयोग करें!"
        ];
        $upBtn = ['en' => '📤 Upload Video', 'bn' => '📤 ভিডিও আপলোড', 'hi' => '📤 वीडियो अपलोड'];
        $keyboard = [
            'inline_keyboard' => [
                [['text' => $upBtn[$lang] ?? $upBtn['en'], 'callback_data' => 'menu_remote_upload']],
                [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
            ]
        ];
        if ($messageId) editTelegramMessage($chatId, $messageId, $empty[$lang] ?? $empty['en'], $keyboard);
        else sendTelegramMessage($chatId, $empty[$lang] ?? $empty['en'], $keyboard);
        return;
    }

    $text = "📁 <b>My Files (Page {$page})</b>\n";
    $text .= "👤 <b>Account:</b> " . htmlspecialchars($activeApi['account_email'] ?: ($activeApi['account_login'] ?: 'Active')) . "\n\n";

    $keyboardRows = [];

    $count = 1;
    foreach ($files as $file) {
        $title = $file['title'] ?? ($file['file_name'] ?? 'Untitled Video');
        $code = $file['file_code'] ?? ($file['filecode'] ?? '');
        $views = $file['views'] ?? 0;

        $text .= "<b>{$count}.</b> 🎬 <b>" . htmlspecialchars(substr($title, 0, 35)) . "</b>\n";
        $text .= "   🆔 Code: <code>{$code}</code> | 👁 Views: {$views}\n\n";

        if ($count <= 5 && !empty($code)) {
            $keyboardRows[] = [
                ['text' => "🔎 Info (" . substr($title, 0, 10) . ")", 'callback_data' => 'finfo_' . $code],
                ['text' => "✏️ Rename", 'callback_data' => 'frename_' . $code]
            ];
        }
        $count++;
    }

    $navButtons = [];
    if ($page > 1) {
        $navButtons[] = ['text' => '⬅️ Prev', 'callback_data' => 'files_p_' . ($page - 1)];
    }
    $navButtons[] = ['text' => "📄 {$page}", 'callback_data' => 'noop'];
    if (count($files) >= 10) {
        $navButtons[] = ['text' => 'Next ➡️', 'callback_data' => 'files_p_' . ($page + 1)];
    }
    $keyboardRows[] = $navButtons;
    $keyboardRows[] = [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']];

    $keyboard = ['inline_keyboard' => $keyboardRows];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /fileinfo command or button.
 */
function handleFileInfoCommand(array $user, int|string $chatId, ?string $fileCode = null): void {
    $lang = $user['language'] ?: 'en';
    if (empty($fileCode)) {
        setUserState($user['id'], 'waiting_file_code');
        $p = [
            'en' => "🔎 <b>File Information — User Guide</b>\n\nSend the <b>File Code</b> of the video you want to inspect (e.g. <code>abc123xyz</code>).\n<i>(Find codes using /files or in vidmoly.me/w/CODE link)</i>\nType /cancel to abort.",
            'bn' => "🔎 <b>ফাইল তথ্য — ইউজার গাইড</b>\n\nযে ভিডিওর তথ্য দেখতে চান তার <b>File Code</b> লিখে পাঠান (যেমন: <code>abc123xyz</code>)।\n<i>(কোড দেখতে /files কমান্ড দিন)</i>\nবাতিল করতে /cancel লিখুন।",
            'hi' => "🔎 <b>फाइल विवरण — गाइड</b>\n\nजिस वीडियो की जानकारी देखना चाहते हैं उसका <b>File Code</b> भेजें (उदा: <code>abc123xyz</code>)।\n<i>(कोड देखने के लिए /files का उपयोग करें)</i>\nरद्द करने के लिए /cancel टाइप करें।"
        ];
        $cBtn = ['en' => '❌ Cancel', 'bn' => '❌ বাতিল', 'hi' => '❌ रद्द करें'];
        $keyboard = [
            'inline_keyboard' => [
                [['text' => $cBtn[$lang] ?? $cBtn['en'], 'callback_data' => 'menu_main']]
            ]
        ];
        sendTelegramMessage($chatId, $p[$lang] ?? $p['en'], $keyboard);
        return;
    }

    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        sendTelegramMessage($chatId, "⚠️ No active Vidmoly API connected.");
        return;
    }

    sendChatAction($chatId, 'typing');

    $codes = array_map('trim', explode(',', $fileCode));
    $firstCode = $codes[0];

    $res = vidmolyRequest('/api/file/info', ['file_code' => $firstCode], 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        sendTelegramMessage($chatId, "❌ <b>File Not Found:</b> " . htmlspecialchars($res['message'] ?: 'Invalid code'));
        return;
    }

    $result = $res['result'] ?? [];
    if (isset($result[0]) && is_array($result[0])) {
        $result = $result[0];
    }

    $title = $result['title'] ?? 'Untitled';
    $code = $result['file_code'] ?? $firstCode;
    $status = $result['status'] ?? 'Active';
    $size = isset($result['size']) ? round($result['size'] / (1024 * 1024), 2) . ' MB' : 'N/A';
    $views = $result['views'] ?? 0;
    $uploaded = $result['uploaded'] ?? $result['created'] ?? 'N/A';
    $watchUrl = "https://vidmoly.me/w/" . $code;

    $text = "🎬 <b>Video File Details</b>\n\n"
          . "📌 <b>Title:</b> " . htmlspecialchars($title) . "\n"
          . "🆔 <b>File Code:</b> <code>{$code}</code>\n"
          . "📊 <b>Status:</b> " . htmlspecialchars($status) . "\n"
          . "💾 <b>Size:</b> {$size}\n"
          . "👁 <b>Views:</b> " . number_format($views) . "\n"
          . "📅 <b>Uploaded:</b> " . htmlspecialchars($uploaded) . "\n\n"
          . "📺 <b>Watch URL:</b> <a href=\"{$watchUrl}\">{$watchUrl}</a>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '▶️ Open in Browser', 'url' => $watchUrl],
                ['text' => '✏️ Rename Title', 'callback_data' => 'frename_' . $code]
            ],
            [['text' => '📁 Back to Files', 'callback_data' => 'menu_files']]
        ]
    ];

    sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /rename command.
 */
function handleRenameCommand(array $user, int|string $chatId): void {
    $lang = $user['language'] ?: 'en';
    setUserState($user['id'], 'waiting_rename_code');
    $p = [
        'en' => "✏️ <b>Rename Video — User Guide</b>\n\nStep 1: Send the <b>File Code</b> of the video you wish to rename.\nType /cancel to abort.",
        'bn' => "✏️ <b>ভিডিও রিনেম — ইউজার গাইড</b>\n\nধাপ ১: যে ভিডিওর নাম পরিবর্তন করতে চান তার <b>File Code</b> পাঠান।\nবাতিল করতে /cancel লিখুন।",
        'hi' => "✏️ <b>वीडियो नाम बदलें — गाइड</b>\n\nचरण 1: जिस वीडियो का नाम बदलना चाहते हैं उसका <b>File Code</b> भेजें।\nरद्द करने के लिए /cancel टाइप करें।"
    ];
    $cBtn = ['en' => '❌ Cancel', 'bn' => '❌ বাতিল', 'hi' => '❌ रद्द करें'];
    sendTelegramMessage($chatId, $p[$lang] ?? $p['en'], [
        'inline_keyboard' => [
            [['text' => $cBtn[$lang] ?? $cBtn['en'], 'callback_data' => 'menu_main']]
        ]
    ]);
}

/**
 * Handle /folders command.
 */
function handleFoldersCommand(array $user, int|string $chatId, ?int $messageId = null): void {
    $lang = $user['language'] ?: 'en';
    $activeApi = getActiveApi($user['id']);
    if (!$activeApi) {
        handleMyApis($user, $chatId, $messageId);
        return;
    }

    $res = vidmolyRequest('/api/folder/list', [], 'GET', $activeApi['decrypted_key']);

    if (!$res['success']) {
        $text = "❌ <b>Failed to Fetch Folders:</b> " . htmlspecialchars($res['message'] ?: 'Error');
        $keyboard = buildBackToMenuKeyboard($lang);
        if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
        else sendTelegramMessage($chatId, $text, $keyboard);
        return;
    }

    $folders = $res['result']['folders'] ?? (isset($res['result'][0]) ? $res['result'] : []);

    $text = "📂 <b>Your Vidmoly Folders</b>\n\n";
    if (empty($folders)) {
        $text .= "<i>No folders created yet. Click below to create your first folder!</i>";
    } else {
        foreach ($folders as $f) {
            $name = $f['name'] ?? 'Unnamed';
            $id = $f['fld_id'] ?? $f['id'] ?? '';
            $text .= "📁 <b>" . htmlspecialchars($name) . "</b> (ID: <code>{$id}</code>)\n";
        }
    }

    $addBtn = ['en' => '➕ Create New Folder', 'bn' => '➕ নতুন ফোল্ডার তৈরি করুন', 'hi' => '➕ नया फ़ोल्डर बनाएं'];
    $keyboard = [
        'inline_keyboard' => [
            [['text' => $addBtn[$lang] ?? $addBtn['en'], 'callback_data' => 'folder_create']],
            [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
        ]
    ];

    if ($messageId) editTelegramMessage($chatId, $messageId, $text, $keyboard);
    else sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /addfolder command.
 */
function handleAddFolderCommand(array $user, int|string $chatId): void {
    $lang = $user['language'] ?: 'en';
    setUserState($user['id'], 'waiting_folder_name');
    $p = [
        'en' => "📂 <b>Create New Folder</b>\n\nEnter the name for your new folder (e.g. <code>Movies</code>, <code>Anime</code>):\nType /cancel to abort.",
        'bn' => "📂 <b>নতুন ফোল্ডার তৈরি</b>\n\nআপনার নতুন ফোল্ডারের নাম পাঠান (যেমন: <code>Movies</code> বা <code>Anime</code>):\nবাতিল করতে /cancel লিখুন।",
        'hi' => "📂 <b>नया फ़ोल्डर बनाएं</b>\n\nअपने नए फ़ोल्डर का नाम भेजें (उदा: <code>Movies</code>, <code>Anime</code>):\nरद्द करने के लिए /cancel टाइप करें।"
    ];
    $cBtn = ['en' => '❌ Cancel', 'bn' => '❌ বাতিল', 'hi' => '❌ रद्द करें'];
    sendTelegramMessage($chatId, $p[$lang] ?? $p['en'], [
        'inline_keyboard' => [
            [['text' => $cBtn[$lang] ?? $cBtn['en'], 'callback_data' => 'menu_main']]
        ]
    ]);
}

// ----------------------------------------------------------------------------
// 10. ADMIN HANDLERS & FORCE SUBSCRIBE CONFIGURATION
// ----------------------------------------------------------------------------

/**
 * Handle /admin command.
 */
function handleAdminCommand(array $user, int|string $chatId): void {
    if (!isTelegramAdmin($user['telegram_user_id'])) {
        sendTelegramMessage($chatId, "⛔ <b>Access Denied:</b> This command is restricted to administrators.");
        return;
    }

    $allUsers = firebaseRequest("users", 'GET');
    $totalUsers = is_array($allUsers) ? count($allUsers) : 0;

    $allApis = firebaseRequest("vidmoly_apis", 'GET');
    $totalApis = 0;
    if (is_array($allApis)) {
        foreach ($allApis as $uApis) {
            if (is_array($uApis)) $totalApis += count($uApis);
        }
    }

    $allUploads = firebaseRequest("uploads", 'GET');
    $totalUploads = is_array($allUploads) ? count($allUploads) : 0;
    $failedUploads = 0;
    if (is_array($allUploads)) {
        foreach ($allUploads as $upl) {
            if (is_array($upl) && ($upl['status'] ?? '') === 'failed') {
                $failedUploads++;
            }
        }
    }

    $mMode = getBotSetting('maintenance_mode', '0') === '1' ? '🔴 ACTIVE' : '🟢 OFF';
    $fSub = getBotSetting('force_sub_channel', '');
    $fSubDisplay = ($fSub !== '') ? "📢 <code>{$fSub}</code>" : "⚪ None (Disabled)";

    $text = "👑 <b>Vidmoly Bot — Admin Control Dashboard (Firebase RTDB)</b>\n\n"
          . "👥 <b>Total Users:</b> {$totalUsers}\n"
          . "🔑 <b>Linked APIs:</b> {$totalApis}\n"
          . "📤 <b>Total Uploads:</b> {$totalUploads}\n"
          . "❌ <b>Failed Uploads:</b> {$failedUploads}\n"
          . "🛠️ <b>Maintenance:</b> {$mMode}\n"
          . "📢 <b>Force Subscribe:</b> {$fSubDisplay}\n\n"
          . "📖 <b>Admin Command Guide:</b>\n"
          . "• /forcesub &lt;@channel&gt; — <i>Set required channel (or /forcesub off).</i>\n"
          . "• /broadcast — <i>Send message to all users.</i>\n"
          . "• /block &lt;id&gt; — <i>Restrict user.</i>\n"
          . "• /unblock &lt;id&gt; — <i>Unblock user.</i>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📢 Configure Force Sub', 'callback_data' => 'admin_forcesub_menu']],
            [['text' => '📢 Broadcast Message', 'callback_data' => 'admin_broadcast']],
            [['text' => '🛠️ Toggle Maintenance Mode', 'callback_data' => 'admin_toggle_maint']],
            [['text' => '🔙 Main Menu', 'callback_data' => 'menu_main']]
        ]
    ];

    sendTelegramMessage($chatId, $text, $keyboard);
}

/**
 * Handle /forcesub command.
 */
function handleForceSubCommand(array $user, int|string $chatId, string $args): void {
    if (!isTelegramAdmin($user['telegram_user_id'])) return;

    $channel = trim($args);
    if ($channel === '') {
        $current = getBotSetting('force_sub_channel', '');
        $status = ($current !== '') ? "Current: <code>{$current}</code>" : "Currently disabled.";
        $text = "📢 <b>Force Subscribe Configuration</b>\n\n{$status}\n\n"
              . "<b>Usage:</b>\n"
              . "• <code>/forcesub @ChannelUsername</code> — Require users to join this channel.\n"
              . "• <code>/forcesub off</code> — Disable force subscribe requirement.\n\n"
              . "<i>⚠️ Note: The bot must be added as an Administrator in the channel to check user membership!</i>";
        sendTelegramMessage($chatId, $text);
        return;
    }

    if (strtolower($channel) === 'off' || strtolower($channel) === 'none' || strtolower($channel) === 'disable') {
        setBotSetting('force_sub_channel', '');
        sendTelegramMessage($chatId, "✅ <b>Force Subscribe Disabled!</b> Users can now use the bot without subscribing to any channel.");
        return;
    }

    if (!str_starts_with($channel, '@') && !is_numeric($channel)) {
        $channel = '@' . $channel;
    }

    setBotSetting('force_sub_channel', $channel);
    sendTelegramMessage($chatId, "✅ <b>Force Subscribe Channel Updated!</b>\n\nRequired Channel: <code>{$channel}</code>\nUsers must now subscribe to this channel before accessing bot features.\n\n<i>⚠️ Ensure this bot is an Admin in {$channel}.</i>");
}

/**
 * Handle /broadcast command.
 */
function handleBroadcastCommand(array $user, int|string $chatId): void {
    if (!isTelegramAdmin($user['telegram_user_id'])) return;

    setUserState($user['id'], 'waiting_broadcast_msg');
    $text = "📢 <b>Broadcast Announcement — Admin Guide</b>\n\n"
          . "Please send the announcement message you want to send to all registered users.\n"
          . "HTML formatting is fully supported.\n\n"
          . "Type /cancel to abort.";

    sendTelegramMessage($chatId, $text, [
        'inline_keyboard' => [
            [['text' => '❌ Cancel', 'callback_data' => 'menu_main']]
        ]
    ]);
}

/**
 * Broadcast message executor with rate limiting.
 */
function executeBroadcast(array $user, int|string $adminChatId, string $messageText): void {
    clearUserState($user['id']);

    $allUsers = firebaseRequest("users", 'GET');
    $users = [];
    if (is_array($allUsers)) {
        foreach ($allUsers as $u) {
            if (is_array($u) && empty($u['is_blocked']) && !empty($u['telegram_user_id'])) {
                $users[] = (int)$u['telegram_user_id'];
            }
        }
    }

    $total = count($users);
    $sent = 0;
    $failed = 0;

    sendTelegramMessage($adminChatId, "⏳ Broadcasting announcement to {$total} users...");

    foreach ($users as $recipientTgId) {
        $res = sendTelegramMessage($recipientTgId, $messageText);
        if ($res && !empty($res['ok'])) {
            $sent++;
        } else {
            $failed++;
        }
        usleep(35000);
    }

    $summary = "📢 <b>Broadcast Finished!</b>\n\n"
             . "✅ <b>Successfully Sent:</b> {$sent}\n"
             . "❌ <b>Failed / Blocked:</b> {$failed}\n"
             . "👥 <b>Total Target:</b> {$total}";
    sendTelegramMessage($adminChatId, $summary, buildBackToMenuKeyboard());
}

/**
 * Handle /block command.
 */
function handleBlockUserCommand(array $user, int|string $chatId, string $args): void {
    if (!isTelegramAdmin($user['telegram_user_id'])) return;

    $targetTgId = (int)trim($args);
    if ($targetTgId <= 0) {
        sendTelegramMessage($chatId, "⚠️ Usage: <code>/block 123456789</code>");
        return;
    }

    firebaseRequest("users/{$targetTgId}", 'PATCH', [
        'is_blocked' => 1,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    sendTelegramMessage($chatId, "✅ User <code>{$targetTgId}</code> has been restricted.");
}

/**
 * Handle /unblock command.
 */
function handleUnblockUserCommand(array $user, int|string $chatId, string $args): void {
    if (!isTelegramAdmin($user['telegram_user_id'])) return;

    $targetTgId = (int)trim($args);
    if ($targetTgId <= 0) {
        sendTelegramMessage($chatId, "⚠️ Usage: <code>/unblock 123456789</code>");
        return;
    }

    firebaseRequest("users/{$targetTgId}", 'PATCH', [
        'is_blocked' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    sendTelegramMessage($chatId, "✅ User <code>{$targetTgId}</code> access restored.");
}

// ----------------------------------------------------------------------------
// 11. CALLBACK QUERY ROUTER
// ----------------------------------------------------------------------------

function handleCallbackQuery(array $callbackQuery): void {
    $callbackId = $callbackQuery['id'];
    $from = $callbackQuery['from'];
    $message = $callbackQuery['message'] ?? null;
    $data = $callbackQuery['data'] ?? '';

    $chatId = $message['chat']['id'] ?? $from['id'];
    $messageId = $message['message_id'] ?? null;

    $user = getOrCreateUser($from);
    if (!$user) {
        answerCallbackQuery($callbackId, "User error", true);
        return;
    }

    if (!empty($user['is_blocked'])) {
        answerCallbackQuery($callbackId, "Access Restricted", true);
        return;
    }

    $lang = $user['language'] ?: 'en';

    // Handle Language Selection Callbacks
    if (str_starts_with($data, 'set_lang_')) {
        $newLang = substr($data, 9);
        setUserLanguage($user['id'], $newLang);
        $user['language'] = $newLang;
        clearUserState($user['id']);

        $confs = [
            'en' => '✅ Language set to English!',
            'bn' => '✅ ভাষা সফলভাবে বাংলায় পরিবর্তন করা হয়েছে!',
            'hi' => '✅ भाषा सफलतापूर्वक हिन्दी में बदल दी गई है!'
        ];
        answerCallbackQuery($callbackId, $confs[$newLang] ?? $confs['en']);
        handleStartCommand($user, $chatId);
        return;
    }

    // Handle Force Subscribe Verification Button
    if ($data === 'check_force_sub') {
        if (checkUserSubscription($user['telegram_user_id'])) {
            answerCallbackQuery($callbackId, "✅ Membership verified!");
            handleStartCommand($user, $chatId);
        } else {
            answerCallbackQuery($callbackId, "❌ You have not joined the channel yet. Please join first!", true);
        }
        return;
    }

    // Check Force Subscribe for all other actions
    if (!checkUserSubscription($user['telegram_user_id'])) {
        answerCallbackQuery($callbackId, "Please join our channel first!", true);
        sendForceSubscribeNotice($chatId, $lang);
        return;
    }

    // Check Maintenance mode
    $isMaint = getBotSetting('maintenance_mode', '0') === '1';
    if ($isMaint && !isTelegramAdmin($user['telegram_user_id'])) {
        answerCallbackQuery($callbackId, "🛠️ Bot is under maintenance.", true);
        return;
    }

    answerCallbackQuery($callbackId);

    // Routing
    switch (true) {
        case $data === 'menu_main':
            clearUserState($user['id']);
            handleStartCommand($user, $chatId);
            break;

        case $data === 'menu_language':
            handleLanguageSelectionPrompt($chatId);
            break;

        case $data === 'menu_upload' || $data === 'menu_remote_upload':
            handleUploadCommand($user, $chatId);
            break;

        case $data === 'menu_apis':
            handleMyApis($user, $chatId, $messageId);
            break;

        case $data === 'api_add':
            handleAddApiCommand($user, $chatId);
            break;

        case $data === 'api_switch':
            handleSwitchApi($user, $chatId, $messageId);
            break;

        case $data === 'api_remove':
            handleRemoveApi($user, $chatId, $messageId);
            break;

        case $data === 'api_refresh':
            handleAccountCommand($user, $chatId, $messageId, true);
            break;

        case str_starts_with($data, 'set_active_'):
            $apiId = substr($data, 11);
            if (switchActiveApi($user['id'], $apiId)) {
                $swMsg = [
                    'en' => "✅ <b>Active API Switched Successfully!</b>\nAll subsequent uploads are now linked to this account.",
                    'bn' => "✅ <b>অ্যাক্টিভ API সফলভাবে পরিবর্তন করা হয়েছে!</b>\nপরবর্তী সমস্ত আপলোড এই অ্যাকাউন্টে সংরক্ষিত হবে।",
                    'hi' => "✅ <b>सक्रिय API सफलतापूर्वक बदल दी गई!</b>\nसभी नए अपलोड अब इस खाते से जुड़े हैं।"
                ];
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '👤 View Account', 'callback_data' => 'menu_account']],
                        [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
                    ]
                ];
                editTelegramMessage($chatId, $messageId, $swMsg[$lang] ?? $swMsg['en'], $keyboard);
            }
            break;

        case str_starts_with($data, 'confirm_del_'):
            $apiId = substr($data, 12);
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Yes, Remove API', 'callback_data' => 'do_del_' . $apiId],
                        ['text' => '❌ Cancel', 'callback_data' => 'menu_apis']
                    ]
                ]
            ];
            editTelegramMessage($chatId, $messageId, "⚠️ <b>Are you sure you want to remove this API key?</b>", $keyboard);
            break;

        case str_starts_with($data, 'do_del_'):
            $apiId = substr($data, 7);
            if (removeUserApi($user['id'], $apiId)) {
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔑 My APIs', 'callback_data' => 'menu_apis']],
                        [['text' => buildBackToMenuKeyboard($lang)['inline_keyboard'][0][0]['text'], 'callback_data' => 'menu_main']]
                    ]
                ];
                editTelegramMessage($chatId, $messageId, "✅ <b>API Removed Successfully!</b>", $keyboard);
            }
            break;

        case $data === 'menu_account':
            handleAccountCommand($user, $chatId, $messageId);
            break;

        case $data === 'menu_stats':
            handleStatsCommand($user, $chatId, $messageId, 7);
            break;

        case $data === 'stats_7':
            handleStatsCommand($user, $chatId, $messageId, 7);
            break;

        case $data === 'stats_30':
            handleStatsCommand($user, $chatId, $messageId, 30);
            break;

        case $data === 'menu_files':
            handleFilesCommand($user, $chatId, $messageId, 1);
            break;

        case str_starts_with($data, 'files_p_'):
            $page = (int)substr($data, 8);
            handleFilesCommand($user, $chatId, $messageId, max(1, $page));
            break;

        case $data === 'menu_file_info':
            handleFileInfoCommand($user, $chatId);
            break;

        case str_starts_with($data, 'finfo_'):
            $code = substr($data, 6);
            handleFileInfoCommand($user, $chatId, $code);
            break;

        case str_starts_with($data, 'frename_'):
            $code = substr($data, 8);
            setUserState($user['id'], 'waiting_rename_title', ['code' => $code]);
            sendTelegramMessage($chatId, "✏️ <b>Send new title for file:</b> <code>{$code}</code>\n<i>Type /cancel to abort</i>", [
                'inline_keyboard' => [
                    [['text' => '❌ Cancel', 'callback_data' => 'menu_files']]
                ]
            ]);
            break;

        case $data === 'menu_folders':
            handleFoldersCommand($user, $chatId, $messageId);
            break;

        case $data === 'folder_create':
            handleAddFolderCommand($user, $chatId);
            break;

        case $data === 'menu_help':
            handleHelpCommand($chatId, $lang);
            break;

        case $data === 'admin_forcesub_menu':
            if (isTelegramAdmin($user['telegram_user_id'])) {
                setUserState($user['id'], 'waiting_forcesub_channel');
                sendTelegramMessage($chatId, "📢 <b>Set Force Subscribe Channel</b>\n\nSend the channel username (e.g. <code>@MyChannel</code>) or type <code>off</code> to disable force subscribe:\nType /cancel to abort.", [
                    'inline_keyboard' => [
                        [['text' => '❌ Cancel', 'callback_data' => 'menu_main']]
                    ]
                ]);
            }
            break;

        case $data === 'admin_broadcast':
            handleBroadcastCommand($user, $chatId);
            break;

        case $data === 'admin_toggle_maint':
            if (isTelegramAdmin($user['telegram_user_id'])) {
                $current = getBotSetting('maintenance_mode', '0');
                $new = ($current === '1') ? '0' : '1';
                setBotSetting('maintenance_mode', $new);
                handleAdminCommand($user, $chatId);
            }
            break;

        default:
            break;
    }
}

// ----------------------------------------------------------------------------
// 12. MESSAGE ROUTER & CONVERSATION HANDLER
// ----------------------------------------------------------------------------

function handleMessage(array $message): void {
    $from = $message['from'] ?? null;
    $chat = $message['chat'] ?? null;
    $text = trim($message['text'] ?? '');

    if (!$from || !$chat) return;

    $chatId = $chat['id'];
    $user = getOrCreateUser($from);
    if (!$user) {
        sendTelegramMessage($chatId, "System error initializing profile.");
        return;
    }

    if (!empty($user['is_blocked'])) {
        sendTelegramMessage($chatId, "🚫 Your access to this bot has been restricted.");
        return;
    }

    $lang = $user['language'] ?: 'en';

    // Check maintenance mode
    $isMaint = getBotSetting('maintenance_mode', '0') === '1';
    if ($isMaint && !isTelegramAdmin($user['telegram_user_id'])) {
        sendTelegramMessage($chatId, "🛠️ <b>Bot is temporarily under maintenance.</b>\nPlease try again later.");
        return;
    }

    // Global cancellation
    if ($text === '/cancel') {
        clearUserState($user['id']);
        sendTelegramMessage($chatId, "❌ Current operation cancelled.", buildMainMenuKeyboard($lang));
        return;
    }

    // First time user language selection check
    $stateInfo = getUserState($user['id']);
    $currentState = $stateInfo['state'];
    $stateData = $stateInfo['data'];

    if ($currentState === 'selecting_language') {
        if (in_array(strtolower($text), ['1', 'english', 'en'])) {
            setUserLanguage($user['id'], 'en');
            $user['language'] = 'en';
            clearUserState($user['id']);
            handleStartCommand($user, $chatId);
            return;
        } elseif (in_array(strtolower($text), ['2', 'bangla', 'bn', 'বাংলা'])) {
            setUserLanguage($user['id'], 'bn');
            $user['language'] = 'bn';
            clearUserState($user['id']);
            handleStartCommand($user, $chatId);
            return;
        } elseif (in_array(strtolower($text), ['3', 'hindi', 'hi', 'हिन्दी'])) {
            setUserLanguage($user['id'], 'hi');
            $user['language'] = 'hi';
            clearUserState($user['id']);
            handleStartCommand($user, $chatId);
            return;
        } else {
            handleLanguageSelectionPrompt($chatId);
            return;
        }
    }

    // Check Force Subscribe (Admins bypass)
    if (!checkUserSubscription($user['telegram_user_id'])) {
        sendForceSubscribeNotice($chatId, $lang);
        return;
    }

    // Check Direct Media Uploads (video or document)
    if (!empty($message['video']) || !empty($message['document'])) {
        handleDirectTelegramUpload($user, $chatId, $message);
        return;
    }

    // Command Dispatch
    if (str_starts_with($text, '/')) {
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        if (str_contains($command, '@')) {
            $command = explode('@', $command)[0];
        }

        switch ($command) {
            case '/start':
                handleStartCommand($user, $chatId);
                return;
            case '/language':
            case '/lang':
                handleLanguageSelectionPrompt($chatId);
                return;
            case '/help':
                handleHelpCommand($chatId, $lang);
                return;
            case '/addapi':
                handleAddApiCommand($user, $chatId);
                return;
            case '/myapis':
                handleMyApis($user, $chatId);
                return;
            case '/switchapi':
                handleSwitchApi($user, $chatId);
                return;
            case '/removeapi':
                handleRemoveApi($user, $chatId);
                return;
            case '/account':
                handleAccountCommand($user, $chatId);
                return;
            case '/stats':
                handleStatsCommand($user, $chatId);
                return;
            case '/upload':
                handleUploadCommand($user, $chatId);
                return;
            case '/files':
                handleFilesCommand($user, $chatId, null, 1);
                return;
            case '/fileinfo':
                handleFileInfoCommand($user, $chatId, $args);
                return;
            case '/rename':
                handleRenameCommand($user, $chatId);
                return;
            case '/folders':
                handleFoldersCommand($user, $chatId);
                return;
            case '/addfolder':
                handleAddFolderCommand($user, $chatId);
                return;
            case '/forcesub':
                handleForceSubCommand($user, $chatId, $args);
                return;
            case '/admin':
                handleAdminCommand($user, $chatId);
                return;
            case '/broadcast':
                handleBroadcastCommand($user, $chatId);
                return;
            case '/block':
                handleBlockUserCommand($user, $chatId, $args);
                return;
            case '/unblock':
                handleUnblockUserCommand($user, $chatId, $args);
                return;
            default:
                break;
        }
    }

    // Conversational State Machine Handler
    switch ($currentState) {
        case 'waiting_api_key':
            handleProcessApiKeyInput($user, $chatId, $text);
            break;

        case 'waiting_remote_url':
            handleProcessRemoteUrl($user, $chatId, $text);
            break;

        case 'waiting_file_code':
            clearUserState($user['id']);
            handleFileInfoCommand($user, $chatId, $text);
            break;

        case 'waiting_rename_code':
            setUserState($user['id'], 'waiting_rename_title', ['code' => trim($text)]);
            sendTelegramMessage($chatId, "📝 <b>Send the new title for this video file:</b>\n<i>(Type /cancel to abort)</i>");
            break;

        case 'waiting_rename_title':
            $fileCode = $stateData['code'] ?? '';
            $newTitle = trim($text);
            clearUserState($user['id']);

            $activeApi = getActiveApi($user['id']);
            if (!$activeApi) {
                sendTelegramMessage($chatId, "⚠️ No active API key found.");
                return;
            }

            $res = vidmolyRequest('/api/file/rename', [
                'file_code' => $fileCode,
                'title'     => $newTitle
            ], 'GET', $activeApi['decrypted_key']);

            if ($res['success']) {
                $text = "✅ <b>File Renamed Successfully!</b>\n\n"
                      . "🆔 <b>File Code:</b> <code>{$fileCode}</code>\n"
                      . "📌 <b>New Title:</b> " . htmlspecialchars($newTitle);
                sendTelegramMessage($chatId, $text, buildMainMenuKeyboard($lang));
            } else {
                sendTelegramMessage($chatId, "❌ Rename failed: " . htmlspecialchars($res['message'] ?: 'Error'));
            }
            break;

        case 'waiting_folder_name':
            $folderName = trim($text);
            clearUserState($user['id']);

            $activeApi = getActiveApi($user['id']);
            if (!$activeApi) {
                sendTelegramMessage($chatId, "⚠️ No active API key found.");
                return;
            }

            $res = vidmolyRequest('/api/folder/create', ['name' => $folderName], 'GET', $activeApi['decrypted_key']);
            if ($res['success']) {
                sendTelegramMessage($chatId, "✅ <b>Folder Created:</b> " . htmlspecialchars($folderName), buildMainMenuKeyboard($lang));
            } else {
                sendTelegramMessage($chatId, "❌ Failed to create folder: " . htmlspecialchars($res['message'] ?: 'Error'));
            }
            break;

        case 'waiting_forcesub_channel':
            clearUserState($user['id']);
            handleForceSubCommand($user, $chatId, $text);
            break;

        case 'waiting_broadcast_msg':
            executeBroadcast($user, $chatId, $text);
            break;

        default:
            if (filter_var($text, FILTER_VALIDATE_URL)) {
                handleProcessRemoteUrl($user, $chatId, $text);
            } else {
                sendTelegramMessage($chatId, "💡 <b>Need help?</b> Type /help for the complete guide, /language to change language, or /start to open the dashboard.", buildMainMenuKeyboard($lang));
            }
            break;
    }
}

// ----------------------------------------------------------------------------
// 13. WEBHOOK RECEIVER & ENTRY POINT
// ----------------------------------------------------------------------------

function runWebhook(): void {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'status'   => 'active',
            'service'  => 'Vidmoly Telegram Bot Backend',
            'database' => 'Firebase Realtime Database',
            'version'  => '3.0.0',
            'features' => ['Firebase RTDB', 'Force Subscribe', 'Multilingual (EN, BN, HI)', 'Multiple APIs', 'Remote & Direct Uploads'],
            'time'     => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    if (BOT_SECRET_TOKEN !== '') {
        $receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!hash_equals(BOT_SECRET_TOKEN, $receivedSecret)) {
            safeLog('Webhook secret token mismatch. Rejected unauthorized update.');
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    $rawInput = file_get_contents('php://input');
    if (!$rawInput) {
        echo json_encode(['ok' => true]);
        exit;
    }

    $update = json_decode($rawInput, true);
    if (!is_array($update)) {
        echo json_encode(['ok' => true]);
        exit;
    }

    if (isset($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }

    echo json_encode(['ok' => true]);
}

// Run bot webhook dispatcher when called through a web server
if (php_sapi_name() !== 'cli') {
    runWebhook();
}
