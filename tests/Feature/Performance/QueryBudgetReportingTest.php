<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\QueryInsights;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-02: an over-budget public request used to report() a full RuntimeException
 * (stack trace, error channel) on every single request. Detection is kept, but it is now a bounded warning:
 * one structured log line per page type + path per window, never an exception report.
 */
final class QueryBudgetReportingTest extends TestCase
{
    private function request(string $path, int $queries, string $type = 'content'): Response
    {
        $request = Request::create($path);
        $request->attributes->set('cms.page_type', $type);

        return (new QueryInsights)->handle($request, static function () use ($queries): Response {
            for ($i = 0; $i < $queries; $i++) {
                DB::select('select 1');
            }

            return new Response('body', 200);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['cms.performance.query_budget.enabled' => true, 'cms.performance.query_budget.budgets' => ['content' => 5, 'default' => 25]]);
    }

    public function test_over_budget_is_a_bounded_warning_not_an_exception_report(): void
    {
        Exceptions::fake();
        Log::spy();

        $first = $this->request('/hello-world', 9);
        $this->request('/hello-world', 9);
        $this->request('/hello-world', 12);

        $this->assertSame('body', $first->getContent());
        $this->assertSame(200, $first->getStatusCode());
        Exceptions::assertNothingReported();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, 'query budget')
                && $context['page_type'] === 'content'
                && $context['budget'] === 5
                && $context['queries'] === 9
                && $context['path'] === 'hello-world';
        })->once();
    }

    public function test_each_page_type_and_path_is_reported_separately(): void
    {
        Exceptions::fake();
        Log::spy();

        $this->request('/a', 9);
        $this->request('/b', 9);
        $this->request('/a', 30, 'archive');

        Log::shouldHaveReceived('warning')->times(3);
        Exceptions::assertNothingReported();
    }

    public function test_within_budget_and_disabled_budget_stay_silent(): void
    {
        Exceptions::fake();
        Log::spy();

        $this->request('/quiet', 5);
        config(['cms.performance.query_budget.enabled' => false]);
        $this->request('/off', 50);

        Log::shouldNotHaveReceived('warning');
        Exceptions::assertNothingReported();
    }
}
