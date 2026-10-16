"""Rewrite a PDF with pypdf (dev-only fixture helper).

Used for the cairo variant of a fixture: `pdftocairo -pdf` re-renders a PDF
the way cairo-based producers (Firefox and GTK "Print to file", Inkscape)
write it - Type0/Identity-H fonts, lowercase hex strings, TJ arrays that move
the pen backwards for right-to-left runs - but it also stores the page
dictionaries in object streams with "% comments" inside, which smalot/pdfparser
cannot read. Re-saving with pypdf keeps every content stream and font as is and
writes plain objects.

Usage: python3 resave_pdf.py <in.pdf> <out.pdf>
"""

import sys

import pypdf


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    reader = pypdf.PdfReader(sys.argv[1])
    writer = pypdf.PdfWriter()
    for page in reader.pages:
        writer.add_page(page)
    writer.add_metadata({"/Producer": "cairo via pdftocairo, re-saved with pypdf"})
    with open(sys.argv[2], "wb") as handle:
        writer.write(handle)


if __name__ == "__main__":
    main()
