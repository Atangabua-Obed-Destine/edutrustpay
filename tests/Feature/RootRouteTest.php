<?php

namespace Tests\Feature;

use App\Models\Body;
use App\Models\User;
use App\Support\BodyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The root route.
 *
 * Replaces Laravel's scaffolding ExampleTest, which asserted a 200 for the
 * welcome page that no longer exists.
 */
class RootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_is_sent_to_sign_in(): void
    {
        // There is no public landing page: everything here is somebody's money.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_body_user_is_sent_to_their_console(): void
    {
        $body = Body::create(['name' => 'Archdiocese of Bamenda', 'type' => 'archdiocese']);

        $this->actingAs(User::create([
            'body_id' => $body->id,
            'name' => 'Bursary Head',
            'email' => 'bursar@example.test',
            'password' => 'password',
            'role' => 'bursary_head',
        ]));
        BodyContext::flush();

        $this->get('/')->assertRedirect(route('body.dashboard'));
    }

    public function test_the_health_check_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
