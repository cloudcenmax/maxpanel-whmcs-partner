# MaxPanel Partner for WHMCS

Sell MaxPanel licenses alongside your VPS and dedicated servers.

Your customer pays you through WHMCS. The plugin creates their license and authorizes their server's IP. MaxPanel bills your saved card monthly for your license usage.

## Get started

1. Have an approved MaxPanel partner account with postpaid billing, a saved card, and a write API key.
2. [Download the plugin](https://github.com/cloudcenmax/maxpanel-whmcs-partner/releases) and copy its `modules` folder into WHMCS.
3. Activate **MaxPanel Partner Licenses** under **System Settings → Addon Modules**.
4. Set `MAXPANEL_PARTNER_API_KEY` in both your web and cron environments.
5. Choose how to sell licenses below.

[Full setup instructions](SETUP.md)

## Choose how to sell

| Option | What your customer sees |
| --- | --- |
| Configurable option | A MaxPanel plan dropdown when ordering a server. |
| Product add-on | A separate MaxPanel add-on they can buy with a server or later. |

Set your own customer prices in WHMCS. Each offer maps to a MaxPanel plan ID.

## Installation

Once the customer has paid and their server is active, the plugin authorizes its public IP. The customer can then install MaxPanel using the instructions on their service page.

Choose what that page shows:

- **Instructions** — an install command and a guide.
- **Link** — a link to your installation guide.
- **Hidden** — use this when your own recipe installs MaxPanel.

Make sure your server provisioning module fills in **Dedicated IP** in WHMCS.

## Compatibility

Designed for self-hosted WHMCS **8.0–9.x**. The current release is a **public preview**.

## Help

Read the [setup guide](SETUP.md) for configuration examples, automation, and troubleshooting. Report bugs through [GitHub Issues](https://github.com/cloudcenmax/maxpanel-whmcs-partner/issues) and security concerns through [private vulnerability reporting](https://github.com/cloudcenmax/maxpanel-whmcs-partner/security/advisories/new).

[MIT License](LICENSE)
