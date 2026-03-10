<?php
/**
 * MAC to M3U Stalker Portal Converter
 * Unified Single File Version
 * Original components by PARAG / Siam
 * 
 * WARNING: This file contains hardcoded credentials and security vulnerabilities.
 * For educational/legacy purposes only.
 */

// ============ CONFIGURATION ============
error_reporting(0);
date_default_timezone_set("Asia/Kolkata");

// Hardcoded credentials (from login.php)
define('VALID_USERNAME', 'stalker');
define('VALID_PASSWORD', 'stalker@123');

// Directory structure
define('DATA_DIR', __DIR__ . '/data');
define('FILTER_DIR', DATA_DIR . '/filter');
define('PLAYLIST_DIR', DATA_DIR . '/playlist');
define('TOKEN_FILE', DATA_DIR . '/token.txt');
define('CONNECTION_FILE', DATA_DIR . '/data.json');

// API constant
define('API_SIGNATURE', '263');

// Create directories if they don't exist
foreach ([DATA_DIR, FILTER_DIR, PLAYLIST_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// ============ SESSION MANAGEMENT ============
session_start();

// Global variables (will be populated from connection file)
$url = $mac = $sn = $device_id_1 = $device_id_2 = $sig = $host = '';

// Load connection data if exists
function loadConnection() {
    global $url, $mac, $sn, $device_id_1, $device_id_2, $sig, $host;
    
    if (file_exists(CONNECTION_FILE)) {
        $jsonData = file_get_contents(CONNECTION_FILE);
        $data = json_decode($jsonData, true);
        
        if ($data) {
            $url = $data["url"] ?? '';
            $mac = $data["mac"] ?? '';
            $sn = $data["serial_number"] ?? '';
            $device_id_1 = $data["device_id_1"] ?? '';
            $device_id_2 = $data["device_id_2"] ?? '';
            $sig = $data["signature"] ?? '';
            
            if ($url) {
                $host = parse_url($url, PHP_URL_HOST);
            }
        }
    }
}

// ============ STALKER API FUNCTIONS ============

/**
 * Execute cURL request with common options
 */
function stalkerCurl($url, $headers = [], $customCookie = '') {
    global $mac;
    
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
        'Connection: Keep-Alive',
        'Accept-Encoding: gzip',
        'X-User-Agent: Model: MAG250; Link: WiFi',
    ];
    
    $headers = array_merge($defaultHeaders, $headers);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_COOKIE => $customCookie ?: "mac=$mac; stb_lang=en; timezone=GMT",
        CURLOPT_HTTPHEADER => $headers,
    ]);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [
        'data' => $response,
        'info' => $info
    ];
}

/**
 * Perform handshake with Stalker Portal
 */
function handshake() {
    global $host;
    
    $url = "http://$host/stalker_portal/server/load.php?type=stb&action=handshake&token=&JsHttpRequest=1-xml";
    $headers = [
        "Referer: http://$host/stalker_portal/c/",
        "Host: $host",
    ];
    
    $result = stalkerCurl($url, $headers);
    $data = json_decode($result['data'], true);
    
    return [
        'token' => $data['js']['token'] ?? '',
        'random' => $data['js']['random'] ?? ''
    ];
}

/**
 * Regenerate token using existing token
 */
function regenerateToken($token) {
    global $host;
    
    $url = "http://$host/stalker_portal/server/load.php?type=stb&action=handshake&token=$token&JsHttpRequest=1-xml";
    $headers = [
        "Referer: http://$host/stalker_portal/c/",
        "Host: $host",
    ];
    
    $result = stalkerCurl($url, $headers);
    $data = json_decode($result['data'], true);
    
    return $data['js']['token'] ?? '';
}

/**
 * Generate new token (two-step process)
 */
function generateToken() {
    global $host;
    
    $handshake = handshake();
    if (empty($handshake['token'])) {
        return '';
    }
    
    $token = regenerateToken($handshake['token']);
    if ($token) {
        file_put_contents(TOKEN_FILE, $token);
        getProfile($token);
    }
    
    return $token;
}

