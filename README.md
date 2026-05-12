# PawPayments for WHMCS

Accept cryptocurrency payments and enable crypto top-ups in WHMCS via
PawPayments. Customers are redirected to the PawPayments paywall to choose an
asset and network; invoices and credit balances are updated automatically once
the on-chain payment confirms.

---

## Features

- **Checkout** — pay any WHMCS invoice with cryptocurrency.
- **Top-up (Add Funds)** — optional addon that adds a client-area page where
  customers credit their WHMCS balance with crypto. Each top-up creates a
  one-time invoice via `POST /api/v2/invoices`.
- **Signature verification** — all webhooks are verified via `X-Paw-Signature`
  (HMAC-SHA256 of the raw body); requests without a valid header are rejected with HTTP 401.
- **Idempotent** — checkout uses `addInvoicePayment` deduplication by
  `transactionId`; top-up uses the `mod_pawpayments_credits` table with
  `order_id` as the primary key.
- Webhooks with `permanent_address_id` are silently acknowledged (200 OK).
- Currency / network selection happens on the PawPayments paywall — the
  plugin does not need to know about supported assets.

---

## Requirements

| Component | Minimum version |
| --------- | --------------- |
| WHMCS     | 8.x             |
| PHP       | 7.4 (8.1+ recommended; WHMCS 8.x supports up to PHP 8.3) |
| ionCube Loader | Required by WHMCS itself (not by this plugin) |
| MySQL / MariaDB | Whatever WHMCS already uses |
| PHP extensions | `curl`, `json`, `mbstring`, `openssl` |

You must already have:

- A working WHMCS installation reachable over **HTTPS** (PawPayments requires
  TLS for webhook delivery).
- An active WHMCS license.
- A PawPayments merchant account with an API key.

---

## 1. Plugin contents

The plugin ships as a zip with two top-level folders that mirror the WHMCS
installation tree:

```
modules/
├── gateways/
│   ├── pawpayments.php                          ← gateway entry point
│   ├── pawpayments/                             ← vendored SDK
│   │   └── vendor/pawpayments/sdk/...
│   └── callback/
│       ├── pawpayments.php                      ← checkout webhook
│       └── pawpayments_topup.php                ← top-up webhook
└── addons/
    └── pawpayments_topup/
        └── pawpayments_topup.php                ← top-up addon (Add Funds)
```

---

## 2. Install the files

### [Upload via SFTP / SSH

1. Extract the zip locally.
2. Upload the `modules/gateways/pawpayments.php`, `modules/gateways/pawpayments/`
   and `modules/gateways/callback/pawpayments*.php` files into your WHMCS
   `modules/gateways/` and `modules/gateways/callback/` directories.
3. Upload `modules/addons/pawpayments_topup/` into your WHMCS
   `modules/addons/` directory (only if you want the top-up feature).
4. Set ownership and permissions (adjust user as needed):

   ```bash
   cd /path/to/whmcs
   chown -R www-data:www-data \
       modules/gateways/pawpayments.php \
       modules/gateways/pawpayments \
       modules/gateways/callback/pawpayments*.php \
       modules/addons/pawpayments_topup
   find modules/gateways/pawpayments -type d -exec chmod 755 {} \;
   find modules/gateways/pawpayments -type f -exec chmod 644 {} \;
   chmod 644 modules/gateways/pawpayments.php \
             modules/gateways/callback/pawpayments*.php
   ```

### Single command on the server

```bash
cd /path/to/whmcs
unzip -o /tmp/pawpayments-whmcs.zip
chown -R www-data:www-data modules/gateways/pawpayments* \
                          modules/gateways/callback/pawpayments* \
                          modules/addons/pawpayments_topup
```

---

## 3. Activate the gateway

1. Log in to WHMCS admin.
2. Go to **Setup → Payments → Payment Gateways → All Payment Gateways**.
3. Click **PawPayments (Crypto)** to activate it.
4. On the **Manage Existing Gateways** tab, fill the fields:

   | Field             | Description                                                  |
   | ----------------- | ------------------------------------------------------------ |
   | **Display Name**  | Visible name on the invoice page (e.g. *PawPayments (Crypto)*). |
   | **API Key**       | The API key from your PawPayments merchant dashboard.        |
   | **API Base URL**  | Leave the default `https://api.pawpayments.com`.              |
   | **Invoice TTL (seconds)** | Lifetime of generated invoices. Default `3600` (1 h). The same payment URL is reused for the invoice within this window. |

5. Click **Save Changes**.

---

## 4. (Optional) Activate the Crypto Deposit addon

The addon adds a client-area page where customers can top up their WHMCS
account balance with cryptocurrency.

