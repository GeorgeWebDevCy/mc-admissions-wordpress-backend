# Desktop release notifications

The public REST endpoint `POST /wp-json/mc-admissions/v1/release-notification`
accepts only a signed, completed release payload from the
`GeorgeWebDevCy/mc-admissions-app` release workflow.

## Configuration

1. Generate a high-entropy random secret.
2. In WordPress, open **Settings > MC Admissions** and enter it in
   **Desktop Release Notification Secret**.
3. Store the same value as the release workflow secret. Never place it in the
   repository, workflow payload, logs, or release assets.

The workflow sends the raw JSON body with:

- `X-GitHub-Event: release`
- `X-GitHub-Delivery: <workflow run id>`
- `X-Hub-Signature-256: sha256=<HMAC-SHA256 of the exact raw body>`

The payload must describe a non-draft, non-prerelease `published` release,
use a semantic `vX.Y.Z` tag, and include the matching Windows installer,
installer blockmap, and `latest.yml`.

## Delivery and retries

Notifications are sent through WordPress `wp_mail()` and therefore continue
through FluentSMTP/Microsoft 365. Recipients are limited to the President and
users assigned to Administrator, Admissions, Finance, Migration, Immigration,
or Registrar. Agents and students are never selected.

Delivery state is stored per repository/tag. Each successful recipient is
recorded immediately. A retry after partial delivery sends only to recipients
that did not previously succeed, and a completed duplicate returns
`{"ok":true,"duplicate":true}` without sending mail.

The email contains only the release version and a short instruction to restart
the app when prompted. It does not include release-page or workflow links.

Normal partial retries do not duplicate messages already recorded as delivered.
In the rare event that `wp_mail()` accepts a message but the immediately
following delivery-state write fails, a later retry can deliver that recipient
again. Delivery is therefore at-least-once in that exceptional failure mode.

Responses contain delivery counts and tag/status only; they never expose the
shared secret or recipient email addresses.
