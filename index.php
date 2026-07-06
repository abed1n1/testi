<?php

// ====================== تنظیمات ======================
$BOT_TOKEN = 'token';
$PANEL_URL = 'adress';
$ADMIN_ID = 1608229217;
$PANEL_BASE= 'adress';

$PANEL_USERNAME = 'user';      // نام کاربری پنل
$PANEL_PASSWORD = 'pass';  // رمز عبور پنل


$limits_file = 'daily_limits.json';
$users_file  = 'users.json';
$admins_file = 'admins.json';

// ====================== توابع ======================

function bot($method, $params) {
    global $BOT_TOKEN;
    $ch = curl_init("https://api.telegram.org/bot$BOT_TOKEN/$method");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return json_decode(curl_exec($ch), true);
}

function getToken(): ?string {
    $file = __DIR__ . '/panel_token.txt';
    if (!file_exists($file)) return null;
    $token = trim(file_get_contents($file));
    return $token === '' ? null : $token;
}




// درخواست عمومی به پنل
function autoLogin(): bool {
    global $PANEL_URL, $PANEL_USERNAME, $PANEL_PASSWORD;
    $file = __DIR__ . '/panel_token.txt';

    // اگر قبلاً توکن هست، خروج
    if (getToken()) return true;

    if (empty($PANEL_URL) || empty($PANEL_USERNAME) || empty($PANEL_PASSWORD)) {
        error_log('autoLogin: PANEL config missing');
        return false;
    }

    $url = rtrim($PANEL_URL, '/') . '/api/admin/token';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'username=' . urlencode($PANEL_USERNAME) . '&password=' . urlencode($PANEL_PASSWORD));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // در محیط production بهتر است true باشد و CA مناسب نصب شود
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('autoLogin curl error: ' . $curlErr);
        return false;
    }

    $data = json_decode($response, true);
    if (isset($data['access_token']) && $data['access_token']) {
        // ذخیره توکن در فایل
        file_put_contents($file, $data['access_token']);
        return true;
    }

    error_log('autoLogin failed, response: ' . $response);
    return false;
}
// ==================== درخواست عمومی به پنل ====================

function panelRequest($endpoint, $method = 'GET', $data = null) {
    global $PANEL_URL;
    // دریافت توکن
    $token = getToken();
    if (!$token) {
        if (!autoLogin()) {
            return ['success' => false, 'code' => 0, 'error' => 'No token and autoLogin failed'];
        }
        $token = getToken();
        if (!$token) return ['success' => false, 'code' => 0, 'error' => 'No token after autoLogin'];
    }

    // اگر endpoint یک URL کامل است از آن استفاده شود، وگرنه base را اضافه کن
    if (parse_url($endpoint, PHP_URL_SCHEME) === null) {
        if (empty($PANEL_URL)) {
            return ['success' => false, 'code' => 0, 'error' => 'PANEL_URL is not configured'];
        }
        $url = rtrim($PANEL_URL, '/') . '/' . ltrim($endpoint, '/');
    } else {
        $url = $endpoint;
    }

    // چک کنیم host موجود باشد (برای جلوگیری از خطای No host part)
    if (empty(parse_url($url, PHP_URL_HOST))) {
        return ['success' => false, 'code' => 0, 'error' => 'Final URL has no host', 'url' => $url];
    }

    // لاگ برای دیباگ (در صورت نیاز آن را غیرفعال کن)
    error_log('panelRequest URL: ' . $url);
    if ($data !== null) {
        error_log('panelRequest payload: ' . json_encode($data));
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // اگر در prod هستید بهتر است true و CA نصب باشد
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = ['Authorization: Bearer ' . $token];

    // متدها را تنظیم کن
    $method = strtoupper($method);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'code' => 0, 'error' => $curlError];
    }

    $decoded = json_decode($response, true);
    // برگرداندن ساختار مشابه آن‌چه در کدهای قبلی استفاده کردی
    return [
        'success' => in_array($httpCode, [200, 201, 204]),
        'code' => $httpCode,
        'data' => $decoded,
        'raw' => $response
    ];
}




// ====================== توابع پکیج ======================

