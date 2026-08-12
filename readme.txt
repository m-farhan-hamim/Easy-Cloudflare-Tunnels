=== PSBDx Smart Report Management ===
Contributors:      psbdx, atwfarhan, mfhamim
Tags:              support ticket, helpdesk, ai, woocommerce, complaint
Requires at least: 5.8
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.4.6
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted support ticket & complaint system for WordPress — instant AJAX forms, smart triage, ticket IDs, deep WooCommerce/LearnPress support.

== Description ==

**PSBDx Smart Report Management** turns any WordPress site into a lightweight support desk. Customers report a problem — with an order, a product, a course, or anything else — through a fast AJAX modal that never reloads the page, and every submission lands in a clean, organized admin inbox your team actually enjoys working from.

No support-ticket plugin bloat, no separate helpdesk to learn, no per-agent pricing. Just a focused, fast, genuinely useful report and complaint management system that plugs straight into WooCommerce and LearnPress — and, when you want it, hands the busywork of sorting and prioritizing incoming reports to AI.

**Why site owners choose it**

* **It's instant.** Reports submit over AJAX with no page reload, so customers actually finish the form instead of abandoning it.
* **It sorts itself.** Turn on AI-assisted classification and every new report gets a suggested category and priority (Low / Medium / High) automatically — you triage a full inbox in minutes, not hours.
* **It gives customers a reference.** Every report gets its own unique, human-readable Ticket ID, so a customer can follow up by email or phone and your team can find the exact report instantly.
* **It already knows your store.** Reports from a WooCommerce order page auto-link to that order; reports from LearnPress courses, lessons, and quizzes are just as seamless.
* **It fits your workflow, not the other way around.** Custom statuses, custom categories, per-form rate limiting, captcha, and a drag-and-drop form builder mean the plugin adapts to how you already work.

**Key Features**

* AJAX-powered modal report form — no page reload, works anywhere via shortcode
* Optional AI-assisted triage (WordPress 7.0+): automatically suggests a category and priority for every new report using the built-in WordPress AI Client — gracefully disabled with no impact on the rest of the plugin if you're on an older WordPress version or haven't connected a provider
* "Summarize with AI" on the report edit screen — a plain-language explanation of what the customer is actually reporting, for reports that are long or vaguely worded
* AI Response Log: a rolling 3-hour audit trail of every AI request and response, so you can see exactly what the AI decided and why
* Unique, human-readable Ticket ID on every report — the customer's reference for following up, shown in their confirmation and report history
* Admin-defined report Categories and Low/Medium/High Priority, settable manually or by AI, editable any time from the report edit screen
* Drag-and-drop Form Builder: ten field types (name, email, phone, text, paragraph, number, select, radio, checkboxes, captcha), fully reorderable, mobile-friendly
* Five built-in report statuses with colour-coded badges (Processing, Contacting, Waiting, Solved, Failed), plus unlimited custom statuses
* E-commerce order auto-link — reports from an order page are automatically linked to that order in the admin, HPOS-compatible
* Per-form cooldown / rate limiting enforced on both the frontend and the server, so the same user can't spam a form
* Captcha support: Google reCAPTCHA, hCaptcha, and Cloudflare Turnstile
* Admin dashboard widget and admin-bar shortcut with live, at-a-glance unsolved-report counts
* Built-in FAQ builder with a `[psbdx_faq]` accordion shortcode for your own site's visitors
* Mobile-first responsive design throughout, including iOS safe-area support and a touch-friendly admin experience
* LearnPress course, lesson, and quiz page support, alongside WooCommerce
* Reporter identity (name and email) collected server-side from the WordPress session — never editable by the user, so it can't be spoofed
* Shortcodes: `[psbdx_report id="X"]`, `[psbdx_user_reports]`, and `[psbdx_faq]`

**Also configurable, per form**

* Fully custom report reasons (comma-separated, "Other" always appended)
* Optional extra fields (e.g. Transaction ID, Coupon Code)
* Contact field with a required/optional toggle
* Show or hide the reporter identity card in the form
* Auto-display on all products/orders/courses, or assign a form per item

**Perfect for**

