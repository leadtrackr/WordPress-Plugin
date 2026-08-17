=== LeadTrackr ===
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.7
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