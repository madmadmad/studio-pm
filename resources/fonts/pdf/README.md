# PDF fonts

Static TTF cuts of Inter Tight (the app's typeface), used by
`resources/views/pdfs/partials/styles.blade.php` for the invoice and
proposal PDFs.

These are separate from `public/webfonts` (the browser-facing WOFF2 set)
for three reasons:

1. dompdf's font loader (FontLib) can't decode WOFF2 on this stack --
   there's no brotli extension available, so a `@font-face` pointing at
   a `.woff2` file silently falls back to Helvetica with no error.
2. dompdf can't read variable fonts, so each weight needs its own static
   file.
3. dompdf's default chroot only allows reading files under the Laravel
   app root, so the source files have to live inside the project
   rather than being referenced from wherever they were originally
   downloaded.

## Regenerating

Inter Tight ships on Google Fonts only as a variable font (`wght`
100-900). Download `InterTight[wght].ttf` from github.com/google/fonts
(ofl/intertight) and cut each weight the PDFs use (600 is the heaviest
weight in the app) with [fonttools](https://github.com/fonttools/fonttools):

```
pip install fonttools
for w in 400:Regular 500:Medium 600:SemiBold; do
  fonttools varLib.instancer 'InterTight[wght].ttf' \
    wght=${w%%:*} --static --update-name-table \
    -o InterTight-${w##*:}.ttf
done
```

Two more cuts match one-off weights on the web pages: `InterTight-250.ttf`
(the "Proposal" / "Invoice" title prefix) and `InterTight-640.ttf`
(proposal headings). This font's named weight values don't include them,
so leave off `--update-name-table` and set the names by hand (the
PostScript names `InterTight-W250` / `InterTight-W640`), or dompdf's font
cache can mistake them for Regular. dompdf only matches standard weights,
so the styles register them as 300 and 700.

Licensed under the SIL Open Font License (public/webfonts/InterTight/OFL.txt).
