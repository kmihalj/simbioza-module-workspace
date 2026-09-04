# Workspace Module Guide

Croatian version: [index_hr.md](index_hr.md)

## Presentation extensions

Derived modules may register a `WorkspacePresentationProviderInterface`
implementation in `WorkspacePresentationRegistry`. The registry decorates
already loaded Workspace rows in one batch for the active or explicitly
requested locale. Providers must change presentation fields only and must not
write to the Workspace tables.

## 1. Mental model

A Workspace is an organizational and security boundary above individual
pages. A Workspace owns the page tree and permissions, while specialized
modules continue to own their content.

For example, a document node stores an HTML editor `document_key`; the HTML
editor still stores HTML versions and attachment metadata. Link nodes contain
an internal route/path or an external HTTPS URL and do not require the editor.

The design prevents the Workspace module from reaching into another module's
private database tables.

## 2. Data model

The single initial migration creates eight tables through the ORM:

| Table | Responsibility |
| --- | --- |
| `workspaces` | Identity, slug, visibility, archive and soft-delete state |
| `workspace_acl` | User/group grants at Workspace level |
| `workspace_nodes` | Ordered hierarchy of documents and links |
| `workspace_node_acl` | Additional restrictions inherited through the tree |
| `workspace_node_workflows` | Per-page and per-language publication state and immutable Editor version pointers |
| `workspace_homepage_settings` | Public and signed-in application-homepage policy |
| `workspace_user_homepages` | Optional personal homepage selections |
| `workspace_themes` | Per-Workspace system selection or isolated private theme JSON |

There are no database-specific SQL statements. Boolean defaults use real
booleans, and schema creation is compatible with SQLite, PostgreSQL, and
MySQL/MariaDB.

The migration intentionally contains no test data.

## 3. Workspace visibility

Visibility is configured in the same ACL table as every other permission. Two
built-in audiences look like groups in the interface, but they are not Auth
groups and are never created in the user-group directory:

- **Public** (`public`) includes guests and signed-in users and always grants
  view only. Add, edit, delete, and manage are deliberately unavailable for
  this audience.
- **All signed-in users** (`authenticated`) includes every authenticated user
  and may receive view plus the broader permissions selected by a Workspace
  manager.
- When neither built-in audience is assigned, the Workspace is `restricted`
  and is visible only to administrators and explicitly authorized
  users or Auth groups.

The `workspaces.visibility` column retains a summarized value for efficient
Workspace filtering, but it is synchronized automatically from the built-in
ACL rows. The form therefore has no second visibility selector that could
drift away from the actual permissions.

An archived Workspace remains readable to authorized users, but content
changes are disabled for managers and administrators as well. Their
`can_manage` permission remains active so they can reactivate the Workspace.
Deleting a Workspace is a soft delete. Administrators can list and restore
deleted Workspaces, including resolving a slug conflict.

## 4. Permissions and inheritance

Workspace ACL accepts individual users, real Auth groups, and the built-in
`public` and `authenticated` audiences. Grants from the current user, every
built-in audience they belong to, and all their groups are combined:

- `can_view`
- `can_add`
- `can_edit`
- `can_publish`
- `can_delete`
- `can_manage`

`can_manage` implies every other permission. Application administrators receive
the complete permission set. A new Workspace creator receives a regular user
ACL row with `can_manage`, without a special owner status.

The management screen does not load every user and group. It renders assigned
ACL rows only, while a searchable picker adds new subjects. Search runs on the
server, accepts part of a display name, login identifier, or group name, and
returns at most 20 results per request. The previous request is cancelled when
the operator keeps typing. This remains usable with thousands of users and
hundreds of groups without embedding the complete Auth directory in HTML.

Removing a table row removes only the permission assignment after save; it never deletes the user
or group from the Auth module.

Node ACL is deliberately restrictive:

1. calculate effective Workspace permission;
2. walk from the root node to the requested node;
3. whenever an ancestor has restriction rows, retain only permissions allowed
   to the current user's already-authorized user/group subjects;
4. never broaden the Workspace permission.

An empty node ACL means “inherit without an additional restriction.” A
restriction on a parent automatically applies to every descendant.

In the open Workspace, select **Edit tree** and then the pencil beside a node.
The modal shows grants inherited from the Workspace and ancestor pages in green and direct page
restrictions in red. Red checks can only retain a permission already granted
in green; they can never broaden it. Removing every red check and saving
returns the node to unrestricted inheritance. Users with `can_edit` but not
`can_manage` may inspect the matrix, while only `can_manage` may change it.
The modal is attached directly to the document body so Theme or Hero stacking
contexts cannot place it behind Bootstrap's backdrop.

When rendering a large tree, the module batch-loads nodes, Workspace ACL,
the user's groups, node restrictions, and workflow states. Ancestor chains and
effective permissions are then calculated in memory. This keeps the number of
ORM queries approximately constant instead of adding several queries for every
new page, while preserving identical inheritance and security checks.

