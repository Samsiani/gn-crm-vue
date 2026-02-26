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
1. Bump version + build: `npm run release` in the dev project (bumps patch in `cig-headless.php` + runs `npm run build`)
2. Sync: `rsync -av --delete /Users/george/Documents/gn-implement/gn-crm-vue/wp-content/plugins/gn-crm-vue/ /private/tmp/gn-plugin-push/ --exclude='.git' --exclude='vendor' --exclude='*.log'`
3. Restore push-repo files: `git checkout HEAD -- .github/workflows/release.yml CLAUDE.md .gitignore`
4. Commit and push from `/private/tmp/gn-plugin-push/`
5. Tag and push: `git tag vX.X.X && git push origin vX.X.X`
6. GitHub Action automatically creates the release, generates release notes, and attaches the ZIP — **no manual `gh release create` needed**.

## ZIP naming
The GitHub Action creates `gn-crm-vue-vX.X.X.zip` with root folder `gn-crm-vue/`.
The update checker slug is `gn-crm-vue` and `enableReleaseAssets()` accepts any ZIP asset.

## Source dev repo
The dev project at `/Users/george/Documents/gn-implement/gn-crm-vue` has NO remote
pointing to `github.com/Samsiani/gn-crm-vue`. It must never push there.
