<?php
/*
|--------------------------------------------------------------------------
| status_label.php
| Display-only labels. Does NOT change stored values, queries, or logic.
| Place this file in: nutritrack/includes/
|--------------------------------------------------------------------------
*/
if (!function_exists('statusLabel')) {
    function statusLabel($status) {
        $map = array(
            'Stunted'          => 'Low Height',
            'Severely Stunted' => 'Severely Low Height'
        );

        $key = trim((string)$status);

        return isset($map[$key]) ? $map[$key] : $status;
    }
}