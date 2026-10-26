#!/usr/bin/env python3
"""Rasterize the official Lumeron elephant logo into square PWA icons.

No recoloring or recreation of the supplied mark. Only padding and scaling.
Requires Pillow. Run at repository root: python docs/mobile/generate-icons.py
"""
from pathlib import Path
from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "public/images/lumeron/elephant.png"
DEST = ROOT / "public/pwa/icons"
DEST.mkdir(parents=True, exist_ok=True)

logo = Image.open(SOURCE).convert("RGBA")

for filename, size, fraction in (
    ("lumeron-192.png", 192, 0.73),
    ("lumeron-512.png", 512, 0.73),
    ("lumeron-maskable-512.png", 512, 0.59),
    ("apple-touch-icon.png", 180, 0.73),
):
    side = round(size * fraction)
    mark = ImageOps.contain(logo, (side, side), Image.Resampling.LANCZOS)
    background = Image.new("RGBA", (size, size), (247, 250, 252, 255))
    xy = ((size - mark.width) // 2, (size - mark.height) // 2)
    background.alpha_composite(mark, xy)
    output = DEST / filename
    background.convert("RGB").save(output, format="PNG", optimize=True)
    assert Image.open(output).size == (size, size)
    print(f"{output.relative_to(ROOT)}: {size} x {size}")