function getAllPackages() {
    $file = 'packages.json';
    if (!file_exists($file)) {
        $default = [];
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    return json_decode(file_get_contents($file), true) ?: [];
}

function savePackages($packages) {
    file_put_contents('packages.json', json_encode($packages, JSON_PRETTY_PRINT));
}


// ====================== سیستم موجودی کاربر ======================
// ====================== سیستم موجودی (بهینه‌شده) ======================

function getAllBalances() {
    $file = 'balances.json';
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
        return [];
    }
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveAllBalances($balances) {
    file_put_contents('balances.json', json_encode($balances, JSON_PRETTY_PRINT));
}

function getUserBalance($chat_id) {
    $balances = getAllBalances();
    return $balances[$chat_id]['balance'] ?? 0;
}

function addBalance($chat_id, $amount) {
    $balances = getAllBalances();
    if (!isset($balances[$chat_id])) {
        $balances[$chat_id] = ['balance' => 0, 'last_update' => date('Y-m-d H:i:s')];
    }
    $balances[$chat_id]['balance'] += $amount;
    $balances[$chat_id]['last_update'] = date('Y-m-d H:i:s');
    saveAllBalances($balances);
}

function reduceBalance($chat_id, $amount) {
    $balances = getAllBalances();
    if (isset($balances[$chat_id]) && $balances[$chat_id]['balance'] >= $amount) {
        $balances[$chat_id]['balance'] -= $amount;
        $balances[$chat_id]['last_update'] = date('Y-m-d H:i:s');
        saveAllBalances($balances);
        return true;
    }
    return false;
}


function getBankCard() {
    $file = 'bank_card.json';
    if (!file_exists($file)) {
        $default = ['card_number' => '6219861900000000', 'owner' => 'ادمین ربات'];
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    return json_decode(file_get_contents($file), true);
}

function saveBankCard($card_number, $owner) {
    $data = ['card_number' => trim($card_number), 'owner' => trim($owner)];
    file_put_contents('bank_card.json', json_encode($data, JSON_PRETTY_PRINT));
}
///--- sakhte karbar


function createUser(string $baseUsername = 'test', int $gb = 50, int $days = 30, string $password = '12345678'): array {
    // تولید یوزرنیم امن‌تر
    try {
        $suffix = random_int(1000, 9999);
    } catch (Exception $e) {
        $suffix = mt_rand(1000, 9999);
    }
    $username = $baseUsername . '_' . $suffix;

    // محاسبه دیتالیمیت بصورت integer
    $dataLimit = (int)$gb * 1024 * 1024 * 1024;

    $payload = [
        'username' => $username,
        'password' => $password,
        'enable' => true,
        'expire' => time() + ($days * 24 * 3600),
        'data_limit' => $dataLimit,
        'data_limit_reset_strategy' => 'no_reset',
        'group_ids' => [1, 2],
        // چون در کدی که کار می‌کردی از 'limitIp' استفاده شده، همین را نگه می‌داریم
        'limitIp' => 2,
    ];

    $result = panelRequest('/api/user', 'POST', $payload);

    // اگر خطای curl یا ساخت URL بوده، همین را بازگردان
    if (!is_array($result)) {
        return ['success' => false, 'error' => 'Unexpected panelRequest result', 'response' => $result];
    }

    if (!empty($result['success'])) {
        // API ممکن است داده‌ها را مستقیم برگرداند یا در یک ساختار متفاوت — اینجا سعی می‌کنیم بهترین حالت را بگیریم
        $data = $result['data'] ?? [];
        // بعضی API ها مستقیماً subscription_url را در روت پاسخ می‌گذارند، بعضی داخل data['data'] یا غیره؛ به شرط محافظتی نگاه می‌کنیم
        if (isset($data['subscription_url'])) {
            $sub_url = $data['subscription_url'];
        } elseif (isset($data['data']['subscription_url'])) {
            $sub_url = $data['data']['subscription_url'];
        } else {
            $sub_url = '';
        }

        return [
            'success' => true,
            'username' => $username,
            'password' => $password,
            'days' => $days,
            'gb' => $gb,
            'subscription_url' => $sub_url,
            'full_data' => $data,
            'http_code' => $result['code'] ?? null,
            'raw' => $result['raw'] ?? null
        ];
    }

    // ناموفق: برگرداندن اطلاعات کامل برای دیباگ
    return [
        'success' => false,
        'error' => 'Failed to create user',
        'code' => $result['code'] ?? 0,
        'response' => $result['data'] ?? $result['raw'] ?? $result
    ];
}



function deleteAccount(string $username) {
    // استفاده از panelRequest که توکن و header را مدیریت می‌کند
    // بازگشت یک آرایه با کلید 'success' و پیام/پاسخ برای دیباگ
    $username = trim($username);
    if ($username === '') return ['success' => false, 'error' => 'empty username'];

    // پیدا کردن ID کاربر با صفحه‌بندی
    $limit = 200;
    $offset = 0;
    $userId = null;

    while (true) {
        $endpoint = "/api/users?limit={$limit}&sort=-created_at&load_sub=true&offset={$offset}";
        $res = panelRequest($endpoint, 'GET', null);

        if (!is_array($res)) {
            return ['success' => false, 'error' => 'panelRequest returned unexpected result', 'response' => $res];
        }
        if (empty($res['success'])) {
            return ['success' => false, 'error' => 'failed to list users', 'code' => $res['code'] ?? 0, 'response' => $res['data'] ?? $res['raw'] ?? $res];
        }

        $list = $res['data']['users'] ?? $res['data'] ?? [];
        if (!is_array($list) || count($list) === 0) break;

        foreach ($list as $u) {
            $uName = $u['username'] ?? $u['user']['username'] ?? null;
            if ($uName !== null && strcasecmp($uName, $username) === 0) {
                // ممکن است id با نام‌های متفاوت باشد، سعی کن از کلیدهای متداول بگیری
                $userId = $u['id'] ?? $u['user_id'] ?? $u['uid'] ?? null;
                break 2;
            }
        }

        // اگر کمتر از limit برگشت یعنی صفحهٔ آخر
        if (count($list) < $limit) break;

        $offset += $limit;
    }

    if (!$userId) {
        return ['success' => false, 'error' => 'user not found'];
    }

    // تلاش برای حذف با bulk delete (بدون ساختاری که panelRequest مدیریت می‌کند)
    $del = panelRequest('/api/users/bulk/delete', 'POST', ['ids' => [$userId]]);
    if (is_array($del) && !empty($del['success'])) {
        return ['success' => true, 'method' => 'bulk', 'response' => $del];
    }

    // اگر bulk حذف نکرد، تلاش کن با DELETE مستقیم روی آی‌دی
    $del2 = panelRequest("/api/users/{$userId}", 'DELETE', null);
    if (is_array($del2) && !empty($del2['success'])) {
        return ['success' => true, 'method' => 'delete_single', 'response' => $del2];
    }

    // در اینجا هر دو تلاش شکست خورده‌اند — اطلاعات را برگردان برای دیباگ
    return ['success' => false, 'error' => 'delete failed', 'bulk_response' => $del, 'single_response' => $del2];
}










function isAdmin($chat_id) {
    global $ADMIN_ID, $admins_file;
    $admins = [$ADMIN_ID];
    if (file_exists($admins_file)) {
        $saved = json_decode(file_get_contents($admins_file), true) ?: [];
        $admins = array_unique(array_merge($admins, $saved));
    }
    return in_array((int)$chat_id, $admins);
}

/////مدیریت کاربران استارت زده


// فایل کاربران (global)
global $users_file;
$users_file = __DIR__ . '/users.json';

// بارگذاری امن کاربران
function loadUsersFile(): array {
    global $users_file;
    if (!file_exists($users_file)) return [];
    $json = file_get_contents($users_file);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

// ذخیرهٔ امن کاربران با lock و atomic replace
function saveUsersFile(array $users): bool {
    global $users_file;
    $dir = dirname($users_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $tmp = tempnam($dir, 'ujson_');
    if ($tmp === false) return false;

    $json = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        @unlink($tmp);
        return false;
    }

    $fp = fopen($tmp, 'c');
    if ($fp === false) {
        @unlink($tmp);
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    ftruncate($fp, 0);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // atomic replace
    return rename($tmp, $users_file);
}

// ذخیره یا به‌روزرسانی رکورد کاربر (ثبت در اولین ورود و به‌روزرسانی last_active)
// می‌تواند نام کاربری تلگرام را هم ذخیره کند اگر داده بدهی
function saveUser($chat_id, $tg_username = null): bool {
    $key = (string)$chat_id;
    $users = loadUsersFile();

    $now = date('Y-m-d H:i:s');

    if (!isset($users[$key])) {
        $users[$key] = [
            'chat_id' => (int)$chat_id,
            'tg_username' => $tg_username ?? null,
            'joined_at' => $now,
            'last_active' => $now,
            'joined_channel' => false,
            'joined_channel_at' => null,
            'meta' => []
        ];
    } else {
        // فقط فیلدهای مرتبط را به‌روز کن
        $users[$key]['last_active'] = $now;
        if ($tg_username !== null) $users[$key]['tg_username'] = $tg_username;
    }

    return saveUsersFile($users);
}

// گرفتن یک رکورد کاربر (یا null)
function getSavedUser($chat_id) {
    $users = loadUsersFile();
    return $users[(string)$chat_id] ?? null;
}

// علامت‌گذاری اینکه کاربر کانال را جوین کرده
function markUserJoined($chat_id): bool {
    $key = (string)$chat_id;
    $users = loadUsersFile();
    if (!isset($users[$key])) return false;
    $users[$key]['joined_channel'] = true;
    $users[$key]['joined_channel_at'] = date('Y-m-d H:i:s');
    return saveUsersFile($users);
}

// گرفتن همهٔ کاربران به صورت آرایهٔ ایندکس‌شده (سازگار با getAllSavedUsers قبلی)
function getAllSavedUsers() {
    $users = loadUsersFile();
    return array_values($users);
}

///





function getUserAccounts($chat_id, $limit = 200, $maxPages = 10) {
    // فقط ارقام chat_id را نگه دار
    $safe_chat = preg_replace('/\D+/', '', (string)$chat_id);
    if ($safe_chat === '') return [];

    $matched = [];
    $offset = 0;
    $page = 0;

    // الگوی: شروع با zibav_ سپس صفر یا چند بخشِ غیرِ '_'، سپس "_" chatid "_" 
    // مثال‌هایی که می‌پوشاند: zibav_1234_..., zibav_target_1234_abcd
    $pattern = '/^zibav(?:_[^_]+)*_' . preg_quote($safe_chat, '/') . '_/';

    while ($page < $maxPages) {
        $endpoint = "/api/users?limit=" . (int)$limit . "&sort=-created_at&load_sub=true&offset=" . (int)$offset;
        $result = panelRequest($endpoint, 'GET', null);

        if (!is_array($result) || empty($result['success'])) {
            // در صورت خطا یا پاسخ غیرمنتظره، حلقه را قطع کن (می‌توانی لاگ کنی)
            break;
        }

        // برخی API ها لیست را در data['users'] قرار می‌دهند، بعضی‌ها مستقیم در data
        $all_users = $result['data']['users'] ?? $result['data'] ?? [];
        if (!is_array($all_users) || count($all_users) === 0) {
            break;
        }

        foreach ($all_users as $u) {
            if (!is_array($u)) continue;
            $username = $u['username'] ?? '';
            if ($username === '') continue;

            if (preg_match($pattern, $username)) {
                $matched[] = $u;
            }
        }

        // اگر تعداد برگشتی کمتر از limit بود یعنی صفحهٔ آخر است
        if (count($all_users) < $limit) break;

        // آماده‌سازی برای صفحهٔ بعدی
        $offset += $limit;
        $page++;
    }

    return $matched;
}


function getAllAccounts() {
    $result = panelRequest('/api/users?limit=500&sort=-created_at&load_sub=true&offset=0');
    
    if (!$result['success']) {
        return [];
    }

    return $result['data']['users'] ?? $result['data'] ?? [];
}




////amarrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr

function extractChatIdFromUsername(string $username): ?string {
    // جدا کردن قسمت‌ها و گرفتن بخش‌های عددی به ترتیب
    $parts = explode('_', $username);
    $nums = [];
    foreach ($parts as $p) {
        if ($p !== '' && ctype_digit($p)) $nums[] = $p;
    }
    if (count($nums) >= 2) {
        // طبق فرمت ما معمولاً target_id سپس chat_id است -> انتخاب دوم
        return $nums[1];
    } elseif (count($nums) === 1) {
        return $nums[0];
    }
    return null;
}

function parseTimestamp($value): ?int {
    if ($value === null || $value === '') return null;
    if (is_int($value) || ctype_digit((string)$value)) return (int)$value;
    $ts = strtotime((string)$value);
    return $ts === false ? null : $ts;
}

function getDataLimitBytes(array $u): int {
    // کلیدهای ممکن برای محدودیت داده (اولویت‌بندی)
    $keys = ['data_limit', 'data_limit_bytes', 'limit_bytes', 'limit', 'bandwidth_limit'];
    foreach ($keys as $k) {
        if (isset($u[$k]) && $u[$k] !== '') return (int)$u[$k];
        if (isset($u['data'][$k]) && $u['data'][$k] !== '') return (int)$u['data'][$k];
    }
    // همچنین ممکن است فیلد limit_gb وجود داشته باشد
    $gb = $u['limit_gb'] ?? ($u['data']['limit_gb'] ?? null);
    if ($gb !== null && $gb !== '') {
        return (int)round((float)$gb * (1024 ** 3));
    }
    return 0;
}

function getBotStats(): array {
    $all_users = getAllAccounts(); // فرض بر این است که این تابع موجود است و آرایه‌ای از users می‌دهد
    $total = 0;
    $today = 0;
    $total_gb = 0.0;
    $users_set = [];
    $seen_usernames = [];
    $today_date = date('Y-m-d');

    foreach ($all_users as $u) {
        // استخراج نام کاربری از مکان‌های ممکن
        $username = $u['username'] ?? $u['user']['username'] ?? ($u['data']['username'] ?? null);
        if (empty($username)) continue;

        // فقط نام‌هایی که با zibav_ شروع می‌شوند را در نظر بگیر
        if (strpos($username, 'zibav_') !== 0) continue;

        // جلوگیری از شمارش دو بار یک username تکراری
        if (isset($seen_usernames[$username])) continue;
        $seen_usernames[$username] = true;
        $total++;

        // محاسبهٔ data limit به بایت و تبدیل به گیگ
        $bytes = getDataLimitBytes($u);
        $gb = $bytes > 0 ? ($bytes / (1024 ** 3)) : (float)($u['limit_gb'] ?? ($u['data']['limit_gb'] ?? 0));
        $total_gb += (float)$gb;

        // استخراج زمان ساخت و مقایسه با امروز
        $created_raw = $u['created_at'] ?? $u['created'] ?? ($u['data']['created_at'] ?? null);
        $created_ts = parseTimestamp($created_raw);
        if ($created_ts !== null && date('Y-m-d', $created_ts) === $today_date) {
            $today++;
        }

        // استخراج chat id و شمارش یکتاها
        $chatid = extractChatIdFromUsername($username);
        if ($chatid !== null && $chatid !== '') {
            $users_set[$chatid] = true;
        }
    }

    return [
        'total_accounts' => $total,
        'today_accounts' => $today,
        'unique_users' => count($users_set),
        'total_gb' => round($total_gb, 2)
    ];
}


// ====================== مدیریت مراحل ادمین ======================
function setAdminStep($chat_id, $step) {
    file_put_contents("admin_step_{$chat_id}.txt", $step);
}

function getAdminStep($chat_id) {
    $file = "admin_step_{$chat_id}.txt";
    if (file_exists($file)) return trim(file_get_contents($file));
    return null;
}

function clearAdminStep($chat_id) {
    $file = "admin_step_{$chat_id}.txt";
    if (file_exists($file)) unlink($file);
}

// ====================== شروع پردازش ======================
$update = json_decode(file_get_contents('php://input'), true);

$chat_id = $update['message']['chat']['id'] ?? ($update['callback_query']['message']['chat']['id'] ?? 0);
$text = $update['message']['text'] ?? '';
$data = $update['callback_query']['data'] ?? '';

if ($chat_id) saveUser($chat_id);

$step = getAdminStep($chat_id);


// ====================== پردازش مراحل ادمین ======================
if ($step && isset($update['message'])) {
    $text = trim($update['message']['text'] ?? '');

    
    if (in_array(strtolower($text), ['/cancel', 'لغو', '/start', 'منو'])) {
        
        if (
    $step == 'wait_for_increase_amount' ||
    $step == 'wait_for_increase_receipt' ||
    str_starts_with($step, 'wait_for_receipt_')
) {
    // ادامه مراحل کاربر
} elseif (!isAdmin($chat_id)) {
    clearAdminStep($chat_id);
    bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ عملیات لغو شد."]);
    goto end_admin;
}
    }

    // ریست محدودیت
    if ($step === 'reset_limit') {
        if (is_numeric($text)) {
            $target_id = (string)$text;
            $limits = file_exists($limits_file) ? json_decode(file_get_contents($limits_file), true) ?: [] : [];
            unset($limits[$target_id]);
            file_put_contents($limits_file, json_encode($limits, JSON_PRETTY_PRINT));
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ محدودیت کاربر {$target_id} ریست شد."]);
        } else {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ آیدی باید عددی باشد."]);
        }
        clearAdminStep($chat_id);
    }
    ////
    
    
    elseif ($step === 'wait_for_increase_amount') {
        if (!is_numeric($text) || $text <= 0) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً عدد مثبت وارد کنید!"]);
        } else {
            $amount = (int)$text;
            $temp = ['amount' => $amount];
            file_put_contents("increase_temp_{$chat_id}.json", json_encode($temp));

            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ مبلغ " . number_format($amount) . " تومان دریافت شد.\n\nحالا **رسید پرداخت** (عکس) را ارسال کنید."
            ]);
            setAdminStep($chat_id, 'wait_for_increase_receipt');
        }
    }

    // افزایش موجودی - مرحله ۲: دریافت رسید
    elseif ($step === 'wait_for_increase_receipt') {
        $temp_file = "increase_temp_{$chat_id}.json";
        if (!file_exists($temp_file)) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا! دوباره شروع کنید."]);
            clearAdminStep($chat_id);
            goto end_admin;
        }

        $temp = json_decode(file_get_contents($temp_file), true);
        $amount = $temp['amount'];

        $admin_text = "🔔 **درخواست افزایش موجودی**\n\n👤 کاربر: $chat_id\n💰 مبلغ: " . number_format($amount) . " تومان";

        $kb = ['inline_keyboard' => [
            [
                ['text' => '✅ تایید افزایش', 'callback_data' => 'approve_increase_' . $chat_id . '_' . $amount],
                ['text' => '❌ رد', 'callback_data' => 'reject_increase_' . $chat_id]
            ]
        ]];

        bot('copyMessage', [
    'chat_id' => $ADMIN_ID,
    'from_chat_id' => $chat_id,
    'message_id' => $update['message']['message_id'],
    'caption' => $admin_text,
    'parse_mode' => 'HTML',
    'reply_markup' => json_encode($kb)
]);

        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ درخواست شما به ادمین ارسال شد.\nلطفاً منتظر تایید باشید."
        ]);

        unlink($temp_file);
        clearAdminStep($chat_id);
    }
    
    

    // پیام همگانی
    elseif ($step === 'broadcast_step') {
        $all_users = array_keys(json_decode(file_get_contents($users_file), true) ?: []);
        $sent = 0;
        foreach ($all_users as $uid) {
            bot('sendMessage', ['chat_id' => $uid, 'text' => $text]);
            $sent++;
            usleep(100000);
        }
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام همگانی به $sent کاربر ارسال شد."]);
        clearAdminStep($chat_id);
    }
    
    ////موجودی
        // مدیریت دستی موجودی
    elseif ($step === 'manage_balance_step') {
        if (preg_match('/^(\d+)\s+([+-]?\d+)$/', $text, $m)) {
            $target_id = (int)$m[1];
            $amount = (int)$m[2];

            $old_balance = getUserBalance($target_id);

            if ($amount > 0) {
                addBalance($target_id, $amount);
                $msg = "✅ $amount تومان به کاربر $target_id اضافه شد.";
                $user_msg = "💰 موجودی شما افزایش یافت!\n\nمقدار: +" . number_format($amount) . " تومان\nموجودی فعلی: " . number_format(getUserBalance($target_id)) . " تومان";
            } else {
                $reduce_amount = abs($amount);
                if (reduceBalance($target_id, $reduce_amount)) {
                    $msg = "✅ $reduce_amount تومان از کاربر $target_id کم شد.";
                    $user_msg = "💰 موجودی شما کاهش یافت!\n\nمقدار: -" . number_format($reduce_amount) . " تومان\nموجودی فعلی: " . number_format(getUserBalance($target_id)) . " تومان";
                } else {
                    $msg = "❌ موجودی کاربر کافی نبود.";
                    $user_msg = null;
                }
            }

            bot('sendMessage', ['chat_id' => $chat_id, 'text' => $msg]);

            // اطلاع به کاربر
            if ($user_msg && $target_id != $chat_id) {
                bot('sendMessage', ['chat_id' => $target_id, 'text' => $user_msg]);
            }
        } else {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت اشتباه!\nمثال: `123456789 +50000`"]);
        }
        clearAdminStep($chat_id);
    }

    ////cart banki
    
        // تغییر کارت بانکی
    elseif ($step === 'change_bank_card') {
        // فرض می‌کنیم کاربر فقط شماره کارت را می‌فرستد (یا شماره + نام)
        $parts = explode('|', $text);
        $card_number = trim($parts[0]);
        $owner = isset($parts[1]) ? trim($parts[1]) : 'ادمین ربات';

        saveBankCard($card_number, $owner);

        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ کارت بانکی با موفقیت بروزرسانی شد.\n\nشماره: `$card_number`\nبه نام: $owner"
        ]);
        clearAdminStep($chat_id);
    }

    // دریافت رسید پرداخت توسط کاربر
