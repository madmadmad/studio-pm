{{-- Shared by pdfs.invoice and pdfs.proposal, so the two documents stay
     one design. Self-hosted TTFs under resources/fonts/pdf -- dompdf's font
     loader can't decode WOFF2 (no brotli support in this stack) and
     its default chroot only allows paths under the app root, so
     these are separate copies from the browser's /public/webfonts
     set rather than the same files. See resources/fonts/pdf/README
     if these ever need regenerating. --}}
{{-- dompdf only matches the standard weights, so the two one-off weights
     the web pages use stand in as 300 and 700: 300 is the 250 cut (the
     "Proposal" / "Invoice" title prefix), 700 the 640 cut (proposal
     headings). Nothing else asks for either. --}}
@font-face { font-family: 'Inter Tight'; src: url('{{ resource_path('fonts/pdf/InterTight-250.ttf') }}') format('truetype'); font-weight: 300; }
@font-face { font-family: 'Inter Tight'; src: url('{{ resource_path('fonts/pdf/InterTight-Regular.ttf') }}') format('truetype'); font-weight: 400; }
@font-face { font-family: 'Inter Tight'; src: url('{{ resource_path('fonts/pdf/InterTight-Medium.ttf') }}') format('truetype'); font-weight: 500; }
@font-face { font-family: 'Inter Tight'; src: url('{{ resource_path('fonts/pdf/InterTight-SemiBold.ttf') }}') format('truetype'); font-weight: 600; }
@font-face { font-family: 'Inter Tight'; src: url('{{ resource_path('fonts/pdf/InterTight-640.ttf') }}') format('truetype'); font-weight: 700; }

@page { margin: 0.5in 1in; } {{-- sides set the 468pt column; top/bottom a little under the sheet's 60px padding, scaled --}}
b, strong { font-weight: 600; } {{-- 600 is the heaviest weight; no Bold cut is loaded --}}
body { font-family: 'Inter Tight', sans-serif; font-size: 10px; line-height: 1.4; color: #23262e; } {{-- 10pt: a step under the strict scale's 11, the usual size for printed body copy --}}
.logo { width: 110px; height: auto; margin-bottom: 20px; } {{-- the page's 160px; under its --space-12, to keep a short invoice to one page --}}
.muted { color: #595F64; }
{{-- Line heights are the web page's own ratios (body text its sm 1.4,
     labels xs 1.38): InvoicePdfRenderer::FONT_HEIGHT_RATIO makes them
     print as written. --}}
{{-- Sizes mirror the public pages (_document.scss, _prose.scss) scaled
     to the printed column, ~0.69 (InvoicePdfRenderer::DPI) -- except
     body text, set at 10px rather than the 11px that scale gives. --}}
h1 { font-family: 'Inter Tight', sans-serif; font-weight: 600; letter-spacing: 0; font-size: 20.5px; line-height: 1.07; margin: 0 0 6px; } {{-- the page's 29.6px --}}
.title-prefix { display: block; font-family: 'Inter Tight', sans-serif; font-weight: 300; letter-spacing: -0.75px; font-size: 30px; line-height: 1.1; color: #595F64; margin-bottom: 22px; } {{-- the page's 44px, weight 250, -0.025em, 32px below --}}
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
{{-- Subhead: every small grey caption (From, Bill to, Issued on...) and
     table column header (Description, Amount). Mirrors the `subhead` mixin
     in resources/scss/abstracts/_mixins.scss, scaled like the rest --
     change both together. --}}
.label, table.items th { font-size: 9px; line-height: 1.38; font-weight: 400; letter-spacing: 0.13px; color: #595F64; } {{-- the mixin's normal weight and +0.015em --}}
.label { margin-bottom: 6px; padding-bottom: 3px; border-bottom: 1px solid #e7e7e9; }
table.items th { text-align: left; border-bottom: 1px solid #e7e7e9; padding: 5px 0; }
table.items th.amount, table.items td.amount { text-align: right; }
table.items td { padding: 8px 0; border-bottom: 1px solid #e7e7e9; vertical-align: top; }
{{-- Amounts pulled in like the page's .document__amount (-0.02em). --}}
table.items td.amount, table.totals td.amount { letter-spacing: -0.2px; }
table.items th.description, table.items td.description { width: 66%; }
{{-- The item's name in medium, its notes under it plain (.document__item-name). --}}
table.items td.description { font-weight: 500; }
.item-details { font-size: 9px; font-weight: 400; color: #595F64; margin-top: 4px; }
{{-- 11px from the last item's rule to the first total: the page's --space-4. --}}
table.totals { width: 100%; border-collapse: collapse; margin-top: 7px; page-break-inside: avoid; } {{-- Subtotal never on one page and Total on the next --}}
table.totals td { padding: 4px 0; }
table.totals td.amount { text-align: right; }
{{-- No rule above the total, as on the page: just a little more room (--space-2). --}}
table.totals tr.total td { font-weight: 600; font-size: 13px; padding-top: 6px; letter-spacing: 0.13px; } {{-- the label's +0.01em --}}
table.totals tr.total td.amount { letter-spacing: -0.26px; } {{-- the figure's -0.02em --}}
{{-- Document header (.document__details): From | Bill to / Client, then
     on invoices the details two across. --}}
table.details { width: 100%; border-collapse: collapse; }
table.details td { width: 50%; vertical-align: top; line-height: 1.4; } {{-- the page's --line-height-sm --}}
table.details td.left { padding-right: 19px; }
table.parties { margin-bottom: 14px; }
.value, .party-name { font-weight: 500; }