* WooCommerce stores that need a fast, structured way for customers to report an order or product problem
* Online course sites (LearnPress) fielding student questions about a specific course, lesson, or quiz
* Any WordPress site that wants a lightweight complaint/report/support-ticket system without a heavyweight external helpdesk

**Shortcodes**

`[psbdx_report id="X"]`
Display a report button and modal form. Replace X with the Report Form post ID shown in the Shortcode box.

`[psbdx_user_reports]`
Display a paginated table of the currently logged-in user's report history, including their ticket IDs.

`[psbdx_faq]`
Display your admin-managed FAQ as a clean accordion, anywhere on your site.

== Installation ==

1. Upload the `psbdx-smart-report-management` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins > Installed Plugins**.
3. Go to **Report Forms** in the admin sidebar and click **Add New Form**.
4. Configure the form, then copy the shortcode from the **Shortcode** meta box.
5. Paste the shortcode on any page, post, or widget area.

Alternatively, enable global auto-display in the form settings to show the report button on all product or order pages automatically.

== Frequently Asked Questions ==

= Does this plugin use AI? =
Optionally, yes. If you're on WordPress 7.0+ and have an AI provider connected under Settings → Connectors, you can turn on AI-assisted classification (Settings → AI) so every new report automatically gets a suggested category and priority, and you can click "Summarize with AI" on any report for a plain-language explanation of the issue. It's entirely opt-in — the plugin works fully without it.

= What happens if I'm on an older WordPress version or don't have AI set up? =
Nothing breaks. The AI controls are automatically greyed out and disabled if your WordPress version is below 7.0 or no AI provider is connected. You (and your admins) can still set a Category and Priority manually on every report, exactly as if AI were never part of the plugin.

= What is the Ticket ID, and who sees it? =
Every submitted report gets a unique, human-readable Ticket ID (e.g. "PSRM-20260714-8K3F2A"). The reporting user sees it in their submission confirmation and their report history, so they have something concrete to reference if they follow up by email or phone. Admins can search for a ticket ID an user gives them to jump straight to that report.

= Can I set my own report categories? =
Yes. Add your own categories under Settings → Categories. They'll appear in the manual Category dropdown on every report, and — if AI is enabled — the AI will be constrained to pick from that exact list instead of inventing its own.

= Can I add a FAQ section for my own site's visitors? =
Yes. Go to the FAQ admin page, add your questions and answers, and drop the `[psbdx_faq]` shortcode anywhere on your site to display them as a clean accordion.

= Can guests submit reports? =
Yes. Guest reports are logged without a user association. The reporter name defaults to "Guest".

= Can I disable the reporter identity card shown in the form? =
Yes. Each form has an "User Identity Display" toggle in its configuration. When turned off, the read-only name and email card is hidden from the form — but identity is still collected server-side for the admin log.

= How does rate limiting work? =
Each form has a configurable cooldown (in minutes, default 30). Once a logged-in user submits a report via a form, they cannot submit again through that same form until the cooldown expires. The cooldown is enforced both on the frontend (form is hidden) and in the AJAX handler (server rejects the request even if the UI is bypassed).

= Does the plugin require any other plugins? =
No. PSBDx Smart Report Management works as a standalone plugin. E-commerce and LearnPress integrations activate automatically when those plugins are present.

= What is the order auto-link feature? =
When a user submits a report from an order page (e.g. My Account > Orders > View Order), the plugin automatically detects and stores the order ID. The admin Report Log shows a direct link to the order, and the user's report history table shows the order number instead of a URL.

= Is it compatible with High-Performance Order Storage (HPOS)? =
Yes. The plugin declares HPOS compatibility and uses `wc_get_order()` with `get_edit_order_url()` for all order links.

= Is it mobile-friendly, for both visitors and admins? =
Yes. The report form and modal are mobile-first with iOS safe-area support, and the admin screens (report list, response view, AI Response Log, settings) are all built with responsive breakpoints so you can manage reports comfortably from a phone.

= From where I can read all the documentations? =
We are happy to see that you are interested to read the documentations. Please visit https://dev.psbdx.xyz/documentations/psbdx-smart-report-managment/

== Developer Hooks ==

For developers building integrations or extensions on top of this plugin.

