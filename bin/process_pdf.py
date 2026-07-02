#!/usr/bin/env python3
"""PDF accessibility processor for SBA Agency WordPress plugin."""

import sys
import json
import re
import subprocess
import argparse
import tempfile
import os
import shutil
import base64
import html

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
        "tagged_pdf": False,
    }
    try:
        doc = fitz.open(path)
        result["pages"] = doc.page_count
        result["tagged_pdf"] = _has_struct_tree(doc)

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


def _has_struct_tree(doc: fitz.Document) -> bool:
    """True if the PDF has a StructTreeRoot (tagged PDF). Required for standards-valid alt text embedding."""
    try:
        return "/StructTreeRoot" in doc.xref_object(doc.pdf_catalog())
    except Exception:
        return False


def _load_env_file(path: str) -> None:
    """Load KEY=VALUE lines from a .env file into os.environ; skips already-set keys."""
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#') or '=' not in line:
                    continue
                key, _, val = line.partition('=')
                key = key.strip()
                val = val.strip().strip('"').strip("'")
                if key and key not in os.environ:
                    os.environ[key] = val
    except OSError:
        pass


def _get_adobe_creds() -> tuple:
    """Return (client_id, client_secret) supporting both ADOBE_ and bare PDF_SERVICES_ prefixes."""
    client_id = (
        os.environ.get('ADOBE_PDF_SERVICES_CLIENT_ID') or
        os.environ.get('PDF_SERVICES_CLIENT_ID') or ''
    )
    client_secret = (
        os.environ.get('ADOBE_PDF_SERVICES_CLIENT_SECRET') or
        os.environ.get('PDF_SERVICES_CLIENT_SECRET') or ''
    )
    return client_id, client_secret


def autotag_pdf(path: str, shift_headings: bool = False) -> dict:
    """Send PDF to Adobe Auto-Tag API; atomically replace input with tagged version.

    Creates a .autotag.bak backup before calling the API; removes it on success,
    restores it on error. Returns structured JSON.
    """
    # Load repo-root .env (4 dirs up from bin/): no python-dotenv required
    script_dir = os.path.dirname(os.path.abspath(__file__))
    env_path = os.path.normpath(os.path.join(script_dir, '..', '..', '..', '..', '.env'))
    _load_env_file(env_path)

    client_id, client_secret = _get_adobe_creds()
    if not client_id or not client_secret:
        return {
            "autotagged": False,
            "reason": "missing_credentials",
            "error": "ADOBE_PDF_SERVICES_CLIENT_ID / ADOBE_PDF_SERVICES_CLIENT_SECRET not set",
        }

    try:
        from adobe.pdfservices.operation.auth.service_principal_credentials import ServicePrincipalCredentials
        from adobe.pdfservices.operation.pdf_services import PDFServices
        from adobe.pdfservices.operation.pdf_services_media_type import PDFServicesMediaType
        from adobe.pdfservices.operation.pdfjobs.jobs.autotag_pdf_job import AutotagPDFJob
        from adobe.pdfservices.operation.pdfjobs.result.autotag_pdf_result import AutotagPDFResult
    except ImportError:
        return {
            "autotagged": False,
            "reason": "sdk_missing",
            "error": "Adobe pdfservices-sdk not installed. Run: pip3 install pdfservices-sdk",
        }

    backup = path + ".autotag.bak"
    shutil.copy2(path, backup)
    tmp = path + ".autotag.tmp"

    try:
        credentials = ServicePrincipalCredentials(
            client_id=client_id,
            client_secret=client_secret,
        )
        pdf_services = PDFServices(credentials=credentials)

        with open(path, 'rb') as f:
            input_stream = f.read()

        input_asset = pdf_services.upload(input_stream=input_stream, mime_type=PDFServicesMediaType.PDF)

        # ponytail: AutotagPDFParams is optional; skip if import fails (older SDK versions)
        autotag_job = None
        if shift_headings:
            try:
                from adobe.pdfservices.operation.pdfjobs.params.autotag_pdf.autotag_pdf_params import AutotagPDFParams
                params = AutotagPDFParams(shift_headings=True)
                autotag_job = AutotagPDFJob(input_asset=input_asset, autotag_pdf_params=params)
            except ImportError:
                pass
        if autotag_job is None:
            autotag_job = AutotagPDFJob(input_asset=input_asset)

        location = pdf_services.submit(autotag_job)
        pdf_response = pdf_services.get_job_result(location, AutotagPDFResult)
        result_asset = pdf_response.get_result().get_tagged_pdf()
        stream_asset = pdf_services.get_content(result_asset)

        with open(tmp, 'wb') as out:
            out.write(stream_asset.get_input_stream())

        # Verify output is a valid tagged PDF
        vdoc = fitz.open(tmp)
        tagged = _has_struct_tree(vdoc)
        vdoc.close()

        if not tagged:
            os.unlink(tmp)
            shutil.copy2(backup, path)
            os.unlink(backup)
            return {"autotagged": False, "tagged_pdf": False, "reason": "no_struct_tree",
                    "error": "Adobe returned PDF without StructTreeRoot"}

        os.replace(tmp, path)
        try:
            os.unlink(backup)
        except Exception:
            pass
        return {"autotagged": True, "tagged_pdf": True}

    except Exception as e:
        if os.path.exists(tmp):
            try:
                os.unlink(tmp)
            except Exception:
                pass
        backup_kept = False
        if os.path.exists(backup):
            try:
                shutil.copy2(backup, path)
                os.unlink(backup)
            except Exception:
                backup_kept = True

        err_str = str(e)
        err_lower = err_str.lower()
        if "401" in err_str or "unauthorized" in err_lower or "authentication" in err_lower:
            reason = "invalid_credentials"
        elif "quota" in err_lower or "429" in err_str or "limit" in err_lower:
            reason = "quota_exceeded"
        elif "timeout" in err_lower:
            reason = "timeout"
        else:
            reason = "api_error"

        result = {"autotagged": False, "reason": reason, "error": err_str[:300]}
        if backup_kept:
            result["backup"] = backup
        return result


