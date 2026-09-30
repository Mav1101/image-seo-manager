# Image SEO Manager

A single-file WordPress plugin to export and bulk-update image alt text and titles in the Media Library.

## Features

**Export tab**
- Lists all images: thumbnail, file name (from `_wp_attached_file`), alt text, title, attachment ID, upload date.
- 50 per page, with search (file name, alt text, title) and a "Missing alt text only" filter.
- **Export to CSV** downloads *all* images as `image-seo-export-YYYY-MM-DD.csv` (UTF-8 with BOM, columns: File Name, Alt Text, Image Title, Attachment ID). Streamed through `admin-post.php` with a nonce check; nothing is saved on the server.

**Bulk Update tab**
- Upload a `.csv`/`.txt` file or paste tab-separated rows from Google Sheets/Excel.
- Columns are detected by header name (case-insensitive, any order): `File Name`, `Alt Text`, `Image Title`. Other columns are ignored.
- Rows are matched by file name: folders ignored, case-insensitive, extension optional, and WordPress suffixes (`-scaled`, `-1`, `-300x200`, ...) are tolerated.
- Empty cells never blank out existing values.
- Options: "Overwrite existing values", and "Update all" / "Skip and flag" when a name matches several images.
- **Preview** is a dry run with a colour-coded status table and summary counts. **Apply Changes** (with a confirm dialog) updates in batches of 100, then offers a results log CSV.

## Installation

1. Download this repository as a zip, or zip the `image-seo-manager` folder so it contains `image-seo-manager.php`.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. Open **Media → Image SEO Manager**.

Requires WordPress 5.0+ and PHP 7.2+. Users need the `upload_files` capability.

## Notes

- WordPress usually sets an image's title to its file name on upload, so with "Overwrite existing values" unchecked most titles will be left unchanged.
- Back up your site (or export a CSV) before applying bulk changes; updates are not automatically reversible.
- Preview jobs expire after two hours.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
