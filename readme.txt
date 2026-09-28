=== Post to PDF for ConvertAPI ===
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Adds a "Download as PDF" link to posts and pages. PDFs are rendered by ConvertAPI and stored in the Media Library.

== Description ==

* A "Download as PDF" link is appended below the content of every published post and page.
* Each post/page has a "Show 'Download as PDF' link" toggle (block editor: Post sidebar → PDF download; classic editor: side meta box).
* The first download renders the page with ConvertAPI (HTML to PDF), saves the PDF to the Media Library attached to the post, then sends it to the visitor.
* While the PDF is generated, a "Preparing your PDF…" message with a spinner shows under the link; the download starts when it is ready, and errors are shown in place. Without JavaScript (or if the REST API is blocked) the link downloads directly.
* Video embeds (embed and video blocks, [video] shortcodes, and iframes from YouTube, Vimeo, Wistia, Kaltura, Panopto and other common hosts) are left out of the PDF.
* Later downloads serve the stored PDF without calling ConvertAPI. When the post or the PDF settings change, the next download renders a fresh PDF and replaces the old one.

== Settings ==

Settings → Post to PDF:

* ConvertAPI secret. It can instead be defined in wp-config.php: `define( 'PTPDF_CONVERTAPI_SECRET', '...' );`
* Rendering method.
  * Automatic (default): sends the page URL when the site is publicly reachable, otherwise uploads the page HTML.
  * Send the page URL: ConvertAPI loads the page itself. The site must be reachable from the internet.
  * Upload the page HTML: WordPress loads the page over loopback, embeds its local CSS, JS, images and fonts, and uploads the result. Use this for local, intranet or password-protected staging sites.
* Include only these elements. One CSS selector per line (e.g. `.entry-title`, `#main-content`). Only matching elements, everything inside them, and the containers needed to reach them are printed. Leave empty to print the whole page. If no selector matches a page, that page is printed whole.
* Custom CSS. Extra CSS applied to the page when it is converted. It is appended as the last stylesheet on the page, so it overrides theme styles of equal specificity. It does not affect the live site or the header/footer.
* Link text. Replaces "Download as PDF"; leave empty for the default.
* Link CSS. Styles the link on the site (`.ptpdf-download` is the paragraph, `.ptpdf-download a` the link). Printed only on pages that show the link. Changing the link settings does not regenerate PDFs.
* Header and Footer. Visual editors whose content is printed on every PDF page, with the space (mm) to reserve for each. Placeholders: `{page_number}`, `{total_pages}`, `{title}`, `{url}`, `{date}` (generation date), `{site_name}`. Headers and footers do not inherit theme CSS; images from this site's Media Library are embedded, external images may not render.
* Delete all generated PDFs. Frees the space used by stored PDFs.

== Notes ==

* "Automatic" treats hosts ending in .local, .test, .localhost etc., and hosts resolving to private or loopback IPs, as not public.
* Upload mode needs the server to be able to request its own pages (loopback). Assets on other public hosts (CDNs) are left as links for ConvertAPI to load.
* The custom CSS and the inclusion list are applied with JavaScript inside ConvertAPI's browser (the `UserJs` parameter). If you supply your own `UserJs` through the filter, append to it rather than replacing it.
* The link is only offered for published posts without a password.
* Failed conversions are written to the PHP error log with the prefix `[Post to PDF for ConvertAPI]`. Administrators also see the details on the error page.

== Filters ==

* `ptpdf_post_types` – post types that get the link (default `post`, `page`).
* `ptpdf_enabled_for_post` – `( bool $enabled, WP_Post $post )`.
* `ptpdf_link_text` – `( string $text, WP_Post $post )`, receives the Link text setting (or the default).
* `ptpdf_convertapi_params` – `( array $params, WP_Post $post )`, e.g. to set `PageSize`, margins, `Header`/`Footer`.
* `ptpdf_frontend_messages` – `( array $messages )`, the preparing/slow/starting/error texts shown under the link.
* `ptpdf_hidden_selectors` – `( string[] $selectors, WP_Post $post )`, elements always hidden in the PDF (video embeds, the link, the admin bar).
* `ptpdf_site_is_public` – `( bool $public, string $host )`, overrides the Automatic rendering method's detection.
