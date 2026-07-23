# WordPress plugin update rename failure

## Symptom

WordPress reports:

> Update failed: Unable to rename the update to match the existing directory.

The error code is `puc-rename-failed` and comes from
`yahnis-elsts/plugin-update-checker`, not from the MC Admissions application.

## Cause

The updater requires the extracted update directory to match the directory of
the installed plugin. For this plugin the canonical directory is:

`mc-admissions-wordpress-backend/`

The release ZIP must therefore contain exactly one top-level directory with
that name. Its main PHP file must be:

`mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php`

The plugin has its own `upgrader_source_selection` callback that repairs
incorrect or flattened package paths. This callback must run before Plugin
Update Checker's callback. Register it at priority `5`; PUC uses the default
priority and can otherwise return `puc-rename-failed` before the repair runs.

## Permanent fix

1. Keep the MC Admissions path-normalization filter registered at priority `5`.
2. Build the release archive from the plugin repository with the canonical
   top-level directory.
3. Name the GitHub release asset exactly:
   `mc-admissions-wordpress-backend.zip`
4. Ensure the plugin header version matches the Git tag and GitHub release.
5. Verify the ZIP before publishing:
   - one top-level `mc-admissions-wordpress-backend/` directory;
   - main PHP file present directly inside it;
   - `vendor/` and Composer files included;
   - no additional wrapper directory such as a GitHub repository/hash name.

Example release archive command:

```bash
git archive \
  --format=zip \
  --prefix=mc-admissions-wordpress-backend/ \
  -o mc-admissions-wordpress-backend.zip \
  vX.Y.Z
```

## Recovery when an older installed version cannot update itself

The fix runs from the currently installed plugin. If that installed version
predates the priority fix, perform one manual replacement:

1. Download the exact `mc-admissions-wordpress-backend.zip` release asset.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP.
4. Confirm **Replace current with uploaded**.
5. Confirm that the plugin remains active and shows the new version.

Future one-click updates should then use the repaired updater flow.

Do not upload a GitHub-generated source-code ZIP. Those archives have a
repository/hash wrapper directory and are not the updater release asset.