Workspace listings use the same rule: a regular user's ACL rows for every
listed Workspace are loaded in one query, while the administrator fast path
does not read ACL rows it cannot need. Adding Workspaces therefore does not
create an ACL query-per-row pattern.

The main directory renders 25 Workspaces per page with a bounded page-number
window. When an optional presentation provider marks personal Workspaces and
their stable owner IDs, the administrator directory separates them behind a
**Personal Workspaces** button. A regular user's own personal Workspace remains
in the main directory, while other personal Workspaces that already passed the
same ACL filter appear in a separate dropdown. A personal marker never bypasses
Workspace ACL.

Those calculated results live for one request only. After saving Workspace or
node ACL entries, the controller explicitly clears the short-lived cache, so a
later check in the same request also sees the new permissions.

## 5. Page tree

The left-side tree can be shown or hidden. It visually follows the HTML
editor's outline card by using the same heading, `list-group`, theme, and
internal scrolling. On desktop it remains available while reading. On mobile
it leaves the document flow and becomes a floating card inset from every
viewport edge that slides in from the left. The card preserves the active
theme's tree colours, border, accent, and shadow. A discreet fixed edge icon
opens the card, while its close button, backdrop, or Escape returns focus to the
document. Focus and `aria-expanded` state follow the open card.

The reader tree is a compact list without item borders. Its first-level
branches expose the second level, while deeper branches start collapsed. A
direct page URL marks that page and expands only its ancestor path; the same
prepared state is retained if the whole tree card starts hidden and is opened
later. The management organizer remains fully expanded so ordering operations
always expose the complete hierarchy.

When a reader manually expands or collapses branches, that state is retained
while navigating inside the same Workspace in the current browser tab. Each
Workspace has an independent state. Active-page ancestors are always opened
after navigation so restored preferences can never hide the selected page.

The tree card and HTML card start in the same row. The tree toggle is the first
SVG action in Editor's shared view, while SVG actions for creating a page and
managing the Workspace live in the tree header itself. Actions therefore do
not reserve a separate empty row and their visibility still follows effective
ACL permissions.
Compact header actions are right-aligned in their own row, while the complete
Workspace name is rendered below them without truncation.

Node types:

- `document`: links to one HTML editor document;
- `internal_link`: follows a named project route or an internal path;
- `external_link`: follows a validated external URL.
- `separator`: the single localized `Linkovi` / `Links` system branch used to
  group links without creating a document.

Parent and order values define the hierarchy. A user with complete management
permission enables the organizer directly from the icon in the left tree
header: up/down arrows move a complete subtree among items with the same
parent, while left/right arrows outdent or indent the subtree by one level.
Unavailable actions are disabled. Order numbers are synchronized automatically
and the complete arrangement is saved by one atomic ORM transaction. Before
the first write, the repository verifies that every active node is present,
all parents are document pages or the system separator, and the resulting tree
contains no cycle. The separator is created explicitly in the organizer, may
be moved like every other branch, and is never synthesized from missing import
data.

The complete organizer loads only after the first click on its icon. Its node
permissions are calculated in one batch. The available-document list and
add-item form load separately only after the footer's **Add item** action. A
normal page view therefore does not ship a large hidden HTML form, and opening
the organizer no longer enumerates every Editor document, substantially
reducing latency for imported Workspaces with many pages.

A small edit icon beside an item opens a Bootstrap modal with title, slug,
type, target, inherited restrictions, and deletion. The form is loaded on
demand so large trees do not create hundreds of hidden forms. The organizer
footer opens a modal for adding a link or existing document and selecting its
initial parent. Deleting a node soft-deletes the complete subtree. A linked
editor document is soft-deleted through the optional bridge. The separate
**Manage Workspace** screen retains only Workspace data, members, Workspace
ACL, and Workspace deletion.

Moving a node requires `can_edit` on the node and `can_add` on the new parent or
tree root. The management view never sends nodes for which the user lacks
effective `can_view`. A user with content-change rights can open tree
management, but Workspace metadata and ACL remain editable only with
`can_manage`.

An internal link accepts an existing named route or a local absolute path that
starts with one slash, for example `/calendars`. The local path is automatically
resolved under the application base path, so it becomes `/example-app/calendars` when
the application is mounted below `/example-app`. Absolute HTTP(S) URLs are accepted only
by `external_link`.

One active HTML editor document may be linked to only one active Workspace
node. This keeps URL and ACL ownership unambiguous.

### 5.1 Workspace Shorts

The list icon in every visible tree header opens
`/{workspace-root}/{workspaceSlug}/shorts`. It is visible to every user who can
view that Workspace; it is not a management action.

The page supports three independent controls:

