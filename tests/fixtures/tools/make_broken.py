"""Build the damaged/special fixtures in tests/fixtures/broken (dev-only).

  truncated.pdf          first 40 % of a real PDF (no xref, no trailer)
  encrypted.pdf          reportlab PDF protected with the user password "secret"
  no-document-xml.docx   valid ZIP/OPC package without word/document.xml
  garbage.docx           "PK\\x03\\x04" followed by junk (not a ZIP)
  empty.txt              0 bytes
  binary.txt             random bytes including NULs

Deterministic: fixed random seed and reportlab invariant mode.

Usage: python3 make_broken.py <broken-dir> <source.pdf> <font.ttf>
"""

import os
import random
import sys
import zipfile

from reportlab import rl_config
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

CONTENT_TYPES = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    '<Default Extension="xml" ContentType="application/xml"/>'
    "</Types>"
)
ROOT_RELS = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
    'Target="word/document.xml"/>'
    "</Relationships>"
)
STYLES = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
    '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>'
)


def write_zip(path, entries):
    """Write a deflated ZIP with fixed timestamps."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, data in entries:
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data)


def main():
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    out_dir, source_pdf, font_path = sys.argv[1:4]
    os.makedirs(out_dir, exist_ok=True)
    rng = random.Random(20260101)

    with open(source_pdf, "rb") as handle:
        data = handle.read()
    with open(os.path.join(out_dir, "truncated.pdf"), "wb") as handle:
        handle.write(data[: int(len(data) * 0.4)])

    rl_config.invariant = 1
    pdfmetrics.registerFont(TTFont("HebrewTTF", font_path))
    pdf = canvas.Canvas(os.path.join(out_dir, "encrypted.pdf"), pagesize=A4, encrypt="secret")
    pdf.setFont("HebrewTTF", 14)
    pdf.drawRightString(A4[0] - 72, A4[1] - 100, "ןידה קספ")
    pdf.drawRightString(A4[0] - 72, A4[1] - 130, "Confidential document 2026")
    pdf.showPage()
    pdf.save()

    write_zip(
        os.path.join(out_dir, "no-document-xml.docx"),
        [
            ("[Content_Types].xml", CONTENT_TYPES),
            ("_rels/.rels", ROOT_RELS),
            ("word/styles.xml", STYLES),
        ],
    )

    junk = bytes(rng.getrandbits(8) for _ in range(4096))
    with open(os.path.join(out_dir, "garbage.docx"), "wb") as handle:
        handle.write(b"PK\x03\x04" + junk)

    open(os.path.join(out_dir, "empty.txt"), "wb").close()

    binary = bytearray(rng.getrandbits(8) for _ in range(4096))
    for position in range(0, len(binary), 97):
        binary[position] = 0
    with open(os.path.join(out_dir, "binary.txt"), "wb") as handle:
        handle.write(bytes(binary))


if __name__ == "__main__":
    main()