elseif (str_starts_with($step, 'wait_for_receipt_')) {
    $pkg_id = (int)str_replace('wait_for_receipt_', '', $step);
    $packages = getAllPackages();
    $package = null;
    foreach ($packages as $p) {
        if ($p['id'] == $pkg_id) {
            $package = $p;
            break;
        }
    }

    if (!$package) {
        clearAdminStep($chat_id);
        exit;
    }

    $admin_text = "🔔 **رسید پرداخت جدید**\n\n";
    $admin_text .= "👤 کاربر: $chat_id\n";
    $admin_text .= "📦 پکیج: " . $package['name'] . "\n";
    $admin_text .= "💰 مبلغ: " . number_format($package['price']) . " تومان\n\n";
    $admin_text .= "تایید کنید:";

    $kb = ['inline_keyboard' => [
        [
            ['text' => '✅ تایید و ساخت اکانت', 'callback_data' => 'approve_receipt_' . $chat_id . '_' . $pkg_id],
            ['text' => '❌ رد کردن', 'callback_data' => 'reject_receipt_' . $chat_id]
        ]
    ]];

    // ارسال اطلاعات به ادمین
    bot('copyMessage', [
    'chat_id' => $ADMIN_ID,
    'from_chat_id' => $chat_id,
    'message_id' => $update['message']['message_id'],
    'caption' => $admin_text,
    'parse_mode' => 'HTML',
    'reply_markup' => json_encode($kb)
]);


    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "✅ رسید شما به ادمین ارسال شد.\nلطفاً منتظر تایید باشید."
    ]);

    clearAdminStep($chat_id);
}

        // ================== اضافه کردن پکیج مرحله به مرحله ==================
    
    
