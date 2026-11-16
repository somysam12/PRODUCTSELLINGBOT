<?php
function svgIcon(string $name, int $size=20): string {
    // Icon set: Lucide (github.com/lucide-icons/lucide, MIT) — 24px grid, 2px rounded stroke.
    $w = $size; $h = $size;
    $s = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
      'user'       => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
      'lock'       => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
      'key'        => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L21 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>',
      'eye'        => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>',
      'eye-off'    => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>',
      'shield'     => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
      'check'      => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M20 6 9 17l-5-5"/></svg>',
      'arrow-right'=> '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>',
      'mail'       => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
      'phone'      => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
      'refresh'    => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
      'link'       => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      'alert'      => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
      'telegram'   => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
      'login'      => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>',
      'register'   => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>',
      'trash'      => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>',
      'ban'        => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>',
      'info'       => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
      'zap'        => '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 24 24" '.$s.'><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
    ];
    return $icons[$name] ?? '';
}
