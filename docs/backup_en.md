# Workspace backup and restore

Workspace owns three providers: complete site/component tables, private workspace-theme files, and the selective `workspace-scope` provider. A selective archive contains the workspace record, tree, portable page labels and structured properties, node ACL and workflow state, homepage/view settings, private theme, and portable references needed by editor, calendar, task, comment, menu, and search integrations.

## Authorization

A workspace manager may export the workspace and may restore it back into the workspace allowed by their `manage` permission. Only an application administrator may import an archive as a new workspace, restore site/component scope, or use destructive conflict handling. Server-side checks are applied independently of button visibility.

## Conflict handling

- `merge` updates matching portable identities and preserves unrelated target data;
- `copy` creates a new workspace identity/slug and rewrites scoped references;
- `replace` is administrator-only and creates a safety snapshot first.

Shared calendars are referenced and reused when they already exist; document content, versions, ACL, dynamic included-page references, comments, tasks, scoped menus, and private theme files are carried by their owning providers. Copy restore rewrites include-reference keys and UUIDs inside copied versions, while a target outside the Workspace stays connected to an existing document with the same key. The search index is never copied and is rebuilt after a successful transaction.

Use preflight before restore. A missing required provider, checksum mismatch, unknown module/schema version, ACL problem, or unresolved identity blocks the operation before target content is changed.
