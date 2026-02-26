# gn-crm-vue Plugin Repository — Rules for Claude

## ⚠️ THIS REPO CONTAINS ONLY THE INSTALLABLE WORDPRESS PLUGIN

This GitHub repository (`Samsiani/gn-crm-vue`) is the **WordPress plugin release repo**.
It must contain **only** the files inside `wp-content/plugins/gn-crm-vue/` from the dev project.

## What belongs here
- `cig-headless.php` — plugin entry point
- `api/`, `models/`, `middleware/`, `includes/`, `migration/`, `cli/` — PHP source
- `dist/` — compiled Vue app (Vite build output)
- `composer.json`, `composer.lock`
- This `CLAUDE.md`

## What NEVER belongs here
- `src/` — Vue source code
- `package.json`, `package-lock.json`, `vite.config.js`, `index.html`
- `wp-content/` wrapper directory
- `.env`, `CLAUDE.md` from the dev project
- Anything from the dev repo root that is not the plugin itself

## How to push a release
1. Bump version: `bash bump-version.sh patch` (or minor/major) in the dev project
2. Build: `npm run build` in the dev project  (or `npm run release` does both steps 1+2)
3. Sync: `rsync -av --delete /Users/george/Documents/gn-implement/gn-crm-vue/wp-content/plugins/gn-crm-vue/ /private/tmp/gn-plugin-push/ --exclude='.git' --exclude='vendor' --exclude='*.log'`
4. Restore push-repo files: `git checkout HEAD -- .github/workflows/release.yml CLAUDE.md`
5. Commit and push from `/private/tmp/gn-plugin-push/`
6. Tag the release: `git tag vX.X.X && git push origin vX.X.X`
7. Create GitHub Release: `gh release create vX.X.X --title "Release vX.X.X" --notes "..." --latest`
8. GitHub Action automatically builds the ZIP and attaches it to the release.

## ZIP naming
The GitHub Action creates `cig-headless-vX.X.X.zip` with root folder `cig-headless/`.
The update checker slug is `cig-headless` and looks for assets matching `/^cig-headless.*\.zip$/`.

## Source dev repo
The dev project at `/Users/george/Documents/gn-implement/gn-crm-vue` has NO remote
pointing to `github.com/Samsiani/gn-crm-vue`. It must never push there.