elseif (str_starts_with($step, 'add_package_')) {

    if ($step === 'add_package_name') {
        $temp = ['name' => $text];
        file_put_contents("package_temp_{$chat_id}.json", json_encode($temp));
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ نام دریافت شد: $text\n\nحالا **توضیحات** پکیج را بنویسید:"
        ]);
        setAdminStep($chat_id, 'add_package_desc');
    }

    elseif ($step === 'add_package_desc') {
        $temp = json_decode(file_get_contents("package_temp_{$chat_id}.json"), true);
        $temp['description'] = $text;
        file_put_contents("package_temp_{$chat_id}.json", json_encode($temp));
        
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ توضیحات دریافت شد.\n\n**حجم به گیگابایت** را ارسال کنید (مثال: 50)"]);
        setAdminStep($chat_id, 'add_package_gb');
    }

    elseif ($step === 'add_package_gb') {
        if (!is_numeric($text) || $text <= 0) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً عدد مثبت وارد کنید!"]);
            return;
        }
        $temp = json_decode(file_get_contents("package_temp_{$chat_id}.json"), true);
        $temp['gb'] = (int)$text;
        file_put_contents("package_temp_{$chat_id}.json", json_encode($temp));
        
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ حجم دریافت شد.\n\n**تعداد روز** را ارسال کنید (مثال: 30)"]);
        setAdminStep($chat_id, 'add_package_days');
    }

    elseif ($step === 'add_package_days') {
        if (!is_numeric($text) || $text <= 0) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً عدد مثبت وارد کنید!"]);
            return;
        }
        $temp = json_decode(file_get_contents("package_temp_{$chat_id}.json"), true);
        $temp['days'] = (int)$text;
        file_put_contents("package_temp_{$chat_id}.json", json_encode($temp));
        
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ روز دریافت شد.\n\n**قیمت به تومان** را ارسال کنید (مثال: 45000)"]);
        setAdminStep($chat_id, 'add_package_price');
    }

    elseif ($step === 'add_package_price') {
        if (!is_numeric($text) || $text <= 0) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً عدد مثبت وارد کنید!"]);
            return;
        }
        $temp = json_decode(file_get_contents("package_temp_{$chat_id}.json"), true);
        $temp['price'] = (int)$text;
        $temp['id'] = time();

        $packages = getAllPackages();
        $packages[] = $temp;
        savePackages($packages);

        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🎉 **پکیج با موفقیت ساخته شد!**\n\n" .
                     "📌 نام: " . $temp['name'] . "\n" .
                     "📝 توضیح: " . ($temp['description'] ?? 'بدون توضیح') . "\n" .
                     "📦 حجم: " . $temp['gb'] . " گیگ\n" .
                     "⏳ مدت: " . $temp['days'] . " روز\n" .
                     "💰 قیمت: " . number_format($temp['price']) . " تومان"
        ]);

        unlink("package_temp_{$chat_id}.json");
        clearAdminStep($chat_id);
    }
}
    
    // ساخت اکانت دستی مرحله ۱
    elseif ($step === 'create_manual') {
        if (is_numeric($text)) {
            $target_id = (string)$text;
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ آیدی دریافت شد: $target_id\n\nحالا حجم و روز را بفرست:\nمثال: `2 30`"
            ]);
            setAdminStep($chat_id, 'create_manual_step2_' . $target_id);
        } else {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ آیدی باید عددی باشد."]);
            clearAdminStep($chat_id);
        }
    }

    // ساخت اکانت دستی مرحله ۲
elseif (str_starts_with($step, 'create_manual_step2_')) {
    $target_id = str_replace('create_manual_step2_', '', $step);

    if (preg_match('/^(\d+)\s+(\d+)$/', $text, $m)) {
        $gb = (int)$m[1];
        $days = (int)$m[2];
        $safe_chat = preg_replace('/\D+/', '', (string)$chat_id); // فقط ارقام
        if ($safe_chat === '') $safe_chat = 'u'; // fallback اگر chat_id عجیب بود

        $username = "zibav_{$target_id}_{$safe_chat}";

        $result = createUser($username, $gb, $days);

        if (!empty($result['success'])) {
            // اگر API subscription_url داد از آن استفاده کن، در غیر این صورت بساز
            $sub = $result['subscription_url'] ?? '';
            if (empty($sub)) {
                $base = rtrim($PANEL_URL ?? '', '/');
                if (!empty(parse_url($base, PHP_URL_HOST))) {
                    $sub = $base . '/sub/' . $username;
                } else {
                    // اگر PANEL_URL معتبر نیست، خالی بذار یا پیام مناسب بده
                    $sub = '';
                    error_log("Warning: PANEL_URL is not set or has no host; cannot build subscription URL for $username");
                }
            }

            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ <b>اکانت ساخته شد!</b>\n\n"
                        . "👤 <code>$username</code>\n"
                        . "📦 $gb گیگابایت\n"
                        . "⏳ $days روز\n"
                        . "🔗 <code>" . ($sub ?: '—') . "</code>",
                'parse_mode' => 'HTML'
            ]);

            // ارسال QR Code فقط اگر آدرس معتبر باشد
            if (!empty($sub)) {
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($sub);
                bot('sendPhoto', [
                    'chat_id' => $chat_id,
                    'photo' => $qr_url,
                    'caption' => "📱 QR Code اکانت"
                ]);
            }
        } else {
            // ارسال خطای کامل برای دیباگ
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ خطا در ساخت اکانت:\n" . print_r($result, true)
            ]);
        }
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ فرمت اشتباه!\nمثال: `50 30`  (حجم روز)"
        ]);
    }

    clearAdminStep($chat_id);
}

    end_admin:;
}


