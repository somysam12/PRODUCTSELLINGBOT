<?php
// =====================================================================
// LIB DELIVERY CHANNEL RESOLVER
// ---------------------------------------------------------------------
// Two link addresses point at the SAME php handlers, but serve from
// different folders so OLD apps and NEW apps get different libs:
//
//   NEW app  →  /secure/auth  +  /secure/pack   →  server/libs/
//   OLD app  →  /secure/unlock + /secure/lib    →  server/libs_legacy/
//
// Old links fall back to server/libs/ ONLY while server/libs_legacy/
// is empty, so nothing breaks the moment this deploys. Drop a frozen
// .so/.zip into server/libs_legacy/ to cut old apps off from updates:
// after that, old apps keep that frozen lib and every new upload to
// server/libs/ reaches new apps only.
// =====================================================================

if (!function_exists('hb_lib_basedir')) {
    function hb_lib_basedir(): string {
        $active = dirname(__DIR__).'/server/libs/';
        $legacy = dirname(__DIR__).'/server/libs_legacy/';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';

        // New app uses the new words → always the active folder.
        if (strpos($uri, '/secure/auth') !== false || strpos($uri, '/secure/pack') !== false) {
            return $active;
        }

        // Old app (legacy words) → frozen folder when it actually holds a lib.
        if (is_dir($legacy)) {
            foreach ((glob($legacy.'*') ?: []) as $f) {
                if (!is_file($f)) continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['so','zip','tar','gz','lib','dll','a','o','bin'], true)) {
                    return $legacy;
                }
            }
        }
        // Frozen folder empty / missing → don't break live apps yet.
        return $active;
    }
}
