<?php
/** Tiny inline-SVG icon set keyed by name (stroke = currentColor). */
function icon(string $name): string
{
    $p = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'calendar'=> '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'upload'  => '<path d="M12 15V3m0 0l-4 4m4-4l4 4"/><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
        'check'   => '<path d="M4 12l5 5L20 6"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'edit'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4z"/>',
        'swap'    => '<path d="M7 7h11l-3-3m3 3l-3 3M17 17H6l3 3m-3-3l3-3"/>',
        'plus'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'users'   => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0112 0"/><path d="M16 5.5a3 3 0 010 5.8M21 20a6 6 0 00-5-5.9"/>',
        'building'=> '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M8 11h2M8 15h2M14 7h2M14 11h2M14 15h2"/>',
    ];
    $body = $p[$name] ?? $p['grid'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
