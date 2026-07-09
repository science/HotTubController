// Prerender v2 pages as directory indexes (v2/index.html, v2/schedule/index.html, ...).
//
// Without this, the v2 home page is emitted as a sibling file `v2.html` while the
// sub-pages create a real `v2/` directory with no index in it. The production
// .htaccess only rewrites to the SPA fallback when the request is neither a real
// file nor a real directory, so `/tub/v2` hit the empty directory and LiteSpeed
// served a directory listing instead of the app.
export const trailingSlash = 'always';