- levels 1, 1–2, or 1–3 of the visible tree;
- 5, 10, 25, 50, or all articles;
- hierarchy, newest-first, or oldest-first order.

The `all` option is enabled only below 100 eligible articles. The controller
rejects a crafted `limit=all` at 100 or more and falls back to the configured
numeric default. Defaults live under the `shorts` key in
`config/workspace.php` and are editable in **Settings → Workspaces**. The
shipped defaults are levels 1–2, 10 articles, and newest first.

The page tree is initially visible and the display options are initially
collapsed. Their theme-aware icon buttons are always rendered with accessible
labels and tooltips. Direct links may use `tree=0|1` and `options=0|1` to
override either state; omitting them uses site configuration. The filter form
preserves the current states, which makes the same route suitable for a normal
page or a compact homepage.

If Menu supplies a route-specific special left menu, the application initially
collapses the Workspace page tree regardless of the Workspace default. This
prevents two open left navigations while keeping both icon controls available;
`tree=1` remains an explicit opt-in for direct links.

Security filtering happens before HTML is loaded: the service starts from
`visibleTree()`, applies inherited node ACL, keeps only document nodes within
the selected depth, then requires a non-archived workflow with a positive
published version pointer. This rule also applies to owners and administrators,
so their access to drafts never leaks draft text into Shorts. The Editor
receives only the already limited document/version map and batch-loads those
exact immutable versions. The view renders Editor-sanitized HTML inside a
twelve-line, theme-aware fade and links to the canonical Workspace page. For
every page it requests the active locale first and falls back only to an
exactly published version in the site default locale from
`app.localization.locale`. Draft content is never a language fallback.

Shorts adds no database table. Its defaults are host-site configuration, so a
complete backup includes `config/workspace.php`; Theme package export does not
own them.

## 6. HTML editor integration

Integration is optional and service-based. Workspace dynamically detects the
editor package and uses its public services. The editor likewise detects the
Workspace ACL service without declaring a hard dependency.

When Workspace is enabled:

- the editor Settings screen shows that Workspace owns public routes;
- the standalone editor slug switch is disabled;
- a linked document is loaded only when inherited `can_view` succeeds;
- Workspace embeds Editor's shared complete view in the right column, so theme,
  languages, history, attachments, ZIP export, document outline, and audit are
  identical to the standalone view;
- edit, upload, metadata, version, and attachment actions require `can_edit`;
- document deletion requires `can_delete`;
- menu document URLs point to Workspace pages.

Workspace neither reads Editor's private tables nor copies its HTML template.
It requests `EditorDocumentViewBuilder` through the optional service bridge and
renders Editor's official `editor/view` partial next to the left tree. Language
and outline links therefore remain in the current Workspace, while export and
asset routes re-check the same inherited ACL on the server.

During one HTTP request, the integration service caches the resolved document,
owning Workspace, calculated permissions, public path, and published version
number. Editor needs those same values for several icons, locale links, and
history entries while building one view. This short-lived cache removes
duplicate ORM queries, is not carried into the next request, and does not alter
security checks.

A user with `can_add` sees **New page** in the open Workspace. The compact
form asks only for a title, optional slug, and parent page. On submit, the
module creates an editor document, links its stable key to the tree page, and
immediately opens the HTML editor. The first created page automatically
becomes the Workspace homepage.

Page creation first validates `can_add` on the Workspace and selected parent
page. A link item cannot be the parent of a new page.
A regular editor may keep the document owned by their node or automatically
create a new one. A crafted POST request cannot attach another existing
document. An administrator may select an existing document, while the server
checks that it exists and is not already owned by another active node.

The management screen is neither the content-authoring nor tree-arrangement
entry point. The tree, links, and inherited restrictions are managed in the
context of the open Workspace. The advanced modal shows only fields relevant
to the selected item type.

Without the editor module, Workspaces and link nodes remain usable. Without
Workspace, the HTML editor retains its standalone behavior.

### 6.1 Portable Workspace HTML export

`GET /workspaces/export?workspace={slug}` opens the selection form and
`POST /workspaces/export` returns the ZIP. Both routes require authentication;
the controller then requires either administrator status or effective
Workspace `can_manage`. This second authorization check is mandatory even when
the user knows the direct URL.

Choose **Complete Workspace** to export every published page the exporter may
view, or **Selected pages** to submit `node_ids[]`. The server never trusts those
IDs: it rebuilds the inherited ACL tree for the current user, removes drafts and
archived-only pages, and intersects the submitted list with that result. A page
with a stricter inherited or direct restriction is therefore absent from both
the generated tree and the ZIP.

The package layout is:

```text
index.html                     # single themed offline shell
manifest.json                  # source, locale and exported-page inventory
assets/css/                    # Theme, Bootstrap, Editor, Calendar and Task CSS
assets/js/workspace-export.js  # local theme/language/panel controls
assets/theme/                  # only active light/dark Theme assets
documents/{document-key}/{source-locale}/v{version}/
                               # immutable-version attachment bytes without locale collisions
{published-locale}/{page}.html
```

Open the extracted root `index.html` directly from the filesystem. It is the
only themed offline application shell. The sole menu item is **Home** and
switches back to the exported Workspace homepage. Tree links switch the
embedded immutable page snapshot without HTTP or `fetch`. The shell reuses the
Theme module's real header and hero renderers, portable CSS, selected light/dark
logo and hero artwork, container width, and content-overlap classes. The hero
therefore contains the current page title exactly as it does in the application
and adds the localized `Exported Workspace: {Workspace}` note. It does not add a
second page title.

The selected light/dark logo and hero images are embedded into `index.html` as
data URIs, so their display does not depend on browser-relative paths over
`file://`. The original selected files remain under `assets/theme/` for ZIP
inspection and integrity verification.

Every `{published-locale}/{page}.html` file is a clean standalone document made
by Editor's own formatter. Opening that file directly shows the same rendered
HTML as a single-page export, without the Workspace header, hero, tree, or
editing actions. A standalone file does not load Theme CSS and therefore does
not inherit the application's page background. Locale directories and page files exist only for real
published translations. The root selector still lists every configured
language; for a missing translation it displays the site-default Croatian
snapshot, then the first real snapshot. That runtime fallback never creates a
duplicate locale file.

Dynamically included pages are pre-rendered from current published content,
and their required images and attachments enter the package after another ACL
check. Calendar and Task placeholders are converted to static read-only HTML through
the same ACL-aware integration methods used by Editor's single-page export.
Native Editor charts become responsive self-contained inline SVG and retain
their configured width, alignment, spacing, legend, and theme colours.
Calendar edit and download actions are omitted: the calendar is a rendered
read-only snapshot. There are no active API requests in the exported page.
Attachments are copied from the exact published version, never from an editable
draft. Theme, language, tree, outline, and attachment controls run locally and
do not require Bootstrap JavaScript. When the Theme module is absent or
disabled, the same package structure remains functional with a minimal readable
fallback header, hero, and layout.

## 7. Publishing workflow

Publication state belongs to a document node and a language. Workspace stores
only status, audit timestamps, user identifiers, and Editor version numbers.
The HTML payload, attachments, and immutable versions continue to belong to the
HTML editor.

The clean initial state is **Draft**. A document node without a workflow row is
also treated as an unpublished draft; there is no legacy auto-publish fallback.
The supported states are:

1. **Draft**: editors work on one shared mutable draft. The regular view shows
   the earlier published version to everyone, if one exists. Editing and
   previewing the draft are separate explicit actions. The regular published
   view does not offer actions that mutate the draft; discard, submit, and
   publish are available only on the explicit draft preview.
2. **In review**: the working version is ready for review. It is still not
   public.
3. **Published**: the selected immutable version becomes the reader version.
4. **Archived**: the page is removed from the reader tree and public view.
   Restoring it creates an unpublished draft that must be published again.

Saving updates the same shared draft and does not add it to history. History
contains published immutable versions only. Restoring a historical version,
copying a locale, deleting an attachment, or another content change also
prepares the shared draft. This does not replace `published_version_number`, so
every regular view receives stable published content while the next publication
is prepared.

Permissions deliberately separate editing from publication:

- `can_edit`: submit a draft for review and withdraw it;
- `can_publish`: publish a draft, including direct `Save and publish`, which
  then opens the public view of the published page;
- `can_manage`: implies every permission and additionally archives or restores;
- every user receives exactly the recorded published version and its historical
  attachment set on the regular view;
- users with edit or publish permission receive separate actions for editing
  and previewing the shared draft.

Workflow transitions are server-validated through
`POST /workspaces/workflow`; changing a button, URL, or request body cannot
bypass effective inherited ACL. Discreet workflow actions are rendered only for
users who may perform them. Never-published pages are marked next to their tree
title and listed behind the `New unpublished pages` counter. Users with
`can_publish` also receive a `Submitted for review` counter and review queue.
Discarding a new page with no publication in any locale permanently deletes
its Workspace node, workflow, restrictions, and Editor document with its
attachments. It therefore does not enter the soft-deleted document list. If
the node has children, they are reparented to its parent. This destructive
discard variant requires effective `can_delete` permission. If another locale
has a publication, only the current locale draft is discarded.

When the optional Notification module is installed, submitting a draft for
review creates a deduplicated inbox message for every effective publisher
except the actor. Publishing creates a message for the user who submitted the
draft when that user is different from the publisher. Notification delivery is
an auxiliary channel and cannot roll back a successful workflow transition.
If the optional E-mail module is enabled, the same notification may also be
queued in its persistent SMTP outbox.