def _pdfinfo_tagged(path: str):
    """Return pdfinfo's Tagged value when poppler-utils is installed."""
    pdfinfo = shutil.which("pdfinfo")
    if not pdfinfo:
        return None
    try:
        proc = subprocess.run([pdfinfo, path], capture_output=True, text=True, timeout=30)
    except Exception:
        return None
    for line in proc.stdout.splitlines():
        if line.lower().startswith("tagged:"):
            return line.split(":", 1)[1].strip().lower() == "yes"
    return None


def _postprocess_tagged_pdf(path: str, lang: str = "sk", title: str = "") -> None:
    """Add catalog-level tagged-PDF metadata that OpenDataLoader may omit.

    This does not claim PDF/UA compliance. It only makes the generated tag tree
    discoverable by common tools (`pdfinfo Tagged: yes`) and preserves language /
    display-title metadata for assistive workflows.
    """
    try:
        import pikepdf
        from pikepdf import Dictionary, String
    except ImportError as exc:
        raise RuntimeError("pikepdf not installed. Run: pip3 install pikepdf") from exc

    lang_map = {"slk": "sk-SK", "sk": "sk-SK", "eng": "en-US", "en": "en-US", "ces": "cs-CZ", "cs": "cs-CZ"}
    lang_code = (lang or "sk").split("+")[0]
    pdf_lang = lang_map.get(lang_code, lang_code)
    safe_title = html.escape(title or "PDF dokument")

    xmp = f'''<?xpacket begin="\ufeff" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="SBA PDF Accessibility">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">
   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">{safe_title}</rdf:li></rdf:Alt></dc:title>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>'''.encode("utf-8")

    with pikepdf.open(path) as pdf:
        root = pdf.Root
        root["/MarkInfo"] = Dictionary({"/Marked": True})
        root["/Lang"] = String(pdf_lang)
        root["/ViewerPreferences"] = Dictionary({"/DisplayDocTitle": True})
        stream = pdf.make_stream(xmp)
        stream["/Type"] = "/Metadata"
        stream["/Subtype"] = "/XML"
        root["/Metadata"] = stream
        pdf.save(path + ".post.tmp")
    os.replace(path + ".post.tmp", path)


