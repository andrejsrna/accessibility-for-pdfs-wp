# Accessibility for PDFs — WordPress Plugin

A WordPress plugin that automatically improves the accessibility of PDF files in your Media Library. Designed to help organisations comply with accessibility standards (WCAG 2.1, PDF/UA) without manual effort.

## What it does

When you upload a PDF, the plugin runs a background job that applies all of the following fixes automatically:

| Check | What gets fixed |
|---|---|
| **OCR** | Scanned PDFs (image-only) are processed with Tesseract OCR so the text becomes selectable and readable by screen readers |
| **Bookmarks / Outline** | If the PDF has no navigation bookmarks, the plugin detects headings by font size and generates a bookmark tree |
| **Document title** | Sets the PDF's internal title metadata from the WordPress attachment title |
| **Author & Subject** | Sets author (site name) and subject (attachment description) in PDF metadata |
| **Document language** | Sets the PDF language attribute (`sk`, `en`, etc.) so screen readers use the correct pronunciation |
| **Font embedding** | Re-processes the PDF through Ghostscript to embed all fonts for consistent rendering |

### Media Library integration

Every PDF attachment shows its accessibility status directly in the Media Library detail panel — the same panel where you copy URLs or add captions. A single **Fix now** button triggers processing without leaving the media screen.

### Dedicated admin page

**Media → PDF Accessibility** lists all PDFs with status badges for each check. Supports bulk selection and processing, with per-row Check / Fix actions and a language selector for OCR.

### Automatic processing on upload

New PDFs are queued for background processing immediately after upload (via WP-Cron). The upload completes instantly — processing happens a few seconds later without blocking the admin UI.

---

## Requirements

### Server / hosting

This plugin calls system tools via PHP's `shell_exec()`. It **will not work** on shared hosting that disables `shell_exec`. It is designed for **self-hosted WordPress** running on a VPS, a dedicated server, or inside a Docker container.

### System packages

```bash
# Debian / Ubuntu
apt-get install -y \
  python3 python3-pip \
  tesseract-ocr \
  tesseract-ocr-eng \
  ghostscript \
  unpaper \
  pngquant
```

For additional OCR languages install the matching Tesseract language pack:

```bash
apt-get install -y tesseract-ocr-slk   # Slovak
apt-get install -y tesseract-ocr-deu   # German
apt-get install -y tesseract-ocr-fra   # French
# Full list: apt-cache search tesseract-ocr-
```

### Python packages

```bash
pip3 install ocrmypdf pymupdf
```

On Debian Bookworm (Python 3.11+) you may need:

```bash
pip3 install --break-system-packages ocrmypdf pymupdf
```

### PHP

- PHP 8.0 or higher
- `shell_exec()` must be enabled (not in the `disable_functions` list in `php.ini`)

---

## Installation

### Docker-based WordPress (recommended)

Add the following `RUN` layer to your `Dockerfile`:

```dockerfile
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    python3 \
    python3-pip \
    tesseract-ocr \
    tesseract-ocr-eng \
    ghostscript \
    unpaper \
    pngquant \
  && pip3 install --no-cache-dir --break-system-packages \
    ocrmypdf \
    pymupdf \
  && rm -rf /var/lib/apt/lists/*
```

Then copy the plugin into your image or mount it as a volume.

### Manual installation

1. Install all system and Python dependencies listed above.
2. Download or clone this repository into `wp-content/plugins/accessibility-for-pdfs/`.
3. In the WordPress admin go to **Plugins → Installed Plugins** and activate **Accessibility for PDFs**.

---

## Usage

### Automatic (recommended)

Just upload PDFs normally. The plugin detects each new PDF upload and schedules a background processing job. Within ~30 seconds the accessibility fixes are applied and the status is visible in the Media Library.

### Manual — per file

1. Go to **Media Library** and click any PDF.
2. In the detail panel on the right, find the **PDF Accessibility** section.
3. Check the current status badges and click **Fix now** if needed.

### Manual — bulk

1. Go to **Media → PDF Accessibility**.
2. Select one or more PDFs using the checkboxes.
3. Choose the OCR language from the dropdown (default: Slovak + English).
4. Click **Fix selected** or use the per-row **Fix** button.

---

## Configuration

### OCR language

The default OCR language is `slk+eng` (Slovak + English). You can change it per-run in the bulk admin page. To change the default, edit line 42 in `accessibility-for-pdfs.php`:

```php
'lang' => 'slk+eng',  // change to e.g. 'eng', 'deu+eng', 'fra'
```

Tesseract language codes follow the ISO 639-2/T standard (`eng`, `deu`, `fra`, `ces`, `slk`, etc.).

---

## How it works

The PHP plugin calls a Python script (`bin/process_pdf.py`) via `shell_exec()`. The script:

1. **Checks** the PDF using PyMuPDF — detects presence of text, bookmarks, metadata, image alt texts, and font embedding.
2. **Runs OCR** via `ocrmypdf` if no selectable text is found. Pages that already have text are skipped automatically.
3. **Embeds fonts** by re-distilling through Ghostscript (`gs -dEmbedAllFonts=true`).
4. **Sets metadata** (title, author, subject, language) using PyMuPDF.
5. **Generates bookmarks** by analysing text spans — spans significantly larger than the median body text size are treated as headings and added to the PDF outline.

All operations modify the original file in-place (using a temp file + atomic replace).

---

## Limitations

- **Structural tags (PDF/UA)** — full H1/H2/paragraph/table/figure tagging as required by PDF/UA is not implemented. Achieving this programmatically with open-source tools is not reliably possible. Use Adobe Acrobat Pro or a dedicated PDF remediation service for full PDF/UA compliance.
- **Image alt texts** — the plugin detects images and shows a counter of missing alt texts. Writing meaningful alt text descriptions requires human judgement. The Media Library panel provides a UI for entering alt texts manually.
- **Large files** — OCR can take up to 10 minutes for large scanned documents. The WP-Cron background processing handles this without blocking the UI, but the PHP `max_execution_time` in your server config must be high enough (300s+ recommended).
- **Shared hosting** — `shell_exec()` is typically disabled on shared hosting. This plugin requires a VPS, dedicated server, or Docker-based environment.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) file.

---

## Credits

Built with:
- [ocrmypdf](https://github.com/ocrmypdf/OCRmyPDF) — OCR pipeline
- [PyMuPDF](https://github.com/pymupdf/PyMuPDF) — PDF analysis and manipulation
- [Tesseract OCR](https://github.com/tesseract-ocr/tesseract) — optical character recognition
- [Ghostscript](https://www.ghostscript.com/) — font embedding
