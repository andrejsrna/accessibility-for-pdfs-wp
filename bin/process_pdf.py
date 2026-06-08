#!/usr/bin/env python3
"""PDF accessibility processor — Accessibility for PDFs (WordPress plugin)."""

import sys
import json
import subprocess
import argparse
import tempfile
import os
import shutil

try:
    import fitz  # PyMuPDF
except ImportError:
    print(json.dumps({"error": "PyMuPDF not installed. Run: pip3 install pymupdf"}))
    sys.exit(1)


def check_pdf(path: str) -> dict:
    """Analyze accessibility status of a PDF."""
    result = {
        "has_text": False,
        "has_bookmarks": False,
        "bookmarks_count": 0,
        "meta_title": "",
        "meta_author": "",
        "meta_subject": "",
        "meta_lang": "",
        "has_images": False,
        "images_with_alt": 0,
        "images_without_alt": 0,
        "fonts_embedded": True,
        "pages": 0,
    }
    try:
        doc = fitz.open(path)
        result["pages"] = doc.page_count

        for page in doc:
            if len(page.get_text().strip()) > 20:
                result["has_text"] = True
                break

        toc = doc.get_toc()
        result["has_bookmarks"] = len(toc) > 0
        result["bookmarks_count"] = len(toc)

        meta = doc.metadata or {}
        result["meta_title"] = meta.get("title", "")
        result["meta_author"] = meta.get("author", "")
        result["meta_subject"] = meta.get("subject", "")
        result["meta_lang"] = doc.language or ""

        # Check images and alt texts
        for page in doc:
            image_list = page.get_images(full=True)
            for img_ref in image_list:
                result["has_images"] = True
                xref = img_ref[0]
                # Check if image is inside a figure tag with Alt
                has_alt = _image_has_alt(doc, page, xref)
                if has_alt:
                    result["images_with_alt"] += 1
                else:
                    result["images_without_alt"] += 1

        # Check font embedding
        for page in doc:
            for font in page.get_fonts():
                # font tuple: (xref, ext, type, basefont, name, encoding, referencer)
                font_type = font[2]
                # Type3 and unembedded fonts are a concern
                if font_type in ("Type1", "TrueType", "Type0") and not font[1]:
                    result["fonts_embedded"] = False
                    break

        doc.close()
    except Exception as e:
        result["error"] = str(e)
    return result


def _image_has_alt(doc: fitz.Document, page: fitz.Page, xref: int) -> bool:
    """Check if an image xref has an Alt attribute in the PDF structure tree."""
    try:
        # Try to find Alt text in the page's marked content
        struct = page.get_text("rawdict", flags=fitz.TEXT_PRESERVE_IMAGES)
        for block in struct.get("blocks", []):
            if block.get("type") == 1:  # image block
                if block.get("xref") == xref and block.get("alt"):
                    return True
    except Exception:
        pass
    return False


def run_ocr(input_path: str, output_path: str, lang: str = "slk+eng") -> dict:
    """OCR a PDF with ocrmypdf. Skips pages that already have text."""
    cmd = [
        "ocrmypdf",
        "--language", lang,
        "--skip-text",
        "--rotate-pages",
        "--deskew",
        "--output-type", "pdf",
        "--jobs", "2",
        input_path,
        output_path,
    ]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        return {
            "success": result.returncode == 0,
            "stdout": result.stdout[-800:] if result.stdout else "",
            "stderr": result.stderr[-800:] if result.stderr else "",
            "returncode": result.returncode,
        }
    except subprocess.TimeoutExpired:
        return {"success": False, "stderr": "OCR timeout after 600s"}
    except FileNotFoundError:
        return {"success": False, "stderr": "ocrmypdf not found"}


def embed_fonts(input_path: str, output_path: str) -> dict:
    """Re-distill PDF through Ghostscript to embed all fonts."""
    cmd = [
        "gs",
        "-dNOPAUSE", "-dBATCH", "-dQUIET",
        "-sDEVICE=pdfwrite",
        "-dCompatibilityLevel=1.7",
        "-dEmbedAllFonts=true",
        "-dSubsetFonts=true",
        f"-sOutputFile={output_path}",
        input_path,
    ]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
        return {
            "success": result.returncode == 0,
            "stderr": result.stderr[-500:] if result.stderr else "",
        }
    except subprocess.TimeoutExpired:
        return {"success": False, "stderr": "gs timeout"}
    except FileNotFoundError:
        return {"success": False, "stderr": "ghostscript not found"}


def set_metadata(path: str, title: str = "", author: str = "", subject: str = "", lang: str = "sk") -> None:
    """Set PDF metadata (title, author, subject, language) in-place."""
    doc = fitz.open(path)
    meta = doc.metadata.copy() if doc.metadata else {}
    if title:
        meta["title"] = title
    if author:
        meta["author"] = author
    if subject:
        meta["subject"] = subject
    doc.set_metadata(meta)
    if lang:
        doc.set_language(lang)
    tmp = path + ".meta.tmp"
    doc.save(tmp, garbage=4, deflate=True)
    doc.close()
    os.replace(tmp, path)


