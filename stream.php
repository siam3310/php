<?php
error_reporting(0);

// Get channel name from query parameter, with a default for testing
$channel_name = isset($_GET['channel']) ? trim($_GET['channel']) : 'ACC Network';

if (empty($channel_name)) {
    http_response_code(400);
    die('Channel name is required. Please use the format: ?channel=CHANNEL_NAME');
}

$m3u_file = 'siamcdnplaylist.m3u';

if (!file_exists($m3u_file)) {
    http_response_code(500);
    die('Error: Playlist file (siamcdnplaylist.m3u) not found.');
}

// Read the playlist file
$lines = file($m3u_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$stream_url = '';
$referer = '';

// Loop through the playlist to find the channel
foreach ($lines as $i => $line) {
    if (strpos($line, '#EXTINF:') === 0) {
        $parts = explode(',', $line);
        $name_from_playlist = trim(end($parts));

        // Case-insensitive comparison of channel names
        if (strcasecmp($name_from_playlist, $channel_name) === 0) {
            // Found the channel. The next lines should contain the referer and the URL.
            
            // Check for referer on the next line
            if (isset($lines[$i + 1]) && strpos($lines[$i + 1], '#EXTVLCOPT:http-referrer=') === 0) {
                $referer = trim(str_replace('#EXTVLCOPT:http-referrer=', '', $lines[$i + 1]));
                // The stream URL will be on the line after the referer
                if (isset($lines[$i + 2]) && filter_var($lines[$i + 2], FILTER_VALIDATE_URL)) {
                    $stream_url = trim($lines[$i + 2]);
                    break; // Exit loop once found
                }
            } 
            // Check for URL on the next line (if no referer)
            else if (isset($lines[$i + 1]) && filter_var($lines[$i + 1], FILTER_VALIDATE_URL)) {
                $stream_url = trim($lines[$i + 1]);
                break; // Exit loop once found
            }
        }
    }
}

if (empty($stream_url)) {
    http_response_code(404);
    die('Error: Channel "' . htmlspecialchars($channel_name) . '" not found in the playlist.');
}

// Use the encoding from your proxy script (proxy.php)
$encoded_url = bin2hex(base64_encode($stream_url));

// Construct the final URL for the proxy
$proxy_url = 'proxy.php?url=' . $encoded_url;

if (!empty($referer)) {
    // Pass the referer to the proxy script
    $proxy_url .= '&referer=' . urlencode($referer);
}

// Redirect the browser to the proxy script
header('Location: ' . $proxy_url);
exit;
?>
