// Puts a brand palette (app/Support/BrandPalette: --brand, --brand-hover,
// --brand-on...) on <html>, where base/_tokens.scss reads it. The server
// sets it for the first frame (app.blade.php); this keeps it current as
// pages change -- a public invoice in its client's color, then back to the
// app in your own -- and the moment a color is saved.
export function applyBrand(palette) {
    if (!palette) return;
    Object.entries(palette).forEach(([name, value]) => document.documentElement.style.setProperty(name, value));
}
