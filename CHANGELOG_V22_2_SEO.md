# Smart Gateway v22.2 — SEO and Public/Private Indexing

- Added a responsive public landing page at `/` for the only indexable URL.
- Added unique title, meta description, canonical URL, Open Graph metadata, Twitter summary metadata, and JSON-LD structured data.
- Added `robots.txt` with sitemap location and crawler exclusions for admin, API, database, include, upload, authentication, and test surfaces.
- Rebuilt `sitemap.xml` to contain only the public homepage.
- Added a central canonical production URL constant: `https://smartgateway-hercorhs.site`.
- Added `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` for `/admin/`, `/api/`, `login.php`, and `forgot_password.php`.
- Updated the shared header so authenticated/internal pages remain noindex by default; public pages must explicitly opt in with `$seoIndexable = true`.
- Existing login and forgot-password pages remain noindex.
