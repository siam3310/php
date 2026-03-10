<?php
/**
 * MAC to M3U Stalker Portal Converter
 * Single File - No Write Permissions Required
 * Uses Session Storage Instead of Files
 * Bootstrap 5 Minimal UI
 */

// ============ CONFIGURATION ============
error_reporting(0);
date_default_timezone_set("Asia/Kolkata");

// Hardcoded credentials
define('VALID_USERNAME', 'siam');
define('VALID_PASSWORD', 'siam');

// API constant
define('API_SIGNATURE', '263');

// ============ SESSION STORAGE (instead of files) ============
session_start();

// Initialize session storage arrays if not exists
if (!isset($_SESSION['connection'])) $_SESSION['connection'] = [];
if (!isset($_SESSION['token'])) $_SESSION['token'] = '';
if (!isset($_SESSION['filter'])) $_SESSION['filter'] = [];
if (!isset($_SESSION['playlist_cache'])) $_SESSION['playlist_cache'] = '';

// Global variables from session
$url = $_SESSION['connection']['url'] ?? '';
$mac = $_SESSION['connection']['mac'] ?? '';
$sn = $_SESSION['connection']['serial_number'] ?? '';
$device_id_1 = $_SESSION['connection']['device_id_1'] ?? '';
$device_id_2 = $_SESSION['connection']['device_id_2'] ?? '';
$sig = $_SESSION['connection']['signature'] ?? '';
$host = '';