def detect_and_set_bookmarks(path: str) -> dict:
    """
    Detect headings by font size and set bookmarks.
    Returns info about what was found and set.
    """
    doc = fitz.open(path)

    # Gather all text spans with sizes (limit to first 200 pages for speed)
    spans = []
    for page_num in range(min(doc.page_count, 200)):
        page = doc[page_num]
        blocks = page.get_text("dict", flags=0)["blocks"]
        for b in blocks:
            if b.get("type") != 0:
                continue
            for line in b.get("lines", []):
                for span in line.get("spans", []):
                    text = span["text"].strip()
                    size = round(span["size"], 1)
                    if len(text) < 2:
                        continue
                    spans.append((size, page_num + 1, text))

    if not spans:
        doc.close()
        return {"bookmarks_added": 0, "reason": "no text spans found"}

    # Find distinct font sizes, sort descending
    all_sizes = sorted(set(s[0] for s in spans), reverse=True)

    # Body text = the most common size
    from collections import Counter
    size_counts = Counter(s[0] for s in spans)
    body_size = size_counts.most_common(1)[0][0]

    # Heading sizes = sizes significantly above body text
    heading_sizes = [sz for sz in all_sizes if sz > body_size * 1.15]

    if not heading_sizes:
        doc.close()
        return {"bookmarks_added": 0, "reason": "no distinct heading sizes found"}

    # Map top 3 heading sizes to H1/H2/H3
    level_map = {}
    for i, sz in enumerate(heading_sizes[:3]):
        level_map[sz] = i + 1

    toc = []
    seen_texts = set()
    for size, page_num, text in spans:
        if size not in level_map:
            continue
        # Deduplicate identical headings on same level
        key = (level_map[size], text[:80])
        if key in seen_texts:
            continue
        seen_texts.add(key)
        toc.append([level_map[size], text[:120], page_num])

    if not toc:
        doc.close()
        return {"bookmarks_added": 0, "reason": "no headings matched"}

    # Limit to 100 bookmarks
    toc = toc[:100]
    doc.set_toc(toc)
    tmp = path + ".bm.tmp"
    doc.save(tmp, garbage=4, deflate=True)
    doc.close()
    os.replace(tmp, path)

    return {"bookmarks_added": len(toc), "toc": toc[:10]}  # Return first 10 as preview


def process_pdf(args) -> dict:
    """Full accessibility processing pipeline."""
    path = args.input
    report = {"input": path, "steps": []}

    # Step 1: OCR (if needed)
    status_before = check_pdf(path)
    if not status_before.get("has_text"):
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
            tmp_path = tmp.name
        ocr = run_ocr(path, tmp_path, args.lang)
        if ocr["success"] and os.path.exists(tmp_path):
            shutil.move(tmp_path, path)
            report["steps"].append({"step": "ocr", "status": "done"})
        else:
            if os.path.exists(tmp_path):
                os.unlink(tmp_path)
            report["steps"].append({"step": "ocr", "status": "error", "detail": ocr.get("stderr", "")})
    else:
        report["steps"].append({"step": "ocr", "status": "skipped", "reason": "already has text"})

    # Step 2: Font embedding (via ghostscript)
    if args.embed_fonts:
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
            tmp_path = tmp.name
        gs = embed_fonts(path, tmp_path)
        if gs["success"] and os.path.exists(tmp_path) and os.path.getsize(tmp_path) > 0:
            shutil.move(tmp_path, path)
            report["steps"].append({"step": "font_embedding", "status": "done"})
        else:
            if os.path.exists(tmp_path):
                os.unlink(tmp_path)
            report["steps"].append({"step": "font_embedding", "status": "error", "detail": gs.get("stderr", "")})

    # Step 3: Metadata
    # Primary language code (first part before '+')
    lang_code = args.lang.split("+")[0]
    # Map tesseract lang codes to ISO 639-1
    lang_iso = {"slk": "sk", "eng": "en", "deu": "de", "ces": "cs"}.get(lang_code, lang_code)
    set_metadata(
        path,
        title=args.title,
        author=args.author,
        subject=args.subject,
        lang=lang_iso,
    )
    report["steps"].append({"step": "metadata", "status": "done", "title": args.title, "lang": lang_iso})

    # Step 4: Bookmarks
    if args.bookmarks:
        current = check_pdf(path)
        if not current.get("has_bookmarks"):
            bm = detect_and_set_bookmarks(path)
            report["steps"].append({"step": "bookmarks", "status": "done" if bm["bookmarks_added"] > 0 else "skipped", **bm})
        else:
            report["steps"].append({
                "step": "bookmarks",
                "status": "skipped",
                "reason": f"already has {current['bookmarks_count']} bookmarks",
            })

    report["final"] = check_pdf(path)
    return report


def main():
    parser = argparse.ArgumentParser(description="PDF Accessibility Processor")
    parser.add_argument("action", choices=["check", "process"])
    parser.add_argument("--input", required=True, help="Path to PDF file")
    parser.add_argument("--title", default="", help="Document title for metadata")
    parser.add_argument("--author", default="", help="Author for metadata")
    parser.add_argument("--subject", default="", help="Subject/description for metadata")
    parser.add_argument("--lang", default="slk+eng", help="Tesseract OCR language(s)")
    parser.add_argument("--no-bookmarks", dest="bookmarks", action="store_false", default=True)
    parser.add_argument("--no-embed-fonts", dest="embed_fonts", action="store_false", default=True)

    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(json.dumps({"error": f"File not found: {args.input}"}))
        sys.exit(1)

    if args.action == "check":
        print(json.dumps(check_pdf(args.input)))
    else:
        print(json.dumps(process_pdf(args)))


if __name__ == "__main__":
    main()