def localtag_pdf(path: str, lang: str = "sk", title: str = "") -> dict:
    """Generate a locally tagged PDF via OpenDataLoader and replace input safely."""
    odl = shutil.which("opendataloader-pdf")
    if not odl:
        sibling = os.path.join(os.path.dirname(sys.executable), "opendataloader-pdf")
        if os.path.exists(sibling):
            odl = sibling
    if not odl:
        return {
            "localtagged": False,
            "tagged_pdf": False,
            "reason": "opendataloader_missing",
            "error": "opendataloader-pdf not installed. Run: pip3 install opendataloader-pdf pikepdf",
        }

    try:
        before = fitz.open(path)
        original_pages = before.page_count
        before.close()
    except Exception as exc:
        return {"localtagged": False, "tagged_pdf": False, "reason": "invalid_pdf", "error": str(exc)[:300]}

    backup = path + ".localtag.bak"
    shutil.copy2(path, backup)
    dest_tmp = path + ".localtag.tmp"
    tmpdir = tempfile.mkdtemp(prefix="sba_odl_")
    output = os.path.join(tmpdir, os.path.splitext(os.path.basename(path))[0] + "_tagged.pdf")

    try:
        proc = subprocess.run(
            [odl, "--format", "tagged-pdf", "-o", tmpdir, path],
            capture_output=True,
            text=True,
            timeout=600,
        )
        if proc.returncode != 0 or not os.path.exists(output):
            return {
                "localtagged": False,
                "tagged_pdf": False,
                "reason": "opendataloader_failed",
                "error": ((proc.stderr or proc.stdout or "OpenDataLoader failed")[:500]),
            }

        _postprocess_tagged_pdf(output, lang=lang, title=title)

        vdoc = fitz.open(output)
        tagged = _has_struct_tree(vdoc)
        pages = vdoc.page_count
        vdoc.close()
        if not tagged or pages != original_pages:
            return {
                "localtagged": False,
                "tagged_pdf": bool(tagged),
                "reason": "validation_failed",
                "error": f"Tagged={tagged}, pages={pages}, original_pages={original_pages}",
            }

        # `output` is in a temp directory, which may be on a different
        # filesystem than WordPress uploads. `os.replace()` across devices fails
        # with EXDEV, so copy to the destination directory first and only then
        # atomically replace the original file within the same filesystem.
        shutil.copy2(output, dest_tmp)
        os.replace(dest_tmp, path)
        try:
            os.unlink(backup)
        except Exception:
            pass
        final = check_pdf(path)
        final.update({
            "localtagged": True,
            "tagged_pdf": True,
            "pdfinfo_tagged": _pdfinfo_tagged(path),
            "message": "PDF lokálne tagované cez OpenDataLoader; vyžaduje kontrolu/validáciu.",
        })
        return final
    except subprocess.TimeoutExpired:
        shutil.copy2(backup, path)
        return {"localtagged": False, "tagged_pdf": False, "reason": "timeout", "error": "OpenDataLoader timed out"}
    except Exception as exc:
        if os.path.exists(dest_tmp):
            try:
                os.unlink(dest_tmp)
            except Exception:
                pass
        if os.path.exists(backup):
            try:
                shutil.copy2(backup, path)
            except Exception:
                pass
        return {"localtagged": False, "tagged_pdf": False, "reason": "localtag_error", "error": str(exc)[:500]}
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)
        if os.path.exists(backup):
            try:
                os.unlink(backup)
            except Exception:
                pass


def _pdf_str(text: str) -> str:
    """Encode text as a PDF hex string (UTF-16BE with BOM). Safe for all Unicode."""
    bom = b'\xfe\xff'
    return '<' + (bom + text.encode('utf-16-be')).hex().upper() + '>'


def _struct_figures_ordered(doc: fitz.Document) -> list:
    """Traverse StructTreeRoot depth-first; return xrefs of /Figure elements in order.

    Returns [] if the PDF is untagged or the struct tree is malformed.
    Only follows indirect object references in /K — MCID integers and inline dicts
    (marked-content references) are correctly ignored.
    """
    try:
        cat = doc.xref_object(doc.pdf_catalog())
        m = re.search(r'/StructTreeRoot\s+(\d+)\s+0\s+R', cat)
        if not m:
            return []
        root_xref = int(m.group(1))
    except Exception:
        return []

    figures: list = []
    visited: set = set()

    def visit(xref: int, depth: int = 0) -> None:
        if xref in visited or xref <= 0 or depth > 100:
            return
        visited.add(xref)
        try:
            obj = doc.xref_object(xref)
        except Exception:
            return
        if re.search(r'/S\s*/Figure\b', obj):
            figures.append(xref)
            return  # leaf — content refs inside Figure are not struct children
        # recurse into struct children via /K (xref kids only)
        k_arr = re.search(r'/K\s*\[([^\]]*)\]', obj, re.DOTALL)
        if k_arr:
            for ref in re.finditer(r'(\d+)\s+0\s+R', k_arr.group(1)):
                visit(int(ref.group(1)), depth + 1)
        else:
            k_single = re.search(r'/K\s+(\d+)\s+0\s+R', obj)
            if k_single:
                visit(int(k_single.group(1)), depth + 1)

    visit(root_xref)
    return figures


