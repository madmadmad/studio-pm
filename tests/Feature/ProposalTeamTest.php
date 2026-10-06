<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Models\User;
use App\Services\ProposalPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProposalTeamTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(): Proposal
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'estimated']);

        return $company->proposals()->create(['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'project_id' => $project->id]);
    }

    public function test_people_keep_a_position_and_a_cleaned_bio(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->patchJson('/api/profile/bio', [
            'job_title' => 'Creative Director',
            'bio' => '<p>Twenty years in <strong>branding</strong>.</p><script>alert(1)</script>',
        ])->assertOk();

        $this->assertSame('Creative Director', $me->fresh()->job_title);
        $this->assertStringContainsString('<strong>branding</strong>', $me->fresh()->bio);
        $this->assertStringNotContainsString('script', $me->fresh()->bio);

        // A super admin can write anyone's.
        $other = User::factory()->teamMember()->create();
        $this->actingAs($me)->patchJson("/api/users/{$other->id}", ['job_title' => 'Designer', 'bio' => '<p>Type nerd.</p>'])->assertOk();
        $this->assertSame('Designer', $other->fresh()->job_title);
    }

    public function test_a_proposal_shows_its_team_in_the_order_picked(): void
    {
        $admin = User::factory()->create(['name' => 'Bill Sattler', 'job_title' => 'Principal', 'bio' => '<p>Founder.</p>']);
        $designer = User::factory()->teamMember()->create(['name' => 'Jack White', 'job_title' => 'Designer']);
        $proposal = $this->proposal();

        $this->actingAs($admin)->patchJson("/api/proposals/{$proposal->id}", [
            'title' => 'Brand refresh', 'body' => '<p>Scope</p>',
            'team_user_ids' => [$designer->id, $admin->id],
            'team_heading' => 'Who you\'ll work with',
        ])->assertOk();

        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page
            ->where('proposal.team.heading', 'Who you\'ll work with')
            ->where('proposal.team.members.0.name', 'Jack White')
            ->where('proposal.team.members.1.job_title', 'Principal')
            ->where('proposal.team.members.1.bio', '<p>Founder.</p>'));
    }

    public function test_team_photos_are_only_reachable_for_people_on_that_proposal(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $admin = User::factory()->create();
        $onIt = User::factory()->teamMember()->create();
        $notOnIt = User::factory()->teamMember()->create();
        foreach ([$onIt, $notOnIt] as $user) {
            $this->actingAs($admin)->post("/api/users/{$user->id}/bio-photo", ['photo' => UploadedFile::fake()->image('me.jpg', 900, 900)])->assertOk();
        }
        $proposal = $this->proposal();
        $proposal->update(['team_user_ids' => [$onIt->id]]);

        auth()->guard('web')->logout();
        $this->get(route('proposals.public.team-photo', ['token' => $proposal->accept_token, 'user' => $onIt->id]))->assertOk();
        $this->get(route('proposals.public.team-photo', ['token' => $proposal->accept_token, 'user' => $notOnIt->id]))->assertNotFound();
        $this->get(route('proposals.public.team-photo', ['token' => 'wrong-token', 'user' => $onIt->id]))->assertNotFound();
    }

    // The team section uses the bio photo, never the avatar.
    public function test_the_bio_photo_is_separate_from_the_avatar(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $me = User::factory()->create();
        $this->actingAs($me)->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('casual.jpg')])->assertOk();
        $proposal = $this->proposal();
        $proposal->update(['team_user_ids' => [$me->id]]);

        $this->assertNull($proposal->teamSection()['members'][0]['photo_url']);

        $this->actingAs($me)->post('/api/profile/bio-photo', ['photo' => UploadedFile::fake()->image('headshot.jpg', 1200, 1200)])->assertOk();
        $me->refresh();
        $this->assertNotSame($me->avatar_path, $me->bio_photo_path);
        $this->assertSame($me->bio_photo_url, $proposal->teamSection()['members'][0]['photo_url']);

        $this->actingAs($me)->deleteJson('/api/profile/bio-photo')->assertOk();
        $this->assertNull($me->fresh()->bio_photo_path);
        $this->assertNotNull($me->fresh()->avatar_path);
    }

    public function test_the_pdf_includes_the_team(): void
    {
        $proposal = $this->proposal();
        $person = User::factory()->create(['name' => 'Rosa Alder', 'job_title' => 'Strategist', 'bio' => '<p>Plans things.</p>']);
        $proposal->update(['team_user_ids' => [$person->id]]);

        $html = view('pdfs.proposal', ['proposal' => $proposal->load('items', 'company', 'project'), 'team' => $proposal->teamMembers(), 'about' => $proposal->aboutSection(), 'studio' => StudioProfile::current()])->render();

        $this->assertStringContainsString('Your team', $html);
        $this->assertStringContainsString('Strategist', $html);
        $this->assertNotEmpty(ProposalPdfRenderer::render($proposal)->output());
    }

    // Bio photos are stored as WebP; dompdf has to be able to embed one.
    public function test_the_pdf_embeds_a_webp_bio_photo(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $me = User::factory()->create();
        $proposal = $this->proposal();
        $proposal->update(['team_user_ids' => [$me->id]]);
        $imagesWithout = substr_count(ProposalPdfRenderer::render($proposal->fresh())->output(), '/Subtype /Image');

        $this->actingAs($me)->post('/api/profile/bio-photo', ['photo' => UploadedFile::fake()->image('headshot.jpg', 1200, 1200)])->assertOk();
        $this->assertStringEndsWith('.webp', $me->refresh()->bio_photo_path);

        $this->assertGreaterThan($imagesWithout, substr_count(ProposalPdfRenderer::render($proposal->fresh())->output(), '/Subtype /Image'));
    }

    public function test_picking_the_team_takes_the_proposals_permission(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())->getJson('/api/proposal-team')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/proposal-team')->assertOk();
    }

    public function test_proposals_close_with_the_about_section_unless_switched_off(): void
    {
        $admin = User::factory()->create();
        $proposal = $this->proposal();

        // On by default, from Settings; headed by the studio's name.
        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page
            ->where('proposal.about.heading', StudioProfile::brandName())
            ->where('proposal.about.body', fn ($body) => str_contains($body, 'We specialize in crafting')));

        $this->actingAs($admin)->patchJson('/api/studio-profile', [
            'name' => 'Madhouse Studio',
            'proposal_about_heading' => 'About us',
            'proposal_about' => '<p>Small studio, <em>big</em> ideas.</p><script>x</script>',
        ])->assertOk();
        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page
            ->where('proposal.about.heading', 'About us')
            ->where('proposal.about.body', '<p>Small studio, <em>big</em> ideas.</p>'));

        $this->actingAs($admin)->patchJson("/api/proposals/{$proposal->id}", ['title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'show_about' => false])->assertOk();
        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page->where('proposal.about', null));
        $fresh = $proposal->fresh()->load('items', 'company', 'project');
        $pdfHtml = view('pdfs.proposal', ['proposal' => $fresh, 'team' => collect(), 'about' => $fresh->aboutSection(), 'studio' => StudioProfile::current()])->render();
        $this->assertStringNotContainsString('Small studio', $pdfHtml);
    }

    // Dated the day it's sent (or written, before then), good for 90 days.
    public function test_a_proposal_shows_its_date_and_how_long_it_is_good_for(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00'));
        $proposal = $this->proposal();
        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page
            ->where('proposal.dates.date', '2026-10-05')
            ->where('proposal.dates.valid_until', '2027-01-03')
            ->where('proposal.dates.valid_days', 90));

        $proposal->update(['sent_at' => Carbon::parse('2026-10-12 15:00')]);
        $this->get('/p/'.$proposal->accept_token)->assertInertia(fn (Assert $page) => $page
            ->where('proposal.dates.date', '2026-10-12')
            ->where('proposal.dates.valid_until', '2027-01-10'));
    }
}
