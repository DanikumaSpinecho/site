# `/admin/` — the shared ability table

This page is the only editor for `mapping.json`, the table both the **prompter**
(`/index.html`) and the **plan creator** (`/plan_creator/`) read to know what an ability is
called, what it looks like, how long it lasts and how much it mitigates.

There is one copy that matters: **the one on the server**. Read this file before editing
the table, and read the changelog at the bottom before assuming your local copy is current.

---

## Two sessions, one table

The prompter and the planner are developed in two separate Claude Code sessions on the same
repository. Both may edit `/admin/index.html`, `/mapping.php` and the table itself. This
file is the only channel between them:

- **read it before editing anything**, to learn what the other side changed;
- **add a dated changelog line after editing anything**, and upload this file. An
  unlogged change is a change the other side will discover by breakage.

### What the prompter reads — do not break this

`index.html` touches exactly six fields per ability, and nothing else:

```js
m[lang] || m.en          // the name to display
m.byJob[job] || m.icon   // the icon path
m.mit                    // percent, displayed as text
m.dur                    // seconds, used as a NUMBER: t + dur
```

So the contract is short:

- **`en` always exists.** It is the fallback for every other language.
- **`mit` and `dur` stay plain numbers.** Turning `mit` into `{ phys, magic }` would break
  the prompter's display, and turning `dur` into anything non-numeric would break the
  still-running-effect tray, which computes `t + dur`.
- **nothing is renamed or removed.** Extension is **additive only**: new keys alongside the
  existing ones.
- **`dur` on a shield stays the timeout duration.** The prompter cannot know when a shield
  actually popped; the icon disappearing at timeout is the correct approximation.
- **`mit` stays the headline number** — the one figure worth showing when you do not care
  about the damage type. A finer split belongs in new fields next to it, not in `mit`.

Unknown fields are safe: `/admin/` loads the whole document into `DOC`, edits it in place
and posts `DOC` back verbatim, and `mapping.php` validates known fields while passing
everything else through. Adding a field costs nothing to the other side — **as long as no
one adds whitelist validation that rejects entries for having unknown keys.**

### Traps already paid for

- **`mit: 0` and `dur: 0` are rejected**, not stored. `mapping.php` refuses anything outside
  `0 < v <= 3600`. A pure shield with no percentage reduction must **omit** `mit`, not set
  it to zero.
- **`byJob` keys must match `^[A-Z]{3}$`.** `BRD` works, `RANGED` does not. Widening this to
  roles means changing the regex in `mapping.php` — and saying so here.
- **`version` is forced to `1` and `generated` to today's date on every write.** Bumping the
  format version means changing `mapping.php`.
- **Ability ids match `^[a-z0-9_]{1,64}$` and are the keys plans point at.** Never rename
  one.

### What upstream already gives you

Before inventing a field, check `api.php?abilities=1` — the upstream catalogue is richer
than what `mapping.json` currently keeps. Across its 153 entries:

| field | coverage | notes |
|---|---|---|
| `type` | 153 | `mitigation` 79, `heal` 39, **`shield` 20**, `utility` 10, `invuln` 5 |
| `scope` | 153 | `party` 57, `self` 41, `single` 31, `enemy` 24 |
| `duration`, `charges`, `minLevel` | 153 | |
| `cooldown` | 147 | |
| `mitPercent` | 72 | a single number, no damage-type split |
| `durationUpgrade` | 17 | why durations must be taken at level 100 |
| `shieldStacks` | 2 | |
| `canceledBy`, `blockedDuringActive`, `requiresWithin`, `chargeGroup` | a few | |

So **shield-or-not already exists upstream** as `type: "shield"` — mirror that vocabulary
rather than inventing a boolean.

The **magical/physical split does not exist as a field**. It appears only in the free-text
`description` of 19 entries:

```
blm_addle  Reduces magical damage dealt by 10%, physical by 5%.
drg_feint  Reduces physical damage dealt by 10%, magical by 5%.
drk_dark_mind  10% magic damage reduction (magic only).
```

Parsing prose is not a foundation. Enter these by hand in `/admin/`, and show the upstream
`description` next to the input so whoever fills it can read the source.

---

## Where the data lives

| | |
|---|---|
| The table | `/mapping.json` — a static file, served by Apache without PHP |
| The editor | `/admin/index.html` — loads the table, lets you edit it, posts it back |
| The writer | `/mapping.php` — validates and writes, keeping 10 backups in `.mapping-backups/` |
| Icon search | `/icons.php?q=reprisal` — used by the editor only |

Reading never goes through PHP. Both applications `fetch("mapping.json")` directly, so a
change is live for everyone the moment the editor saves.

## The format

