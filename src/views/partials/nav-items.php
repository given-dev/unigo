<?php
/**
 * UniGo - navigation map.
 *
 * Single source of truth for the sidebar and the mobile bottom bar. A user
 * with several roles simply gets the union of their groups (deduplicated by
 * path, first match wins).
 *
 * @var array $navBadges  path => badge count (upcoming bookings, open SOS, ...)
 * @return array<int,array{label:string,items:array<int,array<string,mixed>>}>
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Request;

$badges = $navBadges ?? [];

$map = [
    'passenger' => [
        ['label' => 'Travel', 'items' => [
            ['path' => '/home',                     'label' => 'Home',        'icon' => 'home'],
            ['path' => '/trips/search',             'label' => 'Find a trip', 'icon' => 'search'],
            ['path' => '/bookings',                 'label' => 'My bookings', 'icon' => 'ticket', 'badge' => '/bookings'],
            ['path' => '/tracking',                 'label' => 'Track trip',  'icon' => 'navigation'],
            ['path' => '/payments',                 'label' => 'Payments',    'icon' => 'card'],
            ['path' => '/deliveries',               'label' => 'My parcels',  'icon' => 'package'],
        ]],
        ['label' => 'Account', 'items' => [
            ['path' => '/complaints', 'label' => 'My complaints', 'icon' => 'message'],
            ['path' => '/support',    'label' => 'Help & support', 'icon' => 'headset'],
            ['path' => '/profile',    'label' => 'My profile',     'icon' => 'user'],
        ]],
    ],

    'driver' => [
        ['label' => 'Driving', 'items' => [
            ['path' => '/driver/dashboard', 'label' => 'Dashboard',    'icon' => 'gauge'],
            ['path' => '/driver/trips',     'label' => 'My trips',     'icon' => 'bus',    'badge' => '/driver/trips'],
            ['path' => '/driver/tracking',  'label' => 'Live tracking', 'icon' => 'navigation'],
            ['path' => '/driver/deliveries', 'label' => 'Deliveries',  'icon' => 'package'],
        ]],
        ['label' => 'Earnings', 'items' => [
            ['path' => '/driver/earnings', 'label' => 'Earnings',  'icon' => 'coins'],
            ['path' => '/driver/ratings',  'label' => 'My ratings', 'icon' => 'star'],
            ['path' => '/driver/sos',      'label' => 'SOS log',   'icon' => 'siren', 'danger' => true],
        ]],
    ],

    'operator' => [
        ['label' => 'Operations', 'items' => [
            ['path' => '/operator/dashboard', 'label' => 'Dashboard',  'icon' => 'dashboard'],
            ['path' => '/operator/trips',     'label' => 'Trips',      'icon' => 'bus',  'badge' => '/operator/trips'],
            ['path' => '/operator/vehicles',  'label' => 'Fleet',      'icon' => 'car'],
            ['path' => '/operator/drivers',   'label' => 'Drivers',    'icon' => 'users'],
            ['path' => '/operator/routes',    'label' => 'Routes',     'icon' => 'route'],
        ]],
        ['label' => 'Insight', 'items' => [
            ['path' => '/operator/bookings', 'label' => 'Bookings', 'icon' => 'ticket'],
            ['path' => '/operator/revenue',  'label' => 'Revenue',  'icon' => 'coins'],
            ['path' => '/operator/reports',  'label' => 'Reports',  'icon' => 'bar-chart'],
            ['path' => '/operator/settings', 'label' => 'Settings', 'icon' => 'settings'],
        ]],
    ],

    'authority' => [
        ['label' => 'Command', 'items' => [
            ['path' => '/authority/dashboard',  'label' => 'Dashboard',   'icon' => 'dashboard'],
            ['path' => '/authority/monitor',    'label' => 'Live network', 'icon' => 'globe'],
            ['path' => '/authority/emergencies', 'label' => 'Emergencies', 'icon' => 'siren', 'badge' => '/authority/emergencies', 'danger' => true],
            ['path' => '/authority/complaints',  'label' => 'Complaints',  'icon' => 'message', 'badge' => '/authority/complaints'],
        ]],
        ['label' => 'Oversight', 'items' => [
            ['path' => '/authority/operators', 'label' => 'Operators', 'icon' => 'building'],
            ['path' => '/authority/reports',   'label' => 'Reports',   'icon' => 'bar-chart'],
            ['path' => '/authority/analytics', 'label' => 'Analytics', 'icon' => 'pie-chart'],
        ]],
    ],

    'admin' => [
        ['label' => 'Overview', 'items' => [
            ['path' => '/admin/dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ]],
        ['label' => 'Directory', 'items' => [
            ['path' => '/admin/users',     'label' => 'Users',     'icon' => 'users'],
            ['path' => '/admin/operators', 'label' => 'Operators', 'icon' => 'building'],
            ['path' => '/admin/vehicles',  'label' => 'Vehicles',  'icon' => 'car'],
            ['path' => '/admin/drivers',   'label' => 'Drivers',   'icon' => 'badge'],
            ['path' => '/admin/routes',    'label' => 'Routes',    'icon' => 'route'],
        ]],
        ['label' => 'Operations', 'items' => [
            ['path' => '/admin/trips',      'label' => 'Trips',      'icon' => 'bus',     'badge' => '/admin/trips'],
            ['path' => '/admin/bookings',   'label' => 'Bookings',   'icon' => 'ticket'],
            ['path' => '/admin/deliveries', 'label' => 'Deliveries', 'icon' => 'package'],
            ['path' => '/admin/payments',   'label' => 'Payments',   'icon' => 'card'],
        ]],
        ['label' => 'Safety & insight', 'items' => [
            ['path' => '/admin/emergencies', 'label' => 'Emergencies', 'icon' => 'siren',   'badge' => '/admin/emergencies', 'danger' => true],
            ['path' => '/admin/complaints',   'label' => 'Complaints',  'icon' => 'message', 'badge' => '/admin/complaints'],
            ['path' => '/admin/ratings',      'label' => 'Ratings',     'icon' => 'star'],
            ['path' => '/admin/reports',      'label' => 'Reports',     'icon' => 'bar-chart'],
        ]],
        ['label' => 'System', 'items' => [
            ['path' => '/admin/audit',     'label' => 'Activity log', 'icon' => 'clipboard'],
            ['path' => '/admin/settings',  'label' => 'Settings',     'icon' => 'settings'],
        ]],
    ],
];

// Merge the groups of every role the user holds. The label is the array key
// while collecting, so it has to be copied into each group before returning.
$groups  = [];
$seen    = [];
$current = Request::instance()->path();

foreach ($map as $role => $roleGroups) {
    if (!Auth::hasRole($role)) {
        continue;
    }
    foreach ($roleGroups as $group) {
        foreach ($group['items'] as $item) {
            if (isset($seen[$item['path']])) {
                continue;
            }
            $seen[$item['path']] = true;
            $item['active'] = nav_active($item['path'], $current, $item['path'] === '/home');
            $item['count']  = (int) ($badges[$item['path']] ?? ($badges[$item['badge'] ?? ''] ?? 0));
            unset($item['badge']);
            $groups[$group['label']]['items'][] = $item;
        }
    }
}

$result = [];
foreach ($groups as $label => $group) {
    if (!empty($group['items'])) {
        $result[] = ['label' => $label, 'items' => $group['items']];
    }
}

return $result;
