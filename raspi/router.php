<?php
/**
 * Router für den eingebauten PHP-Webserver auf dem Raspberry Pi.
 * Sperrt interne Dateien (Messdaten, Token, Sicherungen, Zugangsdaten), alles andere normal ausliefern.
 */
$pfad = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#^/(data|raspi|tests|lib|mappings|online|docs)/#', $pfad) || preg_match('#\.(bak|md|bat|sh|txt|json)$#i', $pfad)
    || preg_match('#/(cloud_config|monitor_config|online_config|config|monitor|logger|mock_server)\.php$#', $pfad)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kein Zugriff.\n";
    return true;
}
if ($pfad === '/' || $pfad === '') {
    header('Location: /monitor.html');
    return true;
}
// vendor/ (QR-Code) und die Seiten/API normal ausliefern
return false;
