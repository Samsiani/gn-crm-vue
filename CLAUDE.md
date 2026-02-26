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
1. Build: `npm run build` in the dev project
2. Sync: `rsync -av --delete /path/to/gn-crm-vue/wp-content/plugins/gn-crm-vue/ /private/tmp/gn-plugin-push/ --exclude='.git' --exclude='vendor' --exclude='*.log'`
3. Restore push-repo files: `git checkout HEAD -- .github/workflows/release.yml CLAUDE.md`
4. Commit and push from `/private/tmp/gn-plugin-push/`

## Source dev repo
The dev project at `/Users/george/Documents/gn-implement/gn-crm-vue` has NO remote
pointing to `github.com/Samsiani/gn-crm-vue`. It must never push there.