/**
 * Get device profile
 */
function getProfile($token) {
    global $host, $mac, $sn, $device_id_1, $device_id_2, $sig;
    
    $timestamp = time();
    $handshake = handshake();
    
    $url = "http://$host/stalker_portal/server/load.php?type=stb&action=get_profile&hd=1&ver=ImageDescription%3A+0.2.18-r14-pub-250%3B+ImageDate%3A+Fri+Jan+15+15%3A20%3A44+EET+2016%3B+PORTAL+version%3A+5.1.0%3B+API+Version%3A+JS+API+version%3A+328%3B+STB+API+version%3A+134%3B+Player+Engine+version%3A+0x566&num_banks=2&sn=$sn&stb_type=MAG250&image_version=218&video_out=hdmi&device_id=$device_id_1&device_id2=$device_id_2&signature=$sig&auth_second_step=1&hw_version=1.7-BD-00&not_valid_token=0&client_type=STB&hw_version_2=08e10744513ba2b4847402b6718c0eae&timestamp=$timestamp&api_signature=" . API_SIGNATURE . "&metrics=%7B%22mac%22%3A%22$mac%22%2C%22sn%22%3A%22$sn%22%2C%22model%22%3A%22MAG250%22%2C%22type%22%3A%22STB%22%2C%22uid%22%3A%22%22%2C%22random%22%3A%22{$handshake['random']}%22%7D&JsHttpRequest=1-xml";
    
    $headers = [
        "Referer: http://$host/stalker_portal/c/",
        "Authorization: Bearer $token",
        "Host: $host",
    ];
    
    stalkerCurl($url, $headers);
}

/**
 * Get or load token
 */
function getToken() {
    if (file_exists(TOKEN_FILE) && filesize(TOKEN_FILE) > 0) {
        return file_get_contents(TOKEN_FILE);
    }
    return generateToken();
}

/**
 * Get channel groups from API
 */
function getGroups($filtered = false) {
    global $host;
    
    $filterFile = FILTER_DIR . "/$host.json";
    
    // Load from cache if exists
    if (file_exists($filterFile)) {
        $cached = json_decode(file_get_contents($filterFile), true);
        if (!empty($cached)) {
            unset($cached["*"]);
            
            if (!$filtered) {
                return array_column($cached, 'title', 'id');
            }
            
            return array_column(array_filter($cached, function($item) {
                return $item['filter'] === true;
            }), 'title', 'id');
        }
    }
    
    // Fetch from API
    $token = getToken();
    $url = "http://$host/stalker_portal/server/load.php?type=itv&action=get_genres&JsHttpRequest=1-xml";
    $headers = [
        "Authorization: Bearer $token",
        "Referer: http://$host/stalker_portal/c/",
        "Host: $host",
    ];
    
    $result = stalkerCurl($url, $headers);
    $data = json_decode($result['data'], true);
    
    if (!isset($data['js']) || !is_array($data['js'])) {
        return [];
    }
    
    // Save to cache
    $filteredData = [];
    foreach ($data['js'] as $genre) {
        if ($genre['id'] === '*') continue;
        $filteredData[$genre['id']] = [
            'id' => $genre['id'],
            'title' => $genre['title'],
            'filter' => true, // Default all enabled
        ];
    }
    
    file_put_contents($filterFile, json_encode($filteredData));
    
    return array_column($filteredData, 'title', 'id');
}

// ============ ROUTING / PAGE HANDLER ============

// Determine which page to show based on request
$request = $_GET['page'] ?? 'index';
$action = $_GET['action'] ?? '';

// Load connection data if needed (for pages that require it)
if (in_array($request, ['index', 'filter', 'playlist', 'play'])) {
    loadConnection();
}

