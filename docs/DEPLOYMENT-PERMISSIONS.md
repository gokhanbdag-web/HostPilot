# Deployment permissions

The live deployment is performed by the server-side deployment hook and
`/opt/hostpilot-deploy/deploy.sh`.

The deployment script must use the dedicated site account
`kodva7153:kodva7153`; it must not rely on the interactive shell's `USER`
environment variable because the hook runs non-interactively with
`set -u` enabled.

After syncing application code, the script restores these permissions:

- Application directories: `755`
- Application files: `644`
- `storage/` and its subdirectories: `750`
- Files inside `storage/`, including `config.php` and `installed.lock`: `640`

The `storage/` directory is excluded from the code sync and must retain
installation-specific configuration and lock files. Never rerun the installer
or remove these files as part of a normal deployment.

A successful deployment is not considered verified until the server log,
live HTTP responses, and the resulting ownership/permissions have been checked.
