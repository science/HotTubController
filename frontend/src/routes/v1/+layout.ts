// Prerender v1 pages as directory indexes (v1/index.html, v1/users/index.html).
//
// Without this, the v1 home page is emitted as a sibling file `v1.html` while the
// sub-pages create a real `v1/` directory with no index in it. The production
// .htaccess only rewrites to the SPA fallback when the request is neither a real
// file nor a real directory, so `/tub/v1` would hit the empty directory and
// LiteSpeed would serve a directory listing instead of the app (same pattern as
// the v2 routes had when they were mounted at /v2).
export const trailingSlash = 'always';
