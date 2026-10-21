<?php
/**
 * Geocodificação de endereços via Nominatim (OpenStreetMap).
 * Fallback (RNF06): sem internet, usa o centro conhecido da região.
 */
require_once __DIR__ . '/db.php';

function geocodificar(string $endereco, string $regiao): array {
    if (function_exists('curl_init')) {
        $ch = curl_init('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . urlencode("$endereco, Taubaté, SP"));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'SENN-AIX-Logistica/1.0']);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (!empty($r[0]['lat'])) return [(float)$r[0]['lat'], (float)$r[0]['lon'], 'osm'];
    }
    $c = q('SELECT AVG(lat) lat, AVG(lng) lng FROM pedidos WHERE regiao = ?', [$regiao])->fetch();
    if ($c && $c['lat']) return [(float)$c['lat'], (float)$c['lng'], 'regiao'];
    return [DEPOSITO_LAT, DEPOSITO_LNG, 'deposito'];
}