// ====================== دستور /start ======================
if ($text === '/start') {
    $kb = ['inline_keyboard' => []];
    if (isAdmin($chat_id)) {
        $kb['inline_keyboard'] = [
            [['text' => '🚀 🛍 خرید اشتراک', 'callback_data' => 'show_packages'],
			['text' => '📊 آمار', 'callback_data' => 'admin_stats']],
            [['text' => '👤 اشتراک من', 'callback_data' => 'my_subscription'],
			['text' => '🔧 پنل مدیریت', 'callback_data' => 'admin_panel']]
        ];
    } else {
        $kb['inline_keyboard'] = [
    [
        ['text' => '🎁 کانال پشتیبانی', 'callback_data' => 'channel_poshtibani']
    ],
    [
        ['text' => '🛍 خرید اشتراک', 'callback_data' => 'show_packages'],
        ['text' => '📡 اشتراک‌های من', 'callback_data' => 'my_subscription']
    ],
    [
        ['text' => '💳 شارژ کیف پول', 'callback_data' => 'increase_balance'],
        ['text' => '👤 پروفایل من', 'callback_data' => 'my_profile']
    ]
];
    }

    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👋 سلام!\n\nبه ربات خوش آمدید.",
        'reply_markup' => json_encode($kb)
    ]);
}

// ====================== ساخت اکانت رایگان ======================
// ====================== ساخت اکانت رایگان (یک بار) ======================
if ($data === 'create_free_account') {
    // چک کردن اینکه قبلاً اکانت رایگان ساخته یا نه
    $user_accounts = getUserAccounts($chat_id);
    
    if (!empty($user_accounts)) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "❌ شما قبلاً اکانت رایگان ساخته‌اید!\nهر کاربر فقط یک اکانت رایگان می‌تواند داشته باشد.",
            'show_alert' => true
        ]);
        exit;
    }

    // ساخت اکانت
    $username = 'zibav_' . $chat_id . '_' . substr(md5(time()), 0, 6);
    $result = createUser($username, 0.200, 1);

    if ($result['success']) {
        $sub = "$PANEL_BASE/sub/$username";
        $qr = "https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=" . urlencode($sub);

        $msg = "🎉 **اکانت رایگان ساخته شد!**\n\n";
        $msg .= "👤 `$username`\n";
        $msg .= "📦 ۱ گیگابایت\n";
        $msg .= "⏳ ۱ روز\n\n";
        $msg .= "🔗 `$sub`";

        bot('sendPhoto', [
            'chat_id' => $chat_id,
            'photo' => $qr,
            'caption' => $msg,
            'parse_mode' => 'Markdown'
        ]);
    } else {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا در ساخت اکانت"]);
    }
}

// ====================== اشتراک من ======================
// ====================== اشتراک من (بهبود یافته) ======================
if ($data === 'my_subscription') {
    $users = getUserAccounts($chat_id);
    if (empty($users)) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "شما هنوز هیچ اکانتی نساخته‌اید!",
            'show_alert' => true
        ]);
        exit;
    }

    $kb = ['inline_keyboard' => []];
    foreach ($users as $user) {
        $username = $user['username'];
        $gb = $user['limit_gb'] ?? 1;
        $kb['inline_keyboard'][] = [
            ['text' => "$username | {$gb}G", 'callback_data' => 'view_' . $username],
            ['text' => '🗑 حذف', 'callback_data' => 'user_delete_' . $username]
        ];
    }
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_main']];

    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👤 **اشتراک‌های من**\n\nبرای دیدن جزئیات کامل، روی نام اکانت کلیک کنید:",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}



///


// ====================== نمایش پکیج‌ها به کاربر ======================
// ====================== نمایش پکیج‌ها به کاربر ======================



if ($data === 'channel_poshtibani') {
    $text="@Zibav";

    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_main']];

    bot('editMessageText', [   // تغییر به editMessageText
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}



if ($data === 'show_packages') {
    $packages = getAllPackages();
    
    if (empty($packages)) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "هنوز هیچ پکیجی تعریف نشده است.",
            'show_alert' => true
        ]);
        exit;
    }

    $text = "📦 **پکیج‌های موجود**\n\nلطفاً پکیج مورد نظر را انتخاب کنید:\n\n";
    $kb = ['inline_keyboard' => []];

    foreach ($packages as $pkg) {
        $kb['inline_keyboard'][] = [[
            'text' => $pkg['name'] . " • " . $pkg['gb'] . "GB • " . $pkg['days'] . "روز • " . number_format($pkg['price']) . "T", 
            'callback_data' => 'buy_package_' . $pkg['id']
        ]];
    }

    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_to_main']];

    bot('editMessageText', [   // تغییر به editMessageText
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}

// ====================== صفحه خرید پکیج ======================
if (strpos($data, 'buy_package_') === 0) {
    $pkg_id = (int)str_replace('buy_package_', '', $data);
    $packages = getAllPackages();
    $package = null;
    foreach ($packages as $p) {
        if ($p['id'] == $pkg_id) { $package = $p; break; }
    }

    if (!$package) exit;

    $balance = getUserBalance($chat_id);

    $text = "📦 **خرید پکیج**\n\n";
    $text .= "🏷 نام: " . $package['name'] . "\n";
    $text .= "📦 حجم: " . $package['gb'] . " گیگابایت\n";
    $text .= "⏳ مدت: " . $package['days'] . " روز\n";
    $text .= "💰 قیمت: " . number_format($package['price']) . " تومان\n\n";
    $text .= "💵 موجودی فعلی شما: " . number_format($balance) . " تومان";

    $kb = ['inline_keyboard' => []];

    if ($balance >= $package['price']) {
        $kb['inline_keyboard'][] = [['text' => '💳 پرداخت با موجودی', 'callback_data' => 'pay_balance_' . $pkg_id]];
    }

    $kb['inline_keyboard'][] = [['text' => '💳 کارت به کارت', 'callback_data' => 'pay_card_' . $pkg_id]];
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'show_packages']];

    bot('editMessageText', [   // ویرایش پیام
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}




// پرداخت با موجودی
if (strpos($data, 'pay_balance_') === 0) {
    $pkg_id = (int)str_replace('pay_balance_', '', $data);
    $packages = getAllPackages();
    $package = null;
    foreach ($packages as $p) if ($p['id'] == $pkg_id) { $package = $p; break; }

    if (!$package) exit;

    if (reduceBalance($chat_id, $package['price'])) {
        $username = 'zibav_' . $chat_id . '_' . substr(md5(time()), 0, 8);
        $result = createUser($username, $package['gb'], $package['days']);

        if ($result['success']) {
            $sub = "$PANEL_BASE/sub/$username";
            $text = "✅ **خرید موفق!**\n\n";
            $text .= "📦 پکیج: " . $package['name'] . "\n";
            $text .= "👤 اکانت: `$username`\n";
            $text .= "🔗 `$sub`";

            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $update['callback_query']['message']['message_id'],
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
        }
    } else {
        bot('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id'], 'text' => "موجودی کافی نیست!", 'show_alert' => true]);
    }
}





// پرداخت کارت به کارت
if (strpos($data, 'pay_card_') === 0) {
    $pkg_id = (int)str_replace('pay_card_', '', $data);
    $packages = getAllPackages();
    $package = null;
    foreach ($packages as $p) if ($p['id'] == $pkg_id) { $package = $p; break; }

    if (!$package) exit;

    $text = "💳 **پرداخت کارت به کارت**\n\n";
    $card = getBankCard();
    $text .= "مبلغ: " . number_format($package['price']) . " تومان\n";
    $text .= "به کارت: `" . $card['card_number'] . "`\n";
    $text .= "به نام: " . $card['owner'] . "\n\n";
    $text .= "✅ بعد از واریز، **رسید پرداخت** (عکس) را ارسال کنید.";

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'cancel_step']]]])    ]);

    setAdminStep($chat_id, 'wait_for_receipt_' . $pkg_id);
}













// ====================== مدیریت کارت بانکی ======================
if ($data === 'manage_bank_card' && isAdmin($chat_id)) {
    $card = getBankCard();
    
    $text = "🏦 **مدیریت کارت بانکی**\n\n";
    $text .= "📍 شماره کارت فعلی:\n`" . $card['card_number'] . "`\n";
    $text .= "👤 به نام: " . $card['owner'] . "\n\n";
    $text .= "برای تغییر، شماره کارت جدید را ارسال کنید.\n(اختیاری: بعد از | نام صاحب کارت را بنویسید)";

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به پنل', 'callback_data' => 'admin_panel']]
            ]
        ])
    ]);
    
    setAdminStep($chat_id, 'change_bank_card');
}

