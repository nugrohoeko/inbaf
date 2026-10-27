"""Independently parse actual PDFs, render pages, and decode their embedded QR images.

QA-only dependencies: PyMuPDF and opencv-python-headless. They are not application dependencies.
Run after certificate-artifact-probe.php; verification URLs never enter the report.
"""
from pathlib import Path
import hashlib
import json
import time

import cv2
import numpy as np
import pymupdf

directory = Path(__file__).resolve().parents[2] / "tmp/qa/certificate-artifacts"
manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
results = []
for artifact in manifest["artifacts"]:
    started = time.perf_counter()
    name = artifact["name"]
    with pymupdf.open(directory / f"{name}.pdf") as document:
        assert not document.is_repaired, f"{name}: damaged PDF required repair"
        assert len(document) == 1, f"{name}: certificate unexpectedly spans {len(document)} pages"
        page = document[0]
        text = page.get_text()
        assert "INBAF QA Certificate Holder" in text, f"{name}: holder text missing"
        assert "92.55" in text, f"{name}: official two-decimal score missing"
        assert "C2" in text, f"{name}: proficiency level missing"
        pixmap = page.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
        pixmap.save(directory / f"{name}.png")
        decoded = []
        for image in page.get_images(full=True):
            extracted = document.extract_image(image[0])
            pixels = cv2.imdecode(np.frombuffer(extracted["image"], dtype=np.uint8), cv2.IMREAD_COLOR)
            if pixels is None:
                continue
            value, points, _ = cv2.QRCodeDetector().detectAndDecode(pixels)
            if value:
                decoded.append(value)
        matches = [url for url in decoded if hashlib.sha256(url.encode()).hexdigest() == artifact["verification_url_sha256"]]
        assert len(matches) == 1, f"{name}: decoded QR does not match the actual issued verification URL"
        assert matches[0].startswith("https://inbaf.example/verify/"), f"{name}: wrong verification route"
        assert len(matches[0].rsplit("/", 1)[1]) == 43, f"{name}: unexpected verification token entropy"
        results.append({"name": name, "pdf_bytes": artifact["bytes"], "pages": len(document), "pdf_parse": "PASS", "official_score": 92.55, "score_text": "PASS", "qr_decode_matches_issued_url": True, "elapsed_ms": round((time.perf_counter()-started)*1000, 2)})
report = {"synthetic_data": True, "environment": "local isolated SQLite memory", "artifacts_checked": len(results), "results": results}
(directory / "verification-report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
print(json.dumps(report, indent=2))
