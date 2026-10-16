"""Build scanned.pdf (dev-only fixture): an image-only PDF.

Hebrew text is rendered into a raster image with Pillow (in visual order,
so the picture reads correctly) and placed on an A4 page with reportlab.
The page has NO text operators, like a scan without OCR: the extractor must
report SCANNED_PDF.

Usage: python3 make_scanned_pdf.py <out.pdf> <font.ttf>
"""

import io
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from PIL import Image, ImageDraw, ImageFont  # noqa: E402
from reportlab import rl_config  # noqa: E402
from reportlab.lib.pagesizes import A4  # noqa: E402
from reportlab.lib.utils import ImageReader  # noqa: E402
from reportlab.pdfgen import canvas  # noqa: E402

from bidi_visual import to_visual  # noqa: E402

LINES = [
    "בית משפט השלום בפתח תקווה",
    "ת\"א 57879-06-22",
    "פסק דין",
    "ניתן תוקף של פסק דין להסכמת הצדדים.",
]


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    target, font_path = sys.argv[1:3]
    rl_config.invariant = 1

    width_px, height_px = 1240, 1754  # A4 at 150 dpi.
    image = Image.new("L", (width_px, height_px), 255)
    draw = ImageDraw.Draw(image)
    font = ImageFont.truetype(font_path, 40)
    y = 150
    for line in LINES:
        visual = to_visual(line)
        box = draw.textbbox((0, 0), visual, font=font)
        draw.text((width_px - 120 - (box[2] - box[0]), y), visual, font=font, fill=0)
        y += 80

    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    buffer.seek(0)

    pdf = canvas.Canvas(target, pagesize=A4, pageCompression=0)
    pdf.setTitle("Scanned fixture (image only)")
    pdf.drawImage(ImageReader(buffer), 0, 0, width=A4[0], height=A4[1])
    pdf.showPage()
    pdf.save()


if __name__ == "__main__":
    main()
