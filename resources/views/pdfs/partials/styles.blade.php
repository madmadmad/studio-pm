{{-- Shared by pdfs.invoice and pdfs.proposal, so the two documents stay
     one design. Self-hosted TTFs under resources/fonts/pdf -- dompdf's font
     loader can't decode WOFF2 (no brotli support in this stack) and
     its default chroot only allows paths under the app root, so
     these are separate copies from the browser's /public/webfonts
     set rather than the same files. See resources/fonts/pdf/README
     if these ever need regenerating. --}}
@font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Regular.ttf') }}') format('truetype'); font-weight: 400; }
@font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Medium.ttf') }}') format('truetype'); font-weight: 500; }
@font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-SemiBold.ttf') }}') format('truetype'); font-weight: 600; }
@font-face { font-family: 'Inter'; src: url('{{ resource_path('fonts/pdf/Inter-Bold.ttf') }}') format('truetype'); font-weight: 700; }
@font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-Medium.ttf') }}') format('truetype'); font-weight: 500; }
@font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-SemiBold.ttf') }}') format('truetype'); font-weight: 600; }
@font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-Bold.ttf') }}') format('truetype'); font-weight: 700; }
@font-face { font-family: 'Inter Display'; src: url('{{ resource_path('fonts/pdf/InterDisplay-ExtraBold.ttf') }}') format('truetype'); font-weight: 800; }

@page { margin: 1in; }
body { font-family: 'Inter', sans-serif; font-size: 11px; color: #23262e; }
.logo { width: 130px; height: auto; margin-bottom: 38px; } {{-- --space-12 scaled like the title --}}
.muted { color: #595F64; }
{{-- Title mirrors the public pages (.document__title, .document__title-prefix
     in _document.scss), scaled by this sheet's 11px body vs the page's
     14px (~0.79). --}}
h1 { font-family: 'Inter Display', sans-serif; font-weight: 800; letter-spacing: -0.02em; font-size: 23px; line-height: 25px; margin: 0 0 6px; }
.title-prefix { display: block; font-family: 'Inter', sans-serif; font-weight: 400; letter-spacing: 0; font-size: 31px; line-height: 35px; color: #595F64; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
{{-- Subhead: every small grey caption (From, Bill to, Issued on...) and
     table column header (Description, Amount). Mirrors the `subhead` mixin
     in resources/scss/abstracts/_mixins.scss, scaled like the title --
     change both together. --}}
.label, table.items th { font-size: 9.5px; line-height: 12px; font-weight: 700; color: #595F64; }
.label { margin-bottom: 6px; padding-bottom: 3px; border-bottom: 1px solid #e7e7e9; }
table.items th { text-align: left; border-bottom: 1px solid #e7e7e9; padding: 6px 0; }
table.items th.amount, table.items td.amount { text-align: right; }
table.items td { padding: 10px 0; border-bottom: 1px solid #e7e7e9; vertical-align: top; }
table.items th.description, table.items td.description { width: 66%; }
.item-details { font-size: 9px; color: #595F64; margin-top: 4px; }
table.totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
table.totals td { padding: 4px 0; }
table.totals td.amount { text-align: right; }
table.totals tr.total td { font-weight: 700; font-size: 13px; border-top: 1px solid #23262e; padding-top: 8px; }
{{-- Document header (.document__details): From | Bill to / Client, then
     on invoices the details two across. --}}
table.details { width: 100%; border-collapse: collapse; }
table.details td { width: 50%; vertical-align: top; line-height: 16px; }
table.details td.left { padding-right: 19px; }
table.parties { margin-bottom: 19px; }
.value, .party-name { font-weight: 500; }
