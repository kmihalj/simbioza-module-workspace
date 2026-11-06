# Page access exceptions

Open **Manage page and permissions → Direct permissions for users and groups**. User and group pickers search on the server and return bounded pages; clicking a group field also offers the first page, including Public and All signed-in users. Existing assignments are excluded from suggestions.

An explicit grant applies only to the selected node. It can supply reading, editing or publication to a user or enabled group, under the existing page-permission controls. Public grants reading only; forged edit/publication flags are ignored. These grants never grant Workspace management, access to child pages, or permission to unrelated HTML or Survey content. Inherited page restrictions retain their separate narrowing role.

A private Workspace appears in the directory when the current visitor has base access or at least one readable directly granted page/survey. The tree promotes a directly granted page whose parent is inaccessible without exposing ancestor names. Removing the final exception or group membership hides the Workspace again. Draft/archived Survey publications do not create public directory entries.

The additive `resources/migrations/workspace_subject_permissions.php` migration preserves existing user grants. The current initial schema includes it. Existing Simbioza installations use the coordinated application updater. Other hosts should review the helper and call it from a new, ordered application migration for their actual schema, after a verified restorable backup. Group/audience grants are included in page/Workspace permission transfer and database backup definitions.
