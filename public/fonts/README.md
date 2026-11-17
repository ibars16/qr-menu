Web fonts served from our own server instead of Google Fonts (no visitor IP
goes to Google). The CSS files are Google Fonts' own css2 responses
(display=swap, woff2, every unicode-range subset) with the gstatic URLs
replaced by these local files, so the pages look exactly as before.

- fraunces-inter.css — Fraunces (opsz 9..144; 400/500/600, italic 400/500)
  + Inter (400/500/600). Public site, public menu.
- plus-jakarta-sans.css — Plus Jakarta Sans 400–800. Public menu, login,
  sign-up pages.
- arabic.css — IBM Plex Sans Arabic 400–700 + Noto Naskh Arabic 400–600.
  Right-to-left public menus.

All under the SIL Open Font License 1.1 (https://openfontlicense.org).