```json
{
  "version": 1,
  "generated": "2026-08-10",
  "langs": ["en", "fr", "de", "ja"],
  "jobs": {
    "PLD": { "icon": "jobs-new/paladin.png", "role": "tank" }
  },
  "abilities": {
    "tank_reprisal": {
      "en": "Reprisal", "fr": "Représailles", "de": "Vergeltung", "ja": "リプライザル",
      "icon": "icons/pve/FFXIVIcons_Battle/01_PLD/Reprisal.png",
      "job": "TANK", "mit": 10, "dur": 15
    },
    "ranged_party_mit": {
      "en": "Party Mit",
      "byJob": {
        "BRD": "icons/pve/FFXIVIcons_Battle/11_BRD/Troubadour.png",
        "MCH": "icons/pve/FFXIVIcons_Battle/12_MCH/Tactician.png",
        "DNC": "icons/pve/FFXIVIcons_Battle/13_DNC/Shield_Samba.png"
      },
      "job": "RANGED", "mit": 10, "dur": 15
    }
  }
}
```

- **Ability id** — `^[a-z0-9_]{1,64}$`, and it is the id upstream uses. Do not rename one:
  it is the key every plan points at.
- **`en` / `fr` / `de` / `ja`** — the name to display. A missing language falls back to `en`.
- **`icon`** — a path under `icons/` or `jobs-new/`, ending in `.png`, `.jpg`, `.webp` or
  `.svg`. Anything else is rejected, including any path containing `..`.
- **`byJob`** — per-job icon for role-generic entries. Keys are **exactly three uppercase
  letters** (`BRD`), so role-level keys such as `RANGED` are refused today. Its value is a
  path only: the *name* does not change per job, so "Party Mit" stays "Party Mit" for a
  bard rather than becoming "Troubadour".
- **`job`** — either a job (`PLD`) or a role (`TANK`, `MELEE`, `RANGED`, `CASTER`,
  `EXTRAS`). The editor uses it to decide which jobs the `+` button offers.
- **`dur`** — effect duration in seconds, **at level 100**. This is what makes the prompter
  keep a pulsing icon while the effect is still running. 22 abilities legitimately have no
  duration: instant heals and utilities such as Provoke or Shirk.
- **`mit`** — mitigation percentage, for display only.
- **`official`** — the real in-game action name, when the displayed name is a community
  term ("Spreadlo", "Kitchen Sink", "Party Mit", "Invuln"). Shown in the editor, never in
  the prompter: translating a community term would make it unrecognisable. 24 entries.

Read by the **planner** only, added 2026-08-15, all optional and all invisible to the prompter
(see the changelog entries for how they were filled):

- **`components`** — the real actions a *generic label* stands for. Either a flat list, or one
  list per job with `"*"` as the fallback. See the 0.0.6 entry below.

And one key at the **document level**, next to `abilities`:

- **`aliases`** — `{ unknownId: knownId }`, for when a plan arrives under a name the table does
  not use. The target must exist; the source is precisely the id that does not.

- **`type`** — `mitigation`, `heal`, `shield`, `invuln`, `utility`. Upstream's own vocabulary.
- **`scope`** — `party`, `self`, `single`, `enemy`.
- **`cd`** — recast in seconds, **at level 100** (`cooldownUpgrade` applied). Absent means the
  ability has no recast worth checking: a GCD, or a pseudo-entry such as `tank_lb3`.
- **`charges`** — stored only when greater than 1.
- **`mitMagic` / `mitPhys`** — reduction against magical and physical damage, when the ability
  distinguishes them. **`mit` stays the headline number**; these two sit beside it and never
  replace it. Absent means zero for that damage type, so a magic-only cooldown carries
  `mitMagic` alone.
- **`shieldStacks`** — for the two stacking shields, Haima and Panhaima.

`dur` and `mit` must be **numbers**, not strings. The writer refuses `"quinze"` and refuses
anything outside `0 < v <= 3600`. This is not pedantry: the prompter computes `t + dur`, and
a string would concatenate instead of adding.

## Editing it

Open `/admin/`, change what you need, press save. The page posts the whole document to
`mapping.php`, which validates it, copies the previous version into `.mapping-backups/`
and writes atomically. A rejected write names the offending entry.

Two things to know:

- **the endpoint is currently open**, with no password. That is deliberate during
  development, and it means the previous version is always one file away in
  `.mapping-backups/` if something goes wrong;
- **the server copy is the reference.** It can be edited from the browser at any time, so a
  copy sitting in a working directory goes stale silently. Fetch `mapping.json` from the
  live site before you edit or generate anything, and never overwrite the server copy with
  a locally generated one without diffing the two first.

## Generating from scratch

`mapping.json` is generated data, not a build step: `tools/build-mapping.ps1` (kept outside
the repository) produced it once from the upstream catalogue plus XIVAPI for the localised
names. It applies `durationUpgrade` at level 100 — without that, Reprisal, Feint and Addle
would be recorded at 10 s instead of 15 and their icons would vanish too early.