// ====================== حذف اکانت ======================
if (strpos($data, 'user_delete_') === 0 || strpos($data, 'delete_acc_') === 0) {
    $username = str_replace(['user_delete_', 'delete_acc_'], '', $data);
    $is_admin_action = strpos($data, 'delete_acc_') === 0;

    if (!$is_admin_action) {
        $is_owner = false;
        foreach (getUserAccounts($chat_id) as $acc) {
            if ($acc['username'] === $username) {
                $is_owner = true;
                break;
            }
        }
        if (!$is_owner) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $update['callback_query']['id'],
                'text' => "❌ این اکانت متعلق به شما نیست!",
                'show_alert' => true
            ]);
            exit;
        }
    }

    deleteAccount($username);

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "🗑 اکانت حذف شد.",
        'show_alert' => true
    ]);

    $data = $is_admin_action ? 'list_all_accounts' : 'my_subscription';
}

// ====================== پنل ادمین ======================
if ($data === 'admin_panel' && isAdmin($chat_id)) {
    $kb = ['inline_keyboard' => [
        [
            ['text' => '📊 آمار کامل', 'callback_data' => 'admin_stats'],
            ['text' => '🔄 ریست محدودیت', 'callback_data' => 'reset_user_limit']
        ],
        [
            ['text' => '✍️ ساخت اکانت دستی', 'callback_data' => 'create_manual_account'],
            ['text' => '📋 لیست همه اکانت‌ها', 'callback_data' => 'list_all_accounts']
        ],
        [
            ['text' => '👥 لیست کاربران', 'callback_data' => 'list_all_users'],
            ['text' => '📢 پیام همگانی', 'callback_data' => 'broadcast']
        ],
        [
            ['text' => '📦 مدیریت پکیج‌ها', 'callback_data' => 'manage_packages'],
            ['text' => '🏦 مدیریت کارت بانکی', 'callback_data' => 'manage_bank_card']
        ],
        [
            ['text' => '💰 مدیریت موجودی کاربران', 'callback_data' => 'manage_user_balance']
        ],
        [
            ['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'back_to_main']
        ]
    ]];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "🔧 **پنل مدیریت ربات**\n\nانتخاب کنید:",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}


if ($data === 'manage_user_balance' && isAdmin($chat_id)) {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "💰 **مدیریت موجودی**\n\nآیدی کاربر + مقدار (+ برای اضافه، - برای کم) بفرست.\n\nمثال:\n`123456789 +50000`",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']]]])
    ]);
    setAdminStep($chat_id, 'manage_balance_step');
}




if ($data === 'admin_stats' && isAdmin($chat_id)) {
    // پاسخ به callback تا spinner برداشته شود
    if (!empty($update['callback_query']['id'])) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => 'در حال دریافت آمار...',
            'show_alert' => false
        ]);
    }

    $stats = getBotStats();
    $total = $stats['total_accounts'] ?? 0;
    $today = $stats['today_accounts'] ?? 0;
    $users = $stats['unique_users'] ?? 0;
    $total_gb = $stats['total_gb'] ?? 0;

    $msg = "<b>📊 آمار ربات</b>\n\n"
         . "👥 کل اکانت‌ها: <code>" . number_format((int)$total) . "</code>\n"
         . "📅 امروز: <code>" . number_format((int)$today) . "</code>\n"
         . "👤 کاربران: <code>" . number_format((int)$users) . "</code>\n"
         . "📦 حجم کل: <code>" . number_format((float)$total_gb, 2) . " GB</code>";

    $replyMarkup = json_encode([
        'inline_keyboard' => [
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']
            ]
        ]
    ]);

    // در صورت وجود message_id، ویرایش کن؛ در غیر این صورت پیام جدید بفرست
    if (!empty($update['callback_query']['message']['message_id'])) {
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $update['callback_query']['message']['message_id'],
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
            'disable_web_page_preview' => true
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
            'disable_web_page_preview' => true
        ]);
    }
}
// لیست همه اکانت‌ها
if ($data === 'list_all_accounts' && isAdmin($chat_id)) {
    $all = getAllAccounts();
    $kb = ['inline_keyboard' => []];
    foreach ($all as $u) {
        $username = $u['username'] ?? '';
        $gb = $u['limit_gb'] ?? 0;
        $kb['inline_keyboard'][] = [
            ['text' => $username . " | {$gb}G", 'callback_data' => 'view_acc_' . $username],
            ['text' => '🗑', 'callback_data' => 'delete_acc_' . $username]
        ];
    }
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']];
    bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $update['callback_query']['message']['message_id'], 'text' => "📋 لیست اکانت‌ها", 'reply_markup' => json_encode($kb)]);
}


// ====================== Callbackهای پنل ادمین ======================

// ساخت اکانت دستی
if ($data === 'create_manual_account' && isAdmin($chat_id)) {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "🆔 لطفاً آیدی عددی کاربر را ارسال کنید:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'admin_panel']]]])
    ]);
    setAdminStep($chat_id, 'create_manual');
}

// ریست محدودیت
if ($data === 'reset_user_limit' && isAdmin($chat_id)) {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "🆔 آیدی عددی کاربر مورد نظر را برای ریست محدودیت ارسال کنید:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'admin_panel']]]])
    ]);
    setAdminStep($chat_id, 'reset_limit');
}

// پیام همگانی
if ($data === 'broadcast' && isAdmin($chat_id)) {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "📢 **ارسال پیام همگانی**\n\nمتن پیام مورد نظر را ارسال کنید.\nبرای لغو /cancel را بفرستید.",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'admin_panel']]]])
    ]);
    setAdminStep($chat_id, 'broadcast_step');
}

// بازگشت به منو
// ====================== بازگشت به منو اصلی ======================
if ($data === 'back_to_main') {
    $kb = ['inline_keyboard' => []];

    if (isAdmin($chat_id)) {
                $kb['inline_keyboard'] = [
            [['text' => '🚀 🛍 خرید اشتراک', 'callback_data' => 'show_packages'],
			['text' => '📊 آمار', 'callback_data' => 'admin_stats']],
            [['text' => '👤 اشتراک من', 'callback_data' => 'my_subscription'],
			['text' => '🔧 پنل مدیریت', 'callback_data' => 'admin_panel']]
        ];
    } else {
        $kb['inline_keyboard'] = [
    [
        ['text' => '🎁 کانال پشتیبانی', 'callback_data' => 'channel_poshtibani']
    ],
    [
        ['text' => '🛍 خرید اشتراک', 'callback_data' => 'show_packages'],
        ['text' => '📡 اشتراک‌های من', 'callback_data' => 'my_subscription']
    ],
    [
        ['text' => '💳 شارژ کیف پول', 'callback_data' => 'increase_balance'],
        ['text' => '👤 پروفایل من', 'callback_data' => 'my_profile']
    ]
];
    }

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "👋 **منوی اصلی**\n\nانتخاب کنید:",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}

///



if ($data === 'increase_balance') {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "💰 **افزایش موجودی**\n\nلطفاً **مبلغ دلخواه به تومان** را ارسال کنید:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'cancel_step']]]])
    ]);
    setAdminStep($chat_id, 'wait_for_increase_amount');
}





// ====================== مدیریت ادمین کردن / برداشتن ======================
// ====================== لیست همه کاربران (نسخه Debug) ======================
if ($data === 'list_all_users' || strpos($data, 'userlist_page_') === 0) {
    $page = 0;
    if (strpos($data, 'userlist_page_') === 0) {
        $page = (int)str_replace('userlist_page_', '', $data);
    }

    $users = getAllSavedUsers();
    
    // === Debug ===
    if (empty($users)) {
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $update['callback_query']['message']['message_id'],
            'text' => "👥 لیست کاربران\n\n⚠️ هنوز هیچ کاربری ثبت نشده است.\n\nفایل users.json خالی است یا کاربری وجود ندارد.",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']]]])
        ]);
        exit;
    }

    $per_page = 6;
    $total_pages = ceil(count($users) / $per_page);
    $page_users = array_slice($users, $page * $per_page, $per_page);

    $kb = ['inline_keyboard' => []];

    foreach ($page_users as $user) {
        $uid = $user['chat_id'] ?? 'نامشخص';
        $name = $user['first_name'] ?? 'کاربر';
        $is_admin = isAdmin($uid);

        $kb['inline_keyboard'][] = [
            ['text' => "$name ($uid)", 'callback_data' => 'user_info_' . $uid],
            ['text' => $is_admin ? '👑 برداشتن' : '👑 ادمین کردن', 'callback_data' => ($is_admin ? 'remove_admin_' : 'make_admin_') . $uid],
            ['text' => '🗑 حذف', 'callback_data' => 'delete_user_' . $uid]
        ];
    }

    $nav = [];
    if ($page > 0) $nav[] = ['text' => '◀️', 'callback_data' => 'userlist_page_' . ($page - 1)];
    if ($page < $total_pages - 1) $nav[] = ['text' => '▶️', 'callback_data' => 'userlist_page_' . ($page + 1)];
    if ($nav) $kb['inline_keyboard'][] = $nav;

    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "👥 **لیست کاربران** (صفحه " . ($page + 1) . "/$total_pages)\n\nتعداد کل: " . count($users),
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}