def write_alts(path: str, alts_data: list) -> dict:
    """Write /Alt into PDF Structure Tree Figure elements.

    alts_data: [{"struct_xref": int, "alt": "text"}, ...]

    Only works for tagged PDFs (StructTreeRoot present). For untagged PDFs returns
    {"embedded": false, "reason": "untagged"} and leaves the file untouched.
    Creates a .altbak backup before any modification; backup is deleted on success.
    """
    probe = fitz.open(path)
    tagged = _has_struct_tree(probe)
    probe.close()

    if not tagged:
        return {"embedded": False, "reason": "untagged"}

    entries = [
        e for e in alts_data
        if isinstance(e.get("struct_xref"), int) and e["struct_xref"] > 0
        and str(e.get("alt", "")).strip()
    ]
    if not entries:
        return {"embedded": False, "reason": "no_tagged_images"}

    backup = path + ".altbak"
    shutil.copy2(path, backup)

    tmp = path + ".alttmp"
    try:
        doc = fitz.open(path)
        count = 0
        for entry in entries:
            xref = int(entry["struct_xref"])
            alt  = str(entry["alt"]).strip()
            doc.xref_set_key(xref, "Alt", _pdf_str(alt))
            count += 1

        doc.save(tmp, garbage=4, deflate=True)
        doc.close()

        # Verify: re-open and confirm /Alt is readable on at least one element
        vdoc = fitz.open(tmp)
        verified = 0
        for entry in entries:
            xref = int(entry["struct_xref"])
            _typ, val = vdoc.xref_get_key(xref, "Alt")
            if val:
                verified += 1
        vdoc.close()

        if verified == 0:
            os.unlink(tmp)
            return {"embedded": False, "reason": "verify_failed", "backup": backup}

        os.replace(tmp, path)
        try:
            os.unlink(backup)
        except Exception:
            pass
        return {"embedded": True, "count": count, "verified": verified}

    except Exception as e:
        if os.path.exists(tmp):
            try:
                os.unlink(tmp)
            except Exception:
                pass
        if os.path.exists(backup):
            shutil.copy2(backup, path)  # restore original
        return {"embedded": False, "reason": f"error: {str(e)[:300]}"}