After a publication pointer changes, a page or subtree is disabled, or
Workspace/tree metadata changes, the repository dispatches the neutral
`WorkspaceContentChanged` event. Optional derived modules may listen to that
event and synchronize only the affected Workspace and language. Draft saves
that preserve the current published pointer intentionally do not dispatch a
publication change, so readers and indexes keep the last published version.
Listener failure is isolated from the source write; periodic or manual reindex
can repair a derived store without losing authoritative Workspace data.

When Workspace is not installed, all integration calls are no-ops. Standalone
Editor save, view, history, and export continue using the current document
version exactly as before.

## 8. Configuration

`config/workspace.php` supports:

```php
return [
    'enabled' => true,
    'routing' => [
        'root_path' => 'workspace',
    ],
    'defaults' => [
        'visibility' => 'restricted',
        'tree_visible' => true,
        'contents_visible' => false,
    ],
    'creation' => [
        'users' => [],
        'groups' => [],
    ],
    'menu' => [
        'auto_register_top' => true,
        'auto_register_settings' => true,
    ],
];
```

Administrators may always create Workspaces. Other creators are selected in
Workspace settings as individual Auth users or existing Auth groups.

`tree_visible` and `contents_visible` are the system fallbacks. Each Workspace
may inherit, show, or hide its tree and page outline, while an individual page
may additionally override only its own outline. Resolution order is:
**page → Workspace → system setting**. Display query parameters remain a
one-request user override and do not change saved defaults.

The root path must be a free first route segment. Settings reject collisions
with an existing application route.

If Menu is enabled, Workspace idempotently registers:

- a top-menu Workspace entry;
- General settings;
- All Workspaces;
- Deleted Workspaces.
- dynamic editor destinations for every active Workspace and document page.

Repeated requests do not duplicate or relocate those entries.
Workspace administration pages render the shared Settings sidebar when Menu is
available. Without Menu, the same pages remain usable through a local fallback
sidebar containing General settings, All Workspaces, and Deleted Workspaces.

The destination integration is lazy, so opening any of Menu's four editors
reads the current Workspace tree at that moment. Areas appear under
**Workspaces**, pages under **Workspace pages** as `Area / Page`. Menu combines
them with all other navigable application pages in **Apply special menu to**.
Selecting an area fills `/workspace/slug` and `/workspace/slug/*`; paths never
contain the current deployment base path. API, action, dialog, asset, and other
technical routes are filtered by Menu and never become navigation choices.

### Per-Workspace theme policy

Theme is an optional integration. With it enabled, a manager may leave a
Workspace on **Default system theme**, explicitly select one system theme, or
edit/import an isolated private theme. The first edit creates a complete private
copy and appends the Workspace name to unchanged source labels. System Theme
JSON is never written from a Workspace request.

The `workspace_themes` row contains selection type, source ID, mode policy, and
private theme JSON. Private binary/SVG assets live under
`data/workspaces/themes/<workspace-id>/assets`; references use
`@runtime-theme-assets/<file>`. Asset delivery rechecks view ACL, while the Theme
repository receives the resolved override only for the current request. This
keeps the global active theme unchanged on all non-Workspace pages and in other
Workspaces.

Managers can import a complete Theme v3 archive. Export is deliberately limited
to application administrators and only exists for a private Workspace theme.
That archive is compatible with both another Workspace import and the global
Theme library import. A complete backup must include the `workspace_themes`
table and `data/workspaces/themes` directory.

### Per-Workspace special menus

Menu is another optional integration. A user with effective `can_manage`
permission can open **Special Workspace menus** from **Manage Workspace** and
edit that Workspace's top and left route-specific menus independently. The
controller repeats the Workspace ACL check for both GET and POST requests.

The browser never owns the scope. Before saving, the server overwrites the
context identifier, label, route patterns, and portable path patterns with the
selected Workspace's `/workspace/{slug}` and `/workspace/{slug}/*` values.
This prevents a forged request from changing menus outside the Workspace. A
left-only definition is absent from the top-menu editor and vice versa;
removing one side preserves the other. At runtime, matching separated records
may still supply both menus on the same page.

### Breadcrumbs and backlinks

Every rendered page has a **Home → Workspace → visible ancestors → page**
breadcrumb. The service receives the tree after ACL filtering, so it cannot
reveal the name of a hidden ancestor. The current page is not a link and its
label uses the localized title of the exact Editor version being displayed.

The **Links to this page** block lists other published pages that link to the
current page. Workspace and page ACL plus the source publication state are
rechecked on every display. A guest therefore sees only publicly readable
sources. The active locale is preferred; the site-default locale is used when
the source page has no backlink record in the active locale.