1. Go to **Setup → Addon Modules**.
2. Find **PawPayments Crypto Deposit** and click **Activate**.
3. Click **Configure** and either:
   - Leave the **API Key** field empty to inherit it from the gateway, or
   - Enter a separate API key here.
4. Set **Access Control** to the admin roles that can manage the addon and
   click **Save Changes**.
5. The addon automatically creates the `mod_pawpayments_credits` table on
   activation (used for top-up idempotency).

Clients access the top-up page at:

```
https://<your-whmcs>/index.php?m=pawpayments_topup
```

You can link to it from your client-area menu (Custom Client Area Menu Items)
or from a billing page.

---

## 5. Webhook URLs

The plugin sends `notify_url` automatically with every invoice it creates,
so manual webhook setup is **not required**. The endpoints are:

| Purpose   | URL |
| --------- | --- |
| Checkout  | `https://<your-whmcs>/modules/gateways/callback/pawpayments.php` |
| Top-up    | `https://<your-whmcs>/modules/gateways/callback/pawpayments_topup.php` |

If your PawPayments merchant settings require a *default* webhook URL, use the
checkout one. Make sure both endpoints are publicly reachable over HTTPS.

---

## 6. Test the integration

### Checkout

1. Create a test client in **Clients → Add New Client**.
2. Create an invoice for that client (**Billing → Invoices → Create New
   Invoice**) with a small amount and **Payment Method: PawPayments
   (Crypto)**.
3. Open the invoice as the client (View Invoice). You should see a
   **Pay with Crypto** button that opens `https://paw.now/invoice#…`.
4. Pay a small amount on the paywall.
5. After the on-chain confirmation, the webhook is delivered and the invoice
   transitions to **Paid**, with a `tblaccounts` entry referencing the
   PawPayments `order_id` as the transaction ID.

### Top-up

1. Open `https://<your-whmcs>/index.php?m=pawpayments_topup` while logged in
   as a client.
2. Enter an amount and submit. You will be redirected to a `paw.now` paywall.
3. After payment, the credit is added via WHMCS `AddCredit` API (visible on
   **Clients → Client Profile → Summary → Credit Balance**).

### Sanity check via curl (simulating a checkout webhook)

```bash
ORDER_ID="<paw_order_id>"
INVOICE_ID="<whmcs_invoice_id>"
KEY="<your_api_key>"
BODY="{\"order_id\":\"$ORDER_ID\",\"extra\":\"$INVOICE_ID\",\"status\":\"success\",\"fiat_amount\":\"15\",\"asset\":\"USDT\"}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')
curl -X POST "https://<your-whmcs>/modules/gateways/callback/pawpayments.php" \
  -H "Content-Type: application/json" \
  -H "X-Paw-Signature: $SIG" \
  -d "$BODY"
```

Expected response: `OK` with HTTP 200, and the invoice changes to **Paid**.

---

## 7. Troubleshooting

| Symptom | Cause / Fix |
| ------- | ----------- |
| Gateway not in the list at **Setup → Payments → Payment Gateways** | Files not uploaded or wrong path. Re-check that `modules/gateways/pawpayments.php` exists and is readable by the web user. |
| `Failed to create payment: Invalid API key` on the invoice page | API key wrong or merchant not activated. |
| Webhook returns HTTP 500 with `Failed to open required init.php` | Old plugin version. The current callback uses `require_once __DIR__ . '/../../../init.php';` (three `..`, not four). Re-upload the latest `callback/pawpayments*.php`. |
| Webhook returns HTTP 401 `Invalid signature` | The API key in the gateway settings does not match the key used to create the invoice. Update the gateway settings and re-issue the invoice. |
| Top-up addon not visible | Activate it in **Setup → Addon Modules** and grant access to your admin role. |
| Top-up page errors with "Module Not Activated" | The gateway must be enabled (the addon falls back to the gateway's API key). |
| Duplicate top-ups | The `mod_pawpayments_credits` table prevents double-credit on the same `order_id`. If the table is missing, re-activate the addon. |

WHMCS gateway logs are at **Utilities → Logs → Gateway Log** (filter by
`pawpayments`).

---

## 8. Uninstall

1. **Setup → Addon Modules → PawPayments Crypto Deposit → Deactivate**
   (top-up).
2. **Setup → Payment Gateways → PawPayments (Crypto) → Deactivate**.
3. Remove the files:

   ```bash
   cd /path/to/whmcs
   rm -rf modules/gateways/pawpayments.php \
          modules/gateways/pawpayments \
          modules/gateways/callback/pawpayments.php \
          modules/gateways/callback/pawpayments_topup.php \
          modules/addons/pawpayments_topup
   ```

4. Optionally drop the top-up bookkeeping table:

   ```sql
   DROP TABLE IF EXISTS mod_pawpayments_credits;
   ```
