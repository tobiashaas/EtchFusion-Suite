<?php
/**
 * Kurze Prüfung: Welche php.ini wird geladen, sind zip/openssl aktiv?
 * Aufruf: php docs/check-php-extensions.php
 */
echo "PHP-Version:    " . PHP_VERSION . "\n";
echo "php.ini (geladen): " . php_ini_loaded_file() . "\n";
echo "extension_dir:  " . ini_get('extension_dir') . "\n";
echo "\nErweiterungen:\n";
echo "  zip:     " . (extension_loaded('zip')     ? 'JA' : 'NEIN') . "\n";
echo "  openssl: " . (extension_loaded('openssl') ? 'JA' : 'NEIN') . "\n";
echo "  curl:    " . (extension_loaded('curl')    ? 'JA' : 'NEIN') . "\n";
echo "  mbstring: " . (extension_loaded('mbstring') ? 'JA' : 'NEIN') . "\n";
if (!extension_loaded('openssl')) {
    echo "\nHinweis: OpenSSL fehlt. Prüfe ob " . ini_get('extension_dir') . "\\php_openssl.dll existiert.\n";
}
