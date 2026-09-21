# eplatforms GDPR Subject Access Request

Finds, shows and exports everything Ninja Forms holds about one person, and keeps an auditable
log of who asked and what was released.

Built for sites where answering a subject access request otherwise means opening twenty forms in
turn and reading submissions looking for a name.

## What it does

**GDPR SAR → Search.** Enter an email address, name, phone number or any other detail the person
gave you. Every answer in every Ninja Forms submission is searched for that substring. Results are
grouped by submission, showing the form, the date, which field matched, and every answer with its
real question label rather than `_field_116`.

**Review, then release.** Each result has a checkbox. Only ticked submissions are exported. There
is no one-click "export everything that matched", because a substring search will match people who
are not the requester and releasing those would be a data breach committed with a compliance tool.

**Export** as CSV (one row per answer, with a byte order mark so Excel reads UTF-8 correctly) or
JSON.

**GDPR SAR → Request log.** Every search and every export is recorded: who ran it, what they
searched for, how many records were involved, when, and from what address. The log is what makes
granting access to this tool defensible.

**Tools → Export Personal Data.** Optionally feeds the same data into WordPress's own exporter,
which confirms the requester's identity by email first. It matches on email address only, so it
finds less than the search screen — the two are complements.

## Access

Everything is gated on a dedicated `ep_sar_manage` capability. Administrators always have it;
**Settings** lets you grant it to any other role, so a DPO or office manager can handle requests
without also getting plugin installation and user administration.

It is deliberately not open to every logged-in user. These screens display and export other
people's personal data, so who can reach them is a decision the site owner makes explicitly.

## Retention

The request log stores search terms, which are usually themselves personal data — normally the
requester's email address. It therefore has its own retention period (default 24 months) and is
purged daily. Set it to 0 to keep entries indefinitely.

Uninstalling drops the log table, removes the capability and deletes the settings. **Ninja Forms
submissions are never touched** — they are not this plugin's data.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Ninja Forms 3.x (verified against 3.15.3)

## How it reads Ninja Forms

Ninja Forms 3.x stores each submission as an `nf_sub` post. Answers are postmeta rows keyed
`_field_{field_id}`; the owning form is `_form_id`. Human-readable labels live outside the posts
table in `{prefix}nf3_fields`, keyed by the same field id — which is why a raw submission dump is
unreadable, and why this joins the two back together.

Fields of type `submit`, `html`, `hr` and `heading` are skipped: they are stored like any other
field and add only noise to a document someone has to check and sign off.

## Performance

The search is `meta_value LIKE '%term%'` across postmeta, which cannot use an index. On a site with
around 8,000 submissions a full-email search takes roughly half a second, which is fine for
occasional admin use. Results are capped (default 500, configurable) so a two-character search
cannot try to load the whole table.
