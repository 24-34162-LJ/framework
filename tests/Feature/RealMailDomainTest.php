<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\SaveClientRequest;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Rules\RealMailDomain;
use App\Support\MailDomainChecker;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RealMailDomainTest extends TestCase
{
    /** @var list<string> domains the fake DNS was asked about */
    private array $lookedUp = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.verify_domains' => true]);

        // Fake DNS: only these domains "have a mail server". No network in tests.
        $test = $this;
        $this->app->instance(MailDomainChecker::class, new class($test) extends MailDomainChecker
        {
            public function __construct(private RealMailDomainTest $test) {}

            public function canReceiveMail(string $domain): bool
            {
                $this->test->recordLookup($domain);

                return in_array(mb_strtolower($domain), ['gmail.com', 'g.batstate-u.edu.ph'], true);
            }
        });
    }

    public function recordLookup(string $domain): void
    {
        $this->lookedUp[] = $domain;
    }

    private function passes(string $email): bool
    {
        return Validator::make(['email' => $email], ['email' => ['email', new RealMailDomain]])->passes();
    }

    public function test_real_mail_domains_are_accepted(): void
    {
        $this->assertTrue($this->passes('trackhidden9@gmail.com'));
        $this->assertTrue($this->passes('24-34162@G.BatState-U.edu.ph'));
    }

    public function test_reserved_local_domains_are_rejected_without_a_dns_lookup(): void
    {
        foreach (['admin@astratrack.local', 'staff@company.test', 'x@localhost.localhost', 'a@b.invalid'] as $email) {
            $this->assertFalse($this->passes($email), $email.' should be rejected');
        }

        $this->assertSame([], $this->lookedUp, 'reserved domains must be rejected before any DNS lookup');
    }

    public function test_domains_without_a_mail_server_are_rejected_with_a_clear_message(): void
    {
        $validator = Validator::make(['email' => 'someone@gmial.con'], ['email' => ['email', new RealMailDomain]]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('gmial.con', $validator->errors()->first('email'));
        $this->assertStringContainsString("can't receive mail", $validator->errors()->first('email'));
    }

    public function test_check_can_be_turned_off(): void
    {
        config(['mail.verify_domains' => false]);

        $this->assertTrue($this->passes('admin@astratrack.local'));
        $this->assertSame([], $this->lookedUp);
    }

    public function test_empty_and_malformed_values_are_left_to_the_other_rules(): void
    {
        $validator = Validator::make(['email' => 'not-an-email'], ['email' => ['nullable', 'email', new RealMailDomain]]);
        $this->assertSame(['The email field must be a valid email address.'], $validator->errors()->get('email'));

        $this->assertTrue(Validator::make(['email' => null], ['email' => ['nullable', 'email', new RealMailDomain]])->passes());
        $this->assertSame([], $this->lookedUp);
    }

    public function test_user_and_client_forms_use_the_rule(): void
    {
        $userRules = (new SaveUserRequest)->merge(['action' => 'add'])->rules()['email'];
        $clientRules = (new SaveClientRequest)->merge(['action' => 'save_client'])->rules()['email'];

        $this->assertNotEmpty(array_filter($userRules, fn ($rule) => $rule instanceof RealMailDomain), 'user form');
        $this->assertNotEmpty(array_filter($clientRules, fn ($rule) => $rule instanceof RealMailDomain), 'client form');
    }
}
