<?php
if (!function_exists('bricks_is_builder')) { function bricks_is_builder() { return true; } }
$token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ0YXJnZXRfdXJsIjoiaHR0cDovL2xvY2FsaG9zdDo4ODg5IiwiZG9tYWluIjoiaHR0cDovL2xvY2FsaG9zdDo4ODg4IiwiaWF0IjoxNzcxOTU5NzM5LCJleHAiOjE3NzE5ODg1MzksImp0aSI6IjIzMjFmOTY5LTE1NWUtNDIyNS05NDYxLWMwMGNlOThlMDAzZCJ9.lddOm1eghrCpqO_OOb2GQKTzB6Hm0i6XkGZGx4OjB0k';
$result = etch_fusion_suite_container()->get('migration_controller')->start_migration(array(
    'migration_key'       => $token,
    'target_url'          => 'http://localhost:8889',
    'batch_size'          => 50,
    'mode'                => 'headless',
    'selected_post_types' => array('page'),
    'post_type_mappings'  => array('page' => 'page'),
    'include_media'       => false,
));
if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message()); exit(1); }
echo wp_json_encode($result);