// ====================== مدیریت ادمین کردن / برداشتن ======================
if (strpos($data, 'make_admin_') === 0 || strpos($data, 'remove_admin_') === 0) {
    $target_id = (int)str_replace(['make_admin_', 'remove_admin_'], '', $data);
    $is_make = strpos($data, 'make_admin_') === 0;

    if ($target_id == $ADMIN_ID && !$is_make) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "❌ نمی‌توانید ادمین اصلی را بردارید!",
            'show_alert' => true
        ]);
    } else {
        $admins = file_exists($admins_file) ? json_decode(file_get_contents($admins_file), true) ?: [] : [];

        if ($is_make) {
            if (!in_array($target_id, $admins)) {
                $admins[] = $target_id;
            }
            $txt = "✅ کاربر $target_id ادمین شد.";
        } else {
            $admins = array_filter($admins, function($id) use ($target_id) {
                return $id != $target_id;
            });
            $txt = "✅ ادمین از کاربر $target_id برداشته شد.";
        }

        file_put_contents($admins_file, json_encode(array_values($admins), JSON_PRETTY_PRINT));

        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => $txt,
            'show_alert' => true
        ]);
    }

    // مهم: لیست را دوباره بارگذاری کن
    $data = 'list_all_users';
}

// ====================== حذف کاربر ======================
if (strpos($data, 'delete_user_') === 0 && isAdmin($chat_id)) {
    $target_id = (int)str_replace('delete_user_', '', $data);
    
    $users = file_exists($users_file) ? json_decode(file_get_contents($users_file), true) ?: [] : [];
    unset($users[$target_id]);
    file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "🗑 کاربر $target_id حذف شد.",
        'show_alert' => true
    ]);

    $data = 'list_all_users';
}



// لغو مرحله
if ($data === 'cancel_step') {
    clearAdminStep($chat_id);
    
    // پاک کردن فایل‌های موقتی
    if (file_exists("package_temp_{$chat_id}.json")) unlink("package_temp_{$chat_id}.json");
    if (file_exists("increase_temp_{$chat_id}.json")) unlink("increase_temp_{$chat_id}.json");

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "عملیات لغو شد.",
        'show_alert' => true
    ]);

    // بازگشت به منو
    $data = 'back_to_main';
}



// ====================== نمایش اطلاعات کامل اشتراک ======================
// ====================== نمایش اطلاعات کامل اشتراک (برای کاربر و ادمین) ======================

if (strpos($data, 'view_') === 0 || strpos($data, 'user_info_') === 0 || strpos($data, 'view_acc_') === 0) {
    global $PANEL_URL;
    $username = str_replace(['view_', 'user_info_', 'view_acc_'], '', $data);
    // پاک‌سازی username برای ایمنی در callback_data و URL
    $username = preg_replace('/[^A-Za-z0-9_\-]/', '', $username);

    // پاسخ به callback query (برای برداشتن spinner)
    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "در حال دریافت اطلاعات...",
        'show_alert' => false
    ]);

    // تلاش برای گرفتن یک یوزر خاص از API (اگر پنل چنین endpoint‌ای دارد)
    $account = null;
    $res = panelRequest("/api/user/{$username}", 'GET', null);
    if (is_array($res) && !empty($res['success'])) {
        // بعضی API ها اطلاعات را در data یا data['data'] می‌گذارند
        $account = $res['data'] ?? $res['data']['data'] ?? $res['data'];
    } else {
        // fallback: جستجو در همه اکانت‌ها (در صورتی که endpoint بالا موجود نباشد)
        $all_accounts = getAllAccounts(); // فرض بر این است که این تابع وجود دارد
        foreach ($all_accounts as $acc) {
            if (isset($acc['username']) && $acc['username'] === $username) {
                $account = $acc;
                break;
            }
        }
    }

    if (empty($account)) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "❌ اکانت پیدا نشد!",
            'show_alert' => true
        ]);
        exit;
    }

    // استخراج فیلدها از چند کلید ممکن
    $created_raw = $account['created_at'] ?? $account['created'] ?? $account['data']['created_at'] ?? null;
    $expire_raw = $account['expire'] ?? $account['expires_at'] ?? $account['data']['expire'] ?? $account['data']['expires_at'] ?? null;
    $expiry_days = (int)($account['expiry_days'] ?? $account['initial_days'] ?? 0);
    $data_limit = (int)($account['data_limit'] ?? $account['limit'] ?? $account['data']['data_limit'] ?? 0);
    $limit_gb = $data_limit ? round($data_limit / (1024 ** 3), 2) : 0;

    // normalize created timestamp
    if (is_numeric($created_raw)) {
        $created_ts = (int)$created_raw;
    } elseif (!empty($created_raw)) {
        $created_ts = strtotime($created_raw) ?: time();
    } else {
        $created_ts = time();
    }

    // determine expiry timestamp: اگر expire timestamp موجود است از آن استفاده کن، وگرنه created + expiry_days
    if (is_numeric($expire_raw) && (int)$expire_raw > 0) {
        $expire_ts = (int)$expire_raw;
    } elseif (!empty($expire_raw)) {
        $parsed = strtotime($expire_raw);
        $expire_ts = $parsed ? $parsed : ($created_ts + max(1, $expiry_days) * 86400);
    } else {
        $expire_ts = $created_ts + max(1, $expiry_days) * 86400;
    }

    $remaining_days = max(0, ceil(($expire_ts - time()) / 86400));

    // وضعیت فعال/غیرفعال از کلیدهای ممکن
    $is_active = $account['is_active'] ?? $account['enable'] ?? $account['active'] ?? $account['status'] ?? 0;
    // normalize boolean
    $is_active = ($is_active === true || $is_active === 1 || $is_active === '1' || $is_active === 'active');

    $status = $is_active ? "✅ فعال" : "❌ غیرفعال";

    // subscription URL از فیلدهای مختلف یا fallback با PANEL_URL
    $sub = $account['subscription_url'] ?? $account['sub'] ?? $account['sub_url'] ?? $account['data']['subscription_url'] ?? '';
    if (empty($sub)) {
        $base = rtrim($PANEL_URL ?? '', '/');
        if (!empty(parse_url($base, PHP_URL_HOST))) {
            $sub = $base . '/sub/' . $username;
        } else {
            $sub = '';
        }
    }

    // آماده‌سازی متن (HTML) — مقادیر را escape کن
    $u_html = htmlspecialchars($username, ENT_QUOTES);
    $limit_html = htmlspecialchars((string)$limit_gb, ENT_QUOTES);
    $created_html = date('Y-m-d H:i', $created_ts);
    $expire_html = date('Y-m-d H:i', $expire_ts);
    $remaining_html = (string)$remaining_days;
    $status_html = $status;
    $sub_html = $sub ? htmlspecialchars($sub, ENT_QUOTES) : '—';

    $text = "<b>📋 جزئیات اشتراک</b>\n\n";
    $text .= "👤 <code>{$u_html}</code>\n";
    $text .= "📦 {$limit_html} گیگابایت\n";
    $text .= "⏳ مدت اولیه: " . max(1, $expiry_days) . " روز\n";
    $text .= "📅 تاریخ انقضا: {$expire_html}\n";
    $text .= "🕒 روزهای باقی‌مانده: <b>{$remaining_html}</b>\n";
    $text .= "🔋 وضعیت: {$status_html}\n";
    $text .= "🔌 حداکثر اتصال: " . htmlspecialchars((string)($account['max_connections'] ?? $account['limitIp'] ?? 2), ENT_QUOTES) . " نفر\n\n";
    if ($sub) {
        // لینک را به سبک HTML به کاربر می‌دهیم (قابل کلیک)
        $text .= "🔗 <a href=\"{$sub_html}\">لینک اشتراک</a>\n";
    } else {
        $text .= "🔗 لینک اشتراک: —\n";
    }

    // کیبورد: دکمهٔ باز کردن لینک و حذف و بازگشت
    $inline_keyboard = [];
    if ($sub) {
        $inline_keyboard[] = [['text' => '🔗 باز کردن لینک', 'url' => $sub]];
    }
    // دکمه حذف با callback_data امن (username پاک‌شده)
    $inline_keyboard[] = [
        ['text' => '🗑 حذف اکانت', 'callback_data' => 'delete_acc_' . $username],
        ['text' => '🔙 بازگشت', 'callback_data' => (strpos($data, 'view_') === 0 ? 'my_subscription' : 'list_all_users')]
    ];

    $kb = ['inline_keyboard' => $inline_keyboard];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($kb),
        'disable_web_page_preview' => true
    ]);
}

















