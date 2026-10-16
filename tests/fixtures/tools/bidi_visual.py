"""Logical -> visual order for one line of text (dev-only fixture helper).

A compact implementation of the Unicode Bidirectional Algorithm (UAX #9)
for a single paragraph without explicit embeddings: rules P2/P3 (with a
right-to-left default when the line has no strong character, as in a
Hebrew document), W1-W7, N1-N2, I1-I2, L1, L2 and L4 (bracket mirroring).
Rule N0 (bracket pairs) is not needed for the fixture texts.

It is written independently of the plugin's PHP HDS_Bidi (which implements
the inverse heuristically), so fixtures produced with it are a real test of
the PHP code, and it reproduces what legacy PDF generators did: store each
line as the glyph sequence seen on screen, mirrored brackets included.

Usage as a script:  python3 bidi_visual.py < logical.txt > visual.txt
"""

import sys
import unicodedata

MIRROR = {
    "(": ")", ")": "(", "[": "]", "]": "[", "{": "}", "}": "{",
    "<": ">", ">": "<", "«": "»", "»": "«",
}

NEUTRAL = {"B", "S", "WS", "ON", "BN", "LRE", "RLE", "LRO", "RLO", "PDF", "LRI", "RLI", "FSI", "PDI"}


def _strong_for_neutrals(bidi_type):
    """Direction a resolved type counts as for rule N1 (numbers act as R)."""
    if bidi_type == "L":
        return "L"
    if bidi_type in ("R", "EN", "AN"):
        return "R"
    return None


def to_visual(line, base_rtl=None):
    """Return `line` (logical order) in visual (left-to-right display) order."""
    n = len(line)
    if n == 0:
        return line
    types = [unicodedata.bidirectional(ch) or "ON" for ch in line]

    # P2/P3: paragraph level from the first strong character; RTL by default.
    if base_rtl is None:
        base_rtl = True
        for t in types:
            if t == "L":
                base_rtl = False
                break
            if t in ("R", "AL"):
                base_rtl = True
                break
    base = 1 if base_rtl else 0
    sos = "R" if base else "L"

    # W1: NSM takes the type of the previous character.
    for i, t in enumerate(types):
        if t == "NSM":
            types[i] = types[i - 1] if i > 0 else sos
    # W2: EN after AL becomes AN.
    last = sos
    for i, t in enumerate(types):
        if t in ("L", "R", "AL"):
            last = t
        elif t == "EN" and last == "AL":
            types[i] = "AN"
    # W3: AL -> R.
    types = ["R" if t == "AL" else t for t in types]
    # W4: a single separator between two numbers of the same kind.
    for i in range(1, n - 1):
        if types[i] == "ES" and types[i - 1] == "EN" and types[i + 1] == "EN":
            types[i] = "EN"
        elif types[i] == "CS" and types[i - 1] == types[i + 1] and types[i - 1] in ("EN", "AN"):
            types[i] = types[i - 1]
    # W5: terminators next to European numbers.
    i = 0
    while i < n:
        if types[i] == "ET":
            j = i
            while j < n and types[j] == "ET":
                j += 1
            if (i > 0 and types[i - 1] == "EN") or (j < n and types[j] == "EN"):
                for k in range(i, j):
                    types[k] = "EN"
            i = j
        else:
            i += 1
    # W6: remaining separators/terminators are neutral.
    types = ["ON" if t in ("ES", "ET", "CS") else t for t in types]
    # W7: EN after L (last strong) becomes L.
    last = sos
    for i, t in enumerate(types):
        if t in ("L", "R"):
            last = t
        elif t == "EN" and last == "L":
            types[i] = "L"
    # N1/N2: runs of neutrals.
    i = 0
    while i < n:
        if types[i] in NEUTRAL:
            j = i
            while j < n and types[j] in NEUTRAL:
                j += 1
            before = _strong_for_neutrals(types[i - 1]) if i > 0 else sos
            after = _strong_for_neutrals(types[j]) if j < n else sos
            direction = before if before == after else ("R" if base else "L")
            for k in range(i, j):
                types[k] = direction
            i = j
        else:
            i += 1
    # I1/I2: embedding levels.
    levels = []
    for t in types:
        if base == 0:
            levels.append(0 if t == "L" else (1 if t == "R" else 2))
        else:
            levels.append(2 if t in ("L", "EN", "AN") else 1)
    # L1: trailing whitespace goes to the paragraph level.
    k = n - 1
    while k >= 0 and unicodedata.bidirectional(line[k]) in ("WS", "S", "B", "BN"):
        levels[k] = base
        k -= 1
    # L4: mirrored glyphs at odd (RTL) levels.
    chars = [MIRROR.get(ch, ch) if levels[i] % 2 == 1 else ch for i, ch in enumerate(line)]
    # L2: reverse runs from the highest level down to the lowest odd level.
    odd = [lv for lv in levels if lv % 2 == 1]
    if odd:
        for level in range(max(levels), min(odd) - 1, -1):
            i = 0
            while i < n:
                if levels[i] >= level:
                    j = i
                    while j < n and levels[j] >= level:
                        j += 1
                    chars[i:j] = chars[i:j][::-1]
                    levels[i:j] = levels[i:j][::-1]
                    i = j
                else:
                    i += 1
    return "".join(chars)


def main():
    lines = sys.stdin.read().split("\n")
    sys.stdout.write("\n".join(to_visual(raw.strip()) for raw in lines))


if __name__ == "__main__":
    main()