// ============ PAGE: LOGIN ============
if ($request === 'login') {
    // If already logged in, redirect to index
    if (isset($_SESSION['user'])) {
        header("Location: ?page=index");
        exit();
    }
    
    $error = '';
    
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if ($username === VALID_USERNAME && $password === VALID_PASSWORD) {
            $_SESSION['user'] = $username;
            header("Location: ?page=index");
            exit();
        } else {
            $error = "Invalid username or password!";
        }
    }
    
    // Display login page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MAC to M3U Login</title>
        <style>
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                background: linear-gradient(135deg, #1a1a2e, #16213e);
                min-height: 100vh;
                margin: 0;
                color: #e0e0e0;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .container {
                background: rgba(34, 40, 49, 0.9);
                border-radius: 20px;
                padding: 30px;
                width: 100%;
                max-width: 400px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
                border: 1px solid rgba(255, 255, 255, 0.1);
                text-align: center;
            }
            h2 {
                color: #00d4ff;
                text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
                margin-bottom: 20px;
            }
            .form-group {
                margin-bottom: 20px;
                text-align: left;
            }
            .form-group label {
                display: block;
                font-weight: 600;
                color: #a0a0a0;
                margin-bottom: 5px;
            }
            .form-group input {
                width: 100%;
                padding: 12px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.05);
                color: #e0e0e0;
                font-size: 16px;
                box-sizing: border-box;
            }
            .form-group input:focus {
                outline: none;
                border-color: #00d4ff;
                box-shadow: 0 0 5px rgba(0, 212, 255, 0.5);
            }
            button {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                background: linear-gradient(45deg, #0077b6, #023e8a);
                color: white;
            }
            button:hover {
                background: linear-gradient(45deg, #0096c7, #0353a4);
                box-shadow: 0 0px 10px rgba(0, 150, 199, 0.4);
                transform: translateY(-2px);
            }
            .popup {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(34, 40, 49, 0.95);
                padding: 20px 30px;
                border-radius: 15px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
                z-index: 1000;
                display: none;
                max-width: 90%;
                text-align: center;
                border: 2px solid #00d4ff;
            }
            .popup button {
                width: auto;
                padding: 10px 20px;
                margin: 0 auto;
                display: inline-block;
            }
            .overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: none;
                z-index: 999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Login</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit">Login</button>
            </form>
        </div>
        
        <div id="overlay" class="overlay" onclick="hidePopup()"></div>
        <div id="popup" class="popup">
            <p id="popup-message"></p>
            <div id="popup-buttons">
                <button onclick="hidePopup()">OK</button>
            </div>
        </div>
        
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                let errorMessage = "<?php echo $error; ?>";
                if (errorMessage.trim() !== "") {
                    showPopup(errorMessage);
                }
            });
            
            function showPopup(message) {
                document.getElementById("popup-message").textContent = message;
                document.getElementById("overlay").style.display = "block";
                document.getElementById("popup").style.display = "block";
            }
            
            function hidePopup() {
                document.getElementById("overlay").style.display = "none";
                document.getElementById("popup").style.display = "none";
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}

// ============ AUTHENTICATION CHECK ============
// All pages below require login
if (!isset($_SESSION['user'])) {
    header("Location: ?page=login");
    exit();
}

// ============ PAGE: LOGOUT ============
if ($request === 'logout') {
    session_destroy();
    header("Location: ?page=login");
    exit();
}

