=== LeadTrackr ===
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.1.1
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Source code: https://github.com/leadtrackr/WordPress-Plugin

## Use of Third-Party Service

This plugin relies on a third-party service provided by the plugin developer (LeadTrackr) to process and send form submissions. Specifically, form submissions are sent to the LeadTrackr API endpoint for the creation of leads. 

- API endpoint: `https://app.leadtrackr.io/api/leads/createLead`
- API endpoint when an API token is configured: `https://app.leadtrackr.io/api/leads/createServerSideLead`. This request also includes the visitor's IP address and user agent.
- The service processes lead creation requests using the submitted data.
- For more information, please review the [LeadTrackr Terms of Use](https://leadtrackr.io/tos) and [Privacy Policy](https://leadtrackr.io/privacy-policy).

By using this plugin, users agree to transmit data to this external service as part of the lead creation functionality.

## Remote Script

The optional LeadBot contact launcher is loaded from a third-party CDN, and only when it is switched on in the plugin settings. Sites that leave it off request nothing from this host.

- Script: `https://cdn.jsdelivr.net/gh/leadtrackr/leadtrackr-leadbot@1/dist/lt-leadbot.min.js`
- Source code: https://github.com/leadtrackr/leadtrackr-leadbot
- Submissions made through the LeadBot are sent to the same LeadTrackr API described above.

## Cookies

With Channel Flow tracking enabled, the plugin sets two first-party cookies on the visitor's browser:

- `lt_channelflow` — the visitor's marketing channel path (source, medium, campaign and landing page per session). Contains no personal information and no unique visitor identifier. Expires after 395 days.
- `lt_session` — indicates whether the visitor is still within the same browsing session. Contains no visitor information. Expires after 30 minutes of inactivity.
- `lt_consent` — the Google Consent Mode state (granted or denied per category) at the visitor's last pageview, so it can be recorded alongside the lead. Only set on sites that run Google Consent Mode, and only when a definite state can be read. Contains no visitor information. Expires after 30 minutes of inactivity.

Channel Flow tracking is enabled by default on new installations and left disabled on sites that update from an earlier version.

## Changelog

### 1.1.0

**Channel Flow no longer needs Google Tag Manager.** The plugin records the visitor's journey itself, so a site without GTM gets a channel path too. One entry per session rather than per pageview, using the same definition GA4 uses: a new session after 30 minutes of inactivity, or when a new campaign brings the visitor in. Enabled by default on new installations and left off on sites that update, so nothing changes under an existing site's feet.

**The LeadBot can be switched on from the plugin.** A contact launcher for message, phone and WhatsApp, with settings for who is talking, which channels appear and how it looks. Nothing is requested from the CDN unless it is switched on.

**Leads carry more context.** The landing page of each session, the page the conversion happened on, and the Google Consent Mode state at that moment. Consent is recorded only — the plugin never blocks on it; that stays the CMP's job.

**Optional API token for server-side delivery.** With a token, leads are sent server-side, which adds the visitor's IP address and user agent. Sites that update keep working without one.

Fixed:

* Country domains of search engines were recorded as referrals instead of organic traffic, so a visitor arriving through google.nl showed up as `www.google.nl / referral`.
* Composite name fields were stored as a single value. Fluent Forms and WPForms submit first and last name as one object, which ended up in the first name field in one piece.
* Fields were matched in form order, so a company name appearing before the first name claimed that slot and the real first name was never stored.
* Campaigns using auto-tagging without UTM parameters are now attributed through the click ID in the URL.
* The Elementor form scan loaded every page builder blob on the site into memory, which could exhaust the memory limit on large sites.
* The settings page could not be saved on sites without pretty permalinks.
* Leads are no longer sent when no project ID is configured, and a failed delivery is logged instead of disappearing silently.
* Divi's tab was always available, even when Divi was not installed.