if ($url) {
    $host = parse_url($url, PHP_URL_HOST);
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
        CURLOPT_SSL_VERIFYPEER => false, // For HTTP URLs
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
        $_SESSION['token'] = $token;
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
 * Get or load token from session
 */
function getToken() {
    if (!empty($_SESSION['token'])) {
        return $_SESSION['token'];
    }
    return generateToken();
}

/**
 * Get channel groups from API
 */
function getGroups($filtered = false) {
    global $host;
    
    // Check session cache
    if (!empty($_SESSION['filter']['all_groups'])) {
        $groups = $_SESSION['filter']['all_groups'];
    } else {
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
        
        $groups = [];
        if (isset($data['js']) && is_array($data['js'])) {
            foreach ($data['js'] as $genre) {
                if ($genre['id'] === '*') continue;
                $groups[$genre['id']] = $genre['title'];
            }
        }
        
        $_SESSION['filter']['all_groups'] = $groups;
        
        // Initialize filter selections if not set
        if (empty($_SESSION['filter']['selected'])) {
            $_SESSION['filter']['selected'] = array_keys($groups);
        }
    }
    
    if ($filtered) {
        $selected = $_SESSION['filter']['selected'] ?? [];
        return array_intersect_key($groups, array_flip($selected));
    }
    
    return $groups;
}

// ============ ROUTING ============
$page = $_GET['page'] ?? 'login';

// ============ PAGE: LOGOUT ============
if ($page === 'logout') {
    session_destroy();
    header("Location: ?page=login");
    exit();
}

// ============ PAGE: LOGIN ============
if ($page === 'login') {
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
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MAC to M3U Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
            .card { border: none; border-radius: 1rem; box-shadow: 0 0.5rem 1rem 0 rgba(0, 0, 0, 0.1); }
            .card-title { color: #333; font-weight: 300; }
            .btn-login { font-size: 0.9rem; letter-spacing: 0.05rem; padding: 0.75rem 1rem; }
        </style>
    </head>
    <body>
        <div class="container py-5 h-100">
            <div class="row d-flex justify-content-center align-items-center h-100">
                <div class="col-12 col-md-8 col-lg-6 col-xl-5">
                    <div class="card bg-white text-dark">
                        <div class="card-body p-5 text-center">
                            <h3 class="card-title mb-4">MAC to M3U</h3>
                            <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <?= $error ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            <form method="POST">
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="username" name="username" placeholder="Username" required>
                                    <label for="username">Username</label>
                                </div>
                                <div class="form-floating mb-3">
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                                    <label for="password">Password</label>
                                </div>
                                <button class="btn btn-primary btn-login w-100" type="submit">Login</button>
                            </form>
                            <hr class="my-4">
                            <p class="text-muted small">Default: stalker / stalker@123</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit();
}

// ============ AUTH CHECK ============
if (!isset($_SESSION['user'])) {
    header("Location: ?page=login");
    exit();
}

// ============ PAGE: INDEX (Connection Form) ============
if ($page === 'index') {
    $isConnected = !empty($_SESSION['connection']);
    $message = '';
    
    // Handle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['disconnect'])) {
            $_SESSION['connection'] = [];
            $_SESSION['token'] = '';
            $_SESSION['filter'] = [];
            $_SESSION['playlist_cache'] = '';
            $isConnected = false;
            $message = '<div class="alert alert-success">Disconnected successfully!</div>';
        } else {
            $_SESSION['connection'] = [
                "url" => htmlspecialchars(trim($_POST['url'] ?? '')),
                "mac" => htmlspecialchars(trim($_POST['mac'] ?? '')),
                "serial_number" => htmlspecialchars(trim($_POST['sn'] ?? '')),
                "device_id_1" => htmlspecialchars(trim($_POST['device_id_1'] ?? '')),
                "device_id_2" => htmlspecialchars(trim($_POST['device_id_2'] ?? '')),
                "signature" => htmlspecialchars(trim($_POST['sig'] ?? ''))
            ];
            $isConnected = true;
            $message = '<div class="alert alert-success">Connected successfully!</div>';
            
            // Generate token on connection
            $url = $_SESSION['connection']['url'];
            $mac = $_SESSION['connection']['mac'];
            $host = parse_url($url, PHP_URL_HOST);
            generateToken();
        }
    }
    
    $playlistUrl = $_SERVER['SCRIPT_NAME'] . '?page=playlist';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Stalker Connection</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    </head>
    <body class="bg-light">
        <nav class="navbar navbar-dark bg-primary mb-4">
            <div class="container">
                <span class="navbar-brand mb-0 h1">MAC to M3U</span>
                <div>
                    <a href="?page=filter" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-funnel"></i> Filter</a>
                    <a href="?page=logout" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
        
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <?= $message ?>
                    
                    <div class="card shadow">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0"><?= $isConnected ? 'Device Information' : 'Connect to Stalker Portal' ?></h5>
                        </div>
                        <div class="card-body">
                            <?php if (!$isConnected): ?>
                                <form method="POST">
                                    <div class="mb-3">
                                        <label class="form-label">Portal URL</label>
                                        <input type="url" class="form-control" name="url" placeholder="http://your-server.com" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">MAC Address</label>
                                            <input type="text" class="form-control" name="mac" placeholder="00:1A:79:XX:XX:XX" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Serial Number</label>
                                            <input type="text" class="form-control" name="sn" placeholder="Serial" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Device ID 1</label>
                                            <input type="text" class="form-control" name="device_id_1" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Device ID 2</label>
                                            <input type="text" class="form-control" name="device_id_2" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Signature (Optional)</label>
                                        <input type="text" class="form-control" name="sig">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">Connect</button>
                                </form>
                            <?php else: ?>
                                <div class="list-group mb-3">
                                    <?php foreach ($_SESSION['connection'] as $key => $value): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><?= ucfirst(str_replace('_', ' ', $key)) ?>:</span>
                                            <span class="badge bg-secondary"><?= $value ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="input-group mb-3">
                                    <span class="input-group-text">Playlist URL</span>
                                    <input type="text" class="form-control" id="playlistUrl" value="<?= $playlistUrl ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyUrl()"><i class="bi bi-clipboard"></i></button>
                                </div>
                                
                                <form method="POST">
                                    <input type="hidden" name="disconnect" value="1">
                                    <button type="submit" class="btn btn-danger w-100">Disconnect</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function copyUrl() {
            var copyText = document.getElementById("playlistUrl");
            copyText.select();
            document.execCommand("copy");
            alert("URL copied to clipboard!");
        }
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit();
}

