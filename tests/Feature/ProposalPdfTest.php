<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_download_the_pdf_from_the_public_proposal_link(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create([
            'title' => 'Brand refresh',
            'body' => '<h2>Scope</h2><p>Logo and <strong>guidelines</strong>.</p><ul><li><p>Two rounds</p></li></ul>',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $proposal->items()->create([
            'description' => 'Design',
            'details' => '<p>Homepage and <em>about</em> page.</p>',
            'quantity' => 2,
            'rate' => 500,
        ]);

        $response = $this->get("/p/{$proposal->accept_token}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('proposal-brand-refresh.pdf', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_public_proposal_pdf_needs_a_valid_token(): void
    {
        $this->get('/p/not-a-real-token/pdf')->assertNotFound();
    }

    public function test_rich_text_is_sanitized_to_the_editors_own_tags(): void
    {
        $html = '<p onclick="x()">Hi <strong style="color:red">there</strong></p>'
            .'<script>alert(1)</script><img src="http://example.com/x.png"><a href="http://example.com">link</a>';

        $this->assertSame('<p>Hi <strong>there</strong></p>link', RichText::toSafeHtml($html));
    }

    public function test_plain_text_from_before_rich_text_is_escaped_into_paragraphs(): void
    {
        $this->assertSame('<p>One &amp; two</p><p>&lt;b&gt;three&lt;/b&gt;</p>', RichText::toSafeHtml("One & two\n<b>three</b>"));
    }

    public function test_plain_text_matches_the_editors_conversion(): void
    {
        $this->assertSame("Intro\n- a\n- b", RichText::toPlainText('<p>Intro</p><ul><li><p>a</p></li><li><p>b</p></li></ul>'));
        $this->assertSame('', RichText::toPlainText('<p></p>'));
    }
}