// ============ PAGE: INDEX (Connection Form / Device Info) ============
if ($request === 'index') {
    $isConnected = file_exists(CONNECTION_FILE);
    $show_popup = false;
    $popup_message = '';
    $storedData = [];
    
    // Handle POST (save connection or disconnect)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['disconnect'])) {
            if (file_exists(CONNECTION_FILE)) {
                unlink(CONNECTION_FILE);
            }
            $show_popup = true;
            $popup_message = 'Disconnected successfully!';
            $isConnected = false;
        } else {
            // Save connection data
            $sanitize = function($input) {
                return htmlspecialchars(trim($input));
            };
            
            $data = [
                "url" => $sanitize($_POST['url'] ?? ''),
                "mac" => $sanitize($_POST['mac'] ?? ''),
                "serial_number" => $sanitize($_POST['sn'] ?? ''),
                "device_id_1" => $sanitize($_POST['device_id_1'] ?? ''),
                "device_id_2" => $sanitize($_POST['device_id_2'] ?? ''),
                "signature" => $sanitize($_POST['sig'] ?? '')
            ];
            
            file_put_contents(CONNECTION_FILE, json_encode($data));
            $isConnected = true;
            loadConnection(); // Reload connection data
        }
    }
    
    // Load stored data for display
    if ($isConnected && file_exists(CONNECTION_FILE)) {
        $storedData = json_decode(file_get_contents(CONNECTION_FILE), true) ?: [];
    }
    
    // Generate playlist URL
    $currentUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $playlistUrl = $currentUrl . '?page=playlist';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <title>Stalker Access</title>
        <style>
            body {
                min-height: 100vh;
                font-family: 'Segoe UI', Arial, sans-serif;
                background: linear-gradient(135deg, #1a1a2e, #16213e);
                display: flex;
                justify-content: center;
                align-items: center;
                margin: 0;
                color: #e0e0e0;
            }
            .container {
                background: rgba(34, 40, 49, 0.9);
                border-radius: 20px;
                padding: 30px;
                margin: 20px;
                overflow: auto;
                max-height: 80vh;
                width: 90%;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            h2 {
                color: #00d4ff;
                text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
                margin-bottom: 25px;
            }
            .form-group {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin: 12px 0;
            }
            .form-group label {
                flex: 1;
                text-align: left;
                font-weight: 600;
                color: #a0a0a0;
            }
            .form-group input {
                flex: 2;
                padding: 12px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.05);
                color: #e0e0e0;
                font-size: 16px;
                transition: all 0.3s ease;
            }
            .form-group input:focus {
                border-color: #00d4ff;
                box-shadow: 0 0 8px rgba(0, 212, 255, 0.3);
                outline: none;
            }
            input::placeholder {
                color: rgba(224, 224, 224, 0.4);
            }
            button.access-btn, button.disconnect {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
            }
            button.access-btn {
                background: linear-gradient(45deg, #0077b6, #023e8a);
                color: white;
            }
            button.access-btn:hover {
                background: linear-gradient(45deg, #0096c7, #0353a4);
                box-shadow: 0 0px 10px rgba(0, 150, 199, 0.4);
                transform: translateY(-2px);
            }
            button.disconnect {
                background: linear-gradient(45deg, #ff4b5c, #d32f2f);
                color: white;
            }
            button.disconnect:hover {
                background: linear-gradient(45deg, #ff6b7c, #f44336);
                box-shadow: 0 0px 10px rgba(255, 75, 92, 0.4);
                transform: translateY(-2px);
            }
            .playlist-container {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 20px 0;
            }
            .playlist-container label {
                font-weight: 600;
                color: #a0a0a0;
            }
            .playlist-container input {
                width: 100%;
                flex: 1;
                padding: 12px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.05);
                color: #e0e0e0;
                font-size: 16px;
            }
            .action-buttons {
                display: flex;
                gap: 10px;
            }
            .btn {
                padding: 10px;
                width: 40px;
                height: 40px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.1);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
            }
            .btn:hover {
                background: rgba(0, 212, 255, 0.2);
                border-color: #00d4ff;
                box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
            }
            .btn i {
                font-size: 16px;
                color: #e0e0e0;
            }
            .popup {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(34, 40, 49, 0.95);
                padding: 20px 30px;
                border-radius: 15px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
                z-index: 1000;
                display: none;
                max-width: 90%;
                text-align: center;
                border: 2px solid #00d4ff;
            }
            .popup button {
                width: auto;
                padding: 10px 20px;
                margin: 0 auto;
                display: inline-block;
                background: linear-gradient(45deg, #0077b6, #023e8a);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
            }
            .popup button:hover {
                background: linear-gradient(45deg, #0096c7, #0353a4);
                box-shadow: 0 0px 10px rgba(0, 150, 199, 0.6);
                transform: translateY(-2px);
            }
            .overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: none;
                z-index: 999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2><?php echo $isConnected ? "Device Info" : "Access Stalker Portal"; ?></h2>
            
            <?php if (!$isConnected): ?>
                <form method="post">
                    <div class="form-group">
                        <label>URL:</label>
                        <input type="text" name="url" placeholder="Enter URL" required>
                    </div>
                    <div class="form-group">
                        <label>MAC Address:</label>
                        <input type="text" name="mac" placeholder="Enter MAC Address" required>
                    </div>
                    <div class="form-group">
                        <label>Serial Number:</label>
                        <input type="text" name="sn" placeholder="Enter Serial Number" required>
                    </div>
                    <div class="form-group">
                        <label>Device ID 1:</label>
                        <input type="text" name="device_id_1" placeholder="Enter Device ID 1" required>
                    </div>
                    <div class="form-group">
                        <label>Device ID 2:</label>
                        <input type="text" name="device_id_2" placeholder="Enter Device ID 2" required>
                    </div>
                    <div class="form-group">
                        <label>Signature:</label>
                        <input type="text" name="sig" placeholder="Enter Signature (Optional)">
                    </div>
                    <button type="submit" class="access-btn">Access</button>
                </form>
            <?php else: ?>
                <form>
                    <?php foreach ($storedData as $key => $value): ?>
                        <div class="form-group">
                            <label><?= ucfirst(str_replace('_', ' ', $key)) ?>:</label>
                            <input type="text" value="<?= $value ?>" readonly>
                        </div>
                    <?php endforeach; ?>
                </form>
                
                <div class="playlist-container">
                    <label>Playlist:</label>
                    <input type="text" id="playlist_url" value="<?= $playlistUrl ?>" readonly>
                    <div class="action-buttons">
                        <button class="btn" onclick="window.location.href='?page=filter'">
                            <i class="fas fa-filter"></i>
                        </button>
                        <button class="btn" onclick="copyToClipboard()">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                
                <form method="post" id="disconnect-form">
                    <input type="hidden" name="disconnect">
                    <button type="button" class="disconnect" onclick="confirmDisconnect()">Disconnect</button>
                </form>
            <?php endif; ?>
            
            <div class="UserLogout" style="text-align: right; margin-top: 20px;">
                <form action="?page=logout" method="POST">
                    <button type="submit" class="btn" style="color: white; font-weight: bold; width: auto;">
                        <i class="fas fa-sign-out-alt" style="margin-right: 8px;"></i> Logout
                    </button>
                </form>
            </div>
        </div>
        
        <div id="overlay" class="overlay" onclick="hidePopup()"></div>
        <div id="popup" class="popup">
            <p id="popup-message"></p>
            <div id="popup-buttons">
                <button onclick="hidePopup()">OK</button>
            </div>
        </div>
        
        <script>
            function showPopup() {
                document.getElementById('popup').style.display = 'block';
                document.getElementById('overlay').style.display = 'block';
            }
            
            function hidePopup(redirect = false) {
                document.getElementById('popup').style.display = 'none';
                document.getElementById('overlay').style.display = 'none';
                if (redirect) {
                    window.location.href = window.location.href;
                }
            }
            
            function confirmDisconnect() {
                showPopup();
                document.getElementById('popup-message').innerText = 'Are you sure you want to Disconnect?';
                document.getElementById('popup-buttons').innerHTML = `
                    <button onclick="document.getElementById('disconnect-form').submit()">Yes</button>
                    <button onclick="hidePopup()">No</button>
                `;
            }
            
            function copyToClipboard() {
                var copyText = document.getElementById("playlist_url");
                copyText.select();
                document.execCommand("copy");
                showPopup();
                document.getElementById('popup-message').innerText = 'URL copied: ' + copyText.value;
                document.getElementById('popup-buttons').innerHTML = `
                    <button onclick="hidePopup()">OK</button>
                `;
            }
            
            <?php if ($show_popup): ?>
                showPopup();
                document.getElementById('popup-message').innerText = '<?= $popup_message ?>';
                document.getElementById('popup-buttons').innerHTML = `
                    <button onclick="hidePopup(true)">OK</button>
                `;
            <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
    exit();
}

// ============ PAGE: FILTER (Channel Group Filtering) ============
if ($request === 'filter') {
    $filterFile = FILTER_DIR . "/$host.json";
    $storedData = file_exists($filterFile) ? json_decode(file_get_contents($filterFile), true) : [];
    $show_popup = false;
    $popup_message = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selected = $_POST['group'] ?? [];
        $allGroups = getGroups(true); // Get all groups
        
        // Update filter status
        foreach ($allGroups as $id => $title) {
            $storedData[$id] = [
                'id' => $id,
                'title' => $title,
                'filter' => in_array($id, $selected)
            ];
        }
        
        $result = file_put_contents($filterFile, json_encode($storedData));
        
        $show_popup = true;
        if ($result === false) {
            $popup_message = 'Error: Unable to save settings. Check file permissions.';
        } else {
            // Clear cached playlist
            $playlistFile = PLAYLIST_DIR . "/{$host}.m3u";
            if (file_exists($playlistFile)) {
                unlink($playlistFile);
            }
            $popup_message = 'Settings saved successfully!';
        }
    }
    
    $groups = getGroups(true);
    $playlistUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?page=playlist';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <title>Group Filter</title>
        <style>
            body {
                min-height: 100vh;
                font-family: 'Segoe UI', Arial, sans-serif;
                background: linear-gradient(135deg, #1a1a2e, #16213e);
                display: flex;
                justify-content: center;
                align-items: center;
                margin: 0;
                color: #e0e0e0;
            }
            .container {
                background: rgba(34, 40, 49, 0.9);
                border-radius: 20px;
                padding: 30px;
                margin: 20px;
                overflow: auto;
                max-height: 85vh;
                width: 90%;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            h2 {
                color: #00d4ff;
                text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
                margin: 0 0 25px 0;
            }
            .checkbox-container {
                max-height: 350px;
                overflow-y: auto;
                padding: 15px;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                margin-bottom: 20px;
            }
            .form-group {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin: 12px 0;
                padding: 8px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.03);
                transition: background 0.2s;
            }
            .form-group:hover {
                background: rgba(255, 255, 255, 0.08);
            }
            .form-group label {
                flex: 1;
                text-align: left;
                font-weight: 500;
                color: #a0a0a0;
                padding-left: 10px;
                cursor: pointer;
            }
            .form-group input[type="checkbox"] {
                transform: scale(1.2);
                cursor: pointer;
                margin-right: 10px;
            }
            button.save-btn {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                background: linear-gradient(45deg, #0077b6, #023e8a);
                color: white;
            }
            button.save-btn:hover {
                background: linear-gradient(45deg, #0096c7, #0353a4);
                box-shadow: 0 0px 10px rgba(0, 150, 199, 0.4);
                transform: translateY(-2px);
            }
            .btn {
                padding: 10px;
                width: 40px;
                height: 40px;
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.1);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
            }
            .btn:hover {
                background: rgba(0, 212, 255, 0.2);
                border-color: #00d4ff;
                box-shadow: 0 0 10px rgba(0, 212, 255, 0.3);
            }
            .search-container {
                margin-bottom: 20px;
                position: relative;
            }
            .search-input {
                width: 100%;
                padding: 12px 40px 12px 15px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.05);
                color: #e0e0e0;
                font-size: 14px;
                box-sizing: border-box;
            }
            .search-input:focus {
                outline: none;
                border-color: #00d4ff;
                box-shadow: 0 0 5px rgba(0, 212, 255, 0.5);
            }
            .search-container::after {
                content: '🔍';
                position: absolute;
                right: 15px;
                top: 50%;
                transform: translateY(-50%);
                color: #a0a0a0;
            }
            .playlist-container {
                display: flex;
                align-items: center;
                gap: 10px;
                margin: 20px 0;
            }
            .playlist-container label {
                font-weight: 600;
                color: #a0a0a0;
            }
            .playlist-container input {
                width: 100%;
                flex: 1;
                padding: 12px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.05);
                color: #e0e0e0;
                font-size: 16px;
            }
            .popup {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(34, 40, 49, 0.95);
                padding: 20px 30px;
                border-radius: 15px;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
                z-index: 1000;
                display: none;
                max-width: 90%;
                text-align: center;
                border: 2px solid #00d4ff;
            }
            .popup button {
                width: auto;
                padding: 10px 20px;
                margin: 0 auto;
                display: inline-block;
                background: linear-gradient(45deg, #0077b6, #023e8a);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
            }
            .overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: none;
                z-index: 999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Group Filter</h2>
            <form method="post">
                <div class="search-container">
                    <input type="text" id="groupSearch" class="search-input" placeholder="Search groups...">
                </div>
                <div class="checkbox-container">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="checkAll" onclick="toggleCheckboxes(this)"> Select All
                        </label>
                    </div>
                    <?php foreach ($groups as $id => $title): ?>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="group[]" value="<?= $id ?>" 
                                    <?= !empty($storedData[$id]['filter']) ? 'checked' : '' ?> 
                                    onchange="updateCheckAll()">
                                <?= htmlspecialchars($title) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="save-btn">Save</button>
            </form>
            <div class="playlist-container">
                <label>Playlist:</label>
                <input type="text" id="playlist_url" value="<?= $playlistUrl ?>" readonly>
                <div class="action-buttons">
                    <button class="btn" onclick="copyToClipboard()">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            <div class="UserLogout" style="text-align: right; margin-top: 20px;">
                <form action="?page=logout" method="POST">
                    <button type="submit" class="btn" style="color: white; font-weight: bold; width: auto;">
                        <i class="fas fa-sign-out-alt" style="margin-right: 8px;"></i> Logout
                    </button>
                </form>
            </div>
        </div>
        
        <div id="overlay" class="overlay" onclick="hidePopup()"></div>
        <div id="popup" class="popup">
            <p id="popup-message"></p>
            <div id="popup-buttons">
                <button onclick="hidePopup()">OK</button>
            </div>
        </div>
        
        <script>
            function toggleCheckboxes(source) {
                let checkboxes = document.querySelectorAll('.form-group:not([style*="display: none"]) input[name="group[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = source.checked;
                });
            }
            
            function updateCheckAll() {
                let visibleCheckboxes = document.querySelectorAll('.form-group:not([style*="display: none"]) input[name="group[]"]');
                let checkAllBox = document.getElementById('checkAll');
                checkAllBox.checked = visibleCheckboxes.length > 0 && 
                    Array.from(visibleCheckboxes).every(checkbox => checkbox.checked);
            }
            
            function filterGroups() {
                let input = document.getElementById('groupSearch').value.toLowerCase();
                let groups = document.getElementsByClassName('form-group');
                
                for (let group of groups) {
                    if (group.querySelector('#checkAll')) continue;
                    
                    let label = group.querySelector('label');
                    let text = label.textContent.toLowerCase().trim();
                    
                    if (text.includes(input)) {
                        group.style.display = '';
                    } else {
                        group.style.display = 'none';
                    }
                }
                updateCheckAll();
            }
            
            function showPopup() {
                document.getElementById('popup').style.display = 'block';
                document.getElementById('overlay').style.display = 'block';
            }
            
            function hidePopup() {
                document.getElementById('popup').style.display = 'none';
                document.getElementById('overlay').style.display = 'none';
            }
            
            function copyToClipboard() {
                var copyText = document.getElementById("playlist_url");
                copyText.select();
                document.execCommand("copy");
                showPopup();
                document.getElementById('popup-message').innerText = 'URL copied: ' + copyText.value;
                document.getElementById('popup-buttons').innerHTML = `
                    <button onclick="hidePopup()">OK</button>
                `;
            }
            
            document.addEventListener('DOMContentLoaded', function () {
                let checkboxes = document.querySelectorAll('input[name="group[]"]');
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateCheckAll);
                });
                
                document.getElementById('groupSearch').addEventListener('input', filterGroups);
                updateCheckAll();
                
                <?php if ($show_popup): ?>
                    showPopup();
                    document.getElementById('popup-message').innerText = '<?= $popup_message ?>';
                    document.getElementById('popup-buttons').innerHTML = `
                        <button onclick="hidePopup()">OK</button>
                    `;
                <?php endif; ?>
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

// ============ PAGE: PLAYLIST (M3U Generation) ============
if ($request === 'playlist') {
    header('Content-Type: audio/x-mpegurl');
    header('Content-Disposition: attachment; filename="playlist.m3u"');
    
    $playlistFile = PLAYLIST_DIR . "/{$host}.m3u";
    
    // Return cached playlist if exists
    if (file_exists($playlistFile)) {
        readfile($playlistFile);
        exit();
    }
    
    // Generate new playlist
    $token = getToken();
    $filteredGroups = getGroups(false); // Get only filtered groups
    
    if (empty($filteredGroups)) {
        echo "# No groups selected for filtering\n";
        exit();
    }
    
    $groupIds = array_keys($filteredGroups);
    $allChannels = [];
    
    // Fetch channels for each selected group
    foreach ($groupIds as $groupId) {
        $url = "http://$host/stalker_portal/server/load.php?type=itv&action=get_ordered_list&genre=$groupId&force_ch_link_check=&JsHttpRequest=1-xml";
        $headers = [
            "Authorization: Bearer $token",
            "Referer: http://$host/stalker_portal/c/",
            "Host: $host",
        ];
        
        $result = stalkerCurl($url, $headers);
        $data = json_decode($result['data'], true);
        
        if (isset($data['js']) && is_array($data['js'])) {
            foreach ($data['js'] as $channel) {
                $allChannels[] = [
                    'id' => $channel['id'],
                    'name' => $channel['name'],
                    'logo' => $channel['logo'] ?? '',
                    'group' => $filteredGroups[$groupId] ?? 'Unknown',
                ];
            }
        }
    }
    
    // Generate M3U content
    $m3u = "#EXTM3U\n";
    foreach ($allChannels as $channel) {
        $m3u .= "#EXTINF:-1";
        if (!empty($channel['logo'])) {
            $m3u .= " logo=\"" . $channel['logo'] . "\"";
        }
        $m3u .= " group-title=\"" . $channel['group'] . "\", " . $channel['name'] . "\n";
        $m3u .= $_SERVER['SCRIPT_NAME'] . "?page=play&id=" . $channel['id'] . "\n";
    }
    
    // Cache the playlist
    file_put_contents($playlistFile, $m3u);
    
    echo $m3u;
    exit();
}

// ============ PAGE: PLAY (Stream Redirect) ============
if ($request === 'play') {
    if (empty($_GET['id'])) {
        die("Error: Channel ID is missing.");
    }
    
    $channelId = $_GET['id'];
    $token = getToken();
    
    // Build stream URL request
    $url = "http://$host/stalker_portal/server/load.php?type=itv&action=create_link&cmd=ffrt%20http://localhost/ch/{$channelId}&JsHttpRequest=1-xml";
    $headers = [
        "Referer: http://$host/stalker_portal/",
        "Authorization: Bearer $token",
        "Host: $host",
    ];
    
    $result = stalkerCurl($url, $headers, "timezone=GMT; stb_lang=en; mac=$mac");
    $data = json_decode($result['data'], true);
    
    // If token expired, regenerate and retry once
    if (!isset($data['js']['cmd'])) {
        $token = generateToken();
        $headers[1] = "Authorization: Bearer $token"; // Update token in headers
        $result = stalkerCurl($url, $headers, "timezone=GMT; stb_lang=en; mac=$mac");
        $data = json_decode($result['data'], true);
    }
    
    if (!isset($data['js']['cmd'])) {
        die("Failed to retrieve stream URL for channel ID: {$channelId}.");
    }
    
    header("Location: " . $data['js']['cmd']);
    exit();
}

// ============ DEFAULT: Redirect to index ============
header("Location: ?page=index");
exit();
