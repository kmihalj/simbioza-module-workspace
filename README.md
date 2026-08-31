# Simbioza Workspace Module

[Hrvatska verzija](README_hr.md)

The Workspace module organizes related content into **Workspaces** (`Područja`
in Croatian). Each Workspace has its own URL, visibility, members,
permissions, and hierarchical page tree.

## Dependencies

Required, in enable order:

1. `aaieduhr/heartphrame-framework` (`^0.0.24`)
2. `aaieduhr/heartphrame-module-orm` (`^0.1.0`)
3. `aaieduhr/heartphrame-module-auth` (`^0.1.0`)
4. `aaieduhr/simbioza-module-workspace` (`^0.1.0`)

Optional integrations:

- HTML Editor provides owned pages and editing; Menu adds navigation.
- Theme enables isolated per-Workspace themes and carries their light/dark presentation into portable exports.
- Calendar and Task turn embedded live data into ACL-aware read-only export snapshots.
- Notification informs reviewers/authors; E-mail can copy those notifications.
- API adds ACL-aware Workspace resources and tree-management endpoints.

```bash
composer require aaieduhr/simbioza-module-workspace:^0.1.0
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Croatian documentation: [README_hr.md](README_hr.md)

## Features

- built-in **Public** and **All signed-in users** audiences plus restricted Workspaces
- user and group permissions: view, add, edit, publish, delete, and manage
- asynchronous Auth-directory search without listing every user and group
- page-level restrictions inherited by every descendant
- ACL-filtered Workspace Shorts with rendered article excerpts, depth, count, and ordering controls
- hierarchical document, internal-link, and external-link nodes
- compact, borderless, collapsible, and responsive page tree that opens only
  the active page's ancestor path below the first level
- ACL-safe breadcrumbs from the application home through visible ancestors
- backlinks from other published pages, revalidated against the viewer's current ACL
- system, Workspace, and page-specific defaults for page-tree and outline visibility
- page creation directly from an open Workspace
- soft deletion and administrator restoration of Workspaces
- optional HTML editor integration for content, versions, and attachments
- per-page and per-language publishing workflow: draft, review, published, archived
- readers keep seeing the last published immutable version while editors prepare a draft
- optional in-app and e-mail notifications for review requests and publications
- optional Menu integration for application and Settings navigation
- public, signed-in, and personal application-homepage selection with ACL-safe fallback
- optional per-Workspace system-theme selection and isolated private theme customization
- optional versioned REST API for Workspace metadata, ACL, link-tree operations,
  homepages, scoped themes, summaries, and portable HTML export
- optional Workspace Search integration for ACL-aware published-content lookup
- administrator maintenance for the complete site or one Workspace, with
  storage reporting, safe history pruning, permanent removal of old deleted
  pages/assets, and confirmed permanent removal of an entire soft-deleted Workspace
- neutral `WorkspaceContentChanged` events after publication, archival, deletion,
  tree metadata, and Workspace lifecycle changes so optional derived indexes can
  synchronize without coupling Workspace to a search implementation
- a public `WorkspaceContentChangeBatch` for large imports: it collects individual
  changes and emits one final `bulk_content_changed` event per Workspace
- portable initial schema for SQLite, PostgreSQL, and MySQL/MariaDB

Page restrictions only narrow the permissions granted at Workspace level. They
never grant access to a user or group that is not already a Workspace member.
Application administrators retain management access. The creator receives
`can_manage` through a regular user ACL row, which can later be changed like any other grant.
In an archived Workspace, add, edit, and delete are disabled for them as well
until they reactivate it.

Open **Edit tree**, then the pencil beside a page, to inspect page restrictions.
Green checkboxes show permissions inherited from the Workspace and ancestor pages; red checkboxes
show permissions retained by a direct restriction on that page. Saving no red
checkboxes removes the direct restriction and returns to full inheritance.

`Public` is a built-in view-only audience. `All signed-in users` is not a real
Auth group either, but it may receive broader permissions. The form renders
assigned ACL rows only; users and groups are added through bounded server-side
search without loading the complete directory.

Default display is resolved in **page → Workspace → system** order. A
Workspace may inherit, show, or hide its tree and page outlines, while an
individual page may override only its own outline display.

Deleted documents are not physically removed immediately: administrators can
restore them until they are explicitly purged after the selected retention
period in **Settings → Workspaces → Maintenance**. See the
[maintenance documentation](docs/index_en.md#13-storage-maintenance) for the
safety rules.
The same page offers **Optimize existing images**, which creates missing
reduced web-display copies without changing the source files. A visible progress
bar is updated after every bounded batch; closing and reopening maintenance
resumes the saved job instead of repeating a long blocking request.

## Requirements

- PHP 8.2 or newer
- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-orm`

