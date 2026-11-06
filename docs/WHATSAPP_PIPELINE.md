# WhatsApp pipeline — how it works

Simple English. Updated after automatic sending was **removed** (not locked — deleted).

## What this system does

The school admin hub can talk to WhatsApp Web through a small Node program called the **bridge**.

- You log in once by scanning a QR code.
- Messages people send you are saved in the school database and show in the hub.
- Admission enquiries are still created from inbound parent messages.
- **Nothing is sent on WhatsApp unless you press Send in the hub.**

## The only way to send

1. Open the WhatsApp hub in the admin site.
2. Open a chat (personal, group, or broadcast).
3. Type a message, or open **Message templates**, edit a card, and press **Send**.
4. The server puts one row in the outbound queue.
5. The bridge picks up that fresh row and sends it **exactly once**.

There is no auto-reply, no AI send, no reminder send, no PDF auto-send, no CLI send, and no “flush old queue when I reconnect.”

If a send fails, the row stays **failed**. Press **Retry** in the hub to try again.

If the bridge was offline when you pressed Send, that row is marked failed with “not sent (bridge was offline) — press Retry.” Press Retry when the bridge is online.

## What happens when a message arrives

1. The bridge sees the message on WhatsApp Web.
2. It posts it to `api_whatsapp_ingest.php` with a signed secret.
3. The message is stored.
4. If it looks like an admission enquiry, an enquiry record is created/updated.
5. **No reply is generated or sent.**

## Delete

- **Delete for me** — removes it from the hub view (on press).
- **Delete for everyone** — queues a revoke for the bridge (on press), only within WhatsApp’s time window (~48 hours).

## How to start and stop the bridge

On the Mac project folder:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/hcc/automation
./start-bridge.sh    # starts once; does not restart by itself
./stop-bridge.sh     # stops the bridge
```

- Use **Node 24 via NVM** on this Mac (`nvm use 24`). Homebrew Node 26 is broken here.
- Only one bridge may run (shared Chrome profile).
- launchd / systemd / Windows helpers are optional crash supervisors. They do **not** send messages. Prefer starting by hand until you are comfortable.

## First login checklist

1. Stop any old bridge: `./stop-bridge.sh`
2. Start: `./start-bridge.sh`
3. Open the hub → scan the QR with your phone.
4. Confirm chats load.
5. Send a test only to **Message yourself**.
6. Confirm it appears once with ticks.
7. Do not expect automatic replies to parents — reply yourself from the hub.

## Offline tests (no real WhatsApp)

With the live bridge **stopped**:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/hcc
./tests/e2e_offline/run_all.sh
```

This uses a fake WhatsApp and the `hcc_wa_test` database. It never opens your real session.

Static audit (no sends):

```bash
./tests/e2e_offline/audit_no_auto_send.sh
```

## Platforms

Works on macOS, Linux, and Windows. The bridge picks the right Chrome path and keyboard modifiers per OS.

## What was removed

See `docs/WHATSAPP_AUTOSEND_REMOVAL.md` for the full list. Automatic sending is gone from the code, not switched off with a flag.