Regenerating replaces the table wholesale and would drop anything added by hand since. The
normal path is to edit in `/admin/`.

---

## Changelog

Newest first. **Anything that changes the table's shape, its contents or the rules above
gets a line here**, so the other side knows what to take into account.

### 2026-08-15 — generic labels and aliases, v0.0.6 (planner session)

**Two new optional keys, both additive, both invisible to the prompter.** No existing field was
renamed, removed or changed, and `mapping.json` itself was **not rewritten** — this entry changes
what the table is *allowed* to carry, not what it currently holds. The live table still has 158
entries, 0 with components and no `aliases` block; filling them is done in `/admin/`.

| key | where | shape |
|---|---|---|
| `components` | on an ability | `["war_rampart", …]` **or** `{ "WAR": [...], "*": [...] }` |
| `aliases` | on the document, next to `abilities` | `{ "party_mitigation": "tank_party_mit" }` |

- **`components` is for generic labels only.** “Kitchen Sink”, “Party Mit”, “Buddy Mit” are not
  actions in the game, they are shorthand — and depending on the job they mean two or three
  different buttons. This is also why five entries legitimately have **no icon at all**: it is
  their components that have one. The planner now draws those icons instead of a grey chip, and
  lists their names in the tooltip, the palette and the inspector.
- **`"*"` means “same for every job”**, a per-job key overrides it for that job. Both the flat
  list and the object are accepted because both are what someone writes by hand; `mapping.php`
  normalises to the simplest form that says the same thing, and **prunes empty lists** — the
  editor creates one when you open a job line, and an unfilled line must not be stored.
- **Component ids are NOT checked against the table by the writer.** The table is edited entry by
  entry, and refusing a composition because its last component is not typed yet would block the
  work in progress. The *editor* does check, turns the field red and drops the unknown token
  while keeping every valid one on the line — a refused value never disappears in silence.
- **`aliases` targets are checked**, and must exist. An alias into the void would rescue nothing
  and would be discovered as a grey chip, without a word. The source, on the contrary, has no
  reason to exist — it is the unknown id. The planner only consults `aliases` when the id is
  **not** a real entry, so a mistyped alias can never shadow an existing ability.

**`admin/index.html`** — the green `+` panel now ends with a **Components** block: one line per
job, `all` first, a job picker fed by the roles in `mapping.json` (no hard-coded job list), and a
live thumbnail strip. A new **Aliases…** toolbar button opens a dialog listing every upstream id
absent from the table — click one to fill the source field. The button carries a count badge when
there are any. New filter: `Generic (components)`. The stat line gained `N generic · N aliases`.
The upstream catalogue is now fetched once at load, only to compute that badge; `api.php` caches
it 24 h, so it does not reach upstream.

**`plan_creator/`** — reads both keys, and warns after an import when a plan uses ids the table
does not know (alias resolution included), naming the first three. That was previously silent.
The header now reads **MITIGOKE**, linking back to the prompter, with `by danikuma SPINECHO`
underneath.

**`index.html` — one line touched, by the planner session.** Its `<title>` gained ` 0.0.6`, so
both pages carry the version in the browser tab. Nothing else in that file was read from, written
to, or reformatted. It was checked byte-for-byte identical between the working copy and the server
immediately before and after, so nothing of the prompter session's could have been overwritten.
Said here because that file is normally off-limits to this side.

Verified on the reference plan (UMAD-G0FF1B, 329 assignments): cast box heights match
`castTime × px` to 0.006 px, the four pinned columns align header-to-lane exactly, and across all
**329 ability icons there are 0 overlapping pairs** at a cascade depth of 6 — the maximum the
layout allows, actually reached by this plan.

### 2026-08-15 — planner fields, seven new keys (planner session)

**Seven optional fields added to every ability. All additive, all ignored by the prompter.**
Nothing was renamed, removed or changed: the 158 entries kept every field they had, byte for
byte, and the write was checked both ways before and after posting.

| key | type | on | source |
|---|---|---|---|
| `type` | word | 158 | upstream `type` — `mitigation` `heal` `shield` `invuln` `utility` |
| `scope` | word | 158 | upstream `scope` — `party` `self` `single` `enemy` |
| `cd` | number, seconds | 134 | upstream `cooldown`, **with `cooldownUpgrade` applied at level 100** |
| `charges` | number | 9 | upstream `charges`, stored only when > 1 |
| `mitMagic` | number, % | 17 | **hand-entered**, read off the upstream `description` |
| `mitPhys` | number, % | 12 | idem |
| `shieldStacks` | number | 2 | upstream `shieldStacks` (Haima, Panhaima) |

