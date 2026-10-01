<?php
/**
 * UniGo - inline SVG icon sprite.
 *
 * Rendered once per page (see layouts/app.php). Views only need to emit
 * <i class="icon" data-icon="name">name</i>; app.js replaces the placeholder
 * with <svg class="icon"><use href="#i-name"></use></svg>, so the icons work
 * offline (no icon font, no extra HTTP request) and inherit `color`.
 *
 * Adding a new icon: drop a <symbol id="i-name"> below, then use "name".
 */
?>
<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <!-- ===================== navigation / structure ===================== -->
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/></symbol>
    <symbol id="i-dashboard" viewBox="0 0 24 24"><path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></symbol>
    <symbol id="i-layers" viewBox="0 0 24 24"><path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></symbol>
    <symbol id="i-breadcrumb" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></symbol>

    <!-- ========================= transport ========================= -->
    <symbol id="i-bus" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 16v4M17 16v4"/><circle cx="7" cy="16" r="1"/><circle cx="17" cy="16" r="1"/><path d="M7 20.5h.01M17 20.5h.01"/></symbol>
    <symbol id="i-car" viewBox="0 0 24 24"><path d="m5 11 1.6-4.3A2 2 0 0 1 8.5 5.4h7a2 2 0 0 1 1.9 1.3L19 11"/><rect x="3" y="11" width="18" height="6" rx="1.5"/><circle cx="7" cy="17" r="1.6"/><circle cx="17" cy="17" r="1.6"/></symbol>
    <symbol id="i-moto" viewBox="0 0 24 24"><circle cx="5.5" cy="17" r="3"/><circle cx="18.5" cy="17" r="3"/><path d="M8.5 17h4.5l2.5-6H12"/><path d="M12 11 10 7H7.5"/><path d="M15.5 11H18"/></symbol>
    <symbol id="i-truck" viewBox="0 0 24 24"><rect x="1.5" y="6" width="12" height="10" rx="1.5"/><path d="M13.5 9.5h4l3 3.5V16h-7z"/><circle cx="6" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/><path d="M4.5 10h6M4.5 12.5h4"/></symbol>
    <symbol id="i-boat" viewBox="0 0 24 24"><path d="M12 3v12"/><path d="M4 11.5 12 15l8-3.5"/><path d="M2 20c2 0 3-1 5-1s3 1 5 1 3-1 5-1 3 1 5 1"/></symbol>
    <symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></symbol>
    <symbol id="i-route" viewBox="0 0 24 24"><circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M15.5 5H10a4 4 0 0 0 0 8h4a4 4 0 0 1 0 8H8.5"/></symbol>
    <symbol id="i-navigation" viewBox="0 0 24 24"><path d="M3 11 21 3l-8 18-2-7z"/></symbol>
    <symbol id="i-compass" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="2.8"/></symbol>
    <symbol id="i-fuel" viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16"/><path d="M3 21h11M4 11h9"/><path d="M16 8h2.5A1.5 1.5 0 0 1 20 9.5V17a1.5 1.5 0 0 0 3 0V9l-3-3"/></symbol>
    <symbol id="i-gauge" viewBox="0 0 24 24"><path d="M20.5 17a9 9 0 1 0-17 0"/><path d="m14.5 9.5-3 3-3-1"/></symbol>

    <!-- ======================== people / roles ======================== -->
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.6"/><path d="M16 14.4a5.5 5.5 0 0 1 5.5 5.6"/></symbol>
    <symbol id="i-user-plus" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 11.5-5.3"/><path d="M19 8v6M22 11h-6"/></symbol>
    <symbol id="i-user-check" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 10-6.2"/><path d="m16 19 2 2 4-4"/></symbol>
    <symbol id="i-award" viewBox="0 0 24 24"><circle cx="12" cy="9" r="6"/><path d="m8.2 14-1.4 7 5.2-3 5.2 3-1.4-7"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 3 3-2 2-2-2-2 2 2 2-3 3z"/></symbol>
    <symbol id="i-badge" viewBox="0 0 24 24"><path d="m12 2 2.5 1.7 3.1-.2.9 3 2.7 1.7-1.2 2.9 1.2 2.9-2.7 1.7-.9 3-3.1-.2L12 22l-2.5-1.7-3.1.2-.9-3L3 16.2 4.2 13 3 10.1l2.7-1.7.9-3 3.1.2z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-building" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01"/><path d="M10 21v-3h4v3"/></symbol>
    <symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M2 12h20"/></symbol>
    <symbol id="i-package" viewBox="0 0 24 24"><path d="m12 2 9 5v10l-9 5-9-5V7z"/><path d="m3 7 9 5 9-5"/><path d="M12 12v10"/></symbol>

    <!-- ========================== commerce ========================== -->
    <symbol id="i-ticket" viewBox="0 0 24 24"><path d="M3 9V7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2a3 3 0 0 0 0 6v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2a3 3 0 0 0 0-6z"/><path d="M14 6v1.5M14 10.8v2.4M14 16.5V18"/></symbol>
    <symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h3"/></symbol>
    <symbol id="i-wallet" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 6V5a2 2 0 0 1 2-2h11"/><path d="M16 12h.01"/></symbol>
    <symbol id="i-receipt" viewBox="0 0 24 24"><path d="M5 3h14v18l-2.5-1.6L14 21l-2-1.6L10 21l-2-1.6L5 21z"/><path d="M9 8h6M9 12h6"/></symbol>
    <symbol id="i-coins" viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></symbol>
    <symbol id="i-banknote" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></symbol>
    <symbol id="i-star" viewBox="0 0 24 24"><path d="m12 2.6 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
    <symbol id="i-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></symbol>

    <!-- ====================== status / feedback ====================== -->
    <symbol id="i-alert" viewBox="0 0 24 24"><path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0z"/><path d="M12 9v4.5M12 17.2h.01"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.2 12.4 2.6 2.6 5-5.4"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8h.01"/></symbol>
    <symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.4-3 4"/><path d="M12 17.3h.01"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
    <symbol id="i-megaphone" viewBox="0 0 24 24"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M16 9.5a4 4 0 0 1 0 5"/><path d="M19 6.5a8 8 0 0 1 0 11"/></symbol>
    <symbol id="i-siren" viewBox="0 0 24 24"><path d="M7 18v-4a5 5 0 0 1 10 0v4"/><path d="M4 18h16v3H4z"/><path d="M12 3v2M4.9 6.1 6.3 7.5M19.1 6.1 17.7 7.5M2 12h2M20 12h2"/></symbol>
    <symbol id="i-flame" viewBox="0 0 24 24"><path d="M12 22a6 6 0 0 0 6-6c0-5-4-6-4-11 0 0-3 2-3 6 0 2-1 3-2 3s-1-1.5-1-2.5C6 13 6 16 6 16a6 6 0 0 0 6 6z"/></symbol>
    <symbol id="i-sparkles" viewBox="0 0 24 24"><path d="m12 3 1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M18 15.5 18.8 18l2.2.8-2.2.8L18 22l-.8-2.4L15 18.8l2.2-.8z"/></symbol>

    <!-- ============================ misc ============================ -->
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></symbol>
    <symbol id="i-filter" viewBox="0 0 24 24"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></symbol>
    <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 6h9M19 6h1M4 12h3M13 12h7M4 18h9M19 18h1"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="16" cy="18" r="2"/></symbol>
    <symbol id="i-sort" viewBox="0 0 24 24"><path d="M8 4v16M4.5 7.5 8 4l3.5 3.5"/><path d="M16 20V4M12.5 16.5 16 20l3.5-3.5"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
    <symbol id="i-more" viewBox="0 0 24 24"><path d="M12 5.5h.01M12 12h.01M12 18.5h.01"/></symbol>
    <symbol id="i-chevron-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-chevron-up" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></symbol>
    <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol>
    <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
    <symbol id="i-arrow-right" viewBox="0 0 24 24"><path d="M4 12h16"/><path d="m14 6 6 6-6 6"/></symbol>
    <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M20 12H4"/><path d="m10 6-6 6 6 6"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3.5V9h-5.5"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/></symbol>
    <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
    <symbol id="i-history" viewBox="0 0 24 24"><path d="M3.5 12a8.5 8.5 0 1 0 2.9-6.4"/><path d="M3 3.5V9h5.5"/><path d="M12 7.5V12l3.4 2"/></symbol>
    <symbol id="i-trending-up" viewBox="0 0 24 24"><path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h6v6"/></symbol>
    <symbol id="i-trending-down" viewBox="0 0 24 24"><path d="m3 7 6 6 4-4 8 8"/><path d="M17 17h6v-6"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
    <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M4 20h4L18 10l-4-4L4 16z"/><path d="m14 6 4 4"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M10 11v6M14 11v6"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/></symbol>
    <symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 21V9"/><path d="m7 13 5-5 5 5"/><path d="M4 4h16"/></symbol>
    <symbol id="i-print" viewBox="0 0 24 24"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="m3 3 18 18"/><path d="M10.6 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.3 4.1"/><path d="M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></symbol>
    <symbol id="i-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 18h1v3h-1zM14 20h3M17 14h4"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 2.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H7a1.7 1.7 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V7a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" transform="translate(1.5 1.5) scale(0.875)"/></symbol>
    <symbol id="i-log-out" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></symbol>
    <symbol id="i-login" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></symbol>
    <symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></symbol>
    <symbol id="i-message" viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/></symbol>
    <symbol id="i-headset" viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0"/><rect x="2" y="13" width="4" height="6" rx="1.5"/><rect x="18" y="13" width="4" height="6" rx="1.5"/><path d="M20 19v1a2 2 0 0 1-2 2h-4"/></symbol>
    <symbol id="i-bar-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M2 20h20"/></symbol>
    <symbol id="i-pie-chart" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M15 3.5A8.5 8.5 0 0 1 20.5 9H15z"/></symbol>
    <symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 9a15 15 0 0 1 20 0"/><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><path d="M12 19.8h.01"/></symbol>
    <symbol id="i-wifi-off" viewBox="0 0 24 24"><path d="m3 3 18 18"/><path d="M5 12.5a10 10 0 0 1 4.6-2.5"/><path d="M8.5 16a5.5 5.5 0 0 1 4-1.6"/><path d="M2 9a15 15 0 0 1 4.3-2.5M12 19.8h.01M15 9.5a10 10 0 0 1 4 3"/></symbol>
    <symbol id="i-signal" viewBox="0 0 24 24"><path d="M2 20h.01M7 20v-4M12 20v-8M17 20V8M22 20V4"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6M9 17h4"/></symbol>
    <symbol id="i-clipboard" viewBox="0 0 24 24"><path d="M8 3h8v3H8z"/><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 11h6M9 15h6"/></symbol>
    <symbol id="i-clipboard-check" viewBox="0 0 24 24"><path d="M8 3h8v3H8z"/><rect x="5" y="4" width="14" height="17" rx="2"/><path d="m9 13 2 2 4-4"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></symbol>
    <symbol id="i-play" viewBox="0 0 24 24"><path d="m6 4 14 8-14 8z"/></symbol>
    <symbol id="i-stop" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"/></symbol>
    <symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></symbol>
    <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></symbol>
    <symbol id="i-smartphone" viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18.5h2"/></symbol>
    <symbol id="i-mic" viewBox="0 0 24 24"><path d="M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3z"/><path d="M19 11a7 7 0 0 1-14 0"/><path d="M12 18.5V21"/></symbol>
    <symbol id="i-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/></symbol>
    <symbol id="i-flag" viewBox="0 0 24 24"><path d="M5 22V4"/><path d="M5 4h11l-1.5 4L16 12H5z"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z"/></symbol>
    <symbol id="i-flame-fill" viewBox="0 0 24 24"><path d="M12 22a6 6 0 0 0 6-6c0-5-4-6-4-11 0 0-3 2-3 6 0 2-1 3-2 3s-1-1.5-1-2.5C6 13 6 16 6 16a6 6 0 0 0 6 6z" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-star-fill" viewBox="0 0 24 24"><path d="m12 2.6 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-spinner" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9" /></symbol>
</svg>