**Report status changes**

Instead of polling the database, listen for these action hooks — they fire from the one place in the plugin that ever writes a report's status, so they're guaranteed to fire exactly once per real change:

`psbdx_srm_report_status_changed( $report_id, $old_status, $new_status, $context )`

Fires for every status change, whatever the new status is.

`psbdx_srm_report_status_changed_to_{$new_status}( $report_id, $old_status, $context )`

Fires only for one specific new status (status run through `sanitize_key()`), e.g. `psbdx_srm_report_status_changed_to_solved`. Use this instead of the generic hook if you only care about one transition.

`$context` is an associative array:

* `report_id` (int) — same as the first hook argument, included for convenience.
* `ticket_id` (string) — the report's human-readable ticket ID.
* `submitter_id` (int) — numeric WP user ID of whoever filed the report, or `0` for a guest.
* `submitter_email` (string) — the reporter's email address, if one was collected.
* `old_status` (string|null) — the previous status key, or `null` if this report never had one before (i.e. this is the very first status it's ever had, from a brand-new submission).
* `new_status` (string) — the new status key.
* `changed_by` (int) — numeric WP user ID of whoever triggered the change (typically the logged-in admin editing the report), or `0` for a guest's own submission or an automated process.
* `updated_at` (string) — MySQL datetime in GMT/UTC.
* `updated_at_local` (string) — MySQL datetime in the site's local timezone.
* `source` (string) — where the change came from: `submission` (a brand-new report), `admin` (the report edit screen), or another value a specific integration may pass.

Example:

`
add_action( 'psbdx_srm_report_status_changed', function( $report_id, $old_status, $new_status, $context ) {
    error_log( sprintf(
        'Ticket %s (user #%d): %s -> %s',
        $context['ticket_id'],
        $context['submitter_id'],
        $context['old_status'] ?? '(new)',
        $new_status
    ) );
}, 10, 4 );
`

Note: bulk/maintenance operations that write status directly to the database (CSV import, and the Repair & Reset page's "fix invalid status values" tool) intentionally do not fire these hooks, since they're bulk restores rather than individual live status changes.

== Screenshots ==

1. Frontend report button and modal form on a product page.
2. Admin dashboard showing Report Form Management Screen.
3. Admin Report Logs list table with status badges, reporter, and order link columns.
4. Admin Report Form configuration screen.
5. Admin dashboard widget showing report customization screen.

== Changelog ==

= 1.4.6 =
* New: Support Agents system — add users as support agents from a dedicated admin submenu, set their work hours, and let the plugin auto-assign new reports to a free agent (notifying only that agent) when replies are enabled. An abandoned report is automatically handed to another free agent instead of sitting unassigned.
* New: Administrators are added as agents automatically; a plugin-level Super Administrator designation is the only one that can manage/edit/remove other administrators from the agent list.
* New: `[psbdx_user_reports]` now shows a full agent portal (My Reports, Assigned Reports, Search Ticket, and Manage Agents for admins) to any support agent or administrator, with per-report reply/status/abandon/handover tools and an admin-only activity log.
* New: Agent Rating — a 2.5-star starting score per agent, shown in Agent Management, that rises with completed tickets and falls when a report is abandoned or left for an admin to reassign.
* New: once a report is marked Solved, neither the reporter nor any agent can send another message until someone reopens it (a "Reopen This Report" button for the reporter; agents just change the status). Reports from a form with replies disabled are marked Solved automatically.
* Fix: mobile layout of the report view & reply page — agent tools and reply buttons had no dedicated styling (inherited unstyled wp-admin button classes on the frontend), causing cramped padding and unreliable taps on small screens; now use the plugin's own responsive button/row styles.

= 1.4.5 =
* New: Setup Wizard for first-time installs (mailing setup, starter form, reopenable anytime via the "Setup Wizard" action link or Repair & Reset).
* New: inline form embedding (`mode="inline"`) and URL popup links (append `?<form id>` to any page).
* New: Attachment field (admin-configurable file types/size limits) and Review (star rating) field types.
* New: file attachments in reply threads, a full "Attachments" management box (manual delete anytime), and optional auto-delete on Solved per field.
* New: optional real email attachments for reply notifications (Settings → Email), off by default.
* Fix: popup links now work on hosts that inject extra query parameters (e.g. some free hosts).
* Fix: asset cache-busting no longer depends on the plugin version number, so CSS/JS fixes reach browsers immediately.
* Improved: mobile touch targets and layout across PSBDx's own admin screens.

= 1.4.4 =
* New: "Always require a verified email" option for API submissions.
* Security: API brute-force lockout, peppered secret hashing, OTP send throttling.

= 1.4.3 =
* New: External API for programmatic report submission — API keys, domain/IP whitelisting, OTP-verified email, session-based `/start` → `/field` → `/verify-otp` → `/submit` flow.
* Critical fix: report/reply submission could fatal on hosts with short execution-time limits; AI classification/auto-reply/notifications now run after the response is sent.
* New: Sender name & email under Settings → Email.
* Fix: email template saving no longer strips literal backslashes.
* New: automatic detection of hosts that block the API (e.g. the InfinityFree family), with a self-test and clear admin notice.
* Fix: CSV form import no longer uses a deprecated WordPress function.

= 1.4.2 =
* New: reply threads on reports (frontend + admin), with an optional automatic AI reply.
* New: "Improve with AI" / "Generate AI Reply" tools for admins.
* New: email notifications (5 events, fully editable), CSV import/export, dedicated report detail page.
* New: report status change hooks (`psbdx_srm_report_status_changed`) for developers.
* Improved: redesigned FAQ with live search.

= 1.4.1 =
* New: unique ticket IDs, AI-assisted report classification (WordPress 7.0+), AI Response Log.
* New: Report Categories, manual Category & Priority override, Summarize with AI.
* Improved: "Report Logs" renamed to "Responses"; redesigned response view; mobile-responsive admin.

= 1.4.0 =
* New: Field Settings is now a popup (drawer on desktop, bottom sheet on mobile) at every viewport.
* New: fully functional mobile Form Builder.
* Fixed: several Form Builder layout/overflow/touch bugs on mobile and in-between viewport widths.

= 1.3.1 =
* Improved: security scan system, Repair & Reset status banner, admin bar reports shortcut, Support submenu page.

= 1.3.0 =
* New: v2 drag-and-drop Form Builder with a 10-field Field Library, legacy form migration, and a v2-aware submission handler — fully backward compatible with v1 forms.

= 1.2.0 =
* New: Captcha support (reCAPTCHA, hCaptcha, Turnstile). Bug fixes.

= 1.1.0 =
* New: PSBDx Reports admin menu, custom statuses, per-form/global rate limiting, Repair & Reset diagnostics, conflict guard, multisite support.

= 1.0.1 =
* New: admin review-notice prompt, Documentation link, improved multisite activation handling.

= 1.0.0 =
* Initial release: AJAX report modal, per-form rate limiting, WooCommerce/LearnPress integration, admin dashboard widget, `[psbdx_report]` / `[psbdx_user_reports]` shortcodes.

Full release notes for every version: https://dev.psbdx.xyz/

== Upcoming Features ==

The following features are planned for future releases:

* **Email Notifications** — Notify the admin on new submissions, and send a confirmation email to the reporter.
* **Status Change Emails** — Email the reporter automatically when their report status is updated.
* **CSV Export** — Export all report logs as a CSV file from the admin screen.
* **File / Screenshot Attachment** — Let users attach a screenshot or file to their report. (Added ✅)
* **Internal Admin Notes** — Private notes on each report log, visible only to admins.
* **Report Categories / Tags** — Organise reports with admin-defined categories for easier filtering. (Added ✅)
* **Guest Email Verification** — Allow non-logged-in users to submit with email verification before saving. (Added ✅)
* **Duplicate Detection** — Alert admins when a new report closely matches an existing open one. (Added ✅)
* **Report Priority Levels** — Assign Low / Medium / High priority to reports, manually or via AI. (Added ✅)
* **REST API Endpoints** — Query and manage reports programmatically via the WordPress REST API.
* **AI Knowledgebase Suggestions** — Recommend existing help-article answers to reporters based on their report content. (Settings → AI → Knowledgebase — coming soon)
