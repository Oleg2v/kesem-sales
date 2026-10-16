#!/usr/bin/env bash
# =============================================================================
# Smart Document Search - regenerate the test fixtures (DEV ONLY).
#
# Inputs (committed, never modified here):
#   tests/fixtures/SH-22-06-57879-590.doc, tests/fixtures/ARAR-25-09-8059.doc
#       real court decisions exported from nevo.co.il (Word 97, Hebrew)
#   tests/fixtures/reference/<name>.txt
#       their text as exported by LibreOffice (UTF-8): the ground truth
#
# Outputs (commit them; re-running overwrites them):
#   tests/fixtures/generated/
#     <name>.docx               soffice --convert-to docx (same Hebrew as the .doc)
#     <name>.pdf                soffice --convert-to pdf (text PDF; LibreOffice draws
#                               each Hebrew run with its glyphs in visual order and
#                               the runs of a line from right to left)
#     <name>.utf8.txt           reference text, UTF-8 without BOM
#     <name>.cp1255.txt         reference text in Windows-1255 (iconv; characters
#                               that cannot be represented are transliterated/dropped)
#     <name>.visual.txt         reference lines in VISUAL order (tools/bidi_visual.py)
#     SH-22-06-57879-590.visual.pdf
#                               reportlab PDF, every line drawn in visual order with
#                               mirrored brackets, like legacy PDF generators
#     nikud-quotes.docx         python-docx: nikud, gershayim/geresh, curly quotes,
#                               maqaf, tab, line break, 2x2 table
#     scanned.pdf               image-only PDF (no text operators)
#     SH-22-06-57879-590.cairo.pdf
#                               optional (pdftocairo + pypdf): the LibreOffice PDF as
#                               cairo writes it (Type0 fonts, backward TJ moves)
#   tests/fixtures/broken/      (only the non-.doc files; *.doc belong to the DOC tests)
#     truncated.pdf  encrypted.pdf  no-document-xml.docx  garbage.docx
#     empty.txt  binary.txt
#
# Requirements: bash, LibreOffice (soffice) headless, python3 with reportlab,
# python-docx and Pillow, iconv, a TrueType font with Hebrew glyphs
# (DejaVu Sans or FreeSans; override with HDS_FIXTURE_FONT=/path/font.ttf).
# Optional: pdftocairo (poppler-utils) and python3 pypdf for the cairo variant.
# Python helpers live in tests/fixtures/tools/.
#
# Usage: tests/fixtures/generate.sh        (from any directory)
# =============================================================================
set -euo pipefail
export PYTHONDONTWRITEBYTECODE=1 # No __pycache__ next to the helpers.

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS="$HERE/tools"
REF="$HERE/reference"
OUT="$HERE/generated"
BROKEN="$HERE/broken"
DOCS=(SH-22-06-57879-590 ARAR-25-09-8059)

die() {
	echo "generate.sh: $*" >&2
	exit 1
}

for tool in soffice python3 iconv; do
	command -v "$tool" >/dev/null 2>&1 || die "missing required tool: $tool"
done
python3 -I -c 'import reportlab, docx, PIL' 2>/dev/null || die "python3 needs reportlab, python-docx and Pillow"

FONT="${HDS_FIXTURE_FONT:-}"
if [[ -z "$FONT" ]]; then
	for candidate in \
		/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf \
		/usr/share/fonts/truetype/freefont/FreeSans.ttf \
		/usr/share/fonts/TTF/DejaVuSans.ttf \
		/usr/share/fonts/dejavu/DejaVuSans.ttf; do
		if [[ -f "$candidate" ]]; then
			FONT="$candidate"
			break
		fi
	done
fi
[[ -n "$FONT" && -f "$FONT" ]] || die "no Hebrew TrueType font found (set HDS_FIXTURE_FONT)"

for name in "${DOCS[@]}"; do
	[[ -f "$HERE/$name.doc" ]] || die "missing input $HERE/$name.doc"
	[[ -f "$REF/$name.txt" ]] || die "missing reference $REF/$name.txt"
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT" "$BROKEN"

# --- 1. LibreOffice conversions (private profile: no clash with a running soffice).
SOFFICE=(soffice --headless --norestore --nolockcheck "-env:UserInstallation=file://$WORK/profile")
for format in docx pdf; do
	"${SOFFICE[@]}" --convert-to "$format" --outdir "$WORK" \
		"$HERE/SH-22-06-57879-590.doc" "$HERE/ARAR-25-09-8059.doc" >/dev/null 2>&1 \
		|| die "soffice --convert-to $format failed"
	for name in "${DOCS[@]}"; do
		[[ -s "$WORK/$name.$format" ]] || die "soffice produced no $name.$format"
		mv -f "$WORK/$name.$format" "$OUT/$name.$format"
	done
done

# --- 2. Plain-text variants and visual-order text.
for name in "${DOCS[@]}"; do
	# UTF-8 without BOM.
	sed '1s/^\xEF\xBB\xBF//' "$REF/$name.txt" >"$OUT/$name.utf8.txt"
	iconv -c -f UTF-8 -t WINDOWS-1255//TRANSLIT "$OUT/$name.utf8.txt" >"$OUT/$name.cp1255.txt"
	python3 -I -B "$TOOLS/bidi_visual.py" <"$OUT/$name.utf8.txt" >"$OUT/$name.visual.txt"
done

# --- 3. Synthetic documents.
python3 -I -B "$TOOLS/make_visual_pdf.py" "$REF/SH-22-06-57879-590.txt" "$OUT/SH-22-06-57879-590.visual.pdf" "$FONT"
python3 -I -B "$TOOLS/make_nikud_docx.py" "$OUT/nikud-quotes.docx"
python3 -I -B "$TOOLS/make_scanned_pdf.py" "$OUT/scanned.pdf" "$FONT"

# --- 3b. Optional: cairo variant (Type0 fonts, lowercase hex, backward TJ moves).
if command -v pdftocairo >/dev/null 2>&1 && python3 -I -c 'import pypdf' 2>/dev/null; then
	pdftocairo -pdf "$OUT/SH-22-06-57879-590.pdf" "$WORK/cairo.pdf"
	python3 -I -B "$TOOLS/resave_pdf.py" "$WORK/cairo.pdf" "$OUT/SH-22-06-57879-590.cairo.pdf"
else
	echo "generate.sh: pdftocairo or pypdf missing - keeping the existing SH-22-06-57879-590.cairo.pdf" >&2
fi

# --- 4. Damaged / special files.
python3 -I -B "$TOOLS/make_broken.py" "$BROKEN" "$OUT/SH-22-06-57879-590.pdf" "$FONT"

echo "Fixtures written:"
(cd "$HERE" && ls -l generated/ broken/truncated.pdf broken/encrypted.pdf broken/no-document-xml.docx \
	broken/garbage.docx broken/empty.txt broken/binary.txt)
