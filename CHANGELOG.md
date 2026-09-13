# Changelog

## 0.1.30 - 2026-09-13

- Keeps quality checks consistent across current Rector releases without rewriting existing path expressions.

## 0.1.29 - 2026-09-13

- Adds page copying and moving between Workspaces with source/target management ACL checks.
- Adds PAGE backup metadata and optional permissions/history alongside Editor content, translations, and attachments.
- Places backup/import, transfer, and deletion actions on the page view rather than inside the editor.
- Preserves the target page URL, tree position, and existing ACL when replacing without ACL import.
- Keeps a latest published version published without history, without publishing a newer draft.