Below it, **This page is included in the content of the following pages** lists
published, currently readable sources that use Editor's dynamic **Include page
content** feature. The list starts from persistent Editor references but
rechecks the source's current version, Workspace workflow, and ACL so a removed
reference or newly restricted page is never disclosed.

The breadcrumb sits above the complete layout, so the page tree, route-specific
left menu, page content, and table of contents remain aligned. It inherits the
contrasting hero text colors; a theme may override them specifically through
`--hph-workspace-breadcrumb-text` and `--hph-workspace-breadcrumb-link`, while
the responsive text size can be overridden with
`--hph-workspace-breadcrumb-font-size`. On desktop, long hierarchies are
truncated within the content width so they cannot cover the cards; full labels
remain available on hover.
Backlinks use the same themed card, border, link, and muted-text values as other
cards. Both components fall back to Bootstrap values when Theme is not
installed.

Existing installations add the two derived tables with:

```bash
vendor/bin/hph workspace:install-backlinks-migration
vendor/bin/hph orm-migrate:up
```

`workspace_backlinks` and `workspace_backlink_index_state` are excluded from
backup because they contain no source-of-truth data. Publication updates the
source incrementally, structural changes rebuild the index, and a periodic
safety check repairs an interrupted event.

## 9. Installation and operation

```bash
composer require aaieduhr/simbioza-module-workspace
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Register Auth and ORM before Workspace in the enabled module list. The module
defers loading until those required services are available.

An existing installation adds page-label storage with:

```bash
vendor/bin/hph workspace:install-node-labels-migration
vendor/bin/hph orm-migrate:up
```

Useful URLs with the default configuration:

- `/workspaces`: visible Workspace list
- `/workspaces/manage`: create or manage a Workspace
- `/workspaces/theme?workspace={slug}`: select or privately edit a Workspace theme
- `GET /workspaces/acl/subjects`: bounded server-side search for users, groups,
  and built-in audiences; requires Workspace management permission
- `GET /workspaces/node/dialog`: ACL-protected modal content for a selected item
- `POST /workspaces/page/create`: securely create a page from an open Workspace
- `POST /workspaces/tree/order`: atomically save the visual tree arrangement
- `POST /workspaces/workflow`: perform an ACL-protected publication transition
- `/workspace/{workspace}`: Workspace homepage
- `/workspace/{workspace}/{page}`: page or link node
- `/settings/workspaces`: administrator settings
- `/settings/workspaces/homepage`: public, signed-in, and personal homepage policy
- `/settings/workspaces/all`: administrator list
- `/settings/workspaces/deleted`: restore screen

Existing installations add isolated Workspace themes with:

```bash
vendor/bin/hph workspace:install-themes-migration
vendor/bin/hph orm-migrate:up
```

### Application homepage policy

Workspace owns this feature because every stored target is a Workspace page
and must be revalidated through its inherited ACL and publication workflow.
Auth remains independently usable: Workspace registers its own optional Auth
account partial and removes it automatically when the module is disabled.

Administrators choose a published page or a Workspace Summaries view for the
public and signed-in defaults under the Workspace settings group. A Summaries
target has structured **Page tree visible** and **Display options visible**
switches. A user who is allowed to personalize the homepage chooses only from
published pages and Summaries views currently visible to that user. The root resolver applies
personal → signed-in → public → host fallback and redirects only to a generated
internal named route, preventing open redirects and stale ACL access.

Existing installations add the two portable tables with:

```bash
vendor/bin/hph workspace:install-homepage-migration
vendor/bin/hph orm-migrate:up
```

An installation that already has those two tables adds structured Summaries
targets without replacing existing page choices:

```bash
vendor/bin/hph workspace:install-homepage-view-options-migration
vendor/bin/hph orm-migrate:up
```

A full site backup includes `workspace_homepage_settings` and
`workspace_user_homepages`, including target type, Workspace ID, and the two
visibility switches. An individual Workspace import must not silently
replace the destination site's homepage policy, and Theme export does not own
these values.

## 10. API integration

### Page labels

Workspace can retain portable source labels supplied by trusted imports and
integrations. A selective Workspace backup carries labels by page UUID, so
`copy`, `merge`, and `replace` restores retain this source metadata. Labels do
not create a page template or a dynamic user-interface component by themselves.

Workspace remains independently installable. Its transport-neutral
`WorkspaceApiService` contains the business boundary. When optional API is
enabled, Workspace's `WorkspaceApiExtension` registers the routes and its
`WorkspaceResourceController` adapts that service:

| Method and path | Required scope | Domain rule |
| --- | --- | --- |
| `GET /api/v1/workspaces` | `workspace:read` | Returns only visible Workspaces |
| `POST /api/v1/workspaces` | `workspace:manage` | Uses the application creation policy |
| `GET/PATCH/DELETE /api/v1/workspaces/{slug}` | read/manage | Rechecks effective view or manage permission |
| `GET /api/v1/workspaces/{slug}/tree?lang=hr` | `workspace:read` | Filters inherited ACL and publication state |
| `GET /api/v1/workspaces/{slug}/shorts?lang=hr` | `workspace:read` | Returns only visible, exactly published summaries |
| `POST /api/v1/workspaces/{slug}/exports/html` | `workspace:manage` | Downloads the ACL-filtered offline HTML ZIP |
| `GET/PUT /api/v1/workspaces/homepage/settings` | `workspace:manage` | Administrator-only public/authenticated policy |
| `GET/PUT /api/v1/workspaces/homepage/preference` | `workspace:read` | Reads or stores only the key owner's personal choice |
| `GET/PATCH /api/v1/workspaces/{slug}/theme` | `workspace:manage` | Reads or privately changes a theme without changing system themes |
| `PUT .../{slug}/theme/selection` | `workspace:manage` | Selects inheritance or a system theme only for the Workspace |
| `POST .../{slug}/theme/import` | `workspace:manage` | Imports a ZIP as the Workspace private theme |
| `GET .../{slug}/theme/export` | `workspace:manage` | Administrator-only private-theme export |
| `POST/DELETE .../{slug}/theme/assets` | `workspace:manage` | Adds or deletes private-theme files |
| `PUT /api/v1/workspaces/{slug}/tree/order` | `workspace:manage` | Atomically validates the complete arrangement |
| `GET/PUT /api/v1/workspaces/{slug}/acl` | `workspace:manage` | Reads or replaces the complete Workspace ACL |
| `GET /api/v1/workspaces/{slug}/acl/subjects` | `workspace:manage` | Bounded `category=user\|group&q=...` search |
| `POST/PATCH/DELETE .../nodes` | `workspace:manage` | Manages internal/external link nodes only |
| `GET/PUT .../nodes/{nodeId}/acl` | `workspace:manage` | Reads or replaces direct inherited restrictions |
| `GET /api/v1/workspaces/deleted` | `workspace:manage` | Administrator only |
| `POST /api/v1/workspaces/deleted/{id}/restore` | `workspace:manage` | Administrator only, resolves slug conflicts |

The key scope is the first gate, never the final authorization decision. The
service then evaluates the same owner, administrator, Workspace ACL, group
membership, archive, and inherited node restrictions used by the web UI.
Missing view permission is concealed as `404`; a visible resource without
management permission returns `403`.

Workspace DTOs include `tree_visibility` and `contents_visibility`; node DTOs
include `contents_visibility`, `labels`, and `properties`. Node `PATCH` accepts
a JSON `labels` array and a `properties` array whose objects contain `key`,
`label`, `type`, `value`, and `sort_order`, from Workspace managers. Supported
property types are `text`, `status`, and `link`. Accepted display values are
`inherit`, `shown`, and `hidden`. HTML export accepts an optional JSON `node_ids` list. An empty list
exports the complete visible Workspace; selected IDs export only those pages
and the tree necessary to reach them.

The ACL-subject search returns only the safe picker DTO: `id`, `label`, `type`,
`category`, `is_builtin`, and `is_read_only`. Internal Auth fields, including
password hashes and login metadata, never cross the repository boundary.

Workspace themes remain isolated: `PATCH .../theme` always creates or updates
the private database/filesystem copy. Workspace API never writes a system JSON
theme. Import uses the `theme` multipart field; image upload uses `asset` with
`role=hero|icon|logo|other`.

Example read with a `workspace:read` key:

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/workspaces"
```

