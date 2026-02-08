# Resource Manager (Blueprint Addon)

Admin-only upload manager for images/assets you want to reuse across Blueprint addons/themes in the Pterodactyl panel.


## What This Addon Does
- Adds an admin extension page for uploading, listing, copying links, and deleting images.
- Stores files under `/extensions/resourcemanager/uploads/` so other addons can reference them by URL.
- Ships with a starter set of images in `public/uploads/` (optional convenience assets).

## Security Notes
- Uploaded files are stored in a public directory and can be accessed by URL.
- Only root admins can upload/delete via the UI, but you should still only upload trusted files.
- SVG is intentionally not accepted by default (it can contain scripts). See `admin/Controller.php` if you want to enable it.

## Compatibility
- Blueprint Framework on Pterodactyl Panel
- Target: `beta-2026-01` (see `conf.yml`)

## Installation / Development Guides
Follow the official Blueprint guides for installing addons and developing extensions:
`https://blueprint.zip/guides`

Uninstall (as shown in the admin view):
`blueprint -remove resourcemanager`

## How It Works (Repo Layout)
- `conf.yml`: Blueprint addon manifest (metadata, target version, entrypoints).
- `routes/web.php`: Backend endpoints for uploading/listing/deleting files.
- `admin/Controller.php`: Admin-only upload and file management endpoints.
- `admin/view.blade.php`: Admin UI for managing uploads.
- `public/uploads/`: Bundled starter assets (optional).

## Contributing
This repo is shared so the community can help improve and extend the addon, not because it's abandoned.
If you customize it for your theme/workflow, consider upstreaming improvements that benefit others.

### Pull Request Requirements
- Clearly state what's been added/updated and why.
- Include images or a short video of it working/in action (especially for UI changes).
- Keep changes focused and avoid unrelated formatting-only churn.
- Keep credits/attribution intact (see `LICENSE`).

## License
Source-available. Redistribution and resale (original or modified) are not permitted, and original credits must be kept within the addon.
See `LICENSE` for the full terms.