- **`mit` and `dur` are untouched and still mean what they meant.** `mit` remains the headline
  figure the prompter shows. `mitMagic` / `mitPhys` sit *next to* it and exist only on the 17
  abilities that genuinely treat the two damage types differently. **An absent value means
  zero for that type**, consistent with the existing "omit, never write 0" rule.
- **`cd` is the level-100 recast**, the same reasoning as durations. Four abilities differ from
  the raw upstream number: `brd_troubadour`, `sch_spreadlo`, `sge_zoe` (120 → 90) and
  `sch_recitation` (90 → 60). This is not cosmetic — the planner was reporting 12 impossible
  recasts on a perfectly legal reference plan because it checked against the low-level value.
- **The magic/physical split was entered by hand, entry by entry**, exactly as this file asked.
  Two entries mention "magic" in their description and get **no** split: `sch_dissipation`
  ("healing magic potency") and `whm_temperance` ("10% party damage reduction. Increases
  healing magic potency by 20%" — the reduction is on everything). A keyword filter would have
  mis-classified both. The remaining figures come straight from the upstream prose and deserve
  an in-game spot-check before anyone treats them as gospel.
- The five hand-added abilities have no upstream row, so their `type` / `scope` were written by
  hand. `ast_card_mit`, `ast_the_ewer`, `sge_prognosis` and `whm_regen` deliberately have **no
  `cd`**: they are GCDs or gauge-driven, and inventing a recast would make the planner report
  conflicts that do not exist. `sge_pepsis` has 30 s.

**`mapping.php`** — new rules, none of them a whitelist on keys:

- numeric fields now come from one table with per-field bounds: `dur` `cd` ≤ 3600, `mit`
  `mitMagic` `mitPhys` ≤ 100, `charges` ≤ 10, `shieldStacks` ≤ 20. `0 < v` still holds
  everywhere, so **omitting stays the way to say "none"**. Note `mit`'s bound tightened from
  3600 to 100; the live table's maximum is 80, so nothing was affected.
- `type` and `scope` are validated **by shape** (`^[a-z][a-z0-9_-]{0,23}$`), *not* against a
  closed list. A value upstream invents tomorrow will save without touching this file.
- **unknown keys still pass through untouched.** Verified against the live endpoint by posting
  the full table with a nested junk key, reading it back intact, then reposting the clean
  version. If you add a field from the prompter side, this writer will not eat it.
- **backup filenames now carry milliseconds.** `date('Ymd-His')` had one-second granularity, so
  two saves in the same second landed on the same filename and the second `copy()` destroyed the
  first backup. Found the hard way, in exactly that situation: the surviving file was the state
  *after* the first write, not before it — the one moment you would want it. Two consecutive
  writes now keep two distinct backups (checked in production).

**`admin/index.html`** — a **second `+`** at the end of every row, green where the icon one is
blue, unfolding the fields above. Both panels open independently; the per-job icon `+` is
untouched. The row shows the upstream `description` verbatim underneath, since that prose is
the only place the damage split exists. Empty and out-of-range input is dropped *and* the field
turns red — a refused value never disappears silently. Three filters added: `Magic/phys split`,
`Shields`, `No type`.

**`plan_creator/`** — `mapping.json` is now the planner's only ability source; it no longer
calls `api.php?abilities=1` at all, except as a fallback if it loads a table older than this
entry (detected by the absence of `cd` and `type`), in which case it says so in a toast.

`tools/enrich-mapping.py` (outside the repository) performed the merge and carries the
hand-entered split, so it can be audited or re-run. **`tools/build-mapping.ps1` does not know
about these fields**: regenerating from scratch would drop all seven, on top of the five
hand-added abilities it already had to be taught. Editing in `/admin/` remains the normal path.

### 2026-08-15 — collaboration contract (prompter session)
- **Both sessions may now edit `/admin/index.html` and `/mapping.php`**, and both upload
  this file. The sections above state what the prompter reads and must keep working.
- The planner is expected to add per-ability fields for the **magical / physical reduction
  split** and **shield behaviour**, reached by a second `+` on each row. Additive fields
  only; `mit` and `dur` keep their current meaning and stay numbers.
- No change to `mapping.json`'s shape yet — this entry is the contract, not an
  implementation.

### 2026-08-15
- Table went from 153 to **158 abilities**: `ast_card_mit`, `ast_the_ewer`, `sge_pepsis`,
  `sge_prognosis`, `whm_regen` added by hand, each with an icon. The 153 pre-existing
  entries are unchanged. The server had been running on the 153-entry version until today
  and has now been updated.
- This README added.

### 2026-08-11 (v0.0.4)
- `byJob` introduced, with the `+` button in the editor that unfolds one line per candidate
  job. Keys constrained to `^[A-Z]{3}$`.

### 2026-08-10 (v0.0.3)
- `dur` column added to the editor and used by the prompter to keep still-running effects
  on screen. Durations are the ones effective at level 100.