The JSON envelope contains only visible Workspace DTOs in `data`, a
`meta.request_id`, and navigation `links`. The API module's bilingual quick
start contains the equivalent plain-PHP client and expected problem response.

Beginners should think of `workspace:manage` as “the client may ask to manage a
Workspace.” The user who owns that key must still be allowed to perform the
specific operation.

Document pages and attachments are intentionally absent from this contract.
The HTML Editor API will own `page:*` and `attachment:*`, while Workspace
integration will add its effective page ACL when both modules are installed.

## 11. Developer checks

```bash
composer on-commit
```

The command runs PHPCS, Rector dry-run, PHPStan for source and tests, and
PHPUnit. Every method is documented in Croatian and English. Views use
escaped output, forms use the framework CSRF field, and controllers validate
effective Workspace permissions before write operations.

## 12. Backup and restore

Selective workspace archives, manager authorization, conflict modes, and
cross-module references are documented in [Workspace backup and restore](backup_en.md).

## 13. Storage maintenance

The administrator-only **Settings → Workspaces → Maintenance** page reports the
estimated database payload and actual managed-file size for the complete site
and for every active Workspace. Historical versions and deleted pages/assets
are reported separately so administrators can identify storage growth before
removing anything.

Cleanup may target the complete site or one Workspace. History may be:

