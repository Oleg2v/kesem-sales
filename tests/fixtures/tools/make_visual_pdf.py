"""Build a PDF whose Hebrew is stored in VISUAL order (dev-only fixture).

Legacy PDF generators wrote each line as the sequence of glyphs seen on the
page, left to right: Hebrew comes out reversed while numbers and Latin keep
their order, and brackets are stored mirrored. This script reproduces that:
every line of a logical-order UTF-8 text is converted with bidi_visual.py and
drawn with reportlab (which does no bidi processing of its own) right-aligned,
as one text-show operation per line, with an embedded Hebrew TrueType font.

Usage: python3 make_visual_pdf.py <reference.txt> <out.pdf> <font.ttf>
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from reportlab import rl_config  # noqa: E402
from reportlab.lib.pagesizes import A4  # noqa: E402
from reportlab.pdfbase import pdfmetrics  # noqa: E402
from reportlab.pdfbase.ttfonts import TTFont  # noqa: E402
from reportlab.pdfgen import canvas  # noqa: E402

from bidi_visual import to_visual  # noqa: E402

FONT_SIZE = 11
LEADING = 15
MARGIN = 56


def wrap_logical(line, font, width):
    """Wrap a logical-order line into lines that fit `width` points."""
    words = line.split(" ")
    lines, current = [], ""
    for word in words:
        candidate = word if not current else current + " " + word
        if current and pdfmetrics.stringWidth(candidate, font, FONT_SIZE) > width:
            lines.append(current)
            current = word
        else:
            current = candidate
    lines.append(current)
    return lines


def main():
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    source, target, font_path = sys.argv[1:4]
    rl_config.invariant = 1  # Reproducible output (no dates / random ids).
    pdfmetrics.registerFont(TTFont("HebrewTTF", font_path))

    with open(source, encoding="utf-8") as handle:
        logical = handle.read().split("\n")

    pdf = canvas.Canvas(target, pagesize=A4, pageCompression=0)
    pdf.setTitle("Visual-order Hebrew fixture")
    pdf.setFont("HebrewTTF", FONT_SIZE)
    width, height = A4
    right = width - MARGIN
    y = height - MARGIN
    previous_blank = False
    for raw in logical:
        text = raw.strip()
        if not text and previous_blank:
            continue  # Collapse runs of empty lines.
        previous_blank = not text
        pieces = wrap_logical(text, "HebrewTTF", width - 2 * MARGIN) if text else [""]
        for piece in pieces:
            if y < MARGIN:
                pdf.showPage()
                pdf.setFont("HebrewTTF", FONT_SIZE)
                y = height - MARGIN
            if piece:
                pdf.drawRightString(right, y, to_visual(piece))
            y -= LEADING
    pdf.save()


if __name__ == "__main__":
    main()
