/*
 * Entrypoint for the Admin Panel (templates/admin/*.html.twig).
 *
 * Deliberately separate from assets/app.js (the public menu's entrypoint,
 * which pulls in the public site's global stylesheet) — this just needs to
 * expose an import map on admin pages so page-specific scripts can dynamically
 * `import('tom-select')` etc. It has no side effects of its own.
 */
