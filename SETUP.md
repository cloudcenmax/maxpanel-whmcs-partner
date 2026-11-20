# Setup guide

[Back to the overview](README.md)

## Set up

1. Enable approved postpaid billing for your partner account. Confirm the start date and credit limit in your Partner Portal.
2. In the MaxPanel Partner Portal, open **Billing → Postpaid billing** and save a card. Also create a write API key in **API Access**.
3. Copy the supplied `modules/addons/maxpanel_partner` directory into the WHMCS installation. Activate **MaxPanel Partner Licenses** in **System Settings → Addon Modules** and assign the admin roles that can access its queue.
4. Set `MAXPANEL_PARTNER_API_KEY` for both the web worker and the WHMCS cron. The MaxPanel URL defaults to `https://maxpanel.net`. For staging, use a separate partner, API key and HTTPS MaxPanel origin. Configure WHMCS cron on its regular schedule.
5. Configure one or both sales routes below. For each server product, offer a single MaxPanel license. If a customer buys both routes for the same server, the module queues the first and logs the duplicate for you to review.

## Configurable option

Create a dropdown or radio option on the server product. Set the machine value to the exact MaxPanel plan ID from the partner catalog. For example, `main|MaxPanel` displays **MaxPanel** in checkout and sends the `main` plan. Use `none|No license` for an opt-out. Set customer pricing for each billing cycle in WHMCS.

In the add-on module settings, map the WHMCS product ID to its configurable option ID:

```json
{"12":{"option":34,"guidance":"instructions"}}
```

Configure the WHMCS server product's existing provisioning module to populate **Dedicated IP**. Keep WHMCS self-service changes to this license option disabled. The purchased MaxPanel plan is recorded when the paid invoice enters the queue. An existing license does not change plans when a label or mapping changes.

## Product add-on

Create a Product Add-on and assign the applicable VPS or dedicated server products. Enter the customer price and billing cycle. Use the normal add-on type in WHMCS 8.0–8.1. From 8.2 onward choose **Independent Product**. The MaxPanel Partner Licenses add-on module handles fulfillment through WHMCS hooks, so leave the product add-on's provisioning module unset.

Map the add-on definition ID to the desired MaxPanel plan ID:

```json
{"56":{"plan":"main","guidance":"link"}}
```

An add-on can be sold on the initial order or later on an active server. The license follows the add-on's suspension and cancellation and the parent server's availability.

## Install guidance and automation

Choose one value for `guidance` in each mapping:

- `instructions` shows the shell command and a docs link. This is the default.
- `link` links to the install guide. Set a custom HTTPS URL in the add-on settings if needed.
- `hidden` hides the MaxPanel card in the customer's service details.

Once the invoice is paid and the purchased service and add-on are active, the module waits for the parent service's public **Dedicated IP**. It then issues the license and opens a 24-hour authorization window. The MaxPanel installer must make its activation request from that IP. The **MaxPanel Partner Licenses** admin page shows the queue, license IDs, IPs and errors. Use **Retry on cron** to prioritize a record on the next cron pass, or **Queue paid invoice** if the original payment happened before setup or while the module was inactive.

If the provider runs a recipe, set `guidance` to `hidden` and wait for `maxpanel_partner_install_ready($serviceId)` to return true inside WHMCS before dispatching it. Run the installation through the provider's own VPS automation on the target server:

```sh
curl -fsSL https://get.maxpanel.net | sh -s -- --defaults
```

For servers with a shared outbound IP, add `"activation":"key"` to the mapping. Generate the installation key for that license in the MaxPanel Partner Portal and deliver it privately. MaxPanel issues the key only for an unbound license, and it expires after 24 hours. See [Installing MaxPanel](https://maxpanel.net/docs/installing#partner-ip-activation).

## Billing

MaxPanel prorates consumption in UTC, applies the partner discount and monthly minimum, and issues one invoice after month-end. The credit limit covers outstanding invoices and the current month's committed spend when granting new licenses. Suspended individual WHMCS services keep their reserved license until cancellation. When an automatic charge is declined or needs authentication, pay the invoice from the Partner Portal. Overdue postpaid invoices suspend license access until paid.


## Troubleshooting

Open **Addons → MaxPanel Partner Licenses** to check the license queue.

- **Waiting for IP** — check that the server's **Dedicated IP** field is filled in.
- **Waiting for service** — check that the invoice is paid and the server and any purchased add-on are active.
- **API errors** — check the MaxPanel URL, API key, and partner account status.
- **Retry on cron** — retry a license on the next cron run.
- **Queue paid invoice** — process a payment received before the plugin was enabled.

Keep your WHMCS cron running. Deactivating the module stops synchronization. Cancel licenses before permanently removing it.

## Development

Install the development dependencies and run the test suite:

```sh
composer install
composer test
```

The tests cover both sales flows with a simulated WHMCS database and MaxPanel API. They pass on PHP 7.2–7.4 and 8.1–8.4 in [GitHub Actions](https://github.com/cloudcenmax/maxpanel-whmcs-partner/actions/workflows/ci.yml).

Upload only the module directory to WHMCS. Keep API keys and customer information out of bug reports.
