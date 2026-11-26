# Templates and pages

## Table of contents

- [Pages](#pages)
- [Wrapping the pages in your layout](#wrapping-the-pages-in-your-layout)
- [Templates and data blocks](#templates-and-data-blocks)
- [Dialogs](#dialogs)
- [Progress of a queued run](#progress-of-a-queued-run)
- [Theme](#theme)
- [Overriding a template](#overriding-a-template)

## Pages

The opt-in UI (`ui.enabled`, 0.2) registers one set of pages per `ui.mounts` entry (`nowo_seed_kit_<kind>_<page>`):

| Route | Path under the mount | Page | UiKit scaffold |
| --- | --- | --- | --- |
| `index` | `/` | Kinds of data the viewer may export or import, with *Empty template*, *Current data*, *JSON Schema*; header actions *History* and *Upload file* | `page/list` |
| `export` | `/providers/{provider}/export?mode=empty\|current&format=yaml\|json\|jsonl` | Download; a current export of a provider with personal-data or secret fields is confirmed first (GET: confirmation page, POST: download) | `page/confirm` |
| `schema` | `/providers/{provider}/schema.json` | Document JSON Schema download | — |
| `upload` | `/upload` | Upload form (the dialog's fragment, or the full page without JavaScript), row errors of a refused file | `page/form` |
| `runs` | `/runs` | History, newest first (`?before=<run id>` keyset pager) | `page/list` |
| `run` | `/runs/{run}` | Summary, warnings, progress, diff preview (before → after), *Apply* / *Discard* | `page/show` |
| `run_apply` | `/runs/{run}/apply` (POST) | Applies or queues the run, then the run page | — |
| `run_discard` | `/runs/{run}/discard` (POST) | Discards the run, then the history | — |
| `run_progress` | `/runs/{run}/progress` | Internal UI JSON `{status, batchesDone, batchesTotal, counts, errorCode}` (not a public API) | — |

Conflicts and refusals of an apply answer a message page (`page/show`) with the code: 409 (lease held, lock conflict
with `Retry-After` and `X-Conflict-Code`, stale plan, row changed), 413 (`SEED_009`), 422 (`SEED_012`), 500
(`SEED_010`). Unknown runs, scopes and providers are 404.

## Wrapping the pages in your layout

Every page extends a UiKit scaffold, which extends `ui.layout` (or `nowo_ui_kit.layouts.admin`). Point `ui.layout` at
your admin layout (or a bridge that renders UiKit's canonical blocks `nowo_ui_page_header`, `nowo_ui_flashes`,
`nowo_ui_content`, `nowo_ui_modals`) and the pages sit in your shell with no template override.

## Templates and data blocks

Namespace `@NowoSeedKitBundle` (REQ-TWIG-002), files in `src/Resources/views/`:

| Template | Extends | Blocks filled | Variables |
| --- | --- | --- | --- |
| `index.html.twig` | `@NowoUiKit/page/list.html.twig` | `nowo_ui_list_row` (cells), `nowo_ui_list_after` (upload dialog shell) | `page` (`ListPage`, rows keyed by provider), `upload_dialog` |
| `upload.html.twig` | `@NowoUiKit/page/form.html.twig` | `nowo_ui_form_body` (form errors, row errors, then the fields) | `page` (`FormPage`), `errors`, `max_bytes` |
| `upload_fragment.html.twig` | `@NowoUiKit/layout/fragment.html.twig` | `body` (the same form for the dialog, actions row moved into the dialog footer) | same |
| `_upload_errors.html.twig` | — | — | `errors` (row, key, field, rule, message), `bundle` |
| `run.html.twig` | `@NowoUiKit/page/show.html.twig` | `nowo_ui_show_after` (progress, warnings, mass-prune form, diff tables) | `page` (`ShowPage`), `run`, `documents`, `warnings`, `stale`, `error`, `owner`, `apply_form`, `deletes`, `active`, `poll_ms`, `self_href`, `upload_href`, `progress_href` |
| `runs.html.twig` | `@NowoUiKit/page/list.html.twig` | `nowo_ui_list_row`, `nowo_ui_list_after` (older runs) | `page`, `next_href` |
| `message.html.twig` | `@NowoUiKit/page/show.html.twig` | — | `page` |

Markup uses UiKit theme slots only (`nowo_ui_class()`), `data-testid` hooks for tests, and no inline script or style.

## Dialogs

*Upload file* is a link to the upload page carrying the UiKit remote-dialog opener attributes: the dialog
(`remote_form_dialog` partial) fetches the form only when it opens, posts it in place (a refused file re-renders with
422) and follows the server to the run page (`204` + `X-Nowo-Redirect`). *Apply*, *Discard* and the personal-data
download are UiKit confirm dialogs over POST forms (CSRF); without JavaScript the personal-data download falls back to
the confirmation page.

## Progress of a queued run

A `queued` / `running` run shows a `<progress>` meter in a region marked for UiKit's `auto-refresh` Stimulus peer
(`data-controller="auto-refresh"`, `ui.poll_interval_ms`) and a *Refresh* link that works without it.

## Theme

Pages carry `bundle: NowoSeedKitBundle`, so `nowo_ui_kit.theme.bundles.NowoSeedKitBundle` applies per-bundle
exceptions. The forms use the FormKit profile `seed_kit` (prepended unless the host declares one with that name).

## Overriding a template

Put a file of the same name in `templates/bundles/NowoSeedKitBundle/`; prefer the data blocks above and `ui.layout`
over a full copy.
