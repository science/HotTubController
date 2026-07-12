// Prerender the v2 pages (now mounted at the root) as directory indexes
// (index.html, schedule/index.html, setup/index.html).
//
// Without this, sub-pages are emitted as sibling files (schedule.html) while any
// nested route creates a real directory with no index in it. The production
// .htaccess only rewrites to the SPA fallback when the request is neither a real
// file nor a real directory, so a bare directory URL would hit LiteSpeed's
// directory listing instead of the app (this bit /tub/v2 before the swap; the v1
// routes now carry the same setting for the same reason).
export const trailingSlash = 'always';