// ====================== مدیریت پکیج‌ها ======================
if ($data === 'manage_packages' && isAdmin($chat_id)) {
    $packages = getAllPackages();
    
    $text = "📦 **مدیریت پکیج‌ها**\n\n";
    $kb = ['inline_keyboard' => []];

    if (!empty($packages)) {
        foreach ($packages as $pkg) {
            $kb['inline_keyboard'][] = [
                ['text' => $pkg['name'] . " | " . $pkg['gb'] . "GB | " . $pkg['days'] . "روز", 'callback_data' => 'pkg_view_' . $pkg['id']],
                ['text' => '🗑', 'callback_data' => 'pkg_delete_' . $pkg['id']]
            ];
        }
    } else {
        $text .= "هنوز هیچ پکیجی تعریف نشده است.\n";
    }

    $kb['inline_keyboard'][] = [['text' => '➕ اضافه کردن پکیج جدید', 'callback_data' => 'add_new_package']];
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel']];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}

// شروع اضافه کردن پکیج
if ($data === 'add_new_package' && isAdmin($chat_id)) {
    $temp_file = "package_temp_{$chat_id}.json";
    $initial = ['step' => 'name'];
    file_put_contents($temp_file, json_encode($initial));
    
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "📝 **اضافه کردن پکیج جدید**\n\nلطفاً **نام پکیج** را ارسال کنید:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'manage_packages']]]])
    ]);
    setAdminStep($chat_id, 'add_package_name');
}



// حذف پکیج
if (strpos($data, 'pkg_delete_') === 0 && isAdmin($chat_id)) {
    $pkg_id = (int)str_replace('pkg_delete_', '', $data);
    $packages = getAllPackages();
    
    $new_packages = array_filter($packages, function($p) use ($pkg_id) {
        return $p['id'] != $pkg_id;
    });

    savePackages(array_values($new_packages));

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "🗑 پکیج حذف شد.",
        'show_alert' => true
    ]);

    $data = 'manage_packages'; // بروزرسانی لیست
}




// ====================== تایید رسید توسط ادمین ======================

// تایید رسید
if (strpos($data, 'approve_receipt_') === 0 && isAdmin($chat_id)) {
    $parts = explode('_', $data);
    $user_id = (int)$parts[2];
    $pkg_id = (int)$parts[3];

    $packages = getAllPackages();
    $package = null;
    foreach ($packages as $p) {
        if ($p['id'] == $pkg_id) {
            $package = $p;
            break;
        }
    }

    if (!$package) exit;

    // ساخت اکانت
    $username = 'zibav_' . $user_id . '_' . substr(md5(time()), 0, 8);
    $result = createUser($username, $package['gb'], $package['days']);

    if ($result['success']) {
        $sub = $base . '/sub/' . $username;
        
        
        
    $kb = ['inline_keyboard' => [
        [['text' => '💰 افزایش موجودی', 'callback_data' => 'increase_balance']],
        [['text' => '📦 خرید پکیج', 'callback_data' => 'show_packages']],
        [['text' => '👤 اشتراک من', 'callback_data' => 'my_subscription']],
        [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_main']]
    ]];
    
        bot('sendMessage', [
            'chat_id' => $user_id,
            'text' => "✅ **پرداخت شما تایید شد!**\n\n" .
                     "📦 پکیج: " . $package['name'] . "\n" .
                     "👤 اکانت: `$username`\n" .
                     "🔗 لینک: `$sub`",
                     'parse_mode' => 'Markdown',
                     'reply_markup' => json_encode($kb)
    ]);
    
        bot('editMessageReplyMarkup', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید شد', 'callback_data' => 'done']
                ]
            ]
        ])
    ]);
        
        
        

        // اطلاع به ادمین
        bot('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => "✅ اکانت برای کاربر ساخته شد.",
            'show_alert' => true
        ]);

        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ اکانت با موفقیت ساخته شد برای کاربر $user_id"
        ]);
    }
}

// رد رسید
if (strpos($data, 'reject_receipt_') === 0 && isAdmin($chat_id)) {
    $user_id = (int)str_replace('reject_receipt_', '', $data);

    bot('sendMessage', [
        'chat_id' => $user_id,
        'text' => "❌ پرداخت شما توسط ادمین رد شد.\nلطفاً دوباره بررسی کنید."
    ]);

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "رد شد.",
        'show_alert' => true
    ]);
}



// افزایش موجودی
if ($data === 'increase_balance') {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => "💰 **افزایش موجودی**\n\nلطفاً **مبلغ دلخواه به تومان** را ارسال کنید:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 لغو', 'callback_data' => 'cancel_step']]]])
    ]);
    setAdminStep($chat_id, 'wait_for_increase_amount');
}




// ====================== پروفایل کاربر ======================
if ($data === 'my_profile') {
    $balance = getUserBalance($chat_id);
    $accounts = getUserAccounts($chat_id);
    $account_count = count($accounts);

    $text = "👤 **پروفایل شما**\n\n";
    $text .= "💰 موجودی: " . number_format($balance) . " تومان\n";
    $text .= "📦 تعداد اکانت‌ها: " . $account_count . "\n";
    $text .= "🆔 آیدی شما: `$chat_id`\n\n";

    if ($account_count > 0) {
        $text .= "🔹 برای دیدن جزئیات اکانت‌ها، به بخش «اشتراک من» مراجعه کنید.";
    }

    $kb = ['inline_keyboard' => [
        [['text' => '💰 افزایش موجودی', 'callback_data' => 'increase_balance']],
        [['text' => '📦 خرید پکیج', 'callback_data' => 'show_packages']],
        [['text' => '👤 اشتراک من', 'callback_data' => 'my_subscription']],
        [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_main']]
    ]];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($kb)
    ]);
}




// تایید افزایش موجودی توسط ادمین
// تایید افزایش موجودی توسط ادمین
if (strpos($data, 'approve_increase_') === 0 && isAdmin($chat_id)) {

    $parts = explode('_', $data);
    $user_id = (int)$parts[2];
    $amount  = (int)$parts[3];

    // موجودی قبل از افزایش
    $old_balance = getUserBalance($user_id);

    // افزایش موجودی
    addBalance($user_id, $amount);

    // موجودی بعد از افزایش
    $new_balance = getUserBalance($user_id);

    bot('sendMessage', [
        'chat_id' => $user_id,
        'text' => "🎉 درخواست افزایش موجودی شما تایید شد.

💵 مبلغ افزایش: +" . number_format($amount) . " تومان

💰 موجودی قبلی: " . number_format($old_balance) . " تومان
💳 موجودی فعلی: " . number_format($new_balance) . " تومان

🙏 از اعتماد شما سپاسگزاریم."
    ]);

    bot('editMessageReplyMarkup', [
        'chat_id' => $chat_id,
        'message_id' => $update['callback_query']['message']['message_id'],
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید شد', 'callback_data' => 'done']
                ]
            ]
        ])
    ]);

    bot('answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
        'text' => "موجودی کاربر با موفقیت افزایش یافت.",
        'show_alert' => true
    ]);
}

// رد درخواست افزایش موجودی
if (strpos($data, 'reject_increase_') === 0 && isAdmin($chat_id)) {
    $user_id = (int)str_replace('reject_increase_', '', $data);
    bot('sendMessage', ['chat_id' => $user_id, 'text' => "❌ درخواست افزایش موجودی شما رد شد."]);
    bot('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id'], 'text' => "رد شد."]);
}

echo "OK";
