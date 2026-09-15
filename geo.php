<?php
/**
 * geo.php — ziyaretçinin ülke kodunu döndürür (Store gate + dil seçimi için).
 * Öncelik: sunucu GeoIP başlığı → yoksa ip-api.com (server-side, ücretsiz, key'siz).
 * IP başına 24 saat cache → API çağrısı minimize. Secret YOK, public.
 * Yanıt: {"country":"GB"}  (bilinmiyorsa {"country":""})
 */
ini_set('display_errors', '0'); // hiçbir uyarı/notice JSON'a sızmasın (yoksa r.json() bozulur → gate çalışmaz)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

function g2c_client_ip() {
    // LiteSpeed doğrudan servis → REMOTE_ADDR gerçek istemci. CDN eklenirse güvenilir header'a geç.
    foreach (array('HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k]) && filter_var($_SERVER[$k], FILTER_VALIDATE_IP)) return $_SERVER[$k];
    }
    return '';
}

$country = '';

// 1) Hazır GeoIP başlığı (varsa anında, API'siz)
foreach (array('GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_GEO_COUNTRY') as $h) {
    if (!empty($_SERVER[$h]) && strlen($_SERVER[$h]) === 2 && ctype_alpha($_SERVER[$h])) {
        $country = $_SERVER[$h]; break;
    }
}

// 2) Fallback: ip-api.com (IP başına cache'li — 45 istek/dk limitini korur)
$ip = g2c_client_ip();
if ($country === '' && $ip !== '') {
    $dir = sys_get_temp_dir() . '/g2c_geo';
    @mkdir($dir, 0700, true);
    $cf = $dir . '/' . sha1($ip) . '.txt';
    if (is_file($cf) && (time() - filemtime($cf) < 86400)) {
        $country = trim((string) @file_get_contents($cf));
    } elseif (function_exists('curl_init')) {
        $ch = curl_init('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=countryCode');
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2));
        $resp = curl_exec($ch);
        $d = $resp ? json_decode($resp, true) : null;
        if (!empty($d['countryCode']) && ctype_alpha($d['countryCode'])) {
            $country = $d['countryCode'];
            @file_put_contents($cf, $country, LOCK_EX);
        }
    }
}

echo json_encode(array('country' => strtoupper(substr((string) $country, 0, 2))));
