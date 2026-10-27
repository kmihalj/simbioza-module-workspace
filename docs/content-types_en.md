# Optional content types

`WorkspaceContentProviderInterface` and `WorkspaceContentRegistry` are generic extension points. Workspace has no required Survey dependency and does not store questionnaire definitions as HTML documents.

A provider registers a unique non-core `node_type`, batches its own metadata, adds type-specific base capabilities **before** Workspace restrictions, checks publication after ACL, renders one authorized module partial and optionally supplies a creation action and manager-only membership section. Normal document/link/container behaviour is unchanged when the registry is empty. Unknown types are stored but never silently treated as HTML documents.

A creation action may supply an `icon` name alongside `label` and `href`. The toolbar renders only a built-in allowed icon, never arbitrary provider HTML/SVG; a missing name uses the generic creation icon. The button title and accessible label retain the full action text. `partials/subject-picker` is shared by content and extension ACL user/group controls: it retains the same debounced searches, 25-result pages and existing authorized endpoint. Both users and groups open the initial page on click/focus without typing; scrolling and Load more support browsing, while searching narrows results. Extensions may exclude built-in audiences without changing Content permissions.

The dependency-free registry is auto-wired, deliberately not replaced by a deferred module service definition. Thus an optional module may register before or after Workspace bootstrap. Factories stay lazy and absent modules are never auto-enabled. Providers must check installed/enabled prerequisites and schema readiness before registration.

`renderContent()` reuses the existing visible tree, breadcrumbs, theme bridge, page-permissions dialog and show/hide controls. A provider does not supply a parallel sidebar or independent Workspace ACL. Survey-only management augments editing of Survey nodes, not host `can_manage`, ordinary pages or unrelated assets. Existing explicit page-user grants and ancestor restrictions still apply. Provider publication checks also apply to lookup and follow-target visibility. Menu destinations use ordinary host page routes and do not auto-create menu entries.

Content mutations belong to the owning module, not the generic Workspace node-edit form/API. Tree organization and page permissions remain host operations. Extension nodes are not parent containers. Disabling a provider hides its nodes without deleting their data.

`WorkspaceContentBackupInterface` declares each extension's encrypted backup owner. Required owners must be included; missing or unsupported owners fail export/preflight instead of silently losing content. Survey backups include definitions, responses, settings and attachments. Static HTML export omits survey nodes rather than attempting to recreate online questionnaires. Ordinary page backup and HTML export retain their existing behaviour.