- left unchanged;
- reduced by deleting all old versions;
- limited to the newest 3, 5, or 10 versions per document and language;
- pruned when older than 10, 30, or 90 days.

Deleted pages and assets may be permanently purged after 10, 30, or 90 days.
Until that purge, deletion is soft and an administrator can still restore the
content. Permanent cleanup cannot be undone, so create and verify a backup
first.

The service always protects the current version, the newest version of every
language, and versions referenced as current or published by the Workspace
tree. An asset remains on disk while any retained version references it.
Database changes run in a transaction; files are removed only after that
transaction succeeds.

Database size is an estimate of useful row payload, not the size of the entire
database file. Database engines normally reuse freed pages, so the physical
file may not shrink immediately. `VACUUM`, `OPTIMIZE`, and equivalent
administrator operations differ across SQLite, PostgreSQL, and MySQL and are
therefore not run automatically by the portable Workspace module.

The same page provides **Optimize existing images**. The HTML Editor creates
missing, reduced WebP display copies for the web without changing the source
files; opening the displayed image still reaches its original. The same process
runs automatically when a document is published and after a Confluence import.
The manual action covers content that predates image optimization or whose
derived display copy has been removed.
Processing runs in small batches with a visible progress bar. Persistent state
preserves completed work if the page is closed, and reopening Maintenance
automatically resumes an unfinished job. Locking prevents two open windows from
processing the same batch concurrently.

An administrator can restore or permanently delete a soft-deleted Workspace
from **Deleted Workspaces** and **Maintenance**. Permanent deletion requires
re-entering the exact Workspace slug. Workspace tree rows, ACL, workflows, the
private theme and its files, special menus, Editor documents, history and
attachments, and related data owned by installed modules are removed. The
derived search index is removed automatically. Standalone calendars remain
preserved because the same calendar may be embedded in multiple Workspaces.

Before one or more pages are permanently removed, Workspace emits the public
`WorkspacePagesPermanentlyDeleting` event with node IDs and document keys. This
allows optional modules to remove only their own references to those pages.
Soft deletion does not emit this event because the content can still be restored.

## Personal following event

The repository emits `WorkspaceContentChanged` with a non-sensitive reason and
actor identifier. Simbioza User may consume it and render its own page/workspace
controls. Disabling that optional module does not change Workspace routes,
persistence, or ACL behavior.

## 14. Labels, properties, and dynamic content

A page may have multiple labels plus structured `key`, `label`, `type`,
`value`, and `sort order` properties. The `text`, `status`, and `link` types
remain structured across persistence, API output, and backup. Status values use
a theme-aware badge in the view, while link values are active only when they
contain an allowed URL. Imported Confluence Page Properties populate the same
native properties rather than importer-specific fields.

On a Workspace page the HTML Editor can insert four native blocks:

- **Pages and properties table** filters published ACL-visible pages by label, shows selected
  properties, and can sort by title, modification time, or a property value;
- **Attachment gallery** shows images attached to the current page;
- **Workspace search** submits directly to a result page fixed to the current
  Workspace and returns only pages that the visitor is allowed to open;
- **Recent changes** lists published changes from the current Workspace with
  author and localized time.

The pages and properties result uses the same bordered, striped, hoverable,
responsive table markup as the HTML Editor. It therefore inherits the active
theme's header, row, alternate-row, border, and text colors; without Theme it
uses the readable Bootstrap fallback.

The blocks are declarative HTML records, but their result is rebuilt on every
view under the current Workspace and page ACL. The web API returns the same
rendered HTML, while HTML export pre-renders a read-only result. Backup keeps
the block configuration inside the document version and stores labels and
properties separately, so restore preserves the dynamic behavior.

A page without a native dynamic block does not execute any dynamic-block
queries. A label-filtered report batch-checks ACL and workflow only for pages
carrying that label, and overlapping blocks share already checked results for
the current HTTP request. An unfiltered report and the recent-changes block
intentionally process the whole Workspace because their result semantically
covers all of its pages.

## 15. Multilingual Workspace and page names

When the site supports multiple languages, the **Workspace name and
description** and every **page title** are edited through the language selector
attached to the field. The value in the site's primary language is required.
Translations in other languages are optional; when one is missing, the primary
language value is shown. A slug remains one shared, stable, language-independent
value, so switching language never changes existing URLs.

The same multilingual page title is used by the new-page form, tree-item
settings, HTML Editor, page tree, breadcrumbs, lists, and search results. Menu
and content-target selectors show the active-language name with the same
primary-language fallback.

The API and notifications expose the localized value for the request's active
language. Backup and restore retain the complete Workspace and page translation
maps. Portable HTML export renders the selected language, while Confluence
import stores the Workspace name, description, and page titles under the
language selected during preflight. Re-import still matches content by stable
slug rather than by a translated title.
