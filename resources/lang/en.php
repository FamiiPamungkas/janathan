<?php

declare(strict_types=1);

return [
    'title' => [
        'dashboard' => 'Dashboard - Janathan',
        'router_unreachable' => 'Router unreachable - Janathan',
    ],
    'menu' => [
        'dashboard' => 'Dashboard',
    ],
    'ui' => [
        'language' => 'Language',
    ],
    'dashboard' => [
        'connection_lost' => 'Connection lost',
        'hotspot_unavailable_title' => 'Hotspot is not available on this router',
        'hotspot_unavailable_body' => 'Session and user stats are hidden. Check that the hotspot package is installed and configured, and that the API user has permission to read it.',
        'stat' => [
            'active_sessions' => 'Active Sessions',
            'active_sessions_sub' => 'currently online',
            'total_users' => 'Total Users',
            'total_users_sub' => 'registered accounts',
            'traffic' => 'Traffic',
            'traffic_sub' => 'active sessions now',
            'cpu' => 'CPU Load',
            'cpu_sub' => 'router processor',
        ],
        'router' => 'Router',
        'router_identity' => 'Identity',
        'router_model' => 'Model',
        'router_board' => 'Board',
        'router_version' => 'Version',
        'router_uptime' => 'Uptime',
        'router_datetime' => 'Date & Time',
        'res_cpu' => 'CPU',
        'res_memory' => 'Memory',
        'res_storage' => 'Storage',
        'hotspot_logs' => 'Hotspot Logs',
        'log_entries' => 'entries',
        'logs_empty' => 'No hotspot log entries found.',
        'log_time' => 'Time',
        'log_user' => 'User (IP)',
        'log_message' => 'Message',
        'updated' => 'Updated',
        'pause_refresh' => 'Pause refresh',
        'resume_refresh' => 'Resume refresh',
    ],
    'error' => [
        'router_unreachable_title' => 'Cannot reach router',
        'router_unreachable_body' => 'Check that the router is powered on, reachable from this server, and that the saved credentials are correct.',
        'manage_routers' => 'Manage routers',
        'try_again' => 'Try again',
    ],
];
