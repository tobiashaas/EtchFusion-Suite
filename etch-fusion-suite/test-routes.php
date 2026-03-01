<?php
// List all REST routes
$routes = rest_get_server()->get_routes();
foreach ($routes as $route => $endpoints) {
    if (strpos($route, 'efs') !== false) {
        echo "Route: $route\n";
        foreach ($endpoints as $endpoint) {
            echo "  Methods: " . implode(', ', $endpoint['methods']) . "\n";
        }
    }
}
?>