// ============ PAGE: FILTER ============
if ($page === 'filter') {
    if (empty($_SESSION['connection'])) {
        header("Location: ?page=index");
        exit();
    }
    
    // Reload global vars
    $url = $_SESSION['connection']['url'];
    $mac = $_SESSION['connection']['mac'];
    $host = parse_url($url, PHP_URL_HOST);
    
    $message = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['filter']['selected'] = $_POST['groups'] ?? [];
        $_SESSION['playlist_cache'] = ''; // Clear playlist cache
        $message = '<div class="alert alert-success">Filter settings saved!</div>';
    }
    
    $allGroups = getGroups(true);
    $selectedGroups = $_SESSION['filter']['selected'] ?? array_keys($allGroups);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Group Filter</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    </head>
    <body class="bg-light">
        <nav class="navbar navbar-dark bg-primary mb-4">
            <div class="container">
                <span class="navbar-brand mb-0 h1">Channel Group Filter</span>
                <div>
                    <a href="?page=index" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-house"></i> Home</a>
                    <a href="?page=logout" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
        
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <?= $message ?>
                    
                    <div class="card shadow">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">Select Groups to Include</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search groups...">
                                </div>
                                
                                <div class="mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="selectAll()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">Deselect All</button>
                                </div>
                                
                                <div class="list-group mb-3" style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($allGroups as $id => $title): ?>
                                        <div class="list-group-item">
                                            <div class="form-check">
                                                <input class="form-check-input group-checkbox" type="checkbox" name="groups[]" 
                                                       value="<?= $id ?>" id="group_<?= $id ?>" 
                                                       <?= in_array($id, $selectedGroups) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="group_<?= $id ?>">
                                                    <?= htmlspecialchars($title) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">Save Filter</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-body">
                            <div class="input-group">
                                <span class="input-group-text">Playlist URL</span>
                                <input type="text" class="form-control" id="playlistUrl" value="<?= $_SERVER['SCRIPT_NAME'] ?>?page=playlist" readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyUrl()"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function selectAll() {
            document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = true);
        }
        
        function deselectAll() {
            document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = false);
        }
        
        function copyUrl() {
            var copyText = document.getElementById("playlistUrl");
            copyText.select();
            document.execCommand("copy");
            alert("URL copied to clipboard!");
        }
        
        document.getElementById('searchInput').addEventListener('input', function(e) {
            let search = e.target.value.toLowerCase();
            document.querySelectorAll('.list-group-item').forEach(item => {
                let text = item.querySelector('label').textContent.toLowerCase();
                item.style.display = text.includes(search) ? '' : 'none';
            });
        });
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit();
}

// ============ PAGE: PLAYLIST (M3U Generation) ============
if ($page === 'playlist') {
    if (empty($_SESSION['connection'])) {
        header("Location: ?page=index");
        exit();
    }
    
    header('Content-Type: audio/x-mpegurl');
    header('Content-Disposition: attachment; filename="playlist.m3u"');
    
    // Return cached playlist if exists
    if (!empty($_SESSION['playlist_cache'])) {
        echo $_SESSION['playlist_cache'];
        exit();
    }
    
    // Reload global vars
    $url = $_SESSION['connection']['url'];
    $mac = $_SESSION['connection']['mac'];
    $sn = $_SESSION['connection']['serial_number'];
    $device_id_1 = $_SESSION['connection']['device_id_1'];
    $device_id_2 = $_SESSION['connection']['device_id_2'];
    $sig = $_SESSION['connection']['signature'];
    $host = parse_url($url, PHP_URL_HOST);
    
    $token = getToken();
    $filteredGroups = getGroups(false);
    
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
    
    // Cache in session
    $_SESSION['playlist_cache'] = $m3u;
    
    echo $m3u;
    exit();
}

// ============ PAGE: PLAY (Stream Redirect) ============
if ($page === 'play') {
    if (empty($_SESSION['connection']) || empty($_GET['id'])) {
        header("Location: ?page=index");
        exit();
    }
    
    $channelId = $_GET['id'];
    
    // Reload global vars
    $url = $_SESSION['connection']['url'];
    $mac = $_SESSION['connection']['mac'];
    $host = parse_url($url, PHP_URL_HOST);
    
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
        $headers[1] = "Authorization: Bearer $token";
        $result = stalkerCurl($url, $headers, "timezone=GMT; stb_lang=en; mac=$mac");
        $data = json_decode($result['data'], true);
    }
    
    if (!isset($data['js']['cmd'])) {
        die("Failed to retrieve stream URL for channel ID: {$channelId}.");
    }
    
    header("Location: " . $data['js']['cmd']);
    exit();
}

// Default redirect
header("Location: ?page=index");
exit();
