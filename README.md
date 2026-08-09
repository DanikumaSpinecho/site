# Mitigoke

A live teleprompter for **FFXIV mitigation plans**. Paste a plan shared from
[xivmit.app](https://xivmit.app), pick your slot, and the page calls out *your* cooldowns —
and only yours — a few seconds before each one is due, karaoke style.

One person can open a party room; their Start button then runs everyone's clock.

```
https://your-host/?plan=UMAD-G0FF1B
```

That single link is all a raid group needs. Everyone opens it and chooses their own slot.

---

## What it does

Three screens in one page:

1. **Load** — accepts a full share link or just the code (`UMAD-G0FF1B`). A `?plan=CODE`
   in the URL loads on its own.
2. **Settings** — slot, ability-name language, lead time, personal offset, pull countdown,
   beep, and party room.
3. **Prompter** — fight clock, a large "now" panel with countdown and ability icon, the
   boss action it answers, the plan's note if there is one, and the upcoming queue.

Settings, in seconds:

| | |
|---|---|
| **Lead time** | how far ahead a cue is announced (green border) |
| **Personal offset** | shifts your own display; adjustable mid-fight |
| **Pull countdown** | the clock starts negative, to match the in-game `/countdown` |

Keyboard: `Space` start/pause, `R` reset.

## Party sync

The leader names the party and gets a room id like `tuesday-static-k7m2qp`. The six random
characters are what keep the room from being guessed — the name alone would be trivial.

A room does not store a running clock. It stores **`t0`, the server instant at which the
fight reaches 00:00**. Every client derives its own position from that and then runs
independently, so the group syncs once per pull rather than continuously. Polling every two
seconds only exists to catch pause, resume, reset and shared-offset changes.

**Client clocks are never trusted.** Two machines can be seconds apart. Every response
carries the server time; the client probes three times, keeps the shortest round trip, and
derives its own skew — bounded by half that round trip. It anchors to `performance.now()`,
which is monotonic, rather than `Date.now()`, which the OS can step mid-session. Measured
between two clients: **0.3 ms apart**.

Clients only ever send a *delay*, never a timestamp — the server dates the start.

Whoever creates the room gets a random key kept in `localStorage`; the room stores only its
SHA-256 hash. Without it the clock buttons do nothing. A `&lead=KEY` link lets the leader
take control back from another browser; it is stripped from the address bar on arrival so a
careless copy-paste does not hand control to the whole party.

## Ability names and icons

`mapping.json` is the single source: display name in English, French, German and Japanese,
plus an icon path, for each of the 153 abilities in the upstream catalogue.

Three mechanisms stack:

- **direct icon** — the common case;
- **per-job icon** — for generic role entries. *Invuln* has no artwork of its own, but
  Hallowed Ground, Holmgang, Living Dead and Superbolide do;
- **fallback** — a role-coloured chip with the first three letters of the job.

That fallback is deliberate: **no job list is hard-coded anywhere**. Jobs come from the
upstream API, icons from `mapping.json`. When a new job ships it works immediately, with a
chip instead of artwork, and gains its icon whenever one is added. Icon folders are matched
by their `_JOB` suffix and never by their number, which a new job could shift.

**Community labels stay untranslated.** When the upstream plan uses a name that is not an
in-game action — *Spreadlo*, *Kitchen Sink*, *Party Mit*, *Invuln* — it is a term of art.
Translating it would make it unrecognisable, so it is shown as-is in every language, with
the underlying action's official names kept alongside for reference.

Edit any of this at **`/admin/`**: a table of every ability, with filters, an icon picker
and per-language name fields.

## Requirements

- PHP 8.0 or later with cURL
- Apache or LiteSpeed with `mod_rewrite` and `mod_headers`
- **No build step, no dependencies, no package manager.** Copy the files, done. This is a
  feature of the project, not an omission — please don't add a bundler.

## Install

Copy the contents of this repository to your web root and make sure the directory is
writable by PHP: it creates `.cache/`, `.sync/` and `.mapping-backups/` on first use. The
bundled `.htaccess` blocks all three from the web, along with `.git/`.

Then open `https://your-host/?plan=SOME-CODE`.

### Endpoints

```
api.php?plan=UMAD-G0FF1B   the shared plan       (cached 300 s)
api.php?fight=umad         the fight             (cached 86400 s)
api.php?abilities=1        the ability catalogue (cached 86400 s)

sync.php?now=1             server time (calibration probe)
sync.php?room=ID           room state
POST sync.php              {action: create|state}

POST mapping.php           writes mapping.json, from /admin/
icons.php?q=reprisal       icon search, for /admin/
```

`api.php` exists because the upstream API sends no CORS headers, so the browser cannot call
it directly. It has three fixed actions and no free-form path: **no arbitrary URL can be
requested through it.** Responses are cached on disk to spare the upstream server, and a
stale cache is served rather than an error if upstream is unreachable.

### Before you expose this publicly

- **`/admin/` and `mapping.php` ship unauthenticated.** Anyone who knows the address can
  rewrite the mapping table. Icon paths are validated and every write is backed up, but
  put a password on both — via your host's directory protection, or by setting
  `MAPPING_TOKEN` in `mapping.php` — before the site is reachable by strangers.
- The site ships configured to **never be indexed**: `noindex` on every response,
  User-Agent blocking for AI and SEO crawlers, and a `robots.txt` that deliberately *lets
  search engines fetch* so they can read the `noindex`. A blanket `Disallow: /` would stop
  them reading it, and a URL nobody may crawl can still be listed. Drop these if you want
  a public, indexable deployment.
- If your host puts a CDN in front of static files, images may bypass `.htaccess` and lose
  those headers. Check, or disable the CDN for the host.

## Known limitations

- **Background tabs.** Browsers throttle `setInterval` to about 1 Hz in a hidden tab, and
  far harder after a few minutes without audio. The clock itself never drifts — it is
  recomputed from `performance.now()` on every pass, never incremented, which is exactly
  why `requestAnimationFrame` is not used — but the display and the beep can lag by a
  second or more. Keep the window visible.
- **Expired rooms.** A follower whose room has expired keeps polling and is not told; the
  clock carries on from the last known state.
- Party sync has been verified between two clients, not yet across a full group of eight.
- The mobile layout works but is not polished.
- `mapping.json` can be edited live from `/admin/`, so a deployed copy may drift from the
  one in this repository.

## Credits

Plans, fights and the ability catalogue come from **[xivmit.app](https://xivmit.app)** by
liam_galt ([ko-fi](https://ko-fi.com/liam_galt)) — this project is only a viewer and would
not exist without it. Its API is public but neither documented nor contractual, and may
change without notice.

Localised ability names come from **[XIVAPI](https://xivapi.com)**, queried once offline
when `mapping.json` is generated; the running site never calls it.

FINAL FANTASY XIV and all associated imagery are the property of **Square Enix Co., Ltd.**
This project is unofficial, unaffiliated and not endorsed by Square Enix. Game assets
included here remain their copyright and are not covered by the licence below.

## Licence

MIT, for the code — see [LICENSE](LICENSE).