The HTML editor, API, Menu, Notification, and E-mail modules are optional integrations.

When Menu is enabled, Workspace also registers live navigation destinations in
the shared editor catalog. All four menu editors show active areas and their
document pages as `Area / Page`. Menu's special-menu selector combines these
dynamic entries with every other navigable application page, and writes
portable paths without the current deployment base path. Menu remains optional
and Workspace does not write another module's JSON directly.

## API Integration

Workspace publishes the neutral `workspace:read` and `workspace:manage`
descriptors from `config/api.php` without depending on the API module. When
the API module is also installed, it conditionally exposes versioned Workspace
routes under `/api/v1/workspaces`.

`workspace:manage` covers Workspace metadata, soft deletion/restoration, ACL,
tree order, internal/external link nodes, and portable HTML export. Read scope
also covers published Shorts. It does not create or delete
HTML documents and attachments; those remain the HTML editor's responsibility.
Every operation checks both the key scope and the effective Workspace ACL of
the key owner. A broad scope never turns an unauthorized user into a manager.

See [docs/index_en.md](docs/index_en.md#10-api-integration) for the route list
and response behavior.

## Installation

```bash
composer require aaieduhr/simbioza-module-workspace
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Add the package after Auth and ORM in `app.modules.enabled`:

```php
'aaieduhr/simbioza-module-workspace',
```

Copy `config/workspace.php` into the host application when its defaults need
to be changed.

No sample Workspace, user, group, or page is created by the migration.

For an application that already ran the older Workspace migration, install the
additive homepage migration once:

```bash
vendor/bin/hph workspace:install-homepage-migration
vendor/bin/hph orm-migrate:up
```

If that homepage migration was already applied before structured Shorts targets
were introduced, add its backward-compatible view-option columns as well:

```bash
vendor/bin/hph workspace:install-homepage-view-options-migration
vendor/bin/hph orm-migrate:up
```

For an existing installation, add private Workspace-theme storage once:

```bash
vendor/bin/hph workspace:install-themes-migration
vendor/bin/hph orm-migrate:up
```

Existing installations also add the rebuildable backlink index once:

```bash
vendor/bin/hph workspace:install-backlinks-migration
vendor/bin/hph orm-migrate:up
```

To retain portable source labels from imports and integrations, an existing
installation adds label storage once:

```bash
vendor/bin/hph workspace:install-node-labels-migration
vendor/bin/hph orm-migrate:up
```

Structured page properties used by dynamic reports require one additional
migration in an existing installation:

```bash
vendor/bin/hph workspace:install-node-properties-migration
vendor/bin/hph orm-migrate:up
```

Breadcrumbs are built only from the already ACL-filtered page tree. Backlinks
are extracted from exact published HTML versions and are checked again against
page ACL and publication state whenever they are displayed. The active locale
is preferred and the site-default locale is used only when that source page has
no backlink record in the active locale. Theme-enabled applications use the
breadcrumb, card, link, border, and muted-text theme tokens; Bootstrap
fallbacks keep both components usable without Theme.
Background rebuilds use the Editor's narrow published-version provider without
a web session. This lets CLI and bulk imports construct the derived index,
while ACL and publication state remain mandatory checks on every display.

`workspace_backlinks` and `workspace_backlink_index_state` are derived data.
They must not be included in backups; publication events keep them current and
the periodic safety rebuild repairs missed events.

## Workspace Shorts

Every visible Workspace tree exposes a **Shorts** icon. The page at
`/{workspace-root}/{workspace}/shorts` renders the exact published Editor
version of every eligible page as a twelve-line fading excerpt with a **Read
more** link. This leaves room for roughly five to six additional text lines
even when the article begins with a compact image. Drafts, archived
publications, inaccessible pages, and every
descendant of an inaccessible page are excluded before content is loaded.

Visitors may select tree levels 1, 1–2, or 1–3; 5, 10, 25, 50, or all
articles; and hierarchy, newest-first, or oldest-first ordering. **All** is
available only when fewer than 100 articles pass publication and ACL checks.
The server enforces the same limit even for a hand-crafted query string.

Defaults are configured under **Settings → Workspaces** and stored in the host
application's `config/workspace.php`:

```php
'shorts' => [
    'depth' => 2,
    'limit' => 10,
    'order' => 'newest',
    'display_options_visible' => false,
],
```

The page tree is expanded and the **Display options** panel is collapsed by
default. Their always-visible, theme-aware icon buttons expose accessible labels
and tooltips. A direct link can override either state with `tree=0|1` or
`options=0|1`; the filter form preserves the visitor's current state. Article
content first uses an exact published version for the active locale, then the
site default from `app.localization.locale` in `config/app.php`. It never falls
back to a draft.

These are site configuration, not Theme design data. Include
`config/workspace.php` in a complete site backup; Theme package export does
not and should not own these values.

## Application homepage

Administrators configure the homepage under **Settings → Workspaces →
Application homepage**. They may select a published page or a Workspace
**Summaries** view for anonymous guests, a different target available to every
signed-in user, and whether users may choose a personal target in their Auth
profile. A selected Summaries target exposes structured **Page tree visible**
and **Display options visible** switches instead of a free-form query string.

Resolution order for a signed-in user is personal page, signed-in default,
public default, and finally the host application's built-in homepage. Guests
use the public default and then the built-in homepage. Every request rechecks
the current Workspace ACL and publication state; a deleted, unpublished, or
newly restricted page is skipped instead of producing a homepage `403`.

The host application may consume the neutral
`heartphrame.application_homepage_resolver` service at `/` and issue a
temporary, non-cacheable redirect to the canonical Workspace page. Auth has no
dependency on Workspace: the profile section is registered only by Workspace
while that module is enabled.

The settings and personal preferences are stored in
`workspace_homepage_settings` and `workspace_user_homepages`. A complete
database/site backup must include both tables. These values are site content
configuration and intentionally do not belong to Theme package export. The
structured target type, Workspace ID, and both visibility switches are stored
in those tables, so ordinary database backup and restore preserves the exact
homepage behavior.

## Workspace themes

When the optional Theme module is enabled, every Workspace manager receives a
**Workspace theme** section under **Manage Workspace**. **Default system theme**
preserves the current global behavior. A manager may instead select any system
theme without copying it. The selected system JSON remains read-only.

The first edit or asset upload creates a private copy stored only for that
Workspace. Unless the manager changes the label, the Workspace name is appended
to the source label. Configuration is stored in the `workspace_themes` database
row; private image assets are stored under
`data/workspaces/themes/<workspace-id>/assets`. Other Workspaces and global Theme
settings cannot see or modify this private copy.

Managers may import a complete Theme v3 ZIP into their Workspace. Only an
application administrator receives **Export complete theme**, allowing that
private theme to be imported into another Workspace or the global system Theme
library. Public asset delivery rechecks Workspace view ACL. Runtime activation
is request-scoped and never writes the global `themes.json` or `settings.json`.

## Workspace special menus

When the optional Menu module is enabled, every user with the effective
Workspace `can_manage` permission receives **Special Workspace menus** under
**Manage Workspace**. The screen contains independent editors for the special
top menu and special left menu. Saving or removing one never changes the other.

The server locks both editors to `/workspace/{slug}` and its descendants. A
manager may edit labels and menu items but cannot alter form data to affect a
different Workspace or another application page. System-wide Menu settings
remain administrator-only. Without Menu, Workspace remains fully usable and
the integration link is omitted.

## HTML Editor Integration

The Workspace module does not store HTML. It links a tree node to the editor's
stable document key through an optional service bridge.

When both modules are enabled:

- Workspace routes and inherited ACL own linked document access;
- the editor's standalone public slug route is disabled;
- authorized Workspace members can add, edit, and delete linked pages;
- a regular editor automatically creates a new document and cannot attach
  somebody else's existing document by guessing its key; attaching existing
  documents is reserved for administrators;
- internal absolute paths are resolved inside the application's configured base
  path, so `/calendars` also works when the application runs under `/example-app`;
- a page uses the complete HTML editor view, including theme, languages,
  history, attachments, ZIP export, document outline, audit data, and responsive behavior;
- Workspace adds only the left tree, while effective node ACL controls editing,
  history, and other protected actions;
- document versions and attachments remain owned by the HTML editor;
- a new or changed page becomes a draft, while only an explicit publish action
  changes the immutable version visible to readers;
- there is one shared draft per page and locale; the regular view always shows
  the latest publication, while draft editing and preview are explicit actions;
- editors may submit or withdraw a review, users with publish permission may
  publish, and managers archive or restore pages;
- submitting for review notifies effective publishers, while publication
  notifies the submitting author; the Notification inbox is primary and the
  E-mail module may queue an optional SMTP copy;
- the tree marks new unpublished pages, while its header exposes permission-aware
  lists of new pages, pages submitted for review, and the Shorts page;
- Shorts requests exact published versions through one optional batch Editor
  service call after applying depth, publication, ACL, ordering, and count filters;
- an editor document can belong to only one active Workspace page.

The HTML editor continues to work independently when Workspaces are absent.
Its standalone view always uses the current editor version and does not expose
Workspace workflow controls.

## Portable Workspace HTML export

Administrators and users with the effective Workspace `can_manage` permission
can open the **Export Workspace to HTML** icon beside **Save** on **Manage
Workspace**, or the matching icon in the administrator's **All Workspaces**
list. Its localized tooltip and accessible name explain the action. The form exports either every eligible
page or an explicit page selection. A selected-page export rebuilds the tree so
it contains only HTML pages that are actually present in the package.

The download is a ZIP transport package. Extract it and open the root
`index.html`; no PHP or web server is required. This file is the single themed
offline shell: it keeps the same header, active light/dark branding, hero,
content width, overlap, tree, and document card composition as the application.
The selected logo and hero artwork are embedded in `index.html` as data URIs,
so they remain visible over `file://` after extraction on Windows, macOS, or
Linux; their original files are retained in the ZIP for inspection.
It swaps the selected page and language locally and shows the actual page title
in the hero plus the localized `Exported Workspace: {Workspace}` note.

A locale directory is created only when at least one selected page is actually
published in that locale. Content is never copied into a missing translation
as a physical fallback file. Every real translation receives one stable,
standalone HTML file built with the same formatter as Editor's single-page
export. It can be opened directly through `file://` and intentionally contains
only the rendered document, not the Workspace shell or Theme page background.
Native Editor charts are converted to responsive self-contained SVG and retain
the same width, alignment, spacing, and theme colours as the page view.
Editor formatted-content tabs remain interactive in both the root offline shell
and every standalone page file. Their panels grow with content, code may scroll
horizontally, and mouse plus keyboard navigation require no server.

The export deliberately applies security before packaging:

- the GET form and POST download route require an administrator or effective
  `can_manage` permission;
- only pages with an effective `can_view` grant and a published, non-archived
  version are included;
- a tampered list of page IDs is intersected again with the ACL-visible tree;
- per-page restrictions and inherited Workspace restrictions remain effective;
- Calendar and Task embeds are rendered as read-only snapshots through their
  existing ACL-aware Editor integrations; the package contains no active API
  calls, credentials, editing actions, or server-side state.
- dynamically included pages are pre-rendered under their own ACL, and the
  package also carries their images and attachments required by the content.

The package includes the current Theme CSS and only the selected light/dark
logo and hero assets, Editor/Calendar/Task CSS, visible attachment files, a
filtered page tree, document outlines, and a machine-readable `manifest.json`.
The offline header lists every configured language and provides a light/dark
selector. When the current page has no exact translation, the shell shows its
site-default Croatian translation and then its first real translation; it does
not manufacture an extra file. Tree, outline, and attachment panels are
independently toggleable and begin in the same state as their application
configuration. Theme is optional; without it, a minimal readable header, hero,
and layout are generated. Editor is required for document content.

## Structured page content

Workspace pages support labels and structured properties (`text`, `status`,
`link`). The HTML Editor can use them in a native pages and properties table and also provides
an attachment gallery, current-Workspace search, and recent changes. Every
dynamic result is filtered again through the current ACL; the API,
backup/restore, and HTML export preserve the same contract. Dynamically generated
tables use the HTML Editor's standard responsive table markup and therefore
follow the active theme without a Workspace-specific visual override.

## Documentation

The detailed architecture and beginner-oriented operational guide are in
[docs/index_en.md](docs/index_en.md).
Workspace backup and restore are documented in [docs/backup_en.md](docs/backup_en.md).

## Licence

This work is published under the
[European Union Public License (EUPL) v1.2](LICENSE).

## Personal following integration

Workspace publishes neutral content-change events with the actor, workspace,
page, language, and reason. When `aaieduhr/simbioza-module-user` is enabled it
adds page/workspace follow controls and converts those events into ACL-safe
personal notifications. Workspace does not depend on that optional module.

## Dependency policy

The Framework is constrained to `^0.0.24` and internal modules to the
compatible `^0.1.0` release line. This module does not commit `composer.lock`; CI resolves
the latest development heads and runs the complete `composer on-commit` suite.
