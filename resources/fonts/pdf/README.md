# PDF fonts

TTF copies of the app's Inter / Inter Display weights, used only by
`resources/views/pdfs/invoice.blade.php`.

These are separate from `public/webfonts` (the browser-facing WOFF2 set)
for two reasons:

1. dompdf's font loader (FontLib) can't decode WOFF2 on this stack --
   there's no brotli extension available, so a `@font-face` pointing at
   a `.woff2` file silently falls back to Helvetica with no error.
2. dompdf's default chroot only allows reading files under the Laravel
   app root, so the source files have to live inside the project
   rather than being referenced from wherever they were originally
   downloaded.

## Regenerating

- `Inter-*.ttf` (Regular/Medium/SemiBold/Bold): real Inter TTFs, sourced
  from the official Inter release (rubjo/inter or Google Fonts).
- `InterDisplay-*.ttf`: Inter Display doesn't ship pre-built TTFs, so
  these were converted from `public/webfonts/InterDisplay/*.woff2` with
  [fonttools](https://github.com/fonttools/fonttools):

  ```
  pip install fonttools brotli
  fonttools ttLib.woff2 decompress InterDisplay-Bold.woff2 -o InterDisplay-Bold.ttf
  ```
