# Workspace configuration

[Croatian version](configuration_hr.md)

Active global settings are JSON data in `HPH_CONFIG_PATH/workspace/workspace.json`.
The unchanged framework resolver also reads `HPH_CONFIG_OVERRIDE_PATHS` in
declared order. Its default base is the host application's `config/` directory;
the directory name itself is not a requirement. Package `config/workspace.php`
contains defaults only and is never edited by the administration GUI.

Use `config/workspace.json.dist` as a starting point, not as a replacement for
an existing installation's private settings. Create the declared configuration
directories first and provision read/search access. Normal reads do not create
files or locks. Missing JSON uses defaults; unreadable or invalid JSON fails
explicitly and never silently loads legacy PHP/database settings.

The sample's bilingual `_documentation` object describes its options and safe
deployment. It is inert metadata, not a runtime option, and can be removed.
The sample is never automatically copied to a private installation.

## Options

| Section | Meaning |
| --- | --- |
| `routing.root_path` | One lower-case URL segment, letters/digits separated by hyphens |
| `defaults` | New Workspace visibility (`restricted`, `authenticated`, `public`), tree and outline defaults |
| `creation.users`, `creation.groups` | Lists of positive Auth IDs allowed to create Workspaces; administrators remain allowed |
| `shorts` | `depth`: 1, 2, 3, or `"all"`; `limit`: 5, 10, 25, 50; order: `hierarchy`, `newest`, `oldest`, `title_asc`, `title_desc`; display-options visibility |
| `backlinks.refresh_seconds` | Derived-index safety refresh, integer 60–86400 |
| `menu` | Optional top/settings-menu registration |
| `homepage` | Public/authenticated targets and personal-selection permission |

Known values require their documented JSON types: use booleans, not `"true"`,
and integer IDs, not strings. Unknown extension options survive GUI updates.
Associative objects merge across layers; lists replace lower lists completely,
including an empty creator list. The GUI/API write only the final declared
layer, reread current values under `workspace.json.lock`, and replace JSON
atomically. Existing mode/owner/group are retained; new directories/files use
`0770`/`0660`. Declared root symlinks are allowed, but module-directory/file/lock
symlinks are rejected. Read-only configuration still permits normal operation;
saving fails clearly instead of writing to a weaker layer.

The existing administration routes, menus, and GUI behavior remain unchanged.
The general-settings form and homepage form edit separate portions of the
same JSON, preserving each other's latest values and manually configured options.

## Homepages and data

Each target contains `type`, `node_id`, `workspace_id`, `show_tree`, and
`show_display_options`. Use `type="default"` with both IDs zero for fallback;
`type="page"` with a positive node ID and workspace ID zero; or `type="shorts"`
with a positive workspace ID and node ID zero. Do not configure arbitrary URLs.
The resolver still checks publication and current ACL on every request.

Global policy lives only in JSON. Personal homepage choices stay in
`workspace_user_homepages`. Workspaces, page trees, ACL, workflow, metadata,
private Workspace themes and theme assets remain domain data in their existing
database/data locations. `HPH_CONFIG_PATH` does not relocate those files.

## HTML Editor integration

Workspace never writes forced values to HTML Editor's configuration. The Editor
remains independently usable without Workspace. When Workspace is installed and
enabled, the existing runtime integration uses Workspace/page outline policy,
canonical Workspace routes, publication and ACL rules, and owned page creation.
Standalone Editor settings stay intact and become effective again if Workspace
is disabled. Do not implement this integration by copying values between JSON
files or making Editor depend on Workspace to read its own configuration.

## Initial schema and backup

The initial migration creates the complete current domain schema. Global
homepage policy exists only in JSON; personal selections remain database data.
No old configuration export or startup schema conversion is distributed.

Current backup format 2 uses the `workspace-configuration` JSON provider and
the `workspace` database provider with exact schema 3. Old Simbioza formats and
the removed global-homepage dataset are not accepted. Configuration restore shares
the GUI lock, validates before publication and remaps imported creator/homepage
identities after database import. Destination-local IDs not supplied by the
archive remain unchanged. A later failure rolls back JSON and database changes.

The archive's source public address and imported route/identity maps drive
cross-site page and attachment link relocation. Unresolved internal references
are reported, not guessed. Credentials and destination storage paths are local.
Workspace remains usable without the optional Backup module.