def extract_image_previews(path: str, max_images: int = 30) -> dict:
    """Return images with small JPEG thumbnails plus PDF tag-structure status.

    Returns {"images": [...], "tagged_pdf": bool} — tagged_pdf indicates whether
    the document has a StructTreeRoot, which is a prerequisite for standards-valid
    alt text embedding. Alt text written without a structure tree is not WCAG/PDF-UA
    compliant, so direct embedding is not attempted here.
    """
    doc = fitz.open(path)

    def make_thumb(page: fitz.Page, xref: int) -> str:
        """Return a small JPEG preview for an image.

        Some PDFs expose image XObjects that cannot be converted directly to a
        JPEG preview (soft masks, CMYK/alpha/stencil images, unusual filters).
        If raw extraction fails, render the page rectangle where the image is
        drawn so the editor still sees what they are describing.
        """
        try:
            pix = fitz.Pixmap(doc, xref)
            if pix.alpha or pix.colorspace is None or pix.n not in (1, 3):
                pix = fitz.Pixmap(fitz.csRGB, pix)
            while max(pix.width, pix.height) > 240 and pix.width >= 4 and pix.height >= 4:
                pix.shrink(2)
            return base64.b64encode(pix.tobytes("jpeg", jpg_quality=65)).decode()
        except Exception:
            pass

        try:
            rects = page.get_image_rects(xref)
            if rects:
                rect = rects[0]
                if not rect.is_empty and rect.width > 1 and rect.height > 1:
                    scale = min(3.0, 240.0 / max(rect.width, rect.height))
                    scale = max(scale, 0.5)
                    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), clip=rect, alpha=False)
                    return base64.b64encode(pix.tobytes("jpeg", jpg_quality=65)).decode()
        except Exception:
            pass

        # Last-resort fallback: show the whole page as context instead of an
        # empty preview. Some PDFs list image XObjects without usable draw
        # rectangles; a page thumbnail is still far better for editors than a
        # blank alt-text field.
        try:
            scale = min(1.5, 240.0 / max(page.rect.width, page.rect.height))
            scale = max(scale, 0.2)
            pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
            return base64.b64encode(pix.tobytes("jpeg", jpg_quality=60)).decode()
        except Exception:
            return ""

    # ponytail: detect struct tree once while doc is open; _has_struct_tree is cheap
    tagged = _has_struct_tree(doc)
    images = []
    count = 0
    for page_num, page in enumerate(doc):
        if count >= max_images:
            break
        for img_idx, img_ref in enumerate(page.get_images(full=True)):
            if count >= max_images:
                break
            xref = img_ref[0]
            has_alt = _image_has_alt(doc, page, xref)
            thumb_b64 = make_thumb(page, xref)
            images.append({
                "page": page_num + 1,
                "index": img_idx + 1,
                "xref": xref,
                "has_alt": has_alt,
                "thumb": thumb_b64,
            })
            count += 1

    # Match images to struct Figure elements by sequential order (heuristic).
    # Works reliably for well-structured tagged PDFs where Figure count == image count.
    if tagged:
        figs = _struct_figures_ordered(doc)
        for i, img in enumerate(images):
            img['struct_fig_xref'] = figs[i] if i < len(figs) else None
    else:
        for img in images:
            img['struct_fig_xref'] = None

    doc.close()
    return {"images": images, "tagged_pdf": tagged}


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

    # Normalize: first entry must be level 1, no entry may jump more than +1 deeper
    normalized = []
    prev_level = 0
    for row in toc:
        lvl = row[0]
        if not normalized:
            lvl = 1
        else:
            lvl = min(lvl, prev_level + 1)
            lvl = max(1, lvl)
        normalized.append([lvl, row[1], row[2]])
        prev_level = lvl
    toc = normalized

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
    parser.add_argument("action", choices=["check", "process", "images", "write-alts", "autotag", "localtag"])
    parser.add_argument("--input", required=True, help="Path to PDF file")
    parser.add_argument("--title", default="", help="Document title for metadata")
    parser.add_argument("--author", default="", help="Author for metadata")
    parser.add_argument("--subject", default="", help="Subject/description for metadata")
    parser.add_argument("--lang", default="slk+eng", help="Tesseract OCR language(s)")
    parser.add_argument("--no-bookmarks", dest="bookmarks", action="store_false", default=True)
    parser.add_argument("--no-embed-fonts", dest="embed_fonts", action="store_false", default=True)
    parser.add_argument("--alts-file", dest="alts_file", default="",
                        help="Path to JSON file with alt data for write-alts action")
    parser.add_argument("--shift-headings", dest="shift_headings", action="store_true", default=False,
                        help="Shift headings in auto-tagged output (autotag action only)")

    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(json.dumps({"error": f"File not found: {args.input}"}))
        sys.exit(1)

    if args.action == "check":
        print(json.dumps(check_pdf(args.input)))
    elif args.action == "images":
        print(json.dumps(extract_image_previews(args.input)))
    elif args.action == "write-alts":
        if not args.alts_file:
            print(json.dumps({"error": "--alts-file required for write-alts"}))
            sys.exit(1)
        try:
            with open(args.alts_file) as f:
                alts_data = json.load(f)
        except Exception as e:
            print(json.dumps({"error": f"Cannot read alts file: {e}"}))
            sys.exit(1)
        print(json.dumps(write_alts(args.input, alts_data)))
    elif args.action == "autotag":
        print(json.dumps(autotag_pdf(args.input, shift_headings=args.shift_headings)))
    elif args.action == "localtag":
        print(json.dumps(localtag_pdf(args.input, lang=args.lang, title=args.title)))
    else:
        print(json.dumps(process_pdf(args)))


if __name__ == "__main__":
    main()